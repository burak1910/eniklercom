<?php
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => !empty($_SERVER['HTTPS']),
        'samesite' => 'Strict',
    ]);
} else {
    // PHP 7.2: samesite için path alanı kullanılır
    session_set_cookie_params(0, '/; samesite=Strict', '', !empty($_SERVER['HTTPS']), true);
}
session_start();

$config = require dirname(__DIR__) . '/config/enikler_config.php';

const UPLOAD_DIR     = __DIR__ . '/uploads/';
const WATERMARK_TEXT = 'enikler.com';
const MAX_FILE_SIZE  = 10 * 1024 * 1024;
const MAX_DIMENSION  = 1600;
const SIMILAR_MAX    = 8;    // 64 bitte en fazla bu kadar fark = "benzer" (~%87 ve üstü)
const PER_PAGE       = 40;
const IDEAS_LIMIT    = 200;

const SAD_FILE       = __DIR__ . '/data/uzgun_enikler.json';   // eski işaretler, ilk açılışta komutlara aktarılır
const BOT_FILE       = __DIR__ . '/data/bot_komutlar.json';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function loadSad(): array {
    $d = is_file(SAD_FILE) ? json_decode((string)file_get_contents(SAD_FILE), true) : [];
    return is_array($d) ? array_values(array_filter($d, 'is_string')) : [];
}

// ---------- Bot cevapları: kelime -> fotoğraflar ----------
function normWords(string $s): array {
    $s = str_replace(['İ', 'I'], ['i', 'ı'], $s);
    $out = [];
    foreach (explode(',', mb_strtolower($s, 'UTF-8')) as $w) {
        $w = trim((string)preg_replace('/\s+/u', ' ', $w));
        $w = mb_substr($w, 0, 40, 'UTF-8');
        if ($w !== '' && !in_array($w, $out, true)) $out[] = $w;
    }
    return $out;
}

function loadCommands(): array {
    if (is_file(BOT_FILE)) {
        $d = json_decode((string)file_get_contents(BOT_FILE), true);
        if (is_array($d)) return $d;
    }
    return [
        ['id' => 'rastgele', 'words' => ['rastgele'], 'reply' => '', 'mode' => 'all', 'photos' => []],
        ['id' => 'uzgun', 'words' => ['üzgün'], 'reply' => '', 'mode' => 'selected', 'photos' => loadSad()],
    ];
}

function saveCommands(array $cmds): bool {
    return file_put_contents(BOT_FILE, json_encode(array_values($cmds), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function takenWord(array $cmds, array $words, string $exceptId): string {
    foreach ($cmds as $c) {
        if ($c['id'] === $exceptId) continue;
        foreach ($words as $w) { if (in_array($w, $c['words'], true)) return $w; }
    }
    return '';
}

function getPdo(array $c): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
            $c['db_user'], $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}
function hasRole(string ...$roles): bool { return in_array($_SESSION['role'] ?? '', $roles, true); }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrfOk(): bool { return hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? ''); }

// ---------- Benzerlik (dHash) ----------
function dhash($im): string {
    $w = imagesx($im); $hgt = imagesy($im);
    $mid = imagecreatetruecolor(32, 32);                       // iki aşamalı küçültme (daha stabil)
    imagecopyresampled($mid, $im, 0, 0, 0, 0, 32, 32, $w, $hgt);
    $s = imagecreatetruecolor(9, 8);
    imagecopyresampled($s, $mid, 0, 0, 0, 0, 9, 8, 32, 32);
    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $a = imagecolorat($s, $x, $y); $b = imagecolorat($s, $x + 1, $y);
            $ga = (($a >> 16 & 255) * 299) + (($a >> 8 & 255) * 587) + (($a & 255) * 114);
            $gb = (($b >> 16 & 255) * 299) + (($b >> 8 & 255) * 587) + (($b & 255) * 114);
            $bits .= $ga > $gb ? '1' : '0';
        }
    }
    imagedestroy($mid); imagedestroy($s);
    $hex = '';
    for ($i = 0; $i < 64; $i += 4) $hex .= dechex(bindec(substr($bits, $i, 4)));
    return $hex;
}
function parts(string $hex): array { return [hexdec(substr($hex, 0, 8)), hexdec(substr($hex, 8, 8))]; }
function pop(int $v): int { $c = 0; while ($v) { $v &= $v - 1; $c++; } return $c; }
function dist(array $a, array $b): int { return pop($a[0] ^ $b[0]) + pop($a[1] ^ $b[1]); }
function simPct(int $d): int { return (int)round((64 - $d) / 64 * 100); }

// ---------- Filigran (ortada) ----------
function addWatermark($im, $text) {
    $w = imagesx($im); $h = imagesy($im);
    $font = 5; $pad = 4;
    $tw = imagefontwidth($font) * strlen($text) + $pad * 2;
    $th = imagefontheight($font) + $pad * 2;
    $tmp = imagecreatetruecolor($tw, $th);
    imagealphablending($tmp, false); imagesavealpha($tmp, true);
    imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
    imagealphablending($tmp, true);
    imagefilledrectangle($tmp, 0, 0, $tw - 1, $th - 1, imagecolorallocatealpha($tmp, 0, 0, 0, 80));
    imagestring($tmp, $font, $pad, $pad, $text, imagecolorallocatealpha($tmp, 255, 255, 255, 10));
    $newW = max(80, (int)($w * 0.40));
    $newH = (int)($newW * $th / $tw);
    imagealphablending($im, true);
    imagecopyresampled($im, $tmp, (int)(($w - $newW) / 2), (int)(($h - $newH) / 2), 0, 0, $newW, $newH, $tw, $th);
    imagedestroy($tmp);
}

// ---------- Tek dosya işleme ----------
// $known: [['id','image_url','p'=>[hi,lo]], ...] (yüklemeler arttıkça güncellenir)
function processImage(array $file, PDO $pdo, array &$known, bool $force): array {
    if ($file['error'] !== UPLOAD_ERR_OK) return [false, 'Yükleme hatası (kod: ' . $file['error'] . ')', null];
    if ($file['size'] > MAX_FILE_SIZE)    return [false, 'Dosya 10 MB sınırını aşıyor.', null];

    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true))
        return [false, 'Sadece JPG, PNG veya WEBP kabul edilir.', null];

    $src = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$src) return [false, 'Resim işlenemedi.', null];

    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $angle = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
        if ($angle) { $rot = imagerotate($src, $angle, 0); if ($rot) { imagedestroy($src); $src = $rot; } }
    }

    $w = imagesx($src); $hh = imagesy($src);
    $scale = min(1, MAX_DIMENSION / max($w, $hh));
    $nw = max(1, (int)($w * $scale)); $nh = max(1, (int)($hh * $scale));
    $im = imagecreatetruecolor($nw, $nh);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagecopyresampled($im, $src, 0, 0, 0, 0, $nw, $nh, $w, $hh);
    imagedestroy($src);

    addWatermark($im, WATERMARK_TEXT);

    // Benzerlik kontrolü (filigranlı halin hash'i, eskilerle aynı yöntem)
    $hex = dhash($im);
    $p = parts($hex);
    $best = null; $bestD = 65;
    foreach ($known as $k) {
        $d = dist($p, $k['p']);
        if ($d < $bestD) { $bestD = $d; $best = $k; }
    }
    if ($best && $bestD <= SIMILAR_MAX && !$force) {
        imagedestroy($im);
        return [false, 'ATLANDI: zaten benzeri var (%' . simPct($bestD) . ' benzerlik, #' . $best['id'] . ')', $best['image_url']];
    }

    $newName = bin2hex(random_bytes(8)) . '.jpg';
    $ok = imagejpeg($im, UPLOAD_DIR . $newName, 85);
    imagedestroy($im);
    if (!$ok) return [false, 'Dosya kaydedilemedi (klasör izni?).', null];

    try {
        $pdo->prepare("INSERT INTO enikler (image_url, phash) VALUES (?, ?)")->execute([$newName, $hex]);
        $known[] = ['id' => (int)$pdo->lastInsertId(), 'image_url' => $newName, 'p' => $p];
    } catch (PDOException $e) {
        @unlink(UPLOAD_DIR . $newName);
        return [false, 'Veritabanına kaydedilemedi.', null];
    }
    return [true, ($best && $bestD <= SIMILAR_MAX) ? 'Yüklendi (benzer olmasına rağmen eklendi)' : 'Yüklendi', null];
}

function loadKnown(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT id, image_url, phash FROM enikler WHERE phash IS NOT NULL AND phash <> ''") as $r) {
        $out[] = ['id' => (int)$r['id'], 'image_url' => $r['image_url'], 'p' => parts($r['phash'])];
    }
    return $out;
}

// ---------- Çıkış / Giriş ----------
if (isset($_GET['logout'])) {
    $_SESSION = []; session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}

$loginError = '';
if (isset($_POST['login']) && csrfOk()) {
    try {
        $pdo = getPdo($config);
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? AND is_active = 1");
        $stmt->execute([trim($_POST['username'] ?? '')]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $locked = $user && $user['locked_until'] && strtotime($user['locked_until']) > time();
        if ($user && $user['locked_until'] && !$locked) {
            $pdo->prepare("UPDATE admins SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$user['id']]);
        }
        if ($locked) {
            $loginError = 'Çok fazla hatalı deneme. 15 dakika sonra tekrar dene.';
        } elseif ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
            $pdo->prepare("UPDATE admins SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
        } else {
            if ($user) {
                $pdo->prepare("UPDATE admins SET
                        locked_until = IF(failed_attempts + 1 >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until),
                        failed_attempts = failed_attempts + 1 WHERE id = ?")->execute([$user['id']]);
            }
            $loginError = 'Kullanıcı adı veya şifre yanlış.';
        }
    } catch (PDOException $e) { $loginError = 'Veritabanı hatası.'; }
}

$isAdmin = !empty($_SESSION['admin_id']) && hasRole('admin', 'editor');
$view = in_array($_GET['view'] ?? '', ['dups', 'ideas', 'scores', 'twitter', 'bot'], true) ? $_GET['view'] : 'upload';
if ($view === 'bot' && !hasRole('admin')) $view = 'upload';
$results = [];
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

// ---------- İşlemler ----------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['login']) && csrfOk()) {
    try {
        $pdo = getPdo($config);
        $do = $_POST['do'] ?? '';

        if (isset($_FILES['enik_foto'])) {                         // çoklu yükleme
            if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
            $known = loadKnown($pdo);
            $force = !empty($_POST['force']);
            $files = $_FILES['enik_foto'];
            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                [$ok, $msg, $thumb] = processImage([
                    'name' => $name, 'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i], 'size' => $files['size'][$i],
                ], $pdo, $known, $force);
                $results[] = [$name, $ok, $msg, $thumb];
            }
        }
        elseif ($do === 'delete' && hasRole('admin')) {            // foto silme
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT image_url FROM enikler WHERE id = ?");
            $st->execute([$id]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $pdo->prepare("DELETE FROM enikler WHERE id = ?")->execute([$id]);
                @unlink(UPLOAD_DIR . basename($row['image_url']));
                $_SESSION['flash'] = "#$id silindi.";
            }
            header('Location: ' . $_SERVER['REQUEST_URI']); exit;
        }
        elseif (in_array($do, ['bot_add', 'bot_save', 'bot_delete'], true) && hasRole('admin')) {   // bot cevapları
            $cmds = loadCommands();
            $cmdId = (string)($_POST['cmd'] ?? '');
            $saved = true;
            if ($do === 'bot_add') {
                $words = normWords((string)($_POST['words'] ?? ''));
                $clash = takenWord($cmds, $words, '');
                if (!$words) {
                    $_SESSION['flash_err'] = 'Bir kelime yazmalısın.';
                } elseif ($clash !== '') {
                    $_SESSION['flash_err'] = "\"$clash\" kelimesi zaten kullanılıyor.";
                } else {
                    $cmdId = bin2hex(random_bytes(4));
                    $cmds[] = ['id' => $cmdId, 'words' => $words, 'reply' => '', 'mode' => 'selected', 'photos' => []];
                    $saved = saveCommands($cmds);
                }
            } elseif ($do === 'bot_save') {
                $words = normWords((string)($_POST['words'] ?? ''));
                $clash = takenWord($cmds, $words, $cmdId);
                if (!$words) {
                    $_SESSION['flash_err'] = 'En az bir kelime kalmalı.';
                } elseif ($clash !== '') {
                    $_SESSION['flash_err'] = "\"$clash\" kelimesi başka bir cevapta kullanılıyor.";
                } else {
                    $valid = $pdo->query("SELECT image_url FROM enikler")->fetchAll(PDO::FETCH_COLUMN);
                    $picked = array_map('basename', array_map('strval', (array)($_POST['photos'] ?? [])));
                    foreach ($cmds as &$c) {
                        if ($c['id'] !== $cmdId) continue;
                        $c['words']  = $words;
                        $c['reply']  = mb_substr(trim((string)($_POST['reply'] ?? '')), 0, 200, 'UTF-8');
                        $c['mode']   = ($_POST['mode'] ?? '') === 'all' ? 'all' : 'selected';
                        $c['photos'] = array_values(array_intersect($picked, array_map('basename', $valid)));
                    }
                    unset($c);
                    $saved = saveCommands($cmds);
                    if ($saved) $_SESSION['flash'] = 'Kaydedildi.';
                }
            } else {
                $cmds = array_filter($cmds, function ($c) use ($cmdId) { return $c['id'] !== $cmdId; });
                $saved = saveCommands($cmds);
                $cmdId = '';
            }
            if (!$saved) $_SESSION['flash_err'] = 'Dosyaya kaydedilemedi.';
            header('Location: ?view=bot' . ($cmdId !== '' ? '&cmd=' . urlencode($cmdId) : '')); exit;
        }
        elseif ($do === 'backfill' && hasRole('admin')) {          // eski fotoların hash'ini hesapla
            $rows = $pdo->query("SELECT id, image_url FROM enikler WHERE phash IS NULL OR phash = '' LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
            $done = 0; $missing = 0;
            foreach ($rows as $r) {
                $path = UPLOAD_DIR . basename($r['image_url']);
                $im = is_file($path) ? @imagecreatefromstring(file_get_contents($path)) : false;
                if (!$im) { $missing++; continue; }
                $pdo->prepare("UPDATE enikler SET phash = ? WHERE id = ?")->execute([dhash($im), $r['id']]);
                imagedestroy($im); $done++;
            }
            $_SESSION['flash'] = "$done foto tarandı" . ($missing ? ", $missing dosya okunamadı" : '') . '. ' .
                ($done === 150 ? 'Daha var, tekrar bas.' : '');
            header('Location: ' . $_SERVER['REQUEST_URI']); exit;
        }
        elseif (in_array($do, ['idea_read', 'idea_unread', 'idea_readall', 'idea_delete'], true)) {   // fikirler
            $id = (int)($_POST['id'] ?? 0);
            if ($do === 'idea_read')    $pdo->prepare("UPDATE fikirler SET is_read = 1 WHERE id = ?")->execute([$id]);
            if ($do === 'idea_unread')  $pdo->prepare("UPDATE fikirler SET is_read = 0 WHERE id = ?")->execute([$id]);
            if ($do === 'idea_readall') $pdo->exec("UPDATE fikirler SET is_read = 1");
            if ($do === 'idea_delete' && hasRole('admin')) $pdo->prepare("DELETE FROM fikirler WHERE id = ?")->execute([$id]);
            if ($do === 'score_delete' && hasRole('admin')) $pdo->prepare("DELETE FROM oyun_skorlari WHERE id = ?")->execute([$id]);
            header('Location: ' . $_SERVER['REQUEST_URI']); exit;
        }
        elseif ($do === 'twitter_save' && hasRole('admin')) {
            $twPath = dirname(__DIR__) . '/config/twitter_config.php';
            $curTw = file_exists($twPath) ? require $twPath : [];
            $newTw = [
                'twitter_api_key'       => trim($_POST['twitter_api_key'] ?? ''),
                'twitter_api_secret'    => trim($_POST['twitter_api_secret'] ?? ''),
                'twitter_access_token'  => trim($_POST['twitter_access_token'] ?? ''),
                'twitter_access_secret' => trim($_POST['twitter_access_secret'] ?? ''),
                'gemini_api_key'        => trim($_POST['gemini_api_key'] ?? ''),
                'cron_token'            => trim($_POST['cron_token'] ?? ($curTw['cron_token'] ?? bin2hex(random_bytes(16)))),
                'enabled'               => !empty($_POST['enabled']),
                'site_url'              => trim($_POST['site_url'] ?? 'https://enikler.com/enik-tinderi.html'),
                'hashtags'              => trim($_POST['hashtags'] ?? '#enik #enikler #köpek #dogsoftwitter'),
            ];
            file_put_contents($twPath, "<?php\n\nreturn " . var_export($newTw, true) . ";\n");
            $_SESSION['flash'] = 'Twitter botu ayarları güncellendi.';
            header('Location: ?view=twitter'); exit;
        }
        elseif ($do === 'twitter_tweet_now' && hasRole('admin')) {
            require_once __DIR__ . '/includes/TwitterBot.php';
            $bot = new TwitterBot();
            $res = $bot->runDailyTweet();
            if ($res['ok']) {
                $_SESSION['flash'] = 'Tebrikler! Rastgele enik paylaşıldı: ' . $res['tweet_url'];
            } else {
                $_SESSION['flash_err'] = 'Tweet paylaşılamadı: ' . $res['error'];
            }
            header('Location: ?view=twitter'); exit;
        }
    } catch (PDOException $e) {
        $results[] = ['Veritabanı', false, 'Hata: tablo ya da sütun eksik olabilir. (' . $e->getCode() . ')', null];
    }
}

// ---------- Görünüm verileri ----------
$gallery = []; $total = 0; $unhashed = 0; $pairs = []; $page = max(1, (int)($_GET['p'] ?? 1));
$ideas = []; $ideasUnread = 0; $onlyUnread = ($_GET['f'] ?? '') === 'unread'; $scores = [];

if ($isAdmin) {
    try {
        $pdo = getPdo($config);
        $total = (int)$pdo->query("SELECT COUNT(*) FROM enikler")->fetchColumn();
        $unhashed = (int)$pdo->query("SELECT COUNT(*) FROM enikler WHERE phash IS NULL OR phash = ''")->fetchColumn();
        if ($view === 'upload') {
            $gallery = $pdo->query("SELECT id, image_url, likes, dislikes, created_at FROM enikler ORDER BY id DESC LIMIT " . PER_PAGE . " OFFSET " . (($page - 1) * PER_PAGE))->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($view === 'dups') {
            $all = [];
            foreach ($pdo->query("SELECT id, image_url, phash FROM enikler WHERE phash IS NOT NULL AND phash <> '' ORDER BY id") as $r) {
                $all[] = ['id' => $r['id'], 'url' => $r['image_url'], 'p' => parts($r['phash'])];
            }
            for ($i = 0, $n = count($all); $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $d = dist($all[$i]['p'], $all[$j]['p']);
                    if ($d <= SIMILAR_MAX + 4) $pairs[] = [$d, $all[$i], $all[$j]];
                }
            }
            usort($pairs, function ($a, $b) { return $a[0] <=> $b[0]; });
            $pairs = array_slice($pairs, 0, 100);
        }
    } catch (PDOException $e) { $flash = 'Veritabanı hatası (phash sütunu eklendi mi?).'; }

    // Fikirler ayrı try'da: tablo yoksa diğer sekmeler bozulmasın
    try {
        $pdo = getPdo($config);
        $ideasUnread = (int)$pdo->query("SELECT COUNT(*) FROM fikirler WHERE is_read = 0")->fetchColumn();
        if ($view === 'ideas') {
            $ideas = $pdo->query("SELECT id, kind, message, contact, is_read, created_at FROM fikirler "
                . ($onlyUnread ? "WHERE is_read = 0 " : "")
                . "ORDER BY id DESC LIMIT " . IDEAS_LIMIT)->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        if ($view === 'ideas') $flash = 'fikirler tablosu bulunamadı. SQL\'i çalıştırdın mı?';
    }
    if ($view === 'scores') {
        try {
            $pdo = getPdo($config);
            $scores = $pdo->query("SELECT * FROM oyun_skorlari ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $flash = 'oyun_skorlari tablosu henüz yok veya henüz skor girilmedi.';
        }
    }
    if ($view === 'bot') {
        $botCommands = loadCommands();
        $botCmd = $botCommands[0] ?? null;
        foreach ($botCommands as $c) { if ($c['id'] === ($_GET['cmd'] ?? '')) $botCmd = $c; }
        $botPhotos = [];
        try {
            $botPhotos = getPdo($config)->query("SELECT id, image_url FROM enikler ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $flash = 'Fotoğraflar okunamadı.'; }
    }
    if ($view === 'twitter') {
        require_once __DIR__ . '/includes/TwitterBot.php';
        $bot = new TwitterBot();
        $twConfigFile = dirname(__DIR__) . '/config/twitter_config.php';
        $twConfig = file_exists($twConfigFile) ? require $twConfigFile : [];
        $twHistory = $bot->getHistory();
        $recentTweets = $twHistory['tweets'] ?? [];
        $seenCount = count($twHistory['seen_ids'] ?? []);
    }
}
$pages = max(1, (int)ceil($total / PER_PAGE));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Enik Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700&display=swap" rel="stylesheet">
<style>
  body { font-family:'Nunito',sans-serif; background:#fffaf0; margin:0; padding:20px; color:#333; }
  .wrap { max-width:960px; margin:0 auto; }
  .box { background:#fff; border:2px dashed #FFB703; padding:28px; border-radius:20px; text-align:center; margin-bottom:22px; }
  .login { max-width:420px; margin:12vh auto 0; }
  h2 { margin-top:0; } h3 { margin:0 0 12px; }
  input[type=file], input[type=password], input[type=text] { margin-bottom:14px; font-family:inherit; max-width:100%; }
  input[type=password], input[type=text] { padding:10px; border:1px solid #ddd; border-radius:10px; width:80%; }
  button, .btn { background:#FFB703; color:#fff; border:none; padding:10px 20px; border-radius:10px; font:700 15px 'Nunito',sans-serif; cursor:pointer; text-decoration:none; display:inline-block; }
  button.danger { background:#d62828; padding:6px 12px; font-size:13px; }
  button:disabled { opacity:.6; cursor:wait; }
  nav { display:flex; gap:10px; align-items:center; margin-bottom:18px; flex-wrap:wrap; }
  nav a.tab { padding:8px 16px; border-radius:99px; background:#fff; color:#555; text-decoration:none; font-weight:700; border:1px solid #eee; }
  nav a.tab.on { background:#FFB703; color:#fff; border-color:#FFB703; }
  nav .sp { flex:1; } nav small { color:#888; }
  .dot { background:#F0506E; color:#fff; border-radius:99px; padding:1px 8px; font-size:12px; margin-left:4px; }
  ul.res { list-style:none; padding:0; text-align:left; font-size:14px; }
  ul.res li { padding:6px 0; display:flex; gap:10px; align-items:center; word-break:break-all; }
  ul.res img { width:44px; height:44px; object-fit:cover; border-radius:8px; }
  .ok { color:#1a8f3c; } .err { color:#d62828; }
  .flash { background:#e8f7ee; color:#1a8f3c; padding:10px 14px; border-radius:10px; margin-bottom:16px; font-weight:700; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
  .item { background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.06); font-size:12px; text-align:center; }
  .item img { width:100%; aspect-ratio:1; object-fit:cover; display:block; }
  .item .m { padding:8px; color:#777; }
  .cmds { display:flex; flex-wrap:wrap; gap:10px; margin:14px 0; }
  .cmd { background:#FFFBEF; border:2px solid #F3E3B8; border-radius:14px; padding:10px 16px; color:#2B2118; text-decoration:none; }
  .cmd.on { background:#FFB703; border-color:#FFB703; color:#fff; }
  .cmd b { display:block; font-size:16px; } .cmd small { font-size:12px; opacity:.8; }
  .addcmd { display:flex; gap:8px; flex-wrap:wrap; } .addcmd input[type=text] { flex:1; min-width:180px; width:auto; margin:0; }
  .modes label { display:block; margin-bottom:8px; font-weight:700; }
  .picks { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:8px; margin-top:12px; }
  .pick { position:relative; display:block; cursor:pointer; border-radius:12px; overflow:hidden; background:#eee; }
  .pick img { width:100%; aspect-ratio:1; object-fit:cover; display:block; opacity:.7; }
  .pick input { position:absolute; top:8px; left:8px; width:22px; height:22px; margin:0; }
  .pick input:checked + img { opacity:1; outline:5px solid #FFB703; outline-offset:-5px; }
  .savebar { position:sticky; bottom:0; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 0; margin-top:12px; background:#fff; font-weight:800; }
  .pair { background:#fff; border-radius:14px; padding:14px; margin-bottom:14px; display:flex; gap:14px; align-items:center; box-shadow:0 2px 8px rgba(0,0,0,.06); flex-wrap:wrap; }
  .pair .it { text-align:center; font-size:12px; } .pair img { width:150px; height:150px; object-fit:cover; border-radius:10px; display:block; margin-bottom:6px; }
  .pair .score { font-size:1.6rem; font-weight:700; color:#F08C00; min-width:80px; text-align:center; }
  .pager { display:flex; gap:6px; justify-content:center; flex-wrap:wrap; margin:20px 0; }
  .pager a { padding:6px 12px; background:#fff; border-radius:8px; text-decoration:none; color:#555; border:1px solid #eee; }
  .pager a.on { background:#FFB703; color:#fff; }
  label.chk { font-size:14px; color:#666; display:block; margin-bottom:14px; }
  .idea { background:#fff; border-radius:14px; padding:14px 16px; margin-bottom:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border-left:5px solid #eee; }
  .idea.unread { border-left-color:#F0506E; }
  .idea .tag { background:#FFF1CC; color:#F08C00; font-weight:700; font-size:12px; padding:3px 10px; border-radius:99px; }
  .idea small { color:#999; margin-left:6px; }
  .idea p { margin:10px 0; line-height:1.5; word-break:break-word; }
  .idea .acts { display:flex; gap:8px; }
  .idea .acts form { margin:0; }
  .idea .acts button { padding:6px 12px; font-size:13px; }
</style>
</head>
<body>
<div class="wrap">
<?php if (!$isAdmin): ?>
  <div class="box login">
    <h2>Admin Girişi</h2>
    <?php if ($loginError): ?><p class="err"><?= h($loginError) ?></p><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="text" name="username" placeholder="Kullanıcı adı" required autofocus><br>
      <input type="password" name="password" placeholder="Şifre" required><br>
      <button type="submit" name="login" value="1">Giriş</button>
    </form>
  </div>
<?php else: ?>
  <nav>
    <a class="tab <?= $view === 'upload' ? 'on' : '' ?>" href="?">📤 Yükle &amp; Galeri (<?= $total ?>)</a>
    <a class="tab <?= $view === 'dups' ? 'on' : '' ?>" href="?view=dups">🔍 Benzer Fotolar</a>
    <a class="tab <?= $view === 'ideas' ? 'on' : '' ?>" href="?view=ideas">💡 Fikirler<?php if ($ideasUnread): ?> <span class="dot"><?= $ideasUnread ?></span><?php endif; ?></a>
    <a class="tab <?= $view === 'scores' ? 'on' : '' ?>" href="?view=scores">🎮 Oyun Skorları</a>
    <a class="tab <?= $view === 'twitter' ? 'on' : '' ?>" href="?view=twitter">🐦 Twitter Botu</a>
    <?php if (hasRole('admin')): ?><a class="tab <?= $view === 'bot' ? 'on' : '' ?>" href="?view=bot">🤖 Bot Cevapları</a><?php endif; ?>
    <span class="sp"></span>
    <small><?= h($_SESSION['username']) ?> (<?= h($_SESSION['role']) ?>) · <a href="?logout=1">Çıkış</a></small>
  </nav>

  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if (!empty($_SESSION['flash_err'])): ?>
    <div class="flash" style="background:#fde8e8; color:#d62828;"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
  <?php endif; ?>

  <?php if ($view !== 'ideas' && $unhashed > 0 && hasRole('admin')): ?>
    <div class="box" style="border-style:solid">
      <b><?= $unhashed ?> fotoğrafın benzerlik izi henüz çıkarılmadı.</b>
      Eski fotolar bu yüzden kontrolde görünmüyor.
      <form method="POST" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="do" value="backfill">
        <button type="submit">Eski fotoları tara</button>
      </form>
    </div>
  <?php endif; ?>

<?php if ($view === 'upload'): ?>
  <div class="box">
    <h2>Yeni Enik Ekle</h2>
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="file" name="enik_foto[]" id="files" accept="image/jpeg,image/png,image/webp" multiple required><br>
      <div id="count" style="font-size:14px;color:#666;margin-bottom:12px;min-height:18px"></div>
      <label class="chk"><input type="checkbox" name="force" value="1"> Benzeri varsa bile ekle</label>
      <button type="submit" id="btn">Yükle ve Filigranla</button>
    </form>
    <?php if ($results): ?>
      <ul class="res">
      <?php foreach ($results as [$name, $ok, $msg, $thumb]): ?>
        <li class="<?= $ok ? 'ok' : 'err' ?>">
          <?php if ($thumb): ?><img src="uploads/<?= h($thumb) ?>" alt=""><?php endif; ?>
          <span><?= $ok ? '✓' : '✗' ?> <?= h($name) ?> — <?= h($msg) ?></span>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <h3>Mevcut fotoğraflar</h3>
  <div class="grid">
  <?php foreach ($gallery as $g): ?>
    <div class="item">
      <a href="uploads/<?= h($g['image_url']) ?>" target="_blank"><img src="uploads/<?= h($g['image_url']) ?>" loading="lazy" alt=""></a>
      <div class="m">#<?= (int)$g['id'] ?> · ❤ <?= (int)$g['likes'] ?> · 👎 <?= (int)$g['dislikes'] ?><br><?= h(substr($g['created_at'], 0, 10)) ?>
      <?php if (hasRole('admin')): ?>
        <form method="POST" onsubmit="return confirm('#<?= (int)$g['id'] ?> silinsin mi?')" style="margin-top:6px">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
          <button class="danger" type="submit">Sil</button>
        </form>
      <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if ($pages > 1): ?>
    <div class="pager"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'on' : '' ?>" href="?p=<?= $i ?>"><?= $i ?></a><?php endfor; ?></div>
  <?php endif; ?>

<?php elseif ($view === 'dups'): ?>
  <h3>Benzer fotoğraf çiftleri</h3>
  <p style="color:#777;font-size:14px">En benzerden başlayarak sıralı. %95 ve üstü neredeyse kesin aynı fotoğraftır.</p>
  <?php if (!$pairs): ?><div class="box">Benzer çift bulunamadı 🎉</div><?php endif; ?>
  <?php foreach ($pairs as [$d, $a, $b]): ?>
    <div class="pair">
      <?php foreach ([$a, $b] as $x): ?>
        <div class="it">
          <img src="uploads/<?= h($x['url']) ?>" loading="lazy" alt="">#<?= (int)$x['id'] ?>
          <?php if (hasRole('admin')): ?>
            <form method="POST" onsubmit="return confirm('#<?= (int)$x['id'] ?> silinsin mi?')">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
              <button class="danger" type="submit">Sil</button>
            </form>
          <?php endif; ?>
        </div>
        <?php if ($x === $a): ?><div class="score">%<?= simPct($d) ?><br><small style="font-size:12px;color:#999">benzerlik</small></div><?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

<?php else: ?>
  <h3>Gelen fikirler</h3>
  <nav>
    <a class="tab <?= !$onlyUnread ? 'on' : '' ?>" href="?view=ideas">Hepsi</a>
    <a class="tab <?= $onlyUnread ? 'on' : '' ?>" href="?view=ideas&amp;f=unread">Okunmamış (<?= $ideasUnread ?>)</a>
    <span class="sp"></span>
    <?php if ($ideasUnread): ?>
      <form method="POST" style="margin:0">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="do" value="idea_readall">
        <button type="submit">Hepsini okundu yap</button>
      </form>
    <?php endif; ?>
  </nav>
  <?php if (!$ideas): ?><div class="box">Fikir yok 📭</div><?php endif; ?>
  <?php foreach ($ideas as $i): ?>
    <div class="idea <?= $i['is_read'] ? '' : 'unread' ?>">
      <span class="tag"><?= h($i['kind']) ?></span>
      <small><?= h($i['created_at']) ?><?php if ($i['contact']): ?> · <a href="https://x.com/<?= h($i['contact']) ?>" target="_blank" rel="noopener">@<?= h($i['contact']) ?></a><?php endif; ?></small>
      <p><?= nl2br(h($i['message'])) ?></p>
      <div class="acts">
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
          <input type="hidden" name="do" value="<?= $i['is_read'] ? 'idea_unread' : 'idea_read' ?>">
          <button type="submit"><?= $i['is_read'] ? 'Okunmadı yap' : 'Okundu' ?></button>
        </form>
        <?php if (hasRole('admin')): ?>
          <form method="POST" onsubmit="return confirm('Bu fikir silinsin mi?')">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
            <input type="hidden" name="do" value="idea_delete">
            <button class="danger" type="submit">Sil</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>


<?php if ($view === 'scores'): ?>
  <h3>🎮 Son Oyun Skorları</h3>
  <?php if (!$scores): ?>
    <div class="box">Henüz skor kaydı yok 🐾</div>
  <?php else: ?>
    <div style="background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.06);">
      <table style="width:100%; border-collapse:collapse; text-align:left; font-size:14px;">
        <thead>
          <tr style="background:#FFF1CC; border-bottom:2px solid #FFB703;">
            <th style="padding:10px 14px;">Oyun</th>
            <th style="padding:10px 14px;">Oyuncu</th>
            <th style="padding:10px 14px;">X (Twitter)</th>
            <th style="padding:10px 14px;">Skor</th>
            <th style="padding:10px 14px;">Tarih</th>
            <th style="padding:10px 14px;">İşlem</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($scores as $s): ?>
            <tr style="border-bottom:1px solid #eee;">
              <td style="padding:10px 14px;"><b><?= $s['game'] === 'enik_besleme' ? '🦴 Enik Besleme' : ($s['game'] === 'enik_crush' ? '🍬 Enik Crush' : h($s['game'])) ?></b></td>
              <td style="padding:10px 14px;"><?= h($s['player_name']) ?></td>
              <td style="padding:10px 14px;">
                <?php if ($s['twitter_handle']): ?>
                  <a href="https://x.com/<?= h($s['twitter_handle']) ?>" target="_blank" rel="noopener">@<?= h($s['twitter_handle']) ?></a>
                <?php else: ?>-<?php endif; ?>
              </td>
              <td style="padding:10px 14px; font-weight:800; color:#F08C00;"><?= (int)$s['score'] ?></td>
              <td style="padding:10px 14px; color:#888; font-size:12px;"><?= h($s['created_at']) ?></td>
              <td style="padding:10px 14px;">
                <?php if (hasRole('admin')): ?>
                  <form method="POST" onsubmit="return confirm('Bu skor silinsin mi?')" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="do" value="score_delete">
                    <button class="danger" type="submit">Sil</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php elseif ($view === 'twitter'): ?>
  <div class="box" style="text-align:left; border-style:solid;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
      <div>
        <h2 style="margin:0;">🐦 Twitter (X) Günlük Enik Botu</h2>
        <small style="color:#777;">Enik Tinder veritabanındaki fotoğrafları ve her gün yeni bir enik tweetini otomatik paylaşır.</small>
      </div>
      <div>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Şimdi rastgele bir enik Twitter hesabınızda paylaşılsın mı?');">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <input type="hidden" name="do" value="twitter_tweet_now">
          <button type="submit" style="background:#1DA1F2; font-size:14px; padding:10px 18px;">🎲 Şimdi Enik Tweetle (Test Et)</button>
        </form>
      </div>
    </div>

    <!-- Durum Kartları -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:20px;">
      <div style="background:#FFF9E6; border:1px solid #FFE08A; padding:14px; border-radius:12px;">
        <div style="font-size:12px; color:#888; font-weight:700;">BOT DURUMU</div>
        <div style="font-size:18px; font-weight:800; color:<?= !empty($twConfig['enabled']) ? '#1a8f3c' : '#d62828' ?>;">
          <?= !empty($twConfig['enabled']) ? '🟢 Aktif' : '🔴 Pasif' ?>
        </div>
      </div>
      <div style="background:#FFF9E6; border:1px solid #FFE08A; padding:14px; border-radius:12px;">
        <div style="font-size:12px; color:#888; font-weight:700;">PAYLAŞILAN ENİKLER</div>
        <div style="font-size:18px; font-weight:800; color:#F08C00;">
          <?= $seenCount ?> / <?= $total ?> enik
        </div>
      </div>
      <div style="background:#FFF9E6; border:1px solid #FFE08A; padding:14px; border-radius:12px;">
        <div style="font-size:12px; color:#888; font-weight:700;">YAPAY ZEKA (GEMINI)</div>
        <div style="font-size:18px; font-weight:800; color:<?= !empty($twConfig['gemini_api_key']) ? '#1DA1F2' : '#888' ?>;">
          <?= !empty($twConfig['gemini_api_key']) ? '✨ Gemini AI Aktif' : '📝 Yerleşik Şablonlar' ?>
        </div>
      </div>
    </div>

    <!-- Ayarlar Formu -->
    <details style="background:#fafafa; border:1px solid #eee; border-radius:12px; padding:14px; margin-bottom:20px;" <?= empty($twConfig['twitter_api_key']) ? 'open' : '' ?>>
      <summary style="font-weight:800; cursor:pointer; font-size:15px; color:#2B2118;">⚙️ Twitter API ve Bot Ayarları (Düzenlemek için tıkla)</summary>
      <form method="POST" style="margin-top:16px;">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="do" value="twitter_save">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:4px;">Twitter API Key (Consumer Key):</label>
            <input type="text" name="twitter_api_key" value="<?= h($twConfig['twitter_api_key'] ?? '') ?>" placeholder="API Key" style="width:100%; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:4px;">Twitter API Secret (Consumer Secret):</label>
            <input type="password" name="twitter_api_secret" value="<?= h($twConfig['twitter_api_secret'] ?? '') ?>" placeholder="API Secret" style="width:100%; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:4px;">Twitter Access Token:</label>
            <input type="text" name="twitter_access_token" value="<?= h($twConfig['twitter_access_token'] ?? '') ?>" placeholder="Access Token" style="width:100%; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:4px;">Twitter Access Token Secret:</label>
            <input type="password" name="twitter_access_secret" value="<?= h($twConfig['twitter_access_secret'] ?? '') ?>" placeholder="Access Token Secret" style="width:100%; box-sizing:border-box;">
          </div>
        </div>

        <div style="margin-top:12px;">
          <label style="display:block; font-size:13px; font-weight:700; margin-bottom:4px;">Google Gemini API Anahtarı (Opsiyonel - Özgün Yapay Zeka Metinleri):</label>
          <input type="password" name="gemini_api_key" value="<?= h($twConfig['gemini_api_key'] ?? '') ?>" placeholder="AI Studio'dan alabileceğiniz Gemini API Key" style="width:100%; box-sizing:border-box;">
          <small style="color:#777;">Boş bırakılırsa sistemdeki 50+ esprili yerleşik Türkçe enik şablonu kullanılır.</small>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:12px;">
          <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:4px;">Web Cron Güvenlik Tokenı:</label>
            <input type="text" name="cron_token" value="<?= h($twConfig['cron_token'] ?? '') ?>" style="width:100%; box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:4px;">Hashtag'ler:</label>
            <input type="text" name="hashtags" value="<?= h($twConfig['hashtags'] ?? '#enik #enikler #köpek #dogsoftwitter') ?>" style="width:100%; box-sizing:border-box;">
          </div>
        </div>

        <div style="margin-top:12px;">
          <label style="font-weight:700; cursor:pointer;">
            <input type="checkbox" name="enabled" value="1" <?= !empty($twConfig['enabled']) ? 'checked' : '' ?>> Bot Aktif Olsun
          </label>
        </div>

        <div style="margin-top:14px;">
          <button type="submit" style="padding:10px 24px;">💾 Ayarları Kaydet</button>
        </div>
      </form>
    </details>

    <!-- Otomasyon / Cron Bilgisi -->
    <div style="background:#FFF9E6; border:1px solid #FFE08A; border-radius:12px; padding:14px; margin-bottom:20px;">
      <h3 style="margin:0 0 6px; font-size:15px; color:#2B2118;">⏰ Günlük Otomatik Paylaşım (Cron Job) Kurulumu</h3>
      <p style="margin:0 0 10px; font-size:13px; color:#555;">
        Her gün otomatik paylaşım yapılması için aşağıdaki yöntemlerden birini kullanabilirsiniz:
      </p>
      <div style="font-size:13px; margin-bottom:8px;">
        <b>1. Yöntem (Plesk / cPanel / Sunucu Zamanlanmış Görevleri):</b><br>
        Günde bir kez çalışacak şekilde şu komutu zamanlayın:<br>
        <code style="background:#fff; border:1px solid #ddd; padding:4px 8px; border-radius:6px; display:inline-block; margin-top:4px;">php <?= h(dirname(__DIR__)) ?>/cron_twitter.php</code>
      </div>
      <div style="font-size:13px;">
        <b>2. Yöntem (Harici Web Cron / cron-job.org / Webhook):</b><br>
        cron-job.org gibi ücretsiz bir servise şu URL'yi günde bir kez çağıracak şekilde ekleyin:<br>
        <code style="background:#fff; border:1px solid #ddd; padding:4px 8px; border-radius:6px; display:inline-block; margin-top:4px;">https://<?= h($_SERVER['HTTP_HOST'] ?? 'enikler.com') ?>/cron_twitter.php?token=<?= h($twConfig['cron_token'] ?? '') ?></code>
      </div>
    </div>

    <!-- Son Paylaşılan Tweetler -->
    <h3 style="margin:0 0 12px; font-size:16px;">📜 Son Paylaşılan Enik Tweetleri</h3>
    <?php if (empty($recentTweets)): ?>
      <p style="color:#777; font-size:14px;">Henüz bu bot ile tweet paylaşılmadı. Yukarıdaki "Şimdi Enik Tweetle" butonuyla ilk tweeti paylaşabilirsiniz!</p>
    <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <?php foreach ($recentTweets as $t): ?>
          <div style="display:flex; gap:14px; align-items:center; background:#fff; border:1px solid #eee; border-radius:12px; padding:12px; box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <img src="uploads/<?= h($t['image_url']) ?>" alt="Enik" style="width:64px; height:64px; object-fit:cover; border-radius:8px; flex:none;">
            <div style="flex:1; min-width:0;">
              <div style="font-size:14px; color:#2B2118; margin-bottom:4px; white-space:pre-wrap;"><?= h($t['text']) ?></div>
              <div style="font-size:12px; color:#888;">
                <span>📅 <?= h($t['date'] ?? '') ?></span> · 
                <a href="<?= h($t['tweet_url'] ?? '#') ?>" target="_blank" rel="noopener" style="color:#1DA1F2; font-weight:700; text-decoration:none;">X'te Tweeti Gör ↗</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($view === 'bot'): ?>
  <div class="box">
    <h2 style="margin-bottom:4px;">🤖 Bot Cevapları</h2>
    <p style="margin:0; color:#777; font-size:14px;">Biri <b>@eniklercom</b> etiketleyip buradaki bir kelimeyi yazınca, bot o kelimeye seçtiğin fotoğraflardan rastgele birini cevap atar.</p>
    <div class="cmds">
      <?php foreach ($botCommands as $c): ?>
        <a class="cmd <?= $botCmd && $c['id'] === $botCmd['id'] ? 'on' : '' ?>" href="?view=bot&amp;cmd=<?= h(urlencode($c['id'])) ?>">
          <b><?= h(implode(', ', $c['words'])) ?></b>
          <small><?= $c['mode'] === 'all' ? 'Tüm fotoğraflar' : count($c['photos']) . ' fotoğraf' ?></small>
        </a>
      <?php endforeach; ?>
    </div>
    <form method="POST" class="addcmd">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="do" value="bot_add">
      <input type="text" name="words" maxlength="200" placeholder="Yeni kelime, örn: mutlu" required>
      <button type="submit">+ Ekle</button>
    </form>
  </div>

  <?php if ($botCmd): $pickedSet = array_flip($botCmd['photos']); ?>
  <div class="box">
    <form method="POST" id="botForm">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="do" value="bot_save">
      <input type="hidden" name="cmd" value="<?= h($botCmd['id']) ?>">

      <label style="display:block; font-weight:800; margin-bottom:4px;">Kullanıcı ne yazınca çıksın?</label>
      <input type="text" name="words" maxlength="200" value="<?= h(implode(', ', $botCmd['words'])) ?>" required>
      <div style="margin:-8px 0 14px; color:#999; font-size:13px;">Birden fazla kelimeyi virgülle ayır: üzgün, ağlayan</div>

      <label style="display:block; font-weight:800; margin-bottom:4px;">Fotoğrafla birlikte yazı (isteğe bağlı)</label>
      <input type="text" name="reply" maxlength="200" value="<?= h($botCmd['reply']) ?>" placeholder="Boş kalırsa sadece fotoğraf atılır">

      <div class="modes">
        <label><input type="radio" name="mode" value="all" <?= $botCmd['mode'] === 'all' ? 'checked' : '' ?>> Tüm fotoğraflardan rastgele</label>
        <label><input type="radio" name="mode" value="selected" <?= $botCmd['mode'] !== 'all' ? 'checked' : '' ?>> Sadece seçtiklerimden rastgele</label>
      </div>

      <div id="pickArea">
        <label class="chk" style="margin:0;"><input type="checkbox" id="onlyPicked"> Sadece seçilileri göster</label>
        <div class="picks">
          <?php foreach ($botPhotos as $p): $file = basename($p['image_url']); ?>
            <label class="pick">
              <input type="checkbox" name="photos[]" value="<?= h($file) ?>" <?= isset($pickedSet[$file]) ? 'checked' : '' ?>>
              <img src="uploads/<?= h($file) ?>" loading="lazy" alt="#<?= (int)$p['id'] ?>">
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="savebar">
        <span id="pickCount"></span>
        <button type="submit">Kaydet</button>
      </div>
    </form>
    <form method="POST" onsubmit="return confirm('Bu kelime silinsin mi? Fotoğraflar silinmez.')">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="do" value="bot_delete">
      <input type="hidden" name="cmd" value="<?= h($botCmd['id']) ?>">
      <button class="danger" type="submit">Bu kelimeyi sil</button>
    </form>
  </div>
  <script>
    (function () {
      const form = document.getElementById('botForm'), area = document.getElementById('pickArea'), count = document.getElementById('pickCount'), only = document.getElementById('onlyPicked');
      const boxes = [...form.querySelectorAll('input[name="photos[]"]')];
      function refresh() {
        const all = form.querySelector('input[name=mode]:checked').value === 'all';
        area.style.display = all ? 'none' : '';
        count.textContent = all ? 'Tüm fotoğraflar kullanılacak' : boxes.filter(b => b.checked).length + ' fotoğraf seçili';
        boxes.forEach(b => { b.closest('.pick').style.display = only.checked && !b.checked ? 'none' : ''; });
      }
      form.addEventListener('change', refresh);
      refresh();
    })();
  </script>
  <?php endif; ?>
<?php endif; ?>

<script>
  const f = document.getElementById('files');
  if (f) {
    f.addEventListener('change', () => {
      document.getElementById('count').textContent = f.files.length ? f.files.length + ' fotoğraf seçildi' : '';
    });
    document.getElementById('uploadForm').addEventListener('submit', () => {
      const b = document.getElementById('btn'); b.disabled = true; b.textContent = 'Yükleniyor...';
    });
  }
</script>
<?php endif; ?>
</div>
</body>
</html>
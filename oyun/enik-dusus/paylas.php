<?php
// Enik Düşüşü paylaşım uç noktası
//   POST (gif, lv, rk) -> GIF'i kaydeder, {"url": "..."} döner
//   GET  ?id=xxxxxxxxxxxx -> önizlemesi (og:image) GIF olan paylaşım sayfası
// Gereken: bu klasörde PHP'nin yazabildiği bir "paylasim" klasörü oluşturabilmesi.
declare(strict_types=1);

const MAX_BYTES = 1500000;   // GIF en fazla ~1,5 MB
const MAX_FILES = 5000;      // klasör şişmesin
const RANKS = ['Rezalet', 'Kötü', 'İdare Eder', 'Fena Değil', 'İyi', 'Harika', 'Süper', 'Muhteşem', 'Kusursuz', 'Efsane', 'Tanrısal'];
$base = 'https://enikler.com/oyun/enik-dusus/';
$dir  = __DIR__ . '/paylasim';

function fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_FILES['gif'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] < 100 || $f['size'] > MAX_BYTES) fail(400, 'gecersiz dosya');
    $info = @getimagesize($f['tmp_name']);
    if (!$info || $info[2] !== IMAGETYPE_GIF || $info[0] > 400 || $info[1] > 900) fail(400, 'gif degil');

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) fail(500, 'klasor yok');
    if (count(glob($dir . '/*.gif') ?: []) >= MAX_FILES) fail(503, 'dolu');

    // basit hız sınırı: aynı IP 3 saniyede bir yükleyebilir
    $rl = $dir . '/.rl_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    if (is_file($rl) && time() - filemtime($rl) < 3) fail(429, 'yavas');
    touch($rl);

    $id = bin2hex(random_bytes(6));
    if (!move_uploaded_file($f['tmp_name'], "$dir/$id.gif")) fail(500, 'kaydedilemedi');
    $lv = max(1, min(999, (int)($_POST['lv'] ?? 1)));
    $rk = in_array($_POST['rk'] ?? '', RANKS, true) ? $_POST['rk'] : RANKS[0];
    file_put_contents("$dir/$id.json", json_encode(['lv' => $lv, 'rk' => $rk]));

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['url' => $base . 'paylas.php?id=' . $id]);
    exit;
}

// ---- GET: paylaşım sayfası ----
$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{12}$/', $id) || !is_file("$dir/$id.gif")) {
    http_response_code(404);
    header('Location: ' . $base, true, 302);
    exit;
}
$meta  = json_decode((string)@file_get_contents("$dir/$id.json"), true) ?: [];
$lv    = max(1, min(999, (int)($meta['lv'] ?? 1)));
$rk    = in_array($meta['rk'] ?? '', RANKS, true) ? $meta['rk'] : RANKS[0];
$gif   = $base . "paylasim/$id.gif";
$title = "Enik Düşüşü 🐾 Seviye $lv: $rk";
$desc  = 'Eniği cuk diye düşürebilir misin? Beni geçebilir misin?';
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?></title>
<meta name="description" content="<?= $e($desc) ?>">
<meta property="og:site_name" content="enikler.com">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= $e($base . 'paylas.php?id=' . $id) ?>">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:description" content="<?= $e($desc) ?>">
<meta property="og:image" content="<?= $e($gif) ?>">
<meta property="og:image:type" content="image/gif">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $e($title) ?>">
<meta name="twitter:description" content="<?= $e($desc) ?>">
<meta name="twitter:image" content="<?= $e($gif) ?>">
<style>
  body { margin:0; min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:16px; padding:16px; background:#0e0705; color:#fff; font-family:'Comic Sans MS','Comic Neue',cursive; text-align:center; }
  img { max-width:min(320px, 100%); max-height:70vh; border:3px solid #000; border-radius:8px; }
  h1 { margin:0; font-size:1.4rem; }
  a { display:inline-block; background:#FFB703; color:#fff; font-weight:700; font-size:1.2rem; padding:14px 28px; border-radius:16px; text-decoration:none; box-shadow:0 5px 0 #F08C00; }
</style>
</head>
<body>
<h1><?= $e($title) ?></h1>
<img src="<?= $e($gif) ?>" alt="Oyunun GIF'i">
<a href="<?= $e($base) ?>">Sen de dene 🐾</a>
</body>
</html>

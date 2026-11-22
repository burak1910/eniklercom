<?php
// Kelimeli enik linkleri: /e/uzgun rastgele foto, /e/uzgun/<foto> paylaşılan sabit foto.
// Kelimeler ve fotoğraflar admin panelindeki Bot Cevapları sayfasından gelir.
const SITE = 'https://enikler.com';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function slugify(string $word): string {
    $word = str_replace(['İ', 'I'], ['i', 'ı'], $word);
    $word = strtr(mb_strtolower($word, 'UTF-8'), ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']);
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', $word), '-');
}

function titleCase(string $word): string {
    $first = mb_substr($word, 0, 1, 'UTF-8');
    $first = $first === 'i' ? 'İ' : mb_strtoupper($first, 'UTF-8');
    return $first . mb_substr($word, 1, null, 'UTF-8');
}

function loadPages(): array {
    $config = require dirname(__DIR__, 2) . '/config/enikler_config.php';
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'], $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $all = array_map('basename', $pdo->query("SELECT image_url FROM enikler")->fetchAll(PDO::FETCH_COLUMN));
    $all = array_values(array_filter($all, function ($f) { return is_file(dirname(__DIR__) . '/uploads/' . $f); }));

    $raw = is_file(dirname(__DIR__) . '/data/bot_komutlar.json')
        ? json_decode((string)file_get_contents(dirname(__DIR__) . '/data/bot_komutlar.json'), true) : null;
    if (!is_array($raw)) $raw = [['words' => ['rastgele'], 'reply' => '', 'mode' => 'all', 'photos' => []]];

    $pages = [];
    foreach ($raw as $c) {
        $pool = ($c['mode'] ?? '') === 'all' ? $all : array_values(array_intersect($c['photos'] ?? [], $all));
        if (!$pool || empty($c['words'])) continue;
        foreach ($c['words'] as $i => $word) {
            $slug = slugify((string)$word);
            if ($slug === '' || isset($pages[$slug])) continue;
            $pages[$slug] = ['word' => (string)$word, 'reply' => (string)($c['reply'] ?? ''), 'pool' => $pool, 'main' => $i === 0];
        }
    }
    return $pages;
}

try {
    $pages = loadPages();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Şu an açılamadı, birazdan tekrar dene.');
}

$slug = strtolower((string)($_GET['k'] ?? ''));
$page = $pages[$slug] ?? null;
if ($slug !== '' && !$page) http_response_code(404);

$photo = null;
if ($page) {
    $wanted = (string)($_GET['p'] ?? '');
    foreach ($page['pool'] as $f) {
        if ($wanted !== '' && pathinfo($f, PATHINFO_FILENAME) === $wanted) { $photo = $f; break; }
    }
    if (!$photo) {
        $last = (string)($_GET['son'] ?? '');
        $pool = array_values(array_filter($page['pool'], function ($f) use ($last) { return pathinfo($f, PATHINFO_FILENAME) !== $last; }));
        if (!$pool) $pool = $page['pool'];
        $photo = $pool[array_rand($pool)];
    }
    header('Cache-Control: no-store');
}

$title     = $page ? titleCase($page['word']) . ' enik' : 'Enik seç';
$photoKey  = $photo ? pathinfo($photo, PATHINFO_FILENAME) : '';
$photoUrl  = $photo ? SITE . '/uploads/' . rawurlencode($photo) : '';
$shareUrl  = $page ? SITE . '/e/' . $slug . '/' . rawurlencode($photoKey) : SITE . '/e/';
$desc      = $page && $page['reply'] !== '' ? $page['reply'] : 'Sana uygun eniği enikler.com\'da bul 🐾';
$tweetUrl  = 'https://x.com/intent/post?text=' . rawurlencode($title . ' 🐾') . '&url=' . rawurlencode($shareUrl);
?><!DOCTYPE html>
<html lang="tr">
<head>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-CCZH5RWPMD"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-CCZH5RWPMD');
</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#FFF4D6">
<title><?= h($title) ?> | enikler.com</title>
<meta name="description" content="<?= h($desc) ?>">
<meta property="og:site_name" content="enikler.com">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($desc) ?>">
<meta property="og:url" content="<?= h($shareUrl) ?>">
<?php if ($photo): ?>
<meta property="og:image" content="<?= h($photoUrl) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= h($photoUrl) ?>">
<?php else: ?>
<meta name="twitter:card" content="summary">
<?php endif; ?>
<meta name="twitter:title" content="<?= h($title) ?>">
<meta name="twitter:description" content="<?= h($desc) ?>">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<style>
  :root { --accent:#FFB703; --accent-d:#F08C00; --ink:#2B2118; --muted:#8a7a66; --line:#F3E3B8; }
  * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
  body {
    margin:0; min-height:100dvh; font-family:'Nunito',system-ui,sans-serif; color:var(--ink);
    background:radial-gradient(60vmax 60vmax at 0% 0%,#FFE9A8 0%,transparent 60%),radial-gradient(50vmax 50vmax at 100% 100%,#FFD3C2 0%,transparent 60%),#FFF8E7;
    background-attachment:fixed;
    padding:calc(env(safe-area-inset-top) + 16px) 16px calc(env(safe-area-inset-bottom) + 28px);
  }
  :focus-visible { outline:3px solid var(--accent); outline-offset:3px; }
  main { width:100%; max-width:520px; margin:0 auto; display:flex; flex-direction:column; gap:16px; }
  .logo { display:block; text-align:center; font-size:1.7rem; font-weight:900; color:var(--accent-d); text-decoration:none; }
  .logo span { color:var(--ink); }
  h1 { margin:0; text-align:center; font-size:clamp(2rem,8vw,2.8rem); font-weight:900; line-height:1.1; }
  .sub { margin:-8px 0 0; text-align:center; font-weight:700; color:var(--muted); }
  .photo { display:block; width:100%; aspect-ratio:1; object-fit:cover; border-radius:28px; background:#fff; box-shadow:0 12px 30px rgba(120,80,20,.18); }
  .acts { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .btn { display:block; text-align:center; text-decoration:none; padding:15px 10px; border-radius:16px; font-weight:900; font-size:1.05rem; }
  .btn.more { background:var(--accent); color:#fff; box-shadow:0 5px 0 var(--accent-d); }
  .btn.share { background:#2B2118; color:#fff; box-shadow:0 5px 0 #000; }
  .btn:active { transform:translateY(3px); box-shadow:none; }
  h2 { margin:8px 0 0; text-align:center; font-size:1.05rem; font-weight:900; }
  .chips { display:flex; flex-wrap:wrap; justify-content:center; gap:8px; }
  .chip { background:#fff; border:2px solid var(--line); border-radius:99px; padding:9px 16px; color:var(--ink); text-decoration:none; font-weight:800; }
  .chip.on { background:var(--accent); border-color:var(--accent); color:#fff; }
  .site { text-align:center; font-weight:800; color:var(--accent-d); }
</style>
</head>
<body>
<main>
  <a class="logo" href="/">enikler<span>.com</span></a>
  <h1><?= h($title) ?></h1>
  <?php if ($page): ?>
    <?php if ($page['reply'] !== ''): ?><p class="sub"><?= h($page['reply']) ?></p><?php endif; ?>
    <img class="photo" src="/uploads/<?= h(rawurlencode($photo)) ?>" alt="<?= h($title) ?> fotoğrafı">
    <div class="acts">
      <a class="btn more" href="/e/<?= h($slug) ?>?son=<?= h(rawurlencode($photoKey)) ?>">Başka enik</a>
      <a class="btn share" href="<?= h($tweetUrl) ?>" target="_blank" rel="noopener">X'te paylaş</a>
    </div>
    <h2>Başka nasıl bir enik?</h2>
  <?php else: ?>
    <p class="sub"><?= $slug !== '' ? 'Bu kelimeyi bulamadık, şunlara bak:' : 'Nasıl bir enik görmek istersin?' ?></p>
  <?php endif; ?>
  <nav class="chips" aria-label="Enik kelimeleri">
    <?php foreach ($pages as $s => $p): if (!$p['main']) continue; ?>
      <a class="chip <?= $s === $slug ? 'on' : '' ?>" href="/e/<?= h($s) ?>"><?= h(titleCase($p['word'])) ?></a>
    <?php endforeach; ?>
  </nav>
  <a class="site" href="/">Tüm enikler için enikler.com 🐾</a>
</main>
</body>
</html>

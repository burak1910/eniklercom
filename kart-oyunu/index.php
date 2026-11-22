<?php
// Enikler Kart Oyunu: şimdilik SADECE admin rolüyle giriş yapmış kişiye açık.
// Herkese açmak için bu kapıyı kaldırmak yeterli. Diğerleri düz 404 görür.
$izinli = false;
if (isset($_COOKIE[session_name()])) {
    @session_start(['read_and_close' => true]);
    $izinli = !empty($_SESSION['admin_id']) && ($_SESSION['role'] ?? '') === 'admin';
}
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
if (!$izinli) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="tr"><meta charset="UTF-8"><title>404</title><p>Sayfa bulunamadı.</p></html>';
    exit;
}
header('Content-Type: text/html; charset=UTF-8');

// Canlı maç sunucusu ayarı web kökünün dışında durur. Dosya yoksa sadece bot modu açılır.
$ayar = null;
$ayarDosya = dirname(__DIR__, 2) . '/config/kart_oyunu_config.php';
if (is_file($ayarDosya)) {
    $c = require $ayarDosya;
    if (is_array($c) && !empty($c['secret']) && !empty($c['ws_url'])) {
        $bitis = time() + 43200;
        $ayar = ['ws' => $c['ws_url'], 'tk' => $bitis . '.' . hash_hmac('sha256', 'kart:' . $bitis, $c['secret'])];
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#FFF4D6">
<title>Enikler Kart Oyunu</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;800;900&display=swap" rel="stylesheet">
<style>
<?php readfile(__DIR__ . '/stil.css'); ?>
</style>
</head>
<body>
<div id="uygulama"></div>
<script>window.KART_AYAR = <?php echo json_encode($ayar, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;</script>
<script>
<?php readfile(__DIR__ . '/motor.js'); ?>
</script>
<script>
<?php readfile(__DIR__ . '/arayuz.js'); ?>
</script>
</body>
</html>

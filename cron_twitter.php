<?php
/**
 * enikler.com - Günlük Twitter (X) Enik Paylaşım Botu
 *
 * Bu dosya hem CLI (Terminal / Cron Job) hem de HTTP (Web URL) üzerinden çalıştırılabilir.
 *
 * CLI Kullanımı:
 *   php httpdocs/cron_twitter.php
 *
 * Web / Webhook Kullanımı:
 *   https://enikler.com/cron_twitter.php?token=GUVENLIK_ANAHTARINIZ
 */

// Zaman aşımını önle ve UTF-8 ayarla
@set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/TwitterBot.php';

$baseDir = dirname(__DIR__);
$twConfigFile = $baseDir . '/config/twitter_config.php';
$config = file_exists($twConfigFile) ? require $twConfigFile : [];

$isCli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));

// Web üzerinden çağrılıyorsa token doğrulaması yap
if (!$isCli) {
    $expectedToken = $config['cron_token'] ?? '';
    $receivedToken = $_GET['token'] ?? ($_POST['token'] ?? '');

    if (empty($expectedToken) || empty($receivedToken) || !hash_equals($expectedToken, $receivedToken)) {
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Yetkisiz erişim. Geçerli bir ?token= parametresi gereklidir.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $bot = new TwitterBot($config);

    // İsteğe bağlı belirli bir enik id'si tetiklenmiş mi
    $specificId = isset($_GET['enik_id']) ? (int)$_GET['enik_id'] : (isset($argv[1]) ? (int)$argv[1] : null);

    $result = $bot->runDailyTweet($specificId);

    if ($result['ok']) {
        echo json_encode([
            'status'    => 'success',
            'message'   => $result['message'],
            'tweet_url' => $result['tweet_url'],
            'tweet_id'  => $result['tweet_id'],
            'enik_id'   => $result['enik_id'],
            'image_url' => $result['image_url'],
            'text'      => $result['text'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => $result['error']
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'exception',
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

<?php
// Bot Cevapları listesini Python botuna verir. Sadece okur; zaten herkese açık fotoğraf adlarını döndürür.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function readJsonList(string $path): ?array {
    if (!is_file($path)) return null;
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

try {
    $config = require dirname(__DIR__) . '/config/enikler_config.php';
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'], $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $all = array_map('basename', $pdo->query("SELECT image_url FROM enikler")->fetchAll(PDO::FETCH_COLUMN));

    $commands = readJsonList(__DIR__ . '/data/bot_komutlar.json');
    if ($commands === null) {   // Bot Cevapları'nda henüz hiç kayıt yapılmadıysa panelin varsayılanları
        $commands = [
            ['words' => ['rastgele'], 'reply' => '', 'mode' => 'all', 'photos' => []],
            ['words' => ['üzgün'], 'reply' => '', 'mode' => 'selected', 'photos' => readJsonList(__DIR__ . '/data/uzgun_enikler.json') ?? []],
        ];
    }

    $out = [];
    foreach ($commands as $c) {
        $out[] = [
            'words'  => $c['words'] ?? [],
            'reply'  => $c['reply'] ?? '',
            'mode'   => $c['mode'] ?? 'selected',
            'photos' => array_values(array_intersect($c['photos'] ?? [], $all)),
        ];
    }
    echo json_encode(['commands' => $out, 'all' => $all], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'liste okunamadi']);
}

<?php
header('Content-Type: application/json; charset=utf-8');

const MAIL_TO   = 'admin@enikler.com, sjasdas40@gmail.com';
const MAIL_FROM = 'no-reply@enikler.com';
const PANEL_URL = 'https://enikler.com/admin.php?view=ideas';

function out($ok, $err = '', $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'error' => $err]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, 'method', 405);
if (!empty($_POST['website'])) out(true);                       // bot tuzağı: sessizce yut

$kinds   = ['Kişilik testi', 'Oyun', 'Özellik', 'Yardım', 'Diğer'];
$kind    = in_array($_POST['type'] ?? '', $kinds, true) ? $_POST['type'] : 'Diğer';
$msg     = trim((string)($_POST['msg'] ?? ''));
$contact = ltrim(trim((string)($_POST['contact'] ?? '')), '@');

if (mb_strlen($msg) < 1) out(false, 'kısa', 400);
$msg     = mb_substr($msg, 0, 250);
$contact = preg_match('/^[A-Za-z0-9_]{1,15}$/', $contact) ? $contact : '';

try {
    $c = require dirname(__DIR__) . '/config/enikler_config.php';
    $pdo = new PDO(
        'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
        $c['db_user'], $c['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Hız sınırı: aynı IP 30 sn'de bir fikir gönderebilir
    $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $st = $pdo->prepare("SELECT COUNT(*) FROM fikirler WHERE ip_hash = ? AND created_at > (NOW() - INTERVAL 30 SECOND)");
    $st->execute([$ipHash]);
    if ((int)$st->fetchColumn() > 0) out(false, 'hız', 429);

    // Mail bombası koruması: son 10 dakikada 20'den fazla fikir geldiyse kaydet ama mail atma
    $recent = (int)$pdo->query("SELECT COUNT(*) FROM fikirler WHERE created_at > (NOW() - INTERVAL 10 MINUTE)")->fetchColumn();

    $pdo->prepare("INSERT INTO fikirler (kind, message, contact, ip_hash) VALUES (?, ?, ?, ?)")
        ->execute([$kind, $msg, $contact, $ipHash]);
} catch (Throwable $e) {
    out(false, 'db', 500);
}

// Mail: hata verse bile kullanıcıya başarılı dönülür, fikir zaten kaydedildi
if ($recent <= 20) {
    $subject = '=?UTF-8?B?' . base64_encode('💡 Yeni fikir: ' . $kind) . '?=';
    $body = "Tür: $kind\n"
          . 'İletişim: ' . ($contact ? "@$contact (https://x.com/$contact)" : '-') . "\n"
          . 'Tarih: ' . date('Y-m-d H:i') . "\n\n"
          . $msg . "\n\n"
          . 'Panel: ' . PANEL_URL . "\n";
    $headers = "From: Enikler <" . MAIL_FROM . ">\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    @mail(MAIL_TO, $subject, $body, $headers);
}

out(true);
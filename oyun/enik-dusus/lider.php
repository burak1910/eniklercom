<?php
// Enik Düşüşü liderlik tablosu (PHP 7.2 uyumlu)
//   GET  lider.php?nick=xxx        -> {"top":[...ilk 20...], "ben":{"sira":..,"l":..,"r":..} | null}
//   POST nick, lv, rk              -> {"ok":true, "sira":.., "rekor":true|false}
// Kayıtlar web'den erişilemeyen /httpdocs/data/ klasöründe (orada .htaccess erişimi kapatıyor).
// Her X kullanıcı adının sadece en iyi skoru tutulur. Profil fotoğrafı istemcide /avatar.php?u=... ile gelir.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const RANK_N   = 11;      // oyundaki rütbe sayısı (Rezalet ... Tanrısal)
const MAX_LV   = 300;     // bundan yüksek seviye gelirse hile sayılır
const MAX_KAYIT = 20000;
const TOP_N    = 20;
$dosya = dirname(__DIR__, 2) . '/data/enik_dusus_lider.json';

function cevap(int $kod, array $v): void { http_response_code($kod); echo json_encode($v, JSON_UNESCAPED_UNICODE); exit; }
function oku(string $f): array { $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return is_array($j) ? $j : []; }
function sirala(array $k): array {
    uasort($k, function ($a, $b) { return ($b['s'] <=> $a['s']) ?: ($a['t'] <=> $b['t']); });   // eşitlikte önce ulaşan önde
    return $k;
}
function sirasi(array $sirali, string $key): int { $i = 1; foreach ($sirali as $k => $_) { if ($k === $key) return $i; $i++; } return 0; }
function nickOk($n): bool { return is_string($n) && preg_match('/^[A-Za-z0-9_]{1,15}$/', $n) === 1; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nick = ltrim(trim((string)($_POST['nick'] ?? '')), '@');
    $lv = (int)($_POST['lv'] ?? 0); $rk = (int)($_POST['rk'] ?? -1);
    if (!nickOk($nick) || $lv < 1 || $lv > MAX_LV || $rk < 0 || $rk >= RANK_N) cevap(400, ['ok' => false, 'hata' => 'gecersiz']);

    // basit hız sınırı: aynı IP 4 saniyede bir gönderebilir
    $rl = sys_get_temp_dir() . '/enik_lider_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    if (is_file($rl) && time() - filemtime($rl) < 4) cevap(429, ['ok' => false, 'hata' => 'yavas']);
    @touch($rl);

    $fp = @fopen($dosya, 'c+');
    if (!$fp) cevap(500, ['ok' => false, 'hata' => 'dosya']);
    flock($fp, LOCK_EX);
    $j = json_decode((string)stream_get_contents($fp), true); $k = is_array($j) ? $j : [];
    $key = 'u_' . strtolower($nick); $s = ($lv - 1) * RANK_N + $rk;   // önek: rakamlı adlar dizi anahtarında sayıya dönmesin
    $rekor = !isset($k[$key]) || $s > $k[$key]['s'];
    if ($rekor) {
        if (!isset($k[$key]) && count($k) >= MAX_KAYIT) { flock($fp, LOCK_UN); fclose($fp); cevap(503, ['ok' => false, 'hata' => 'dolu']); }
        $k[$key] = ['n' => $nick, 'l' => $lv, 'r' => $rk, 's' => $s, 't' => time()];
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($k, JSON_UNESCAPED_UNICODE)); fflush($fp);
    } elseif ($k[$key]['n'] !== $nick) {   // büyük/küçük harf düzeltmesi
        $k[$key]['n'] = $nick; ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($k, JSON_UNESCAPED_UNICODE)); fflush($fp);
    }
    flock($fp, LOCK_UN); fclose($fp);
    $sir = sirala($k);
    cevap(200, ['ok' => true, 'sira' => sirasi($sir, $key), 'rekor' => $rekor, 'toplam' => count($k)]);
}

// ---- GET: tablo ----
$sir = sirala(oku($dosya));
$top = [];
foreach (array_slice($sir, 0, TOP_N, true) as $v) $top[] = ['n' => $v['n'], 'l' => $v['l'], 'r' => $v['r']];
$ben = null;
$nick = ltrim((string)($_GET['nick'] ?? ''), '@');
if (nickOk($nick) && isset($sir['u_' . strtolower($nick)])) {
    $key = 'u_' . strtolower($nick); $v = $sir[$key];
    $ben = ['sira' => sirasi($sir, $key), 'l' => $v['l'], 'r' => $v['r']];
}
cevap(200, ['top' => $top, 'ben' => $ben, 'toplam' => count($sir)]);

<?php
// avatar.php?u=kullaniciadi  ->  X profil fotoğrafını (resim olarak) döndürür.
// Sıra: önbellek -> resmi API (token varsa) -> syndication sayfası -> unavatar (sunucu tarafı yedek)
// Bearer token tarayıcıya hiç çıkmaz. Aynı kullanıcı adı 7 gün önbellekten gelir.
// Dış istek: IP başına saatlik ve günlük toplam limitle sınırlıdır.

$configFile = dirname(__DIR__) . '/config/enikler_config.php';
$config = is_file($configFile) ? require $configFile : array();
if (!is_array($config)) $config = array();

$handle = isset($_GET['u']) ? $_GET['u'] : '';
if (!preg_match('/^[A-Za-z0-9_]{1,15}$/', $handle)) { http_response_code(400); exit; }

$hasToken = !empty($config['x_bearer']);
$scrape   = !isset($config['x_scrape']) || $config['x_scrape'];   // config'de 'x_scrape' => false ile kapatılır

$dir = sys_get_temp_dir() . '/enik_avatars';
if (!is_dir($dir)) @mkdir($dir, 0700, true);

$key    = strtolower($handle);
$img    = "$dir/$key.img";
$none   = "$dir/$key.none";
$failF  = "$dir/$key.fail";
$TTL_OK = 7 * 86400;   // bulunan fotoğraf
$TTL_NO = 3600;        // bulunamayan hesap
$TTL_FAIL = 6 * 3600;  // hiçbir yoldan çekilemeyen fotoğraf: 6 saat yeniden deneme (her sayfa açılışında kota yemesin)

function sendImage($file) {
    header("Access-Control-Allow-Origin: *");
    $info = @getimagesize($file);
    header('Content-Type: ' . ($info ? $info['mime'] : 'image/jpeg'));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}
function fail($code) { http_response_code($code); exit; }

// Sayaç: pencere içinde en fazla $limit kez true döner
function bump($file, $limit, $window) {
    $fp = @fopen($file, 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_EX);
    $d = json_decode(stream_get_contents($fp), true);
    if (!is_array($d) || $d['t'] < time() - $window) $d = array('t' => time(), 'n' => 0);
    if ($d['n'] >= $limit) { flock($fp, LOCK_UN); fclose($fp); return false; }
    $d['n']++;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($d)); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    return true;
}

function httpGet($url, $headers = array()) {
    $ua = 'Mozilla/5.0 (compatible; enikler.com avatar)';
    if (!function_exists('curl_init')) {                       // curl yoksa yedek yöntem
        if (!ini_get('allow_url_fopen')) return array(0, false);
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => 8, 'ignore_errors' => true, 'follow_location' => 1,
            'header' => implode("\r\n", array_merge($headers, array('User-Agent: ' . $ua))),
        )));
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $hl) {
                if (preg_match('~^HTTP/\S+\s(\d{3})~', $hl, $m)) $code = (int)$m[1];   // son (yönlendirme sonrası) kod
            }
        }
        return array($code, $body);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => $ua,
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($code, $body);
}

// JSON ağacında screen_name'i istenen kullanıcıya ait profile_image_url_https'i bulur
function findUserImage($node, $handle) {
    if (!is_array($node)) return null;
    if (isset($node['screen_name'], $node['profile_image_url_https'])
        && is_string($node['screen_name']) && strcasecmp($node['screen_name'], $handle) === 0) {
        return $node['profile_image_url_https'];
    }
    foreach ($node as $child) {
        $r = findUserImage($child, $handle);
        if ($r) return $r;
    }
    return null;
}

// Resmi API. Dönüş: url (bulundu) | null (böyle bir hesap yok) | false (hata/yetki/kredi sorunu)
function viaApi($handle, $token) {
    list($code, $body) = httpGet(
        'https://api.x.com/2/users/by/username/' . $handle . '?user.fields=profile_image_url',
        array('Authorization: Bearer ' . $token)
    );
    if ($code === 404) return null;
    if ($code !== 200) return false;
    $j = json_decode($body, true);
    if (!empty($j['data']['profile_image_url'])) return $j['data']['profile_image_url'];
    return isset($j['errors']) ? null : false;
}

// DENEYSEL: herkese açık gömme (syndication) sayfasından okur. Resmi değil, X kapatabilir.
function viaPage($handle) {
    list($code, $body) = httpGet(
        'https://syndication.twitter.com/srv/timeline-profile/screen-name/' . $handle,
        array('Accept: text/html', 'Accept-Language: en')
    );
    if ($code !== 200 || !$body) return false;
    if (!preg_match('~<script id="__NEXT_DATA__"[^>]*>(.*?)</script>~s', $body, $m)) return false;
    $j = json_decode($m[1], true);
    $u = findUserImage($j, $handle);
    return $u ? $u : false;
}

// Yalnızca twimg.com'daki fotoğrafı indirir (yüksek çözünürlük). Dönüş: ikili veri | false
function downloadTwimg($url) {
    $url = str_replace('_normal.', '_400x400.', $url);
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || !preg_match('/(^|\.)twimg\.com$/i', $host) || strpos($url, 'https://') !== 0) return false;
    list($c, $b) = httpGet($url);
    if ($c !== 200 || !$b || strlen($b) > 2 * 1024 * 1024) return false;
    return $b;
}

// Sunucu tarafı yedek: unavatar.io doğrudan resmi döndürür
function viaUnavatar($handle) {
    foreach (array('twitter', 'x') as $svc) {
        list($c, $b) = httpGet('https://unavatar.io/' . $svc . '/' . $handle . '?fallback=false');
        if ($c === 200 && $b && strlen($b) <= 2 * 1024 * 1024) return $b;
    }
    return false;
}

// 1) Önbellek (ücretsiz)
if (is_file($img)  && filemtime($img)  > time() - $TTL_OK) sendImage($img);
if (is_file($none) && filemtime($none) > time() - $TTL_NO) fail(404);
if (is_file($failF) && filemtime($failF) > time() - $TTL_FAIL) fail(404);

// 2) Harcama korumaları
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x';
if (!bump("$dir/ip_" . sha1($ip) . '.cnt', 60, 3600)) fail(429);              // IP başına saatte 60 (liderlik tablosu tek seferde 20 foto ister)
$cap = isset($config['x_daily_cap']) ? (int)$config['x_daily_cap'] : 300;      // günde en fazla 300 dış istek
if (!bump("$dir/global.cnt", $cap, 86400)) fail(503);

// 3) Fotoğrafı bul
$body = false;

if ($hasToken) {
    $u = viaApi($handle, $config['x_bearer']);
    if ($u === null) { @touch($none); fail(404); }          // hesap yok: kısa süre hatırla
    if ($u) $body = downloadTwimg($u);
}
if (!$body && $scrape) {
    $u = viaPage($handle);
    if ($u) $body = downloadTwimg($u);
}
if (!$body) $body = viaUnavatar($handle);
if (!$body) { @touch($failF); fail(502); }                  // istemci kendi yedeğine geçer; 6 saat yeniden denenmez

// 4) Doğrula ve önbelleğe yaz
$tmp = "$dir/" . $key . '.' . bin2hex(random_bytes(4)) . '.tmp';
file_put_contents($tmp, $body);
if (!@getimagesize($tmp)) { @unlink($tmp); @touch($failF); fail(502); }
@unlink($failF);
rename($tmp, $img);
sendImage($img);
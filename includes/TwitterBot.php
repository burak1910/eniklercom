<?php

class TwitterBot
{
    private $pdo;
    private $dbConfig;
    private $twitterConfig;
    private $uploadDir;
    private $dataDir;

    public function __construct(?array $customTwitterConfig = null)
    {
        $baseDir = dirname(__DIR__, 2);
        $this->uploadDir = dirname(__DIR__) . '/uploads/';
        $this->dataDir   = dirname(__DIR__) . '/data/';

        // Config'leri yükle
        $dbConfigFile = $baseDir . '/config/enikler_config.php';
        $this->dbConfig = file_exists($dbConfigFile) ? require $dbConfigFile : [];

        $twConfigFile = $baseDir . '/config/twitter_config.php';
        $defaultTw = file_exists($twConfigFile) ? require $twConfigFile : [];
        $this->twitterConfig = $customTwitterConfig ?? $defaultTw;

        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0755, true);
        }
    }

    /**
     * PDO veritabanı bağlantısı
     */
    public function getPdo(): PDO
    {
        if (!$this->pdo) {
            $this->pdo = new PDO(
                'mysql:host=' . ($this->dbConfig['db_host'] ?? 'localhost') . ';dbname=' . ($this->dbConfig['db_name'] ?? '') . ';charset=utf8mb4',
                $this->dbConfig['db_user'] ?? '',
                $this->dbConfig['db_pass'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        return $this->pdo;
    }

    /**
     * Enik Tinder'da gösterilen fotoğraflardan rastgele bir tane seçer.
     * Daha önce paylaşılanları hatırlar ve tekrar etmez.
     */
    public function getRandomEnik(): ?array
    {
        $pdo = $this->getPdo();
        $history = $this->getHistory();
        $seenIds = $history['seen_ids'] ?? [];

        $total = (int)$pdo->query("SELECT COUNT(*) FROM enikler")->fetchColumn();
        if ($total === 0) {
            return null;
        }

        // Eğer tüm enikler paylaşılmışsa geçmişi sıfırla
        if (count($seenIds) >= $total) {
            $seenIds = [];
        }

        $enik = null;
        $maxAttempts = 10;

        while ($maxAttempts-- > 0) {
            if (!empty($seenIds)) {
                $ph = implode(',', array_fill(0, count($seenIds), '?'));
                $stmt = $pdo->prepare("SELECT id, image_url, likes, dislikes FROM enikler WHERE id NOT IN ($ph) ORDER BY RAND() LIMIT 1");
                $stmt->execute($seenIds);
            } else {
                $stmt = $pdo->query("SELECT id, image_url, likes, dislikes FROM enikler ORDER BY RAND() LIMIT 1");
            }

            $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$candidate) {
                // Eğer bulunamadıysa geçmişi temizleyip tekrar dene
                $seenIds = [];
                continue;
            }

            // Dosyanın diskte gerçekten var olduğunu doğrula
            $filePath = $this->uploadDir . $candidate['image_url'];
            if (file_exists($filePath) && is_file($filePath)) {
                $enik = $candidate;
                break;
            } else {
                // Dosya yoksa seen listesine ekleyip diğerini dene
                $seenIds[] = (int)$candidate['id'];
            }
        }

        return $enik;
    }

    /**
     * Günün tweet metnini oluşturur: "her gün yeni bir enik-1", "her gün yeni bir enik-2", ...
     */
    public function generateTweetText(?array $enik = null): string
    {
        $history = $this->getHistory();
        $nextDay = ((int)($history['day_count'] ?? 0)) + 1;
        return "her gün yeni bir enik-{$nextDay}";
    }

    /**
     * Google Gemini API ile her gün tamamen yeni ve özgün enik tweeti yazar.
     */
    private function generateWithGemini(string $apiKey): ?string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

        $prompt = "Sen 'enikler.com' adlı sevimli köpek ve enik web sitesinin resmi Twitter (X) botusun.
Bugün Twitter'da paylaşacağımız rastgele bir sevimli enik fotoğrafı için günlük taze, neşeli, tatlı veya esprili bir Türkçe tweet metni yaz.

Kurallar:
1. Kesinlikle Türkçe yaz.
2. Maksimum 140-180 karakter arasında olsun.
3. İçerik temaları: Günün dozu, enik felsefesi, sabah enerjisi, mama heyecanı, terlik operasyonu, tatlı uyku halleri, pati sevgisi veya minik bir enik monoloğu.
4. Sıcak, güldüren ve sevimli emojiler kullan (🐾, 🐶, 🥺, 🦴, 💛, ✨).
5. Asla hashtag veya site linki ekleme (onları biz otomatik ekleyeceğiz).
6. Yanıt olarak YALNIZCA tweet metnini döndür, tırnak işareti, başlık veya ek açıklama yazma.";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 1.0,
                'maxOutputTokens' => 120,
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($response)) {
            $data = json_decode($response, true);
            $candidateText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $candidateText = trim(trim($candidateText, "\"' \n\r\t"));
            if (!empty($candidateText)) {
                return $candidateText;
            }
        }

        return null;
    }

    /**
     * Zengin yerleşik şablon motoru (Gemini olmadan da her gün farklı ve eğlenceli).
     */
    private function generateFromTemplates(?array $enik = null): string
    {
        $templates = [
            "Günün eniği huzurlarınızda! Şu masum bakışa hangi kalp dayanabilir ki? 🐾🥺",
            "Bugünün enerji ve mutluluk dozu yüklendi. İşte günün seçilen eniği! 🐶✨",
            "Bu enik bugün sadece sizin gününüzü güzelleştirmek için kuyruk salladı! 🐾💛",
            "Sabah alarmı yerine şu tipe bakarak uyanmak isteyenler kulübü burada mı? 😴🐶",
            "Günün felsefesi: Uyu, mama ye, terlik kaçır, tatlı ol ve tekrar et! 🦴🐾",
            "Dünyanın tüm dertlerini 3 saniyeliğine unutturacak bir çift göz bırakıyoruz buraya 🥺✨",
            "Biri bu eniğe acil pati öpücüğü versin, sevimlilik seviyesi tehlikeli sınıra ulaştı! 🚨🐾",
            "Günün eniği diyor ki: 'Bir parça peynir veya sevgi verirseniz tüm gün arkandan ayrılmam' 🐶🧀",
            "Köfte burun, kulak radar, sınırsız sevgi motoru devrede! 🐾❤️",
            "Bugün de terliği saklayıp hiçbir şey olmamış gibi bakan bir şüpheli tespit edildi 🐶🕵️‍♂️",
            "Paticiklerini sevdiğimin günün eniği! Bugün bu tipe sarılmadan geçen güne yazık olur 🐾🥺",
            "Küçük beden, kocaman yürek ve sınırsız mutluluk deposu. Günün eniği karşınızda! 🐶✨",
            "Günün tavsiyesi: Bir enik kadar neşeli ol, hayatı kuyruk sallayarak karşıla! 🐾🌈",
            "Bu bakışa taviz vermeyecek bir insan henüz dünyaya gelmedi... 🐶💛",
            "Günün enik raporu: Kulaklar dik, burun nemli, sevgi oranı %100! 🐾📈",
            "Senin için günün en tatlı eniğini seçtik! Biraz sevgi ve mıncırma garantili 🐶🥰",
            "Günün dozu: 1 adet minik enik, günde 3 öğün bakılması tavsiye edilir 🐾💊",
            "Biraz mama, sonsuz oyun ve sınırsız sevgi... Enik dünyası ne kadar da güzel! 🐶🎈",
            "Fotoğrafa 5 saniye bakınca tüm günün negatif enerjisi kayboluyormuş, test edildi onaylandı! 🐾✨",
            "Günün eniği bu tatlılıkla dünyaya hükmetmeye hazır gibi görünüyor 🐶👑",
            "Enik Tinder'da bugün herkesin kalbini çalan o meşhur bakış! 🐾🌹",
            "Günün sürprizi! Bu enik bugün sana şans ve bolca neşe getirmeye geldi 🐶🍀",
            "Pati sesleri tıkır tıkır, gözler pıtır pıtır! Günün eniği selam veriyor 🐾👋",
            "Günün enik monoloğu: 'Sen şimdi nereye gidiyorsun? Beni de cebine koysana?' 🥺🐶",
            "Hayat kısa, enikler çok tatlı! Günün eniğine kocaman bir kalp bırakmayı unutmayın 🐾❤️"
        ];

        // Beğeni oranı varsa esprili ekleme yapılabilir
        $likes = (int)($enik['likes'] ?? 0);
        $dislikes = (int)($enik['dislikes'] ?? 0);
        $totalVotes = $likes + $dislikes;

        $chosen = $templates[array_rand($templates)];

        if ($totalVotes >= 5 && ($likes / $totalVotes) > 0.8) {
            $pct = round(($likes / $totalVotes) * 100);
            $chosen .= "\n(Enik Tinder'da %{$pct} beğeniyle zirveye oynuyor! 🌹)";
        }

        return $chosen;
    }

    /**
     * OAuth 1.0a Authorization başlığını üretir (RFC 5849 standardı).
     */
    public static function buildOAuthHeader(
        string $method,
        string $url,
        array $extraParams,
        string $consumerKey,
        string $consumerSecret,
        string $token,
        string $tokenSecret
    ): string {
        $oauthParams = [
            'oauth_consumer_key'     => $consumerKey,
            'oauth_nonce'            => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string)time(),
            'oauth_token'            => $token,
            'oauth_version'          => '1.0',
        ];

        $urlParts = explode('?', $url, 2);
        $baseUrl = $urlParts[0];

        $allParams = array_merge($oauthParams, $extraParams);
        if (isset($urlParts[1])) {
            parse_str($urlParts[1], $queryParams);
            $allParams = array_merge($allParams, $queryParams);
        }

        uksort($allParams, 'strcmp');

        $paramPairs = [];
        foreach ($allParams as $k => $v) {
            $paramPairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }
        $paramString = implode('&', $paramPairs);

        $baseString = strtoupper($method) . '&' . rawurlencode($baseUrl) . '&' . rawurlencode($paramString);
        $signingKey = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);
        $signature = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));

        $oauthParams['oauth_signature'] = $signature;
        uksort($oauthParams, 'strcmp');

        $headerParts = [];
        foreach ($oauthParams as $k => $v) {
            $headerParts[] = rawurlencode((string)$k) . '="' . rawurlencode((string)$v) . '"';
        }

        return 'OAuth ' . implode(', ', $headerParts);
    }

    /**
     * Twitter API v1.1 ile fotoğrafı yükler ve media_id_string döndürür.
     */
    public function uploadMedia(string $imagePath): array
    {
        $ck  = trim($this->twitterConfig['twitter_api_key'] ?? '');
        $cs  = trim($this->twitterConfig['twitter_api_secret'] ?? '');
        $at  = trim($this->twitterConfig['twitter_access_token'] ?? '');
        $ats = trim($this->twitterConfig['twitter_access_secret'] ?? '');

        if (empty($ck) || empty($cs) || empty($at) || empty($ats)) {
            return ['ok' => false, 'error' => 'Twitter API anahtarları eksik. Lütfen yapılandırın.'];
        }

        if (!file_exists($imagePath)) {
            return ['ok' => false, 'error' => 'Resim dosyası bulunamadı: ' . $imagePath];
        }

        $url = 'https://upload.twitter.com/1.1/media/upload.json';
        $authHeader = self::buildOAuthHeader('POST', $url, [], $ck, $cs, $at, $ats);

        $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';
        $postFields = [
            'media' => new CURLFile($imagePath, $mimeType, basename($imagePath))
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $authHeader,
                'Content-Type: multipart/form-data'
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!empty($curlError)) {
            return ['ok' => false, 'error' => 'cURL Hatası: ' . $curlError];
        }

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['media_id_string'])) {
            return ['ok' => true, 'media_id' => $data['media_id_string']];
        }

        $err = $data['errors'][0]['message'] ?? ($data['error'] ?? 'HTTP ' . $httpCode . ': ' . $response);
        return ['ok' => false, 'error' => 'Fotoğraf yüklenemedi: ' . $err];
    }

    /**
     * Twitter API v2 ile tweet paylaşır.
     */
    public function postTweet(string $text, ?string $mediaId = null): array
    {
        $ck  = trim($this->twitterConfig['twitter_api_key'] ?? '');
        $cs  = trim($this->twitterConfig['twitter_api_secret'] ?? '');
        $at  = trim($this->twitterConfig['twitter_access_token'] ?? '');
        $ats = trim($this->twitterConfig['twitter_access_secret'] ?? '');

        if (empty($ck) || empty($cs) || empty($at) || empty($ats)) {
            return ['ok' => false, 'error' => 'Twitter API anahtarları eksik.'];
        }

        $url = 'https://api.twitter.com/2/tweets';
        $authHeader = self::buildOAuthHeader('POST', $url, [], $ck, $cs, $at, $ats);

        $body = ['text' => $text];
        if (!empty($mediaId)) {
            $body['media'] = ['media_ids' => [(string)$mediaId]];
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $authHeader,
                'Content-Type: application/json; charset=utf-8'
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!empty($curlError)) {
            return ['ok' => false, 'error' => 'cURL Hatası: ' . $curlError];
        }

        $data = json_decode($response, true);

        if (($httpCode === 200 || $httpCode === 201) && !empty($data['data']['id'])) {
            $tweetId = $data['data']['id'];
            return [
                'ok'        => true,
                'tweet_id'  => $tweetId,
                'tweet_url' => 'https://x.com/i/status/' . $tweetId,
                'data'      => $data['data']
            ];
        }

        $err = $data['detail'] ?? ($data['errors'][0]['message'] ?? ($data['title'] ?? 'HTTP ' . $httpCode . ': ' . $response));
        return ['ok' => false, 'error' => 'Tweet paylaşılamadı: ' . $err];
    }

    /**
     * Tam akış: Rastgele enik seç -> Metin üret -> Twitter'a fotoğrafı yükle -> Tweet paylaş -> Kaydet
     */
    public function runDailyTweet(?int $specificEnikId = null): array
    {
        if (empty($this->twitterConfig['enabled'])) {
            $this->log("Bot devre dışı bırakılmış (enabled = false).");
            return ['ok' => false, 'error' => 'Twitter botu devre dışı.'];
        }

        // 1. Enik seçimi
        $enik = null;
        if ($specificEnikId) {
            $stmt = $this->getPdo()->prepare("SELECT id, image_url, likes, dislikes FROM enikler WHERE id = ?");
            $stmt->execute([$specificEnikId]);
            $enik = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$enik) {
            $enik = $this->getRandomEnik();
        }

        if (!$enik) {
            $msg = "Paylaşılacak uygun enik fotoğrafı bulunamadı.";
            $this->log($msg);
            return ['ok' => false, 'error' => $msg];
        }

        $imagePath = $this->uploadDir . $enik['image_url'];

        // 2. Metin oluşturma
        $tweetText = $this->generateTweetText($enik);

        // 3. Fotoğraf yükleme
        $this->log("Fotoğraf Twitter'a yükleniyor (Enik #{$enik['id']} - {$enik['image_url']})...");
        $uploadResult = $this->uploadMedia($imagePath);
        if (!$uploadResult['ok']) {
            $this->log("Fotoğraf yükleme hatası: " . $uploadResult['error']);
            return ['ok' => false, 'error' => $uploadResult['error']];
        }

        $mediaId = $uploadResult['media_id'];
        $this->log("Fotoğraf başarıyla yüklendi. Media ID: {$mediaId}");

        // 4. Tweet oluşturma
        $this->log("Tweet paylaşılıyor...");
        $tweetResult = $this->postTweet($tweetText, $mediaId);
        if (!$tweetResult['ok']) {
            $this->log("Tweet gönderme hatası: " . $tweetResult['error']);
            return ['ok' => false, 'error' => $tweetResult['error']];
        }

        // 5. Başarı kaydı ve geçmişi güncelleme
        $tweetId = $tweetResult['tweet_id'];
        $tweetUrl = $tweetResult['tweet_url'];

        $this->recordSuccess($enik['id'], $enik['image_url'], $tweetText, $tweetId, $tweetUrl);
        $this->log("TWEET PAYLAŞILDI: {$tweetUrl} (Enik #{$enik['id']})");

        return [
            'ok'        => true,
            'enik_id'   => $enik['id'],
            'image_url' => $enik['image_url'],
            'text'      => $tweetText,
            'tweet_id'  => $tweetId,
            'tweet_url' => $tweetUrl,
            'message'   => 'Tweet başarıyla paylaşıldı!'
        ];
    }

    /**
     * Geçmiş ve istatistik dosyasını okur
     */
    public function getHistory(): array
    {
        $file = $this->dataDir . 'twitter_history.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) return $data;
        }
        return ['seen_ids' => [], 'tweets' => []];
    }

    /**
     * Başarılı tweet'i geçmişe kaydeder
     */
    private function recordSuccess(int $enikId, string $imageUrl, string $text, string $tweetId, string $tweetUrl): void
    {
        $history = $this->getHistory();
        $history['day_count'] = ((int)($history['day_count'] ?? 0)) + 1;
        if (!in_array($enikId, $history['seen_ids'] ?? [], true)) {
            $history['seen_ids'][] = $enikId;
        }

        $item = [
            'day'       => $history['day_count'],
            'enik_id'   => $enikId,
            'image_url' => $imageUrl,
            'text'      => $text,
            'tweet_id'  => $tweetId,
            'tweet_url' => $tweetUrl,
            'date'      => date('Y-m-d H:i:s'),
        ];

        array_unshift($history['tweets'], $item);
        $history['tweets'] = array_slice($history['tweets'], 0, 100); // Son 100 tweet

        file_put_contents($this->dataDir . 'twitter_history.json', json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Log dosyasına yazar
     */
    public function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        @file_put_contents($this->dataDir . 'twitter_bot.log', $line, FILE_APPEND);
    }
}

# enikler.com

**enikler.com**, sevimli köpek yavrularını (enikleri) merkeze alan; etkileşimli testler, tarayıcı oyunları, oylama galerisi, kelime tabanlı paylaşım sayfaları ve X (Twitter) bot entegrasyonları barındıran bir web uygulamasıdır.

Canlı Site: [enikler.com](https://enikler.com)

---

## Teknoloji Yığını ve Mimari

Proje herhangi bir harici frontend framework'ü veya derleme (build) aracı olmadan, doğrudan tarayıcıda ve paylaşımlı hosting ortamında yüksek hızla çalışacak şekilde tasarlanmıştır:

- **Backend:** PHP (PHP 7.2+ uyumlu), MySQL (`PDO`, `utf8mb4`), GD kütüphanesi (görsel işleme, boyutlandırma, filigran ve dHash), cURL ve Apache `mod_rewrite` (`.htaccess`).
- **Frontend:** Vanilla HTML5, CSS3 ve JavaScript. HTML5 Canvas (oyun motoru ve sonuç kartı üretimi), tarayıcı içi GIF kodlama (`gifenc.js`), Web Share API ve `localStorage`.
- **Veri Depolama:**
  - **MySQL:** `enikler` (fotoğraflar, beğeni/beğenmeme sayıları ve algısal hash değerleri), `admins` (rol tabanlı yönetim), `fikirler` (kullanıcı önerileri) ve `oyun_skorlari` tabloları.
  - **JSON Dosyaları (`data/`):** Bot komut eşleşmeleri (`bot_komutlar.json`), Enik Düşüşü liderlik tablosu (`enik_dusus_lider.json`) ve günlük Twitter paylaşım geçmişi (`twitter_history.json`). `data/.htaccess` içindeki `Require all denied` kuralıyla doğrudan web erişimine kapalıdır.
- **Gizli Yapılandırma:** Veritabanı bilgileri, X/Twitter API anahtarları, Gemini API anahtarı ve kart oyunu WebSocket gizli anahtarı web kökünün dışındaki `../config/` dizininde (`enikler_config.php`, `twitter_config.php`, `kart_oyunu_config.php`) tutulur ve repoda yer almaz.

---

## Proje Yapısı

```text
eniklercom/
├── index.html                  # Ana sayfa, dinamik özellik kartları, kayan şerit ve fikir kutusu
├── enik-tinderi.html           # Kart kaydırma (swipe) ve sıralanabilir galeri arayüzü
├── enik-radari.html            # X profil fotoğrafı destekli kişilik testi ve Canvas kart üretici
├── api.php                     # Enik listeleme, rastgele getirme, oylama ve dosya indirme API'si
├── avatar.php                  # Çok katmanlı X profil fotoğrafı proxy ve önbellek servisi
├── fikir.php                   # Kullanıcı fikir formu uç noktası (DB kaydı + e-posta bildirimi)
├── admin.php                   # Çoklu fotoğraf yükleme, dHash kopya tespiti ve yönetim paneli
├── bot_api.php                 # Harici Python botu için komut ve fotoğraf havuzu JSON API'si
├── cron_twitter.php            # Günlük otomatik X paylaşım scripti (CLI ve webhook destekli)
├── includes/
│   └── TwitterBot.php          # Tekrarsız enik seçimi, metin üretimi (sayaç / Gemini) ve X paylaşımı
├── e/
│   ├── .htaccess               # /e/<kelime> ve /e/<kelime>/<foto> URL yönlendirmeleri
│   └── index.php               # Kelimeye göre enik seçici ve dinamik Open Graph paylaşım sayfası
├── oyun/
│   ├── index.html              # Oyunlar vitrin sayfası
│   └── enik-dusus/
│       ├── index.html          # Enik Düşüşü (Canvas oyun motoru, ses, skor ve GIF kaydı)
│       ├── gifenc.js           # Tarayıcı içinde GIF oluşturma kütüphanesi
│       ├── lider.php           # Dosya kilitli (flock) liderlik tablosu API'si
│       ├── paylas.php          # GIF yükleme ve OG önizlemeli paylaşım sayfası
│       └── paylasim/           # Paylaşılan GIF ve meta JSON dosyaları
├── kart-oyunu/
│   ├── index.php               # Admin kapısı ve WebSocket token üretici giriş noktası
│   ├── motor.js                # DOM'dan bağımsız sıra tabanlı kart oyunu kural motoru
│   ├── arayuz.js               # Oyun arayüzü ve etkileşim katmanı
│   └── stil.css                # Kart oyunu stilleri
├── data/                       # Web'den gizli JSON veri dosyaları ve .htaccess koruması
└── uploads/                    # İşlenmiş ve filigranlı enik görselleri
```

---

## Özellikler ve Nasıl Geliştirildi

### 1. Ana Sayfa (`index.html` ve `fikir.php`)
- **Dinamik Özellik Kartları:** Sayfadaki özellik kartları JavaScript içindeki `FEATURES` dizisinden otomatik olarak üretilir. Her öğenin `added` tarihine bakılarak 14 gün boyunca "YENİ" rozeti gösterilir ve en güncel özellik otomatik olarak üstte geniş öne çıkan kart şeklinde konumlandırılır.
- **Kayan Polaroid Şeridi ve Lightbox:** Sayfa yüklendiğinde `/api.php?action=get_all&sort=new` üzerinden mevcut görseller çekilir, toplam fotoğraf sayısı rozette gösterilir ve rastgele seçilen 14 enik sonsuz CSS animasyonuyla kaydırılır. Herhangi bir fotoğrafa tıklandığında sayfa içi büyütme penceresi (lightbox) açılır.
- **Fikir Kutusu (`fikir.php`):** Kullanıcılar kategori seçerek (Kişilik testi, Özellik, Yardım, Diğer) en fazla 250 karakterlik öneri ve isteğe bağlı X kullanıcı adı gönderebilir. Spam koruması için gizli honeypot alanı (`website`), SHA-256 IP hash tabanlı 30 saniyelik hız sınırı ve son 10 dakikada 20'den fazla kayıt geldiğinde e-posta bildirimini susturan koruma mekanizması uygulanmıştır.

### 2. Enik Tinderi ve Galeri (`enik-tinderi.html` ve `api.php`)
- **Kart Kaydırma (Swipe) ve Oylama:** Kartlar dokunmatik veya fare sürükleme hareketleriyle sağa (beğen) veya sola (geç) kaydırılır. Oylar `api.php?action=vote` adresine `POST` isteğiyle gönderilerek MySQL'deki `likes` ve `dislikes` sayaçlarını günceller.
- **Tekrar ve Mükerrer Oy Engelleme:** PHP oturumu (`$_SESSION['seen']` ve `$_SESSION['voted']`) kullanılarak aynı oturumda aynı fotoğrafın tekrar çıkması ve aynı fotoğrafa birden fazla oy verilmesi engellenir.
- **Galeri, Sıralama ve Yedek (Fallback) Modu:** `api.php?action=get_all` uç noktası; popülerlik oranı (`likes / (likes + dislikes)`), en yeni, en eski, rastgele ve en çok beğenilen sıralama seçeneklerini destekler. Veritabanı bağlantısı kurulamadığı durumlarda sistem otomatik olarak `uploads/` klasörünü tarayarak kesintisiz çalışmaya devam eder.
- **Doğrudan İndirme:** `api.php?action=download` uç noktası dosya adını ve uzantısını (`jpg`, `jpeg`, `png`, `webp`, `gif`) doğruladıktan sonra `Content-Disposition: attachment` başlığıyla doğrudan indirme başlatır.

### 3. Enik Radarı (`enik-radari.html` ve `avatar.php`)
- **Kişilik Testi ve Puanlama:** 6 farklı enik karakteri (Uykucu, Mahcup, Bahtı Sikik, Beyni Bomboş, Üzgün, Aşko Kuşko Diva) ve 14 adet 5'li ölçekli sorudan oluşur. Tipler arası soru sayısı dengesizliğini gidermek için her tipin puanı kendi maksimum puanına bölünerek yüzdelik oran hesaplanır; böylece hem ana enik tipi hem de tüm tiplerin karışım yüzdesi gösterilir.
- **X Profil Fotoğrafı Servisi (`avatar.php`):** Kullanıcının girdiği X kullanıcı adının profil fotoğrafını tarayıcıya güvenli biçimde ulaştırmak için 4 aşamalı bir hat izler:
  1. Sunucu disk önbelleği (`sys_get_temp_dir()/enik_avatars`): Bulunan fotoğraflar 7 gün, bulunamayan hesaplar 1 saat, çekilemeyenler 6 saat önbellekte tutulur.
  2. Resmi X API v2 (`x_bearer` tanımlıysa; token tarayıcıya hiç gönderilmez).
  3. Twitter syndication sayfası (`__NEXT_DATA__` JSON ayrıştırması).
  4. `unavatar.io` yedek servisi.
  Ayrıca IP başına saatlik (60) ve günlük genel dış istek limiti (varsayılan 300) ile kota koruması sağlanır.
- **Canvas Sonuç Kartı:** 1080x1350 piksel boyutundaki paylaşım kartı tamamen istemci tarafında HTML5 Canvas ile çizilir. Kullanıcının profil fotoğrafı, eşleşen enik görseli, açıklaması ve karakter etiketleri tek bir PNG görselinde birleştirilir; Web Share API ile doğrudan paylaşılabilir veya cihaza indirilebilir.

### 4. Kelimeli Enik Seçici (`e/index.php` ve `e/.htaccess`)
- **Temiz URL Yapısı:** `e/.htaccess` kurallarıyla `/e/uzgun` adresinde o kelimeye ait havuzdan rastgele bir enik, `/e/uzgun/<foto-adi>` adresinde ise paylaşılan sabit enik açılır.
- **Tekrarsız Yenileme:** "Başka enik" butonu `?son=<foto-adi>` parametresini geçirerek bir önceki fotoğrafın art arda gelmesini engeller.
- **Dinamik Sosyal Medya Önizlemesi:** Kelime ve fotoğraf havuzu `data/bot_komutlar.json` dosyasından okunur. Her sabit link için `og:title`, `og:description`, `og:image` ve `twitter:card=summary_large_image` etiketleri sunucu tarafında dinamik üretilir; böylece X'te paylaşıldığında fotoğraf büyük kart olarak görünür.

### 5. Oyunlar ve Enik Düşüşü (`oyun/index.html` ve `oyun/enik-dusus/`)
- **Canvas Oyun Motoru (`oyun/enik-dusus/index.html`):** Tek dokunuşlu zamanlama mekaniğine dayalı tarayıcı oyunudur. Oyuncu eniği dikenlere değmeden hedef deliğe düşürmeye çalışır; ilerleyen seviyelerde rüzgar, hareketli delik ve sarhoş enik gibi ek engeller devreye girer. 11 kademeli rütbe sistemi (Rezalet'ten Tanrısal'a) bulunur ve cihaz içi en iyi skor `localStorage` (`enikDususBest3`) üzerinde saklanır.
- **Tarayıcı İçi GIF Kaydı ve Paylaşım (`gifenc.js`, `paylas.php`):** Oyun anı harici sunucu yükü olmadan doğrudan tarayıcıda `gifenc.js` ile GIF olarak kodlanır. Kullanıcı GIF'i cihazına indirebilir veya `paylas.php` üzerinden sunucuya yükleyerek (`max 1.5 MB`, `GIF` tip kontrolü, 3 sn IP hız sınırı, 5000 dosya kotası) Open Graph GIF önizlemeli kalıcı paylaşım bağlantısı oluşturabilir.
- **Liderlik Tablosu (`lider.php`):** Oyuncular X kullanıcı adıyla skorlarını kaydedebilir. Skorlar `data/enik_dusus_lider.json` dosyasında `flock` dosya kilidiyle eşzamanlılık güvenliği sağlanarak tutulur; her kullanıcı için yalnızca en yüksek skor saklanır, seviye üst sınırı (300) ve 4 saniyelik IP hız sınırı ile temel hile/spam koruması yapılır. Tablodaki profil fotoğrafları `/avatar.php` üzerinden gösterilir.

### 6. Enikler Kart Oyunu (`kart-oyunu/`)
- **Erişim Kontrolü (`index.php`):** Geliştirme aşamasında olan modül yalnızca `admin` rolüyle oturum açmış kullanıcılara sunulur; yetkisiz istekler doğrudan `404` yanıtı alır.
- **Bağımsız Kural Motoru (`motor.js`):** DOM'a bağımlılığı olmayan saf JavaScript motoru olarak yazılmıştır; bu sayede hem tarayıcıda hem de ileride Node.js tabanlı çok oyunculu sunucuda çalışabilir. 14 benzersiz enik (her birinin kendine özgü pasif yeteneği ve Süper Hamle'si), Saldırı/Savunma/Özellik kategorilerinde kart havuzu, Mama ekonomisi, kapalı tuzaklar, kalkan ve 7 farklı durum efekti (Pire, Uyku, Utanç, Kafa Karışıklığı, Uğursuzluk, Büyülenmiş, Esneme) içerir.
- **Canlı Maç ve Bot Modu (`arayuz.js`):** Sunucuda `kart_oyunu_config.php` mevcutsa `index.php` tarafından HMAC-SHA256 ile imzalanmış 12 saatlik token üretilir ve WebSocket sunucusuna bağlanılır; yapılandırma yoksa oyun yerel bot modunda çalışır.

### 7. Yönetim Paneli (`admin.php`)
- **Güvenlik ve Rol Yönetimi:** `admins` tablosu üzerinden `password_verify` ile giriş yapılır. `HttpOnly` + `SameSite=Strict` çerez ayarları, CSRF token doğrulaması, rol bazlı yetkilendirme (`admin` ve `editor`) ve 5 hatalı denemede 15 dakikalık hesap kilitleme mekanizması bulunur.
- **Otomatik Görsel İşleme ve dHash Kopya Tespiti:** Çoklu fotoğraf yükleme sırasında her görsel PHP GD ile işlenir:
  - JPEG EXIF yön bilgisi okunarak dikey/yatay dönme sorunları düzeltilir.
  - En uzun kenar 1600 piksel olacak şekilde orantılı küçültülür ve JPG (kalite 85) olarak kaydedilir.
  - Görselin ortasına yarı saydam `enikler.com` filigranı eklenir.
  - İki aşamalı küçültme (32x32 -> 9x8) ile 64-bit fark hash'i (`dHash`) hesaplanır. Veritabanındaki mevcut kayıtlarla Hamming mesafesi karşılaştırılır; 8 bit ve altı fark (yaklaşık %87 ve üzeri benzerlik) tespit edilirse fotoğraf kopya olarak işaretlenip atlanır (istenirse zorla yüklenebilir).
  - Eski fotoğrafların hash'lerini topluca hesaplamak için `backfill` aracı ve benzer fotoğrafları incelemek için `dups` ekranı yer alır.
- **İçerik ve Sistem Sekmeleri:** Kullanıcı fikirlerini yönetme (`ideas`), oyun skorlarını inceleme (`scores`), kelime-fotoğraf eşleşmelerini düzenleme (`bot`) ve Twitter botu ayarlarını güncelleme / anlık paylaşım tetikleme (`twitter`).

### 8. X (Twitter) Bot Entegrasyonları (`cron_twitter.php`, `includes/TwitterBot.php`, `bot_api.php`)
- **Günlük Otomatik Paylaşım (`cron_twitter.php`, `TwitterBot.php`):** Terminalden cron job olarak veya `cron_token` parametresiyle HTTP webhook üzerinden tetiklenebilir. `data/twitter_history.json` dosyasında paylaşılan enik ID'lerini tutarak tüm havuz tükenene kadar aynı fotoğrafı tekrar paylaşmaz. Paylaşım metnini günlük sayaç formatında (`her gün yeni bir enik-N`) veya isteğe bağlı Google Gemini API (`gemini-2.0-flash`) üzerinden üretir.
- **Harici Bot API (`bot_api.php`):** Yönetim panelindeki Bot Cevapları bölümünde tanımlanan kelime listelerini, yanıt metinlerini ve eşleştirilmiş fotoğraf havuzlarını JSON formatında dışa açar.

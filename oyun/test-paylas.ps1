# paylas.php'yi canlıda dener. paylas.php'yi siteye yükledikten sonra çalıştır:
#   powershell -File .\test-paylas.ps1
#   powershell -File .\test-paylas.ps1 -Base https://enikler.com/oyun/enik-dusus/
# Dikkat: sunucuya 1 adet 1x1 piksellik deneme GIF'i yükler (paylasim klasöründe kalır, istersen elle silersin).
param([string]$Base = 'https://enikler.com/oyun/enik-dusus/')

Add-Type -AssemblyName System.Net.Http
$ErrorActionPreference = 'Stop'
$fail = 0
function Check($name, $ok, $detail = '') {
  if ($ok) { Write-Host "[OK]    $name" -ForegroundColor Green }
  else { Write-Host "[HATA]  $name $detail" -ForegroundColor Red; $script:fail++ }
}

# 40x40 geçerli GIF (sunucu 100 bayttan küçük dosyaları reddeder, 1x1 GIF çok küçük kalır)
Add-Type -AssemblyName System.Drawing
$bmp = New-Object System.Drawing.Bitmap 40, 40
$g = [System.Drawing.Graphics]::FromImage($bmp); $g.Clear([System.Drawing.Color]::Orange); $g.Dispose()
$ms = New-Object System.IO.MemoryStream
$bmp.Save($ms, [System.Drawing.Imaging.ImageFormat]::Gif); $bmp.Dispose()
$gif = $ms.ToArray()

function Send-Upload($bytes, $fileName, $lv, $rk) {
  $c = New-Object System.Net.Http.HttpClient
  $form = New-Object System.Net.Http.MultipartFormDataContent
  $part = New-Object System.Net.Http.ByteArrayContent(, $bytes)
  $form.Add($part, 'gif', $fileName)
  $form.Add((New-Object System.Net.Http.StringContent([string]$lv)), 'lv')
  $form.Add((New-Object System.Net.Http.StringContent($rk, [Text.Encoding]::UTF8)), 'rk')
  $r = $c.PostAsync($Base + 'paylas.php', $form).GetAwaiter().GetResult()
  [pscustomobject]@{ Status = [int]$r.StatusCode; Body = $r.Content.ReadAsStringAsync().GetAwaiter().GetResult() }
}

# 1) geçerli GIF yüklenir ve link döner
$up = Send-Upload $gif 'enik.gif' 7 'Harika'
Check 'GIF yükleme 200 döner' ($up.Status -eq 200) "(durum $($up.Status): $($up.Body))"
$url = $null
try { $url = ($up.Body | ConvertFrom-Json).url } catch {}
Check 'Yanıtta paylaşım linki var' ($url -match 'paylas\.php\?id=[a-f0-9]{12}$') "($($up.Body))"

if ($url) {
  # 2) paylaşım sayfası og:image ve başlığı içerir
  $page = Invoke-WebRequest $url -UseBasicParsing
  $html = [Text.Encoding]::UTF8.GetString($page.RawContentStream.ToArray())
  Check 'Sayfa 200 döner' ($page.StatusCode -eq 200)
  Check 'og:image GIF dosyasını gösterir' ($html -match 'og:image" content="[^"]+\.gif"')
  Check 'Başlıkta seviye ve rütbe yazar' ($html -match 'Seviye 7: Harika')
  Check 'twitter:card summary_large_image' ($html -match 'summary_large_image')

  # 3) GIF dosyası gerçekten gif olarak servis ediliyor
  if ($html -match 'og:image" content="([^"]+\.gif)"') {
    $img = Invoke-WebRequest $Matches[1] -UseBasicParsing
    Check 'GIF adresi image/gif döner' ($img.Headers['Content-Type'] -like 'image/gif*') "($($img.Headers['Content-Type']))"
  }
}

# 2) GIF olmayan dosya reddedilir
$bad = Send-Upload ([Text.Encoding]::UTF8.GetBytes('merhaba bu bir gif degil, sadece metin ' * 10)) 'x.gif' 1 'Rezalet'
Check 'GIF olmayan dosya reddedilir (400)' ($bad.Status -eq 400) "(durum $($bad.Status))"

# 3) çok büyük dosya reddedilir (1,5 MB sınırı)
$big = New-Object byte[] 1600000; [Array]::Copy($gif, $big, $gif.Length)
Start-Sleep -Seconds 4   # sunucudaki 3 sn'lik hız sınırı geçsin
$huge = Send-Upload $big 'big.gif' 1 'Rezalet'
Check 'Büyük dosya reddedilir (400)' ($huge.Status -eq 400) "(durum $($huge.Status))"

# 4) yanlış id ana sayfaya yönlendirir, hata sayfası vermez
$req = [Net.HttpWebRequest]::Create($Base + 'paylas.php?id=000000000000')
$req.AllowAutoRedirect = $false
$resp = $req.GetResponse()
Check 'Olmayan id yönlendirilir' ([int]$resp.StatusCode -in 301, 302) "(durum $([int]$resp.StatusCode))"
$resp.Close()

if ($fail) { Write-Host "`n$fail kontrol başarısız." -ForegroundColor Red; exit 1 }
Write-Host "`nHepsi geçti." -ForegroundColor Green

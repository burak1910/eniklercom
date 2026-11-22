/* =====================================================================
   ENİKLER KART OYUNU: KURAL MOTORU
   DOM'a dokunmaz; tarayıcıda da Node'da da çalışır (ileride sunucuya taşınır).
   Tasarım dokümanındaki "Enik'ler kart olarak" kuralları esas alınmıştır.
   Yorum kararları:
   - "moral" = oyuncu morali, "can" = masadaki Enik'in canı.
   - Tur sayacı iki oyuncunun turlarını ayrı sayar (12. tur = yaklaşık 9 dk).
   - Tuzakları Saldırı kartları tetikler; bedelsiz Pati vuruşu tetiklemez.
   ===================================================================== */
(function (kok) {
'use strict';

const BASLANGIC_MORAL = 16, EL_LIMIT = 7, MASA_LIMIT = 3, KALKAN_LIMIT = 10, UZUN_TUR = 12, SUPER_MAMA = 2;

/* ---------- 14 Enik ---------- */
const ENIK_LISTE = [
  { id:'mahcup',  ad:'Mahcup Enik',   em:'😳', rol:'sav', moral:22, tarz:'Tank',
    pasif:['Göz Kaçırma','Her tur aldığı ilk hasar 1 azalır. Utanç\'tan etkilenmez.'],
    sup:['Yerin Dibine Gir','Rakibin bir sonraki turu boyunca hedef alınamaz; 2 kart çeker.'] },
  { id:'bahti',   ad:'Bahtı Sikik Enik', em:'🫠', rol:'sal', moral:20, tarz:'Kumar',
    pasif:['Dibi Gördüm','Morali 8 ve altındayken saldırıları +2 hasar. Zarı 1–2 gelirse 1 kart çeker.'],
    sup:['Uğursuzluk Bulaştır','Üzerindeki tüm kötü durumları rakibe aktarır; aktarılan her durum için 2 hasar.'] },
  { id:'uykucu',  ad:'Uykucu Enik',   em:'😴', rol:'sav', moral:24, tarz:'Dayanıklı',
    pasif:['Şekerleme','Bir turda en fazla 1 kart oynarsa tur sonunda 3 moral yeniler. Uyku\'dan etkilenmez.'],
    sup:['Esneme Salgını','Rakip bir sonraki turunda en fazla 1 kart oynayabilir; 3 moral yeniler.'] },
  { id:'uzgun',   ad:'Üzgün Enik',    em:'😢', rol:'sal', moral:18, tarz:'Birikim',
    pasif:['Hüzün Birikimi','Hasar aldığı her tur 1 Gözyaşı kazanır (en fazla 6).'],
    sup:['Ağlama Krizi','Tüm Gözyaşlarını harcar, her biri 2 hasar; savunmaları yok sayar.'] },
  { id:'beyni',   ad:'Beyni Bomboş Enik', em:'🤪', rol:'des', moral:20, tarz:'Kaos',
    pasif:['Ne Yapıyordum Ben?','Tur başında elindeki rastgele 1 kartın maliyeti 0 olur ama hedefi rastgele seçilir. Kafa Karışıklığı\'ndan etkilenmez.'],
    sup:['Beyin Fırtınası (Boş)','Elini tamamen atar, attığı kart sayısı +1 kadar çeker; bu tur kartlar 1 Mama ucuz.'] },
  { id:'diva',    ad:'Diva Enik',     em:'💅', rol:'sal', moral:18, tarz:'Yansıtma',
    pasif:['Dokunma Bana','3+ hasarlı her saldırıda 1 hasar saldırana döner. Her tur ilk Özellik kartı 1 Mama ucuz.'],
    sup:['Show Time','Bu tur oynadığı bir sonraki Saldırı kartı 2 kez tetiklenir ve savunmaları yok sayar.'] },
  { id:'ac',      ad:'Aç Enik',       em:'🍖', rol:'des', moral:20, tarz:'Ekonomi',
    pasif:['Mama Canavarı','Her tur 4 Mama alır ama el sınırı 5.'],
    sup:['Mamayı Kap','Rakibin sonraki turundaki Mama\'dan 2\'sini çalar, kendi sonraki turuna ekler.'] },
  { id:'sinirli', ad:'Sinirli Enik',  em:'😡', rol:'sal', moral:18, tarz:'Agresif',
    pasif:['Hır Çıkarma','Her tur oynadığı ilk Saldırı kartı +1 hasar.'],
    sup:['Kudurma','2 Saldırı kartı çeker; bu tur Saldırı kartları 1 Mama ucuz.'] },
  { id:'tweetci', ad:'Tweetçi Enik',  em:'📱', rol:'sal', moral:20, tarz:'Tempo',
    pasif:['Ratio','Bir turda rakibin önceki turundan daha fazla kart oynarsa tur sonunda 1 kart çeker.'],
    sup:['Viral Oldum','Elindeki kart sayısı kadar hasar (en fazla 7).'] },
  { id:'kiskanc', ad:'Kıskanç Enik',  em:'😒', rol:'des', moral:20, tarz:'Kopya',
    pasif:['Onda Var Bende Yok','Rakip bir turda 3+ kart çekerse 1 kart çeker; rakip moral yenilerse 1 moral yeniler.'],
    sup:['Taklit','Rakibin son oynadığı kartın kopyasını bedelsiz oynar.'] },
  { id:'ukala',   ad:'Ukala Enik',    em:'🧐', rol:'des', moral:19, tarz:'Bilgi',
    pasif:['Ben Demiştim','Rakibin kapalı kartlarını ve kendi destesinin üst kartını görür.'],
    sup:['Aslında...','Rakibin elini görür, 1 kartını seçip attırır, 1 kart çeker.'] },
  { id:'asik',    ad:'Aşık Enik',     em:'😍', rol:'sav', moral:22, tarz:'İyileştirme',
    pasif:['Kalp Gözler','Tüm iyileştirmeleri +1.'],
    sup:['Aşk Acısı','Rakibe Büyülenmiş verir (sonraki turunda Saldırı kartı oynayamaz); 4 moral yeniler.'] },
  { id:'hiper',   ad:'Hiperaktif Enik', em:'⚡', rol:'sal', moral:18, tarz:'Kart akışı',
    pasif:['Duramıyorum','Tur başında 2 yerine 3 kart çeker.'],
    sup:['Zoomies','Bu tur +3 Mama.'] },
  { id:'kurnaz',  ad:'Kurnaz Enik',   em:'😏', rol:'sav', moral:20, tarz:'Tuzak',
    pasif:['Hileci','Tuzak kartları 1 Mama ucuz; 3 aktif savunma tutabilir.'],
    sup:['Çifte Oyun','Destesindeki ilk 2 Tuzağı bulup bedelsiz kurar.'] },
];

/* ---------- Kart havuzu: [id, ad, tip, mama, nadirlik, metin, ek] ---------- */
const KART_LISTE = [
  // Saldırı
  ['havlama','Havlama','sal',0,'s','1 hasar.'],
  ['isirik','Isırık','sal',1,'s','3 hasar.'],
  ['tokat','Pati Tokadı','sal',1,'s','2 hasar, 1 kart çek.'],
  ['kamci','Kuyruk Kamçısı','sal',1,'s','2 hasar; bu tur başka Saldırı oynadıysan +2.'],
  ['pirebul','Pire Bulaştır','sal',1,'s','Rakibe Pire (3 tur, tur başı 1 hasar).',{hedefsiz:1}],
  ['terlik','Terlik Fırlatma','sal',2,'s','5 hasar.'],
  ['postaci','Postacıya Saldırı','sal',1,'n','3 hasar; tuzakları tetiklemez, kalkanı ve Koruyucu\'yu yok sayar.',{bypass:1}],
  ['yazitura','Yazı Tura Isırığı','sal',1,'n','Zar 4+: 6 hasar. Zar 1–3: sen 1 hasar alırsın.',{sans:1}],
  ['camur','Çamura Bulama','sal',2,'n','3 hasar + Utanç.'],
  ['gece3','Gece 3\'te Havlama','sal',2,'n','2 hasar + Uyku 1.'],
  ['ayakkabi','Ayakkabı Çiğneme','sal',2,'n','Rakibin 1 aktif savunmasını yok et (kapalıysa açmadan) + 2 hasar.',{hedefsiz:1}],
  ['kudurmus','Kudurmuş Saldırı','sal',3,'n','8 hasar; sen 2 hasar alırsın.'],
  ['mahalle','Mahalle Köpeklerini Çağır','sal',3,'e','3 kez 2 hasar (her vuruş ayrı hesaplanır).'],
  ['linc','Linç Kampanyası','sal',2,'e','Rakibin elindeki her kart için 1 hasar (en fazla 6).'],
  // Savunma
  ['yastik','Yastığa Saklan','sav',1,'s','4 Kalkan.',{kal:4}],
  ['kulube','Kulübe','sav',2,'s','7 Kalkan.',{kal:7}],
  ['sevimli','Sevimli Bakış','sav',1,'s','Rakibin bir sonraki Saldırı kartının hasarı yarıya iner.'],
  ['kucak','Kucağa Atla','sav',1,'s','3 moral yenile. Anında etki eder.',{aninda:1}],
  ['dikenli','Dikenli Tasma','sav',1,'s','3 tur boyunca sana Saldırı kartı oynayan her seferinde 1 hasar alır.',{sure:3}],
  ['kabi','Mama Kabı Koruması','sav',1,'s','2 Kalkan; rakip bu arada senden kart veya Mama çalamaz.',{kal:2}],
  ['olu','Ölü Taklidi','sav',1,'n','Tuzak: 3+ hasarlı ilk saldırıyı tamamen boşa çıkarır.',{tuzak:1}],
  ['anne','Annesinin Arkasına Saklan','sav',2,'n','Tuzak: İlk saldırıyı engeller; 1 kart çekersin.',{tuzak:1}],
  ['veteriner','Veterinere Kaç','sav',2,'n','6 moral yenile, tüm kötü durumları temizle. Anında etki eder.',{aninda:1}],
  ['kendine','Kendine Havla','sav',2,'e','Tuzak: 4+ hasarlı ilk saldırının hasarı saldırana döner.',{tuzak:1}],
  // Özellik
  ['vakti','Mama Vakti','oz',0,'s','Bu tur +2 Mama.'],
  ['kovala','Kuyruk Kovalama','oz',0,'s','Elinden 1 kart at, 2 kart çek.'],
  ['zarat','Zar At','oz',0,'s','1–2: 2 hasar al. 3–4: 1 kart çek. 5–6: +2 Mama ve 1 kart.',{sans:1}],
  ['kemik','Kemik Bul','oz',1,'s','2 kart çek.'],
  ['cop','Çöpü Karıştır','oz',1,'s','Destenin üst 3 kartına bak, 1\'ini al, kalanını dibe koy.'],
  ['mamacal','Mama Çal','oz',1,'s','Rakibin sonraki turundaki Mama\'dan 1\'ini al.'],
  ['mahcubiyet','Mahcubiyet Anı','oz',1,'s','Rakibe Utanç.'],
  ['selfie','Selfie','oz',1,'s','2 moral yenile; bu tur sonraki kartın 1 Mama ucuz.'],
  ['topgetir','Top Getir','oz',1,'n','Atılan kartlar yığınından istediğin 1 kartı eline al.'],
  ['kafakar','Kafa Karıştır','oz',1,'n','Rakibe Kafa Karışıklığı.'],
  ['sofra','Sofradan Yemek Çal','oz',1,'n','Rakibin elinden rastgele 1 kart çal.'],
  ['karakedi','Kara Kedi','oz',1,'n','Rakibe Uğursuzluk.'],
  ['ninni','Uyku Ninnisi','oz',2,'n','Rakibe Uyku 2.'],
  ['tasma','Tasmayı Çöz','oz',2,'n','Masadaki tüm savunmaları (iki tarafın da) yok et.'],
  ['sallama','Kuyruk Sallama','oz',2,'e','Rakibe Büyülenmiş.'],
  ['sahibin','Sahibin Değişti','oz',3,'e','Elini rakibin eliyle takas et.'],
  ['cagir','Mama Kabına Çağır','oz',1,'s','Destenden rastgele 1 Enik\'i bedelsiz masaya getir.'],
  ['dolusu','Kucak Dolusu','oz',1,'s','Masandaki 1 Enik\'in canını tamamen yenile.'],
  ['evedon','Eve Dön','oz',1,'n','Masandaki 1 Enik\'i eline geri al; Süper Hamle hakkı yenilenir.'],
  ['kavga','Mama Kavgası','oz',2,'n','İki rakip Enik birbirine Pati vuruşu yapar.'],
  ['sahiplen','Sahiplen','oz',3,'e','Rakibin canı 3 veya altındaki 1 Enik\'ini kendi masana al.'],
];

const ENIK = {}, KART = {};
ENIK_LISTE.forEach(e => { e.can = Math.round(e.moral / 3); e.mama = e.can <= 6 ? 2 : 3; ENIK[e.id] = e; });
KART_LISTE.forEach(k => { KART[k[0]] = Object.assign({ id:k[0], ad:k[1], tip:k[2], mama:k[3], nad:k[4], metin:k[5] }, k[6] || {}); });

const NAD_LIMIT = { s:3, n:2, e:1 };
const KOTU = ['pire','uyku','utanc','kafa','ugursuz','buyu','esneme'];
const DURUM_AD = { pire:'Pire', uyku:'Uyku', utanc:'Utanç', kafa:'Kafa Karışıklığı', ugursuz:'Uğursuzluk', buyu:'Büyülenmiş', esneme:'Esneme' };
const BAGISIK = { utanc:'mahcup', uyku:'uykucu', esneme:'uykucu', kafa:'beyni' };
const HEDEFLI = { mahcubiyet:1, kafakar:1, karakedi:1, ninni:1, sallama:1 };

/* ---------- Yardımcılar ---------- */
function rnd(S) {
  S.seed = (S.seed + 0x6D2B79F5) | 0;
  let t = S.seed;
  t = Math.imul(t ^ (t >>> 15), t | 1);
  t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
}
function karistir(S, a) { for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(rnd(S) * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; } return a; }
const ad = id => (ENIK[id] || KART[id] || { ad:'Kapalı kart' }).ad;
const rakip = (S, p) => S.o[1 - p.i];
const has = (p, id) => p.masa.some(e => e.id === id);
const defLimit = p => has(p, 'kurnaz') ? 3 : 2;
const elLimit = p => has(p, 'ac') ? 5 : EL_LIMIT;
const kotuSay = p => KOTU.filter(k => p.st[k] > 0).length;
function log(S, m) { S.log.push(m); if (S.log.length > 80) S.log.shift(); }
const yeniUid = S => 'k' + (S.uid++);
const kaynak = p => p.masa.filter(e => !e.sick).sort((a, b) => b.hp - a.hp)[0] || null;

function hedefler(S, dfn, bypass) {
  const g = dfn.masa.filter(e => e.gizli < S.tur);
  if (!g.length) return [{ hedef:'p', lbl:dfn.ad + ' (oyuncu)' }];
  const kor = bypass ? [] : g.filter(e => ENIK[e.id].rol === 'sav');
  return (kor.length ? kor : g).map(e => ({ hedef:e.uid, lbl:ENIK[e.id].ad + ' (' + e.hp + ' can)' }));
}
function otoHedef(S, dfn, y) {
  if (y[0].hedef === 'p') return 'p';
  return y.map(h => dfn.masa.find(e => e.uid === h.hedef)).sort((a, b) => a.hp - b.hp)[0].uid;
}

/* ---------- Hasar / iyileştirme / kart çekme ---------- */
function moralHasar(S, p, n) {
  if (n <= 0) return;
  p.moral -= n;
  S.fx.push({ t:'moral', p:p.i, n:-n });
  log(S, p.ad + ' ' + n + ' moral kaybetti.');
}
function enikHasar(S, sahip, e, n) {
  if (n <= 0 || !sahip.masa.includes(e)) return;
  if (e.id === 'mahcup' && !e.az) { e.az = true; n = Math.max(0, n - 1); if (!n) { log(S, 'Mahcup Enik gözünü kaçırdı, hasar almadı.'); return; } }
  if (e.id === 'uzgun' && !e.hit) { e.hit = true; e.tears = Math.min(6, e.tears + 1); }
  e.hp -= n;
  S.fx.push({ t:'can', uid:e.uid, p:sahip.i, n:-n });
  log(S, ENIK[e.id].ad + ' ' + n + ' hasar aldı.');
  if (e.hp <= 0) {
    const artan = -e.hp;
    sahip.masa.splice(sahip.masa.indexOf(e), 1);
    sahip.atik.push({ uid:e.uid, id:e.id });
    log(S, ENIK[e.id].ad + ' masadan düştü.');
    moralHasar(S, sahip, artan);
  }
}
function iyilestir(S, p, n) {
  if (has(p, 'asik')) n += 1;
  const once = p.moral;
  p.moral = Math.min(BASLANGIC_MORAL, p.moral + n);
  const g = p.moral - once;
  if (g > 0) {
    S.fx.push({ t:'moral', p:p.i, n:g });
    log(S, p.ad + ' ' + g + ' moral yeniledi.');
    const f = rakip(S, p);
    if (has(f, 'kiskanc') && f.moral < BASLANGIC_MORAL) { f.moral += 1; S.fx.push({ t:'moral', p:f.i, n:1 }); log(S, 'Kıskanç Enik kıskandı: ' + f.ad + ' 1 moral yeniledi.'); }
  }
}
function cek(S, p, n) {
  for (let i = 0; i < n; i++) {
    if (!p.deste.length) { log(S, p.ad + ' için deste bitti: Yorgunluk!'); moralHasar(S, p, 2); continue; }
    p.el.push(p.deste.pop());
    p.cekilen++;
    if (p.cekilen >= 3 && !p.tmp.kisk) {
      p.tmp.kisk = true;
      const f = rakip(S, p);
      if (has(f, 'kiskanc')) { log(S, 'Kıskanç Enik: "Onda var bende yok!"'); cek(S, f, 1); }
    }
  }
}
function durumVer(S, p, k, v) {
  const b = BAGISIK[k];
  if (b && has(p, b)) { log(S, ENIK[b].ad + ' sayesinde ' + DURUM_AD[k] + ' işlemedi.'); return; }
  p.st[k] = k === 'uyku' ? p.st.uyku + v : v;
  S.fx.push({ t:'durum', p:p.i, k });
  log(S, p.ad + ': ' + DURUM_AD[k] + '!');
}
function zar(S, p) {
  let r = 1 + Math.floor(rnd(S) * 6);
  if (p.st.ugursuz > 0) { r = Math.max(1, r - 2); p.st.ugursuz--; }
  S.fx.push({ t:'zar', p:p.i, n:r });
  log(S, p.ad + ' zar attı: ' + r);
  if (r <= 2 && has(p, 'bahti')) { log(S, 'Bahtı Sikik Enik alışkın: 1 kart.'); cek(S, p, 1); }
  return r;
}
function defCikar(S, p, d) {
  p.defs.splice(p.defs.indexOf(d), 1);
  if (d.kal) p.st.kalkan = Math.max(0, p.st.kalkan - d.kal);
  p.atik.push(d.gercek || { uid:d.uid, id:d.id });
}
function tetikler(d, n) {
  if (d.id === 'blof' || d.id === 'anne') return true;
  if (d.id === 'olu') return n >= 3;
  if (d.id === 'kendine') return n >= 4;
  if (d.id === 'sevimli') return n > 0;
  return false;
}
function hit(S, atk, dfn, n, o) {
  o = o || {};
  let d = n;
  if (o.kart) {
    if (atk.tmp.bonus) { d += atk.tmp.bonus; atk.tmp.bonus = 0; }
    if (atk.st.utanc > 0) d = Math.max(0, d - 2);
    if (has(atk, 'bahti') && atk.moral <= 8) d += 2;
    if (S.tur >= UZUN_TUR) d *= 2;
    if (!o.noTrap && atk !== dfn) {
      const t = dfn.defs.find(x => tetikler(x, d));
      if (t) {
        S.fx.push({ t:'tuzak', p:dfn.i, id:t.id });
        defCikar(S, dfn, t);
        if (t.id === 'blof') log(S, 'Kapalı kart açıldı: Blöfmüş!');
        else if (t.id === 'olu') { log(S, 'Ölü Taklidi! Saldırı boşa gitti.'); return; }
        else if (t.id === 'anne') { log(S, 'Annesinin Arkasına Saklandı! Saldırı engellendi.'); cek(S, dfn, 1); return; }
        else if (t.id === 'kendine') {
          log(S, 'Kendine Havla! Hasar geri döndü.');
          if (o.src && atk.masa.includes(o.src)) enikHasar(S, atk, o.src, d); else moralHasar(S, atk, d);
          return;
        } else if (t.id === 'sevimli') { d = Math.ceil(d / 2); log(S, 'Sevimli Bakış hasarı yarıya indirdi.'); }
      }
    }
  }
  if (d <= 0) return;
  if (!o.noShield && dfn.st.kalkan > 0) {
    const e = Math.min(dfn.st.kalkan, d);
    dfn.st.kalkan -= e; d -= e;
    S.fx.push({ t:'kalkan', p:dfn.i, n:-e });
    log(S, 'Kalkan ' + e + ' hasar emdi.');
    if (dfn.st.kalkan === 0) dfn.defs.filter(x => x.kal).forEach(x => defCikar(S, dfn, x));
    if (d <= 0) return;
  }
  const y = hedefler(S, dfn, o.bypass);
  const t = y.some(h => h.hedef === o.hedef) ? o.hedef : otoHedef(S, dfn, y);
  if (t === 'p') { moralHasar(S, dfn, d); return; }
  const e = dfn.masa.find(x => x.uid === t);
  if (e.id === 'diva' && o.kart && d >= 3 && o.src && atk !== dfn && atk.masa.includes(o.src)) {
    log(S, 'Diva Enik: "Dokunma bana!"');
    enikHasar(S, atk, o.src, 1);
  }
  enikHasar(S, dfn, e, d);
}

/* ---------- Kart etkileri ---------- */
function v(c, n, ek) { hit(c.S, c.me, c.foe, n, Object.assign({ kart:true, src:c.src, hedef:c.hedef }, c.ov, ek || {})); }
function kendineHasar(c, n) { moralHasar(c.S, c.me, n); }
const FX = {
  havlama: c => v(c, 1),
  isirik: c => v(c, 3),
  tokat: c => { v(c, 2); cek(c.S, c.me, 1); },
  kamci: c => v(c, 2 + (c.me.tmp.salSayisi > 0 ? 2 : 0)),
  pirebul: c => durumVer(c.S, c.foe, 'pire', 3),
  terlik: c => v(c, 5),
  postaci: c => v(c, 3, { noTrap:1, noShield:1, bypass:1 }),
  yazitura: c => { if (zar(c.S, c.me) >= 4) v(c, 6); else kendineHasar(c, 1); },
  camur: c => { v(c, 3); durumVer(c.S, c.foe, 'utanc', 2); },
  gece3: c => { v(c, 2); durumVer(c.S, c.foe, 'uyku', 1); },
  ayakkabi: c => {
    const d = c.foe.defs.find(x => x.uid === c.hedef2);
    if (d) { defCikar(c.S, c.foe, d); log(c.S, 'Bir savunma çiğnendi.'); }
    hit(c.S, c.me, c.foe, 2, Object.assign({ kart:true, src:c.src }, c.ov));
  },
  kudurmus: c => { v(c, 8); kendineHasar(c, 2); },
  mahalle: c => { v(c, 2); v(c, 2); v(c, 2); },
  linc: c => v(c, Math.min(6, c.foe.el.length)),
  kucak: c => iyilestir(c.S, c.me, 3),
  veteriner: c => { iyilestir(c.S, c.me, 6); KOTU.forEach(k => { c.me.st[k] = 0; }); },
  vakti: c => { c.me.mama += 2; },
  kovala: c => {
    if (!c.me.el.length) { cek(c.S, c.me, 2); return; }
    bekle(c.S, c.me, 'at', 'Atılacak kartı seç', elSec(c.me), { sonra:'cek2' });
  },
  zarat: c => {
    const r = zar(c.S, c.me);
    if (r <= 2) kendineHasar(c, 2);
    else if (r <= 4) cek(c.S, c.me, 1);
    else { c.me.mama += 2; cek(c.S, c.me, 1); }
  },
  kemik: c => cek(c.S, c.me, 2),
  cop: c => bekle(c.S, c.me, 'ust3', 'Eline alacağın kartı seç', c.me.deste.slice(-3).reverse().map(k => ({ uid:k.uid, id:k.id, lbl:ad(k.id) }))),
  mamacal: c => {
    if (c.foe.st.koruma) { log(c.S, 'Mama Kabı korunuyor, çalınamadı.'); return; }
    c.foe.sonraki -= 1; c.me.sonraki += 1;
    log(c.S, c.me.ad + ' 1 Mama çaldı.');
  },
  mahcubiyet: c => durumVer(c.S, c.foe, 'utanc', 2),
  selfie: c => { iyilestir(c.S, c.me, 2); c.me.tmp.sonraki = true; },
  topgetir: c => bekle(c.S, c.me, 'topgetir', 'Yığından bir kart seç', c.me.atik.map(k => ({ uid:k.uid, id:k.id, lbl:ad(k.id) }))),
  kafakar: c => durumVer(c.S, c.foe, 'kafa', 1),
  sofra: c => {
    if (c.foe.st.koruma) { log(c.S, 'Mama Kabı korunuyor, çalınamadı.'); return; }
    if (!c.foe.el.length) return;
    const k = c.foe.el.splice(Math.floor(rnd(c.S) * c.foe.el.length), 1)[0];
    delete k.joker;
    c.me.el.push(k);
    log(c.S, c.me.ad + ' rakibin elinden bir kart çaldı.');
  },
  karakedi: c => durumVer(c.S, c.foe, 'ugursuz', 2),
  ninni: c => durumVer(c.S, c.foe, 'uyku', 2),
  tasma: c => {
    [c.me, c.foe].forEach(p => { p.defs.slice().forEach(d => defCikar(c.S, p, d)); p.st.kalkan = 0; });
    log(c.S, 'Tüm savunmalar yok edildi.');
  },
  sallama: c => durumVer(c.S, c.foe, 'buyu', 1),
  sahibin: c => {
    const t = c.me.el; c.me.el = c.foe.el; c.foe.el = t;
    log(c.S, 'Eller takas edildi!');
  },
  cagir: c => {
    const a = c.me.deste.filter(k => ENIK[k.id]);
    if (!a.length || c.me.masa.length >= MASA_LIMIT) return;
    const k = a[Math.floor(rnd(c.S) * a.length)];
    c.me.deste.splice(c.me.deste.indexOf(k), 1);
    masayaKoy(c.S, c.me, k);
  },
  dolusu: c => {
    const e = c.me.masa.find(x => x.uid === c.hedef);
    if (e) { const g = e.max - e.hp; e.hp = e.max; if (g) c.S.fx.push({ t:'can', uid:e.uid, p:c.me.i, n:g }); log(c.S, ENIK[e.id].ad + ' tamamen iyileşti.'); }
  },
  evedon: c => {
    const e = c.me.masa.find(x => x.uid === c.hedef);
    if (e) { c.me.masa.splice(c.me.masa.indexOf(e), 1); c.me.el.push({ uid:e.uid, id:e.id }); log(c.S, ENIK[e.id].ad + ' eve döndü.'); }
  },
  kavga: c => {
    const a = c.foe.masa.find(x => x.uid === c.hedef), b = c.foe.masa.find(x => x.uid === c.hedef2);
    if (!a || !b) return;
    const g = e => ENIK[e.id].rol === 'sal' ? 2 : 1;
    const ga = g(a), gb = g(b);
    log(c.S, 'Mama Kavgası çıktı!');
    enikHasar(c.S, c.foe, b, ga); enikHasar(c.S, c.foe, a, gb);
  },
  sahiplen: c => {
    const e = c.foe.masa.find(x => x.uid === c.hedef);
    if (!e || c.me.masa.length >= MASA_LIMIT) return;
    c.foe.masa.splice(c.foe.masa.indexOf(e), 1);
    e.sick = true; e.pati = false;
    c.me.masa.push(e);
    log(c.S, ENIK[e.id].ad + ' sahiplenildi!');
  },
};

function elSec(p) { return p.el.map(k => ({ uid:k.uid, id:k.id, lbl:ad(k.id) })); }
function bekle(S, p, tur, baslik, sec, ek) { S.bekleyen = Object.assign({ p:p.i, tur, baslik, sec }, ek || {}); }

function masayaKoy(S, p, k) {
  const d = ENIK[k.id];
  const e = { uid:k.uid, id:k.id, hp:d.can, max:d.can, sick:true, pati:false, sup:false, tears:0, hit:false, az:false, gizli:-1 };
  p.masa.push(e);
  S.fx.push({ t:'gel', uid:e.uid, p:p.i });
  log(S, p.ad + ' masaya ' + d.ad + ' koydu.');
  if (d.rol === 'des') bekle(S, p, 'gelis', d.ad + ' geldi: ne alacaksın?', [{ v:'kart', lbl:'2 kart çek' }, { v:'mama', lbl:'+2 Mama al' }]);
}

function yerlestir(S, p, id, uid) {
  const d = KART[id], o = { uid, id, kapali:!!d.tuzak };
  if (d.kal) { o.kal = d.kal; p.st.kalkan = Math.min(KALKAN_LIMIT, p.st.kalkan + d.kal); }
  if (d.sure) o.sure = d.sure;
  if (id === 'kabi') p.st.koruma = true;
  p.defs.push(o);
}

function uygula(S, p, id, a, src, joker, uid) {
  const d = KART[id], foe = rakip(S, p);
  const c = { S, me:p, foe, src:src || kaynak(p), hedef:a.hedef, hedef2:a.hedef2, ov:{} };
  let ters = false;
  if (d.tip === 'sal' || HEDEFLI[id]) {
    if (p.st.kafa > 0) { p.st.kafa = 0; ters = rnd(S) < 0.5; log(S, ters ? 'Kafası karıştı: hedef kendisi oldu!' : 'Kafası karışıktı ama hedefi tutturdu.'); }
    else if (joker) { ters = rnd(S) < 0.5; log(S, ters ? 'Ne yapıyordu ki? Kendine denk geldi!' : 'Kör atış tuttu.'); }
    if (ters) { c.foe = p; c.hedef = null; }
  }
  if (d.tip === 'sal') {
    if (has(p, 'sinirli') && !p.tmp.sinirli) { p.tmp.sinirli = true; p.tmp.bonus = 1; }
    let kez = 1;
    if (p.tmp.show) { p.tmp.show = false; kez = 2; c.ov = { noTrap:1, noShield:1, bypass:1 }; }
    for (let i = 0; i < kez; i++) FX[id](c);
    p.tmp.bonus = 0;
    if (!ters && c.src && foe.defs.some(x => x.id === 'dikenli') && p.masa.includes(c.src)) {
      log(S, 'Dikenli Tasma battı!');
      enikHasar(S, p, c.src, 1);
    }
    p.tmp.salSayisi++;
    return;
  }
  if (d.tip === 'sav' && !d.aninda) { yerlestir(S, p, id, uid || yeniUid(S)); return; }
  FX[id](c);
}

/* Kartın geçerli hedef/seçenek listesi (Mama hariç); boş dizi = oynanamaz */
function secenekler(S, p, id, src) {
  const d = KART[id], foe = rakip(S, p);
  if (d.tip === 'sal') {
    if (p.st.buyu > 0) return [];
    if (!src && !kaynak(p)) return [];
  }
  if (d.tip === 'sav' && !d.aninda && p.defs.length >= defLimit(p)) return [];
  switch (id) {
    case 'ayakkabi': return foe.defs.map((x, i) => ({ hedef2:x.uid, lbl:(x.kapali ? 'Kapalı kart ' + (i + 1) : ad(x.id)) }));
    case 'dolusu': case 'evedon': return p.masa.map(e => ({ hedef:e.uid, lbl:ENIK[e.id].ad }));
    case 'kavga': {
      const g = foe.masa.filter(e => e.gizli < S.tur), o = [];
      for (let i = 0; i < g.length; i++) for (let j = i + 1; j < g.length; j++) o.push({ hedef:g[i].uid, hedef2:g[j].uid, lbl:ENIK[g[i].id].ad + ' ⚔ ' + ENIK[g[j].id].ad });
      return o;
    }
    case 'sahiplen': return p.masa.length >= MASA_LIMIT ? [] : foe.masa.filter(e => e.hp <= 3 && e.gizli < S.tur).map(e => ({ hedef:e.uid, lbl:ENIK[e.id].ad + ' (' + e.hp + ' can)' }));
    case 'topgetir': return p.atik.length ? [{}] : [];
    case 'cagir': return p.masa.length < MASA_LIMIT && p.deste.some(k => ENIK[k.id]) ? [{}] : [];
    case 'cop': return p.deste.length ? [{}] : [];
    case 'sofra': return foe.el.length ? [{}] : [];
    case 'tasma': return p.defs.length + foe.defs.length ? [{}] : [];
  }
  if (d.tip === 'sal' && !d.hedefsiz) return hedefler(S, foe, !!d.bypass || !!p.tmp.show);
  return [{}];
}

function supSec(S, p, e) {
  const foe = rakip(S, p);
  switch (e.id) {
    case 'bahti': return kotuSay(p) ? hedefler(S, foe) : [];
    case 'uzgun': return e.tears ? hedefler(S, foe, true) : [];
    case 'tweetci': return p.el.length ? hedefler(S, foe) : [];
    case 'kiskanc': {
      const son = S.son[foe.i];
      return son ? secenekler(S, p, son, e).map(o => Object.assign({}, o, { lbl:ad(son) + (o.lbl ? ' → ' + o.lbl : '') })) : [];
    }
    case 'ukala': return foe.el.length ? [{}] : [];
    case 'beyni': return p.el.length ? [{}] : [];
    case 'sinirli': return p.deste.some(k => KART[k.id] && KART[k.id].tip === 'sal') ? [{}] : [];
    case 'kurnaz': return p.defs.length < defLimit(p) && p.deste.some(k => KART[k.id] && KART[k.id].tuzak) ? [{}] : [];
  }
  return [{}];
}

function supUygula(S, p, e, a) {
  const foe = rakip(S, p);
  const dd = (n, o) => hit(S, p, foe, n, Object.assign({ src:e, hedef:a.hedef }, o || {}));
  switch (e.id) {
    case 'mahcup': e.gizli = S.tur + 1; cek(S, p, 2); break;
    case 'bahti': {
      let n = 0;
      KOTU.forEach(k => { if (p.st[k] > 0) { const v2 = p.st[k]; p.st[k] = 0; durumVer(S, foe, k, v2); n++; } });
      dd(n * 2);
      break;
    }
    case 'uykucu': durumVer(S, foe, 'esneme', 1); iyilestir(S, p, 3); break;
    case 'uzgun': { const n = e.tears; e.tears = 0; dd(n * 2, { noShield:1, bypass:1 }); break; }
    case 'beyni': {
      const n = p.el.length;
      p.el.forEach(k => p.atik.push({ uid:k.uid, id:k.id }));
      p.el = [];
      cek(S, p, n + 1);
      p.tmp.genel = true;
      break;
    }
    case 'diva': p.tmp.show = true; break;
    case 'ac':
      if (foe.st.koruma) { log(S, 'Mama Kabı korunuyor, çalınamadı.'); break; }
      foe.sonraki -= 2; p.sonraki += 2;
      break;
    case 'sinirli': {
      let n = 2;
      for (let i = p.deste.length - 1; i >= 0 && n > 0; i--) {
        const k = p.deste[i];
        if (KART[k.id] && KART[k.id].tip === 'sal') { p.el.push(p.deste.splice(i, 1)[0]); n--; }
      }
      p.tmp.salInd = true;
      break;
    }
    case 'tweetci': dd(Math.min(7, p.el.length)); break;
    case 'kiskanc': {
      const son = S.son[foe.i];
      if (son) uygula(S, p, son, a, e, false);
      break;
    }
    case 'ukala':
      bekle(S, p, 'attir', 'Rakibin eli: attıracağın kartı seç', foe.el.map(k => ({ uid:k.uid, id:k.id, lbl:ad(k.id) })));
      break;
    case 'asik': durumVer(S, foe, 'buyu', 1); iyilestir(S, p, 4); break;
    case 'hiper': p.mama += 3; break;
    case 'kurnaz': {
      let n = Math.min(2, defLimit(p) - p.defs.length);
      for (let i = p.deste.length - 1; i >= 0 && n > 0; i--) {
        const k = p.deste[i];
        if (KART[k.id] && KART[k.id].tuzak) { p.deste.splice(i, 1); yerlestir(S, p, k.id, k.uid); n--; }
      }
      break;
    }
  }
}

function maliyet(S, p, k) {
  if (k.joker) return 0;
  const en = ENIK[k.id], d = KART[k.id];
  let m = en ? en.mama : d.mama;
  if (p.tmp.genel) m -= 1;
  if (p.tmp.sonraki) m -= 1;
  if (d) {
    if (d.tip === 'sal' && p.tmp.salInd) m -= 1;
    if (d.tip === 'oz' && has(p, 'diva') && !p.tmp.diva) m -= 1;
    if (d.tuzak && has(p, 'kurnaz')) m -= 1;
  }
  return Math.max(0, m);
}

/* ---------- Kurulum ve tur akışı ---------- */
function desteKur(S) {
  const d = karistir(S, ENIK_LISTE.map(e => e.id)).slice(0, 8).map(id => ({ uid:yeniUid(S), id }));
  [['sal', 9], ['sav', 6], ['oz', 7]].forEach(([tip, adet]) => {
    const say = {};
    let n = 0;
    while (n < adet) {
      const r = rnd(S), nad = r < 0.6 ? 's' : r < 0.9 ? 'n' : 'e';
      const h = KART_LISTE.map(k => KART[k[0]]).filter(k => k.tip === tip && k.nad === nad && (say[k.id] || 0) < NAD_LIMIT[nad]);
      if (!h.length) continue;
      const k = h[Math.floor(rnd(S) * h.length)];
      say[k.id] = (say[k.id] || 0) + 1;
      d.push({ uid:yeniUid(S), id:k.id });
      n++;
    }
  });
  return d;
}
function dagit(S, p) {
  karistir(S, p.deste);
  p.el = p.deste.splice(-5);
  if (!p.el.some(k => KART[k.id] && KART[k.id].tip === 'sal')) {
    const i = p.deste.findIndex(k => KART[k.id] && KART[k.id].tip === 'sal');
    if (i >= 0) { const t = p.el[0]; p.el[0] = p.deste[i]; p.deste[i] = t; }
  }
}
function yeniOyuncu(S, i, adi) {
  const p = { i, ad:adi, moral:BASLANGIC_MORAL, mama:0, sonraki:0, el:[], deste:[], atik:[], masa:[], defs:[], sayac:0, oncekiSayac:0, cekilen:0, hazir:false, tmp:{},
    st:{ kalkan:0, pire:0, uyku:0, utanc:0, kafa:0, ugursuz:0, buyu:0, esneme:0, koruma:false } };
  p.deste = desteKur(S);
  dagit(S, p);
  return p;
}
function tmpSifirla(p) { p.tmp = { salSayisi:0 }; }
function yeniOyun(adlar, seed) {
  const S = { seed:(seed === undefined ? Math.floor(Math.random() * 2147483647) : seed) | 0, uid:1, tur:0, cur:0, faz:'eliyenile', kazanan:null, bekleyen:null, son:[null, null], log:[], fx:[], o:[] };
  S.o = [yeniOyuncu(S, 0, adlar[0]), yeniOyuncu(S, 1, adlar[1])];
  S.o.forEach(tmpSifirla);
  S.cur = rnd(S) < 0.5 ? 0 : 1;
  S.ilk = S.cur;
  S.o[1 - S.cur].sonraki += 1;
  log(S, 'Yazı-tura: ' + S.o[S.cur].ad + ' başlıyor.');
  return S;
}
function turBasi(S, q) {
  S.tur++;
  S.o.forEach(p => { p.cekilen = 0; p.masa.forEach(e => { e.az = false; e.hit = false; }); });
  tmpSifirla(q);
  q.st.koruma = false;
  q.sayac = 0;
  q.defs.filter(d => d.sure).forEach(d => { d.sure--; if (d.sure <= 0) defCikar(S, q, d); });
  log(S, '— ' + S.tur + '. tur: ' + q.ad + ' —');
  if (q.st.pire > 0) { log(S, 'Pire ısırdı!'); moralHasar(S, q, 1); q.st.pire--; }
  q.mama = Math.max(0, (has(q, 'ac') ? 4 : 3) - q.st.uyku + q.sonraki);
  q.st.uyku = 0; q.sonraki = 0;
  q.masa.forEach(e => { e.sick = false; e.pati = false; });
  cek(S, q, has(q, 'hiper') ? 3 : 2);
  q.masa.filter(e => e.id === 'beyni').forEach(() => {
    if (q.el.length) { const k = q.el[Math.floor(rnd(S) * q.el.length)]; k.joker = true; }
  });
}
function turBitir(S) {
  const p = S.o[S.cur];
  if (p.el.length > elLimit(p)) { bekle(S, p, 'at', 'Elin dolu: atılacak kartı seç', elSec(p), { sonra:'bitir' }); return; }
  turSonu(S);
}
function turSonu(S) {
  const p = S.o[S.cur], f = rakip(S, p);
  p.masa.filter(e => e.id === 'uykucu').forEach(() => { if (p.sayac <= 1) { log(S, 'Şekerleme iyi geldi.'); iyilestir(S, p, 3); } });
  if (has(p, 'tweetci') && p.sayac > f.oncekiSayac) { log(S, 'Ratio! 1 kart.'); cek(S, p, 1); }
  ['utanc', 'esneme', 'buyu'].forEach(k => { if (p.st[k] > 0) p.st[k]--; });
  p.el.forEach(k => { delete k.joker; });
  p.oncekiSayac = p.sayac;
  kazananBak(S);
  if (S.kazanan !== null) return;
  S.cur = 1 - S.cur;
  turBasi(S, S.o[S.cur]);
}
function kazananBak(S) {
  if (S.kazanan !== null) return;
  const a = S.o[0].moral <= 0, b = S.o[1].moral <= 0;
  if (a && b) S.kazanan = S.cur;
  else if (a) S.kazanan = 1;
  else if (b) S.kazanan = 0;
  if (S.kazanan !== null) { S.bekleyen = null; log(S, S.o[S.kazanan].ad + ' kazandı!'); }
}
const kim = S => S.kazanan !== null ? null : S.faz === 'eliyenile' ? (S.o[0].hazir ? 1 : 0) : S.bekleyen ? S.bekleyen.p : S.cur;

function legal(S) {
  if (S.kazanan !== null) return [];
  if (S.faz === 'eliyenile') return [{ t:'mull', yenile:false, lbl:'Bu elle başla' }, { t:'mull', yenile:true, lbl:'Eli yenile' }];
  if (S.bekleyen) return S.bekleyen.sec.map((s, i) => ({ t:'pick', i, lbl:s.lbl }));
  const p = S.o[S.cur], out = [{ t:'end', lbl:'Turu bitir' }];
  const kartSerbest = !(p.st.esneme > 0 && p.sayac >= 1);
  p.el.forEach(k => {
    if (!kartSerbest) return;
    if (maliyet(S, p, k) <= p.mama) {
      if (ENIK[k.id]) { if (p.masa.length < MASA_LIMIT) out.push({ t:'play', uid:k.uid, lbl:'' }); }
      else secenekler(S, p, k.id).forEach(o => out.push(Object.assign({ t:'play', uid:k.uid, lbl:'' }, o)));
    }
    if (p.mama >= 1 && p.defs.length < defLimit(p)) out.push({ t:'blof', uid:k.uid, lbl:'Blöf olarak kapalı koy (1 Mama)' });
  });
  const foe = rakip(S, p);
  p.masa.forEach(e => {
    if (e.sick) return;
    if (!e.pati && ENIK[e.id].rol !== 'des') hedefler(S, foe).forEach(o => out.push(Object.assign({ t:'pati', eu:e.uid }, o)));
    if (!e.sup && p.mama >= SUPER_MAMA) supSec(S, p, e).forEach(o => out.push(Object.assign({ t:'super', eu:e.uid }, o)));
  });
  return out;
}

function sec(S, i) {
  const b = S.bekleyen, s = b.sec[i], p = S.o[b.p];
  S.bekleyen = null;
  switch (b.tur) {
    case 'gelis': if (s.v === 'kart') cek(S, p, 2); else p.mama += 2; break;
    case 'at': {
      const j = p.el.findIndex(k => k.uid === s.uid);
      const k = p.el.splice(j, 1)[0];
      p.atik.push({ uid:k.uid, id:k.id });
      if (b.sonra === 'cek2') cek(S, p, 2);
      else if (b.sonra === 'bitir') turBitir(S);
      break;
    }
    case 'ust3': {
      const ust = p.deste.splice(-Math.min(3, p.deste.length));
      p.el.push(ust.splice(ust.findIndex(k => k.uid === s.uid), 1)[0]);
      p.deste.unshift(...ust);
      break;
    }
    case 'attir': {
      const f = rakip(S, p), j = f.el.findIndex(k => k.uid === s.uid);
      const k = f.el.splice(j, 1)[0];
      f.atik.push({ uid:k.uid, id:k.id });
      log(S, ad(k.id) + ' attırıldı.');
      cek(S, p, 1);
      break;
    }
    case 'topgetir': {
      const j = p.atik.findIndex(k => k.uid === s.uid);
      p.el.push(p.atik.splice(j, 1)[0]);
      break;
    }
  }
}

function act(S, a) {
  if (S.kazanan !== null) return S;
  const p = S.o[kim(S)];
  switch (a.t) {
    case 'mull':
      if (a.yenile) { p.deste.push(...p.el); p.el = []; dagit(S, p); log(S, p.ad + ' elini yeniledi.'); }
      p.hazir = true;
      if (S.o[0].hazir && S.o[1].hazir) { S.faz = 'oyun'; turBasi(S, S.o[S.cur]); }
      break;
    case 'pick': sec(S, a.i); break;
    case 'end': turBitir(S); break;
    case 'blof': {
      const j = p.el.findIndex(k => k.uid === a.uid), k = p.el.splice(j, 1)[0];
      p.mama -= 1; p.sayac++;
      delete k.joker;
      p.defs.push({ uid:yeniUid(S), id:'blof', kapali:true, gercek:{ uid:k.uid, id:k.id } });
      log(S, p.ad + ' kapalı bir kart koydu.');
      break;
    }
    case 'play': {
      const j = p.el.findIndex(k => k.uid === a.uid), k = p.el[j];
      p.mama -= maliyet(S, p, k);
      p.el.splice(j, 1);
      p.sayac++;
      p.tmp.sonraki = false;
      if (ENIK[k.id]) { masayaKoy(S, p, k); break; }
      const d = KART[k.id];
      if (d.tip === 'oz' && has(p, 'diva')) p.tmp.diva = true;
      if (d.tuzak) log(S, p.ad + ' kapalı bir kart koydu.');
      else log(S, p.ad + ': ' + d.ad);
      S.fx.push({ t:'kart', p:p.i, id:k.id });
      const yerlesen = d.tip === 'sav' && !d.aninda;
      uygula(S, p, k.id, a, null, !!k.joker, k.uid);
      if (!d.tuzak) S.son[p.i] = k.id;               // kapalı tuzak Taklit ile açığa çıkmasın
      if (!yerlesen) p.atik.push({ uid:k.uid, id:k.id });
      break;
    }
    case 'pati': {
      const e = p.masa.find(x => x.uid === a.eu);
      e.pati = true;
      log(S, ENIK[e.id].ad + ' Pati vuruşu yaptı.');
      hit(S, p, rakip(S, p), ENIK[e.id].rol === 'sal' ? 2 : 1, { src:e, hedef:a.hedef });
      break;
    }
    case 'super': {
      const e = p.masa.find(x => x.uid === a.eu);
      e.sup = true;
      p.mama -= SUPER_MAMA;
      log(S, ENIK[e.id].ad + ' Süper Hamle: ' + ENIK[e.id].sup[0] + '!');
      S.fx.push({ t:'super', uid:e.uid, p:p.i });
      supUygula(S, p, e, a);
      break;
    }
  }
  kazananBak(S);
  return S;
}

/* ---------- Basit bot: hamleleri kopya durumda dener, en iyisini seçer ---------- */
function taraf(S, p) {
  let v2 = p.moral * 2 + p.st.kalkan * 0.7 + p.sonraki * 0.6 + p.mama * 0.2;
  p.masa.forEach(e => { v2 += 3 + e.hp + (ENIK[e.id].rol === 'sal' ? 1.5 : 0) + e.tears * 0.6 + (e.sup ? 0 : 0.8); });
  p.el.forEach(k => { v2 += 0.5 + 0.1 * (ENIK[k.id] ? ENIK[k.id].mama : KART[k.id].mama); });
  p.defs.forEach(d => { v2 += d.id === 'blof' ? 0.9 : d.kal ? 0.3 : 2; });
  v2 -= p.st.pire + p.st.uyku + p.st.utanc + p.st.kafa + p.st.ugursuz * 0.4 + p.st.buyu * 2 + p.st.esneme * 3;
  return v2;
}
function puan(S, i) {
  if (S.kazanan === i) return 1e4;
  if (S.kazanan === 1 - i) return -1e4;
  return taraf(S, S.o[i]) - taraf(S, S.o[1 - i]);
}
function kopya(S) { const c = JSON.parse(JSON.stringify(Object.assign({}, S, { log:[], fx:[] }))); c.seed = (c.seed ^ 0x5bd1e995) | 0; return c; }
function botSec(S) {
  const i = kim(S);
  const ac = legal(S);
  if (S.faz === 'eliyenile') return ac[S.o[i].el.some(k => ENIK[k.id]) ? 0 : 1];
  if (ac.length === 1) return ac[0];
  const dene = S.bekleyen ? ac : ac.filter(a => a.t !== 'end' && !(a.t === 'blof' && ENIK[S.o[i].el.find(k => k.uid === a.uid).id]));
  let en = ac[0], ep = S.bekleyen ? -Infinity : puan(S, i) + 0.01;
  dene.forEach(a => {
    const c = kopya(S);
    const f = c.o[1 - i];
    f.defs = f.defs.filter(d => !d.kapali);          // kapalı kartları bilmiyormuş gibi
    act(c, a);
    let g = 0;
    while (c.bekleyen && c.bekleyen.p === i && g++ < 5) act(c, { t:'pick', i:0 });
    const s = puan(c, i);
    if (s > ep) { ep = s; en = a; }
  });
  return en;
}

const Motor = { yeniOyun, legal, act, kim, botSec, maliyet, has, defLimit, elLimit, ad, ENIK, KART, ENIK_LISTE, KOTU, DURUM_AD, BASLANGIC_MORAL, SUPER_MAMA };
if (typeof module !== 'undefined' && module.exports) module.exports = Motor; else kok.Motor = Motor;
})(typeof window !== 'undefined' ? window : globalThis);

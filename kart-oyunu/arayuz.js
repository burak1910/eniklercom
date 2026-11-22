/* Enikler Kart Oyunu: ekran. Kurallar motor.js'te; burası sadece çizer ve hamle gönderir. */
(function () {
'use strict';
const M = window.Motor, kok = document.getElementById('uygulama');
let BEN = 0, RAK = 1;
const BOT_ADIM = 900, AYAR = window.KART_AYAR || null;
const ROL = { sal:'⚔️', sav:'🛡️', des:'✨' }, ROL_AD = { sal:'Saldırı', sav:'Savunma (Koruyucu)', des:'Destek' }, TIP_AD = { sal:'Saldırı', sav:'Savunma', oz:'Özellik' };
const TIP_EM = { sal:'💥', sav:'🛡️', oz:'⭐' }, NAD_AD = { s:'Sık', n:'Nadir', e:'Efsane' };
const DURUM_EM = { pire:'🐜', uyku:'💤', utanc:'😳', kafa:'😵', ugursuz:'🐈‍⬛', buyu:'💘', esneme:'🥱' };

let S = null, pencere = null, zaman = null, adSakli = '', gunluk = false, yeniEnik = new Set();
let mod = 'bot', ws = null, oda = null, ilkMesaj = null, deneme = 0, tekrarT = null, koptuMu = false, rakipBagli = true, sureBitis = 0, rovans = [false, false];
let davet = (new URLSearchParams(location.search).get('oda') || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6) || null;

const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
const kisa = a => a.replace(/ Enik$/, '');
try { adSakli = localStorage.getItem('enikler_takma_ad') || ''; } catch (e) {}

/* ---------- Menü ---------- */
function menu() {
  clearTimeout(zaman); soketKapat(); S = null; pencere = null; gunluk = false; mod = 'bot';
  if (davet && AYAR) {
    const a = kayitOku(sessionStorage, davet);
    if (a) { odayaBaglan({ t:'katil', kod:davet, anahtar:a, zorla:true, ad:adSakli, tk:AYAR.tk }); return; }
  }
  kok.innerHTML = `<div class="menu">
    <span class="etiket">🔒 Gizli test: sadece sana açık</span>
    <h1>Enikler<span>Kart Oyunu</span></h1>
    <p>Enik'lerini masaya koy, rakibin moralini 16'dan 0'a indir.</p>
    <div><label for="ad">Takma adın</label>
      <input id="ad" maxlength="20" autocomplete="off" value="${esc(adSakli)}" placeholder="En az 2 karakter">
      <div class="hata" id="adHata"></div></div>
    ${davet && AYAR ? `<div class="ayrac"><p><b>${esc(davet)}</b> odasına davet edildin.</p><button class="btn ikinci" data-a="katil">⚔️ Masaya otur</button></div>` : ''}
    <button class="btn" data-a="basla">🤖 Bota karşı oyna</button>
    ${AYAR && !davet ? `<div class="ayrac"><div><label for="sure">Tur süresi</label>
      <select id="sure"><option value="30">30 saniye</option><option value="45" selected>45 saniye</option><option value="60">60 saniye</option></select></div>
      <button class="btn ikinci" data-a="odakur">👥 Oda kur, linki paylaş</button></div>` : ''}
    <details class="kural"><summary>Nasıl oynanır?</summary><ul>
      <li>Her tur 3 Mama ve 2 kart gelir. Artan Mama sonraki tura geçmez.</li>
      <li>Saldırmak için masada hazır bir Enik lazım; masaya geldiği tur saldıramaz.</li>
      <li>Rakibin masasında Enik varsa saldırılar önce Enik'e gider; Koruyucular (🛡️) önce vurulur.</li>
      <li>Her Enik turda bir bedelsiz Pati vuruşu yapar (Destek ✨ hariç).</li>
      <li>Süper Hamle 2 Mama tutar, her Enik için bir kez.</li>
      <li>Tuzaklar kapalı konur. Herhangi bir kartı 1 Mama ile Blöf yapabilirsin.</li>
      <li>12. turdan sonra Saldırı kartlarının hasarı 2 katına çıkar.</li>
    </ul></details></div>`;
}
function adAl() {
  const g = document.getElementById('ad'), v = g.value.trim();
  if (v.length < 2) { document.getElementById('adHata').textContent = 'Takma ad en az 2 karakter olmalı.'; g.focus(); return false; }
  adSakli = v;
  try { localStorage.setItem('enikler_takma_ad', v); } catch (e) {}
  return true;
}
function basla() { if (adAl()) oyunuKur(); }
function oyunuKur() {
  clearTimeout(zaman); soketKapat(); pencere = null; gunluk = false; mod = 'bot'; BEN = 0; RAK = 1;
  S = M.yeniOyun([adSakli, 'Bot Enik']);
  ilerle();
}

/* ---------- Akış ---------- */
function ilerle() {
  ciz();
  fxOynat();
  if (S.kazanan !== null) return;
  if (M.kim(S) === RAK) {
    zaman = setTimeout(() => { M.act(S, M.botSec(S)); ilerle(); }, S.faz === 'eliyenile' ? 0 : BOT_ADIM);
  }
}
const tumHamleler = () => mod === 'online' ? (S.legal || []) : M.legal(S);
function yap(a) {
  if (!a) return;
  pencere = null;
  if (mod === 'online') {
    const i = (S.legal || []).findIndex(x => JSON.stringify(x) === JSON.stringify(a));
    if (i < 0 || !ws || ws.readyState !== 1) { ciz(); return; }
    ws.send(JSON.stringify({ t:'hamle', seq:S.seq, i }));
    S.legal = [];                                    // cevap gelene kadar çift basmayı engeller
    ciz();
    return;
  }
  M.act(S, a); ilerle();
}
const benimSiram = () => S.faz === 'oyun' && !S.bekleyen && S.kazanan === null && S.cur === BEN;
const eylemler = () => benimSiram() ? tumHamleler() : [];

/* ---------- Çizim ---------- */
function durumlar(p) {
  let h = '';
  if (p.st.kalkan > 0) h += `<span class="chip kal">🛡️${p.st.kalkan}</span>`;
  if (p.st.koruma) h += '<span class="chip kal">🥣</span>';
  M.KOTU.forEach(k => { if (p.st[k] > 0) h += `<span class="chip kotu" title="${M.DURUM_AD[k]}">${DURUM_EM[k]}${p.st[k]}</span>`; });
  return h;
}
function enikHtml(e, taraf, ac) {
  const d = M.ENIK[e.id];
  const hazir = taraf === BEN && ac.some(a => (a.t === 'pati' || a.t === 'super') && a.eu === e.uid);
  const gizli = e.gizli >= S.tur;
  const c = ['enik', e.sick ? 'sick' : '', hazir ? 'hazir' : '', gizli ? 'gizli' : '', yeniEnik.has(e.uid) ? 'gel' : ''].join(' ');
  return `<button class="${c}" data-a="enik" data-u="${e.uid}" data-s="${taraf}" aria-label="${esc(d.ad)}, ${e.hp} can">
    <span class="rol">${ROL[d.rol]}</span>${e.sup ? '' : '<span class="sup" title="Süper Hamle hazır">🌟</span>'}
    <span class="yuz">${d.em}</span><b>${esc(kisa(d.ad))}</b>
    ${e.tears ? `<span class="jet">💧${e.tears}</span>` : ''}${e.sick ? '<span class="jet">💤</span>' : ''}
    <span class="can">${e.hp}</span></button>`;
}
function masa(p, ac) {
  let h = p.masa.map(e => enikHtml(e, p.i, ac)).join('');
  for (let i = p.masa.length; i < 3; i++) h += '<div class="yuva"></div>';
  return `<div class="masa" data-masa="${p.i}">${h}</div>`;
}
function savlar(p) {
  const goren = p.i === BEN || M.has(S.o[BEN], 'ukala');
  const h = p.defs.map(d => {
    const kapali = d.kapali && !goren;
    const ad = kapali ? '❓ Kapalı' : d.id === 'blof' ? '🎭 Blöf' : (d.kapali ? '🪤 ' : '') + M.ad(d.id) + (d.sure ? ' ' + d.sure + 't' : '');
    return `<button class="sav ${d.kapali ? 'kapali' : ''}" data-a="def" data-u="${d.uid}" data-s="${p.i}">${esc(ad)}</button>`;
  }).join('');
  return `<div class="savlar">${h || '<span class="bos">savunma yok</span>'}</div>`;
}
function kartHtml(k, oynanir, minik) {
  const en = M.ENIK[k.id], d = M.KART[k.id];
  const c = en ? 'enikk' : d.tip;
  const mm = S && S.faz === 'oyun' && !minik ? M.maliyet(S, S.o[BEN], k) : (en ? en.mama : d.mama);
  const metin = en ? `${ROL[en.rol]} ${en.can} can · ${en.tarz}` : d.metin;
  return `<button class="kart ${c} ${oynanir ? '' : 'soluk'} ${k.joker ? 'joker' : ''}" ${minik ? 'tabindex="-1"' : `data-a="kart" data-u="${k.uid}"`}>
    <span class="mm">${mm}</span><span class="ad">${esc(M.ad(k.id))}</span>
    <span class="me">${en ? en.em : TIP_EM[d.tip]}</span><span class="mt">${esc(metin)}</span></button>`;
}
function ciz() {
  if (!S) return;
  const ben = S.o[BEN], bot = S.o[RAK], ac = eylemler();
  const oy = new Set(ac.filter(a => a.t === 'play').map(a => a.uid));
  const sira = S.kazanan === null && S.faz === 'oyun';
  const son = S.log.slice(-2);
  const elYeri = kok.querySelector('.el'), kaydir = elYeri ? elYeri.scrollLeft : 0;
  const ust = M.has(ben, 'ukala') && ben.deste.length ? `<span class="bilgi">Üst: ${esc(M.ad(ben.deste[ben.deste.length - 1].id))}</span>` : '';
  kok.innerHTML = `<div class="oyun">
    <div class="bar ${sira && S.cur === RAK ? 'sira' : ''}" id="bar-${RAK}">
      <span class="isim">${mod === 'bot' ? '🤖' : '🐾'} ${esc(bot.ad)}</span><span class="moral">❤️ ${bot.moral}</span>
      <span class="bilgi">🃏${bot.el.length} 📚${bot.deste.length}</span><span class="chips">${mod === 'online' && !rakipBagli ? '<span class="chip koptu">📵 koptu</span>' : ''}${durumlar(bot)}</span></div>
    ${savlar(bot)}${masa(bot, ac)}
    <button class="olay" data-a="gunluk" aria-label="Maç günlüğünü aç">${son.map(x => `<span>${esc(x)}</span>`).join('')}</button>
    ${masa(ben, ac)}${savlar(ben)}
    <div class="kontrol">
      <div class="bar ${sira && S.cur === BEN ? 'sira' : ''}" id="bar-${BEN}">
        <span class="moral">❤️ ${ben.moral}</span><span class="mamalar">🥣 ${ben.mama}</span>
        <span class="bilgi">📚${ben.deste.length} · ${S.tur}. tur</span>${ust}${mod === 'online' && S.kazanan === null ? '<span class="sure" id="sayac"></span>' : ''}<span class="chips">${durumlar(ben)}</span></div>
      <button class="bitir" data-a="bitir" ${benimSiram() ? '' : 'disabled'}>${S.cur === RAK && sira ? (mod === 'bot' ? 'Bot…' : 'Rakip…') : 'Turu Bitir'}</button>
    </div>
    <div class="el">${ben.el.map(k => kartHtml(k, oy.has(k.uid))).join('')}</div>
  </div>${koptuMu ? '<div class="serit">Bağlantı koptu, yeniden bağlanılıyor…</div>' : ''}${pencereHtml()}`;
  sayacYaz();
  const yeniEl = kok.querySelector('.el');
  if (yeniEl) yeniEl.scrollLeft = kaydir;
  yeniEnik.clear();
}
function pencereHtml() {
  if (S.kazanan !== null) {
    const k = S.kazanan === BEN;
    const dugme = mod === 'bot' ? '<button class="btn" data-a="tekrar">Tekrar oyna</button>'
      : rovans[BEN] ? `<button class="btn" disabled>${rakipBagli ? 'Rakibin kararı bekleniyor…' : 'Rakip ayrıldı'}</button>`
      : `<button class="btn" data-a="rovans">${rovans[RAK] ? '🔥 Rövanşı kabul et' : '🔁 Rövanş iste'}</button>`;
    return `<div class="perde"><div class="pencere son"><div class="buyuk">${k ? '🏆' : '🐾'}</div><h2>${k ? 'Kazandın!' : esc(S.o[RAK].ad) + ' kazandı'}</h2>
      <p>${S.tur} turda bitti. Kalan moralin: ${Math.max(0, S.o[BEN].moral)}</p>
      ${dugme}<button class="btn alt" data-a="menu">Menüye dön</button></div></div>`;
  }
  if (S.faz === 'eliyenile' && M.kim(S) === BEN) {
    const ilk = S.o[S.cur].ad;
    return `<div class="perde"><div class="pencere"><h2>Açılış elin</h2><p class="alt">Yazı-tura: ${esc(ilk)} başlıyor. Bir kez eli yenileyebilirsin.</p>
      <div class="minikler">${S.o[BEN].el.map(k => kartHtml(k, true, true)).join('')}</div>
      <button class="btn" data-a="mull" data-y="0">Bu elle başla</button><button class="btn alt" data-a="mull" data-y="1">🔄 Eli yenile</button></div></div>`;
  }
  if (S.faz === 'eliyenile') {
    return `<div class="perde"><div class="pencere"><h2>${esc(S.o[RAK].ad)} elini seçiyor</h2><p><span class="nokta">●</span> Sıra birazdan sende.</p></div></div>`;
  }
  if (S.bekleyen && S.bekleyen.p === BEN) {
    return `<div class="perde"><div class="pencere"><h2>${esc(S.bekleyen.baslik)}</h2>
      ${S.bekleyen.sec.map((s, i) => `<button class="btn alt" data-a="sec" data-i="${i}">${esc(s.lbl)}${s.id ? `<div class="alt">${esc(aciklama(s.id))}</div>` : ''}</button>`).join('')}</div></div>`;
  }
  if (gunluk) {
    return `<div class="perde" data-a="kapat"><div class="pencere" data-a="yut"><h2>Maç günlüğü</h2>
      <div class="gunluk">${S.log.slice().reverse().map(x => `<div>${esc(x)}</div>`).join('')}</div>
      <button class="btn alt" data-a="kapat">Kapat</button></div></div>`;
  }
  if (!pencere) return '';
  const p = pencere;
  return `<div class="perde" data-a="kapat"><div class="pencere" data-a="yut">
    <div class="ust"><div class="buyuk">${p.em}</div><div><h2>${esc(p.baslik)}</h2><div class="alt">${esc(p.alt)}</div></div></div>
    ${p.satirlar.map(x => `<p class="bolum">${x}</p>`).join('')}
    ${p.neden ? `<p class="neden">${esc(p.neden)}</p>` : ''}
    ${p.ac.map((a, i) => `<button class="btn ${a.t === 'blof' ? 'blof' : ''}" data-a="yap" data-i="${i}">${esc(etiket(a))}</button>`).join('')}
    <button class="btn alt" data-a="kapat">Vazgeç</button></div></div>`;
}
function aciklama(id) {
  const e = M.ENIK[id];
  return e ? ROL_AD[e.rol] + ' · ' + e.can + ' can' : M.KART[id].metin;
}
function etiket(a) {
  if (a.t === 'blof') return '🎭 ' + a.lbl;
  if (a.t === 'pati') return '🐾 Pati → ' + a.lbl;
  if (a.t === 'super') return '🌟 Süper (2 Mama)' + (a.lbl ? ' → ' + a.lbl : '');
  return a.lbl ? 'Oyna → ' + a.lbl : 'Oyna';
}

/* ---------- Ayrıntı pencereleri ---------- */
function neden(k) {
  if (!benimSiram()) return S.kazanan === null ? 'Şu an sıra sende değil.' : '';
  const p = S.o[BEN], d = M.KART[k.id];
  if (p.st.esneme > 0 && p.sayac >= 1) return 'Esneme: bu tur en fazla 1 kart oynayabilirsin.';
  if (M.maliyet(S, p, k) > p.mama) return 'Mama yetmiyor.';
  if (M.ENIK[k.id]) return 'Masa dolu (en fazla 3 Enik).';
  if (d.tip === 'sal' && p.st.buyu > 0) return 'Büyülendin: bu tur Saldırı kartı oynayamazsın.';
  if (d.tip === 'sal') return 'Saldırmak için masada hazır bir Enik lazım.';
  if (d.tip === 'sav' && !d.aninda && p.defs.length >= M.defLimit(p)) return 'Aktif savunma sınırı dolu.';
  return 'Şu an oynanacak uygun hedef yok.';
}
function kartAc(uid) {
  const k = S.o[BEN].el.find(x => x.uid === uid); if (!k) return;
  const ac = eylemler().filter(a => (a.t === 'play' || a.t === 'blof') && a.uid === uid);
  const oyna = ac.filter(a => a.t === 'play');
  const en = M.ENIK[k.id], d = M.KART[k.id], mm = M.maliyet(S, S.o[BEN], k);
  const jok = k.joker ? ['<b>🤪 Joker:</b> bu tur bedava ama hedefi rastgele.'] : [];
  pencere = en ? {
    em:en.em, baslik:en.ad, alt:`Enik kartı · ${ROL_AD[en.rol]} · ${en.can} can · ${mm} Mama`,
    satirlar:jok.concat(enikSatir(en)), ac, neden:oyna.length ? '' : neden(k),
  } : {
    em:TIP_EM[d.tip], baslik:d.ad, alt:`${TIP_AD[d.tip]}${d.tuzak ? ' · Tuzak' : ''}${d.sans ? ' · Şans' : ''} · ${NAD_AD[d.nad]} · ${mm} Mama`,
    satirlar:jok.concat([esc(d.metin)]), ac, neden:oyna.length ? '' : neden(k),
  };
  ciz();
}
function enikSatir(en) {
  return [`<b>${esc(en.pasif[0])}:</b> ${esc(en.pasif[1])}`, `<b>🌟 ${esc(en.sup[0])}:</b> ${esc(en.sup[1])}`];
}
function enikAc(uid, taraf) {
  const e = S.o[taraf].masa.find(x => x.uid === uid); if (!e) return;
  const d = M.ENIK[e.id];
  const ac = taraf === BEN ? eylemler().filter(a => (a.t === 'pati' || a.t === 'super') && a.eu === uid) : [];
  let n = '';
  if (taraf === BEN && !ac.length && benimSiram()) n = e.sick ? 'Bu tur masaya geldi, sonraki turdan itibaren hazır.' : '';
  pencere = { em:d.em, baslik:d.ad, alt:`${ROL_AD[d.rol]} · ${e.hp}/${e.max} can${e.tears ? ' · 💧' + e.tears : ''}${e.sup ? ' · Süper kullanıldı' : ''}`, satirlar:enikSatir(d), ac, neden:n };
  ciz();
}
function defAc(uid, taraf) {
  const d = S.o[taraf].defs.find(x => x.uid === uid); if (!d) return;
  const goren = taraf === BEN || M.has(S.o[BEN], 'ukala');
  if (d.kapali && !goren) pencere = { em:'❓', baslik:'Kapalı kart', alt:'Tuzak da olabilir, blöf de.', satirlar:[], ac:[], neden:'' };
  else if (d.id === 'blof') pencere = { em:'🎭', baslik:'Blöf', alt:M.ad(d.gercek.id) + ' kapalı kondu', satirlar:['İlk Saldırı kartında açılır ve boş çıkar.'], ac:[], neden:'' };
  else { const k = M.KART[d.id]; pencere = { em:'🛡️', baslik:k.ad, alt:d.kapali ? 'Tuzak' : 'Aktif savunma', satirlar:[esc(k.metin)], ac:[], neden:'' }; }
  ciz();
}

/* ---------- Görsel geri bildirim ---------- */
function ucan(x, y, metin, sinif) {
  const d = document.createElement('div');
  d.className = 'ucan ' + sinif; d.textContent = metin; d.style.left = x + 'px'; d.style.top = y + 'px';
  document.body.appendChild(d);
  setTimeout(() => d.remove(), 950);
}
function fxOynat() {
  const liste = S.fx; S.fx = [];
  liste.forEach((f, i) => {
    if (f.t === 'gel') { yeniEnik.add(f.uid); const el = kok.querySelector(`[data-u="${f.uid}"]`); if (el) el.classList.add('gel'); }
    if (f.t === 'zar') {
      const z = document.createElement('div'); z.className = 'zar'; z.textContent = f.n;
      document.body.appendChild(z); setTimeout(() => z.remove(), 1050);
    }
    if (f.t === 'can' || f.t === 'moral' || f.t === 'kalkan') {
      const hedef = (f.uid && kok.querySelector(`[data-u="${f.uid}"]`)) || kok.querySelector(f.t === 'can' ? `[data-masa="${f.p}"]` : `#bar-${f.p}`);
      if (!hedef) return;
      const r = hedef.getBoundingClientRect();
      const y = r.top + r.height / 2 + (i % 3) * 14 - 14;
      ucan(r.left + r.width / 2, y, (f.n > 0 ? '+' : '') + f.n, f.t === 'kalkan' ? 'mavi' : f.n > 0 ? 'arti' : 'eksi');
    }
  });
}

/* ---------- Tıklamalar ---------- */
kok.addEventListener('click', ev => {
  const t = ev.target.closest('[data-a]'); if (!t) return;
  const a = t.dataset.a, d = t.dataset;
  switch (a) {
    case 'yut': return;
    case 'basla': basla(); break;
    case 'tekrar': oyunuKur(); break;
    case 'menu': davetiBirak(); menu(); break;
    case 'odakur':
      if (adAl()) odayaBaglan({ t:'kur', ad:adSakli, sure:+document.getElementById('sure').value, tk:AYAR.tk });
      break;
    case 'katil':
      if (adAl()) odayaBaglan({ t:'katil', kod:davet, ad:adSakli, anahtar:kayitOku(localStorage, davet) || undefined, tk:AYAR.tk });
      break;
    case 'kopyala': linkKopyala(t); break;
    case 'rovans': if (ws && ws.readyState === 1) { ws.send(JSON.stringify({ t:'rovans' })); rovans[BEN] = true; ciz(); } break;
    case 'bitir': if (benimSiram()) yap(tumHamleler().find(x => x.t === 'end')); break;
    case 'mull': yap(tumHamleler()[+d.y]); break;
    case 'sec': yap(tumHamleler()[+d.i]); break;
    case 'yap': yap(pencere.ac[+d.i]); break;
    case 'kart': kartAc(d.u); break;
    case 'enik': enikAc(d.u, +d.s); break;
    case 'def': defAc(d.u, +d.s); break;
    case 'gunluk': gunluk = true; ciz(); break;
    case 'kapat': if (ev.target.closest('[data-a]') === t) { pencere = null; gunluk = false; ciz(); } break;
  }
});
kok.addEventListener('keydown', ev => { if (ev.key === 'Enter' && ev.target.id === 'ad') basla(); });

/* ---------- Canlı maç: tüm kararlar sunucuda, burası sadece görüntüler ---------- */
function kayitOku(depo, kod) { try { return depo.getItem('enikler_oda_' + kod); } catch (e) { return null; } }
function kayitYaz(depo, kod, a) { try { depo.setItem('enikler_oda_' + kod, a); } catch (e) {} }
function kayitSil(kod) { [sessionStorage, localStorage].forEach(d => { try { d.removeItem('enikler_oda_' + kod); } catch (e) {} }); }
function davetiBirak() {
  if (davet) kayitSil(davet);
  if (oda) kayitSil(oda.kod);
  davet = null;
  try { history.replaceState(null, '', location.pathname); } catch (e) {}
}
function soketKapat() {
  clearTimeout(tekrarT);
  if (ws) { const s = ws; ws = null; s.onclose = null; s.close(); }
  oda = null; ilkMesaj = null; deneme = 0; koptuMu = false; rakipBagli = true; rovans = [false, false]; sureBitis = 0;
}
function odayaBaglan(m) {
  soketKapat();
  S = null; pencere = null; gunluk = false; mod = 'online'; ilkMesaj = m;
  kok.innerHTML = '<div class="menu"><h1>Enikler<span>Masa hazırlanıyor</span></h1><p><span class="nokta">●</span> Bağlanılıyor…</p></div>';
  soketAc();
}
function soketAc() {
  const s = new WebSocket(AYAR.ws);
  ws = s;
  s.onopen = () => {
    deneme = 0;
    s.send(JSON.stringify(oda ? { t:'katil', kod:oda.kod, anahtar:oda.anahtar, zorla:true, tk:AYAR.tk } : ilkMesaj));
  };
  s.onmessage = e => { let m; try { m = JSON.parse(e.data); } catch (x) { return; } gelen(m); };
  s.onclose = () => {
    if (ws !== s) return;
    if (deneme >= 12) { hataEkrani('Sunucuya bağlanılamadı. Birazdan tekrar dene.'); return; }
    koptuMu = true;
    if (S) ciz();
    tekrarT = setTimeout(soketAc, Math.min(1000 * Math.pow(1.6, deneme++), 8000));
  };
}
function gelen(m) {
  if (m.t === 'oda') {
    oda = m; BEN = m.ben; RAK = 1 - m.ben; koptuMu = false;
    kayitYaz(sessionStorage, m.kod, m.anahtar);
    kayitYaz(localStorage, m.kod, m.anahtar);
    davet = m.kod;
    try { history.replaceState(null, '', location.pathname + '?oda=' + m.kod); } catch (e) {}
    if (!S && m.durum === 'bekliyor') lobi();
  } else if (m.t === 'durum') {
    S = m.v; BEN = S.ben; RAK = 1 - S.ben; koptuMu = false;
    rakipBagli = m.rakipBagli; rovans = m.rovans; sureBitis = m.kalan ? Date.now() + m.kalan : 0;
    pencere = null;
    ciz(); fxOynat();
  } else if (m.t === 'rakip') {
    rakipBagli = m.bagli;
    if (S) ciz();
  } else if (m.t === 'rovans') {
    rovans = m.istek;
    if (S) ciz();
  } else if (m.t === 'hata') {
    if (m.kalici) { if (m.odaYok && davet) kayitSil(davet); hataEkrani(m.m); }
    else if (document.getElementById('adHata')) document.getElementById('adHata').textContent = m.m;
  }
}
function hataEkrani(mesaj) {
  soketKapat(); S = null;
  kok.innerHTML = `<div class="menu"><h1>Olmadı<span>🐾</span></h1><p>${esc(mesaj)}</p><button class="btn alt" data-a="menu">Menüye dön</button></div>`;
}
const odaLinki = () => location.origin + location.pathname + '?oda=' + oda.kod;
function lobi() {
  kok.innerHTML = `<div class="menu">
    <span class="etiket">🔒 Gizli test: link sadece admin girişiyle açılır</span>
    <h1>Oda<span>hazır</span></h1>
    <div class="ayrac"><div class="kod">${esc(oda.kod)}</div>
      <div class="linkkutu">${esc(odaLinki())}</div>
      <button class="btn ikinci" data-a="kopyala">🔗 Linki kopyala</button></div>
    <p><span class="nokta">●</span> Rakip bekleniyor. Tur süresi ${oda.sure} saniye.</p>
    <button class="btn alt" data-a="menu">Vazgeç</button></div>`;
}
function linkKopyala(b) {
  const bitti = () => { b.textContent = '✅ Kopyalandı'; };
  try { navigator.clipboard.writeText(odaLinki()).then(bitti, () => { b.textContent = 'Linki elle kopyala'; }); }
  catch (e) { b.textContent = 'Linki elle kopyala'; }
}
function sayacYaz() {
  const e = document.getElementById('sayac'); if (!e) return;
  if (!sureBitis) { e.textContent = ''; return; }
  const n = Math.max(0, Math.ceil((sureBitis - Date.now()) / 1000));
  e.textContent = '⏱' + n; e.classList.toggle('az', n <= 10);
}
setInterval(sayacYaz, 500);

menu();
})();

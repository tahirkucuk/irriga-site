(function () {
  var ONAY_KEY = 'sa-cerez-onay';
  var mevcut = localStorage.getItem(ONAY_KEY);

  // GA4 + Google Ads (Consent Mode v2) consent güncelle
  function ga4Guncelle(durum) {
    if (typeof gtag === 'function') {
      gtag('consent', 'update', {
        analytics_storage: durum,
        ad_storage: durum,
        ad_user_data: durum,
        ad_personalization: durum
      });
    }
  }

  // Meta Pixel consent — sayfalarda fbq('consent','revoke') ile başlar,
  // yalnızca kullanıcı kabul edince grant edilir.
  function pixelGuncelle(durum) {
    if (typeof fbq === 'function') {
      fbq('consent', durum === 'granted' ? 'grant' : 'revoke');
    }
  }

  function izinVer(durum) {
    ga4Guncelle(durum);
    pixelGuncelle(durum);
  }

  // Kaydedilmiş onay varsa izinleri hemen uygula, banner gösterme
  if (mevcut === 'kabul') {
    izinVer('granted');
    return;
  }
  if (mevcut === 'red') {
    return; // tüm depolama denied / pixel revoked olarak kalır
  }

  // Banner HTML'i enjekte et
  var banner = document.createElement('div');
  banner.id = 'cerez-banner';
  banner.setAttribute('role', 'dialog');
  banner.setAttribute('aria-label', 'Çerez tercihleri');
  banner.setAttribute('aria-live', 'polite');
  banner.innerHTML = [
    '<style>',
    '#cerez-banner{position:fixed;bottom:0;left:0;right:0;z-index:99999;',
    'background:#0F172A;border-top:1px solid rgba(251,191,36,0.25);',
    'padding:16px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;',
    'font-family:"DM Sans",system-ui,sans-serif;font-size:14px;color:#CBD5E1;',
    'box-shadow:0 -4px 24px rgba(0,0,0,0.4);animation:cerezSlide .3s ease;}',
    '@keyframes cerezSlide{from{transform:translateY(100%)}to{transform:translateY(0)}}',
    '#cerez-banner p{margin:0;flex:1;min-width:220px;line-height:1.5;}',
    '#cerez-banner a{color:#FBBF24;text-decoration:underline;}',
    '#cerez-banner a:hover{color:#F59E0B;}',
    '.cerez-btn{padding:9px 18px;border-radius:8px;border:none;cursor:pointer;',
    'font-size:13px;font-weight:600;font-family:inherit;white-space:nowrap;',
    'transition:opacity .15s;}',
    '.cerez-btn:hover{opacity:.85;}',
    '#cerez-kabul{background:#FBBF24;color:#0F172A;}',
    '#cerez-red{background:transparent;color:#94A3B8;border:1px solid #334155;}',
    '@media(max-width:600px){#cerez-banner{flex-direction:column;align-items:stretch;}',
    '.cerez-btn{width:100%;text-align:center;padding:12px;}}',
    '</style>',
    '<p>Bu site, deneyiminizi iyileştirmek ve trafik analizi yapmak için çerezler kullanır.',
    ' <a href="cerez-politikasi.html">Çerez Politikası</a> ve',
    ' <a href="kvkk.html">KVKK Aydınlatma Metni</a>\'ni inceleyebilirsiniz.</p>',
    '<div style="display:flex;gap:8px;flex-shrink:0;">',
    '<button class="cerez-btn" id="cerez-kabul">Kabul Et</button>',
    '<button class="cerez-btn" id="cerez-red">Sadece Gerekli</button>',
    '</div>'
  ].join('');

  function kapat(durum) {
    localStorage.setItem(ONAY_KEY, durum);
    if (durum === 'kabul') izinVer('granted');
    banner.style.transition = 'transform .25s ease, opacity .25s ease';
    banner.style.transform = 'translateY(100%)';
    banner.style.opacity = '0';
    setTimeout(function () { banner.remove(); }, 260);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.body.appendChild(banner);
    document.getElementById('cerez-kabul').addEventListener('click', function () { kapat('kabul'); });
    document.getElementById('cerez-red').addEventListener('click', function () { kapat('red'); });
  });
})();

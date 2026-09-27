/**
 * thumbnail-uret.mjs — posts.json'daki kapak=null olan makaleler için
 * Puppeteer ile 600×315 JPEG thumbnail üretir, posts.json'u günceller.
 * Kullanım: node yerel-araclar/thumbnail-uret.mjs
 */
import puppeteer from 'puppeteer';
import { mkdirSync, readFileSync, writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __dir = dirname(fileURLToPath(import.meta.url));
const SITE  = join(__dir, '..');
const OUT   = join(SITE, 'media/thumbnails');
mkdirSync(OUT, { recursive: true });

const postsPath = join(SITE, 'posts.json');
const posts     = JSON.parse(readFileSync(postsPath, 'utf8'));

const KAT_RENK = {
  'Rehber':            { bg: '#163325', accent: '#6EE7B7' },
  'Sera':              { bg: '#1B4332', accent: '#95D5B2' },
  'Fertigasyon':       { bg: '#1E5631', accent: '#B7E4C7' },
  'Su Tasarrufu':      { bg: '#1B3A4B', accent: '#90E0EF' },
  'Otomasyon':         { bg: '#1A1A2E', accent: '#A78BFA' },
  'Bakım':             { bg: '#2D1B00', accent: '#FCD34D' },
  'Devlet Destekleri': { bg: '#4A0E1C', accent: '#F9A8D4' },
  'Karşılaştırma':     { bg: '#1E1B4B', accent: '#C4B5FD' },
  'Meyve Bahçeleri':   { bg: '#3B1C00', accent: '#FDE68A' },
  'Tarla Bitkileri':   { bg: '#1A2E0A', accent: '#BEF264' },
  'Sistem Tasarımı':   { bg: '#0D2626', accent: '#5EEAD4' },
  'Enerji':            { bg: '#1C1A00', accent: '#FEF08A' },
  'Su Kalitesi':       { bg: '#0C2340', accent: '#7DD3FC' },
  'Sebze':             { bg: '#1A2E0A', accent: '#86EFAC' },
  'Teknikler':         { bg: '#1C1C1C', accent: '#D4D4D4' },
};
const DEFAULT_RENK = { bg: '#163325', accent: '#6EE7B7' };

function katRenk(kat) { return KAT_RENK[kat] || DEFAULT_RENK; }

function sulamaIkon(accent) {
  return `<svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M40 10 C40 10 18 38 18 52 C18 63.05 28.06 72 40 72 C51.94 72 62 63.05 62 52 C62 38 40 10 40 10Z"
      stroke="${accent}" stroke-width="3.5" stroke-linejoin="round" fill="${accent}18"/>
    <path d="M30 54 C30 48 36 44 40 44" stroke="${accent}" stroke-width="3" stroke-linecap="round"/>
  </svg>`;
}

function makeThumbHtml(post) {
  const { bg, accent } = katRenk(post.kategori);
  const baslik = (post.baslik || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
  const kat    = (post.kategori || 'SULAMA').toUpperCase();

  return `<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
html,body{width:600px;height:315px;overflow:hidden;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;}
body{background:${bg};position:relative;display:flex;flex-direction:column;}
.bg{position:absolute;inset:0;
  background:radial-gradient(ellipse 80% 100% at 100% 0%,${accent}20 0%,transparent 60%),
             radial-gradient(ellipse 60% 70% at 0% 100%,${accent}12 0%,transparent 55%);}
.serit{position:absolute;top:0;left:0;bottom:0;width:5px;background:linear-gradient(180deg,${accent},${accent}66);}
.ikon{position:absolute;right:22px;bottom:30px;width:120px;height:120px;opacity:0.18;}
.ikon svg{width:100%;height:100%;}
.ust{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;padding:20px 28px 0 30px;}
.marka{font-size:12px;font-weight:700;color:${accent};letter-spacing:0.06em;text-transform:uppercase;}
.alan{font-size:10px;color:rgba(255,255,255,0.4);font-weight:600;letter-spacing:0.04em;}
.icerik{position:relative;z-index:1;flex:1;display:flex;flex-direction:column;justify-content:center;padding:0 160px 0 30px;}
.badge{display:inline-block;width:fit-content;background:${accent};color:${bg};font-size:9.5px;font-weight:800;letter-spacing:0.1em;padding:5px 12px;border-radius:99px;margin-bottom:14px;}
.title{font-size:${baslik.length > 65 ? 20 : baslik.length > 45 ? 22 : 25}px;font-weight:800;color:#fff;line-height:1.3;letter-spacing:-0.02em;}
.altbar{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;padding:12px 28px 14px 30px;border-top:1px solid rgba(255,255,255,0.08);}
.tarih{font-size:11px;font-weight:600;color:rgba(255,255,255,0.45);}
.okbtn{font-size:11px;font-weight:700;color:${accent};letter-spacing:0.04em;}
</style>
</head>
<body>
  <div class="bg"></div>
  <div class="serit"></div>
  <div class="ikon">${sulamaIkon(accent)}</div>
  <div class="ust">
    <div class="marka">Irriga Sulama</div>
    <div class="alan">irriga.com.tr</div>
  </div>
  <div class="icerik">
    <div class="badge">${kat}</div>
    <div class="title">${baslik}</div>
  </div>
  <div class="altbar">
    <div class="tarih">${post.tarih || ''}</div>
    <div class="okbtn">Devamını Oku →</div>
  </div>
</body></html>`;
}

const browser = await puppeteer.launch({
  headless: true,
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--font-render-hinting=none'],
  protocolTimeout: 60000,
});
const page = await browser.newPage();
await page.setViewport({ width: 600, height: 315, deviceScaleFactor: 2 });

let updated = 0;
for (const post of posts) {
  if (post.kapak) continue; // zaten görseli var

  // url: "blog/slug.html" → slug
  const slug  = (post.url || '').replace(/^blog\//, '').replace(/\.html$/, '');
  if (!slug) continue;

  const fname = `thumb-${slug}.jpg`;
  const fpath = join(OUT, fname);

  const html = makeThumbHtml(post);
  await page.setContent(html, { waitUntil: 'domcontentloaded' });
  await page.evaluate(async () => {
    try { await Promise.race([document.fonts.ready, new Promise(r => setTimeout(r, 2000))]); } catch(e){}
  });
  await new Promise(r => setTimeout(r, 150));

  await page.screenshot({ path: fpath, type: 'jpeg', quality: 88,
    clip: { x: 0, y: 0, width: 600, height: 315 } });

  post.kapak = `media/thumbnails/${fname}`;
  console.log(`✅ ${slug}`);
  updated++;
}

await browser.close();
writeFileSync(postsPath, JSON.stringify(posts, null, 2), 'utf8');
console.log(`\n🎨 ${updated} thumbnail üretildi → posts.json güncellendi`);

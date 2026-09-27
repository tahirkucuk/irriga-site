/**
 * feed-sitemap-uret.mjs — posts.json'dan feed.xml üretir,
 * sitemap.xml'e eksik blog URL'lerini idempotent ekler.
 * Kullanım: node yerel-araclar/feed-sitemap-uret.mjs
 */
import { readFileSync, writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __dir = dirname(fileURLToPath(import.meta.url));
const SITE  = join(__dir, '..');
const BASE  = 'https://irriga.com.tr';
const BUGUN = new Date().toISOString().slice(0, 10);

function guvenliTarih(v) {
  return (/^\d{4}-\d{2}-\d{2}$/.test(v) && v !== '0000-00-00' && !isNaN(Date.parse(v))) ? v : BUGUN;
}

function esc(s) {
  return String(s || '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
}

function rfc822(dateStr) {
  const d   = new Date((dateStr || '1970-01-01') + 'T09:00:00+03:00');
  const gun = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getUTCDay()];
  const ay  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][
    parseInt((dateStr || '1970-01-01').slice(5, 7), 10) - 1];
  const dd   = (dateStr || '1970-01-01').slice(8, 10);
  const yyyy = (dateStr || '1970-01-01').slice(0, 4);
  return `${gun}, ${dd} ${ay} ${yyyy} 09:00:00 +0300`;
}

const posts  = JSON.parse(readFileSync(join(SITE, 'posts.json'), 'utf8'));
const sirali = [...posts].sort((a, b) =>
  guvenliTarih(b.tarih_iso).localeCompare(guvenliTarih(a.tarih_iso)));

// ── feed.xml (tamamen yeniden üret) ──
const now   = new Date().toUTCString().replace('GMT', '+0000');
const items = sirali.slice(0, 30).map(p => `  <item>
    <title>${esc(p.baslik)}</title>
    <link>${BASE}/${esc(p.url)}</link>
    <guid>${BASE}/${esc(p.url)}</guid>
    <pubDate>${rfc822(guvenliTarih(p.tarih_iso))}</pubDate>
    <description>${esc(p.ozet)}</description>
    <category>${esc(p.kategori)}</category>
  </item>`).join('\n');

const feed = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title>Irriga Sulama Sistemleri Blog</title>
  <link>${BASE}/blog.html</link>
  <description>Sulama mühendisliği, damlama sistemleri ve tarımsal otomasyon rehberleri</description>
  <language>tr</language>
  <lastBuildDate>${now}</lastBuildDate>
  <atom:link href="${BASE}/feed.xml" rel="self" type="application/rss+xml"/>
${items}
</channel>
</rss>
`;
writeFileSync(join(SITE, 'feed.xml'), feed);
console.log(`feed.xml üretildi: ${Math.min(sirali.length, 30)} makale`);

// ── sitemap.xml (eksik blog URL'lerini ekle, mevcutları koru) ──
let sitemap = readFileSync(join(SITE, 'sitemap.xml'), 'utf8');
const mevcut = new Set(
  [...sitemap.matchAll(/<loc>https:\/\/irriga\.com\.tr\/([^<]+)<\/loc>/g)].map(m => m[1])
);
const eksik = sirali.filter(p => !mevcut.has(p.url));
if (eksik.length) {
  const bloklar = eksik.map(p => `  <url>
    <loc>${BASE}/${esc(p.url)}</loc>
    <lastmod>${guvenliTarih(p.tarih_iso)}</lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.7</priority>
  </url>`).join('\n');
  sitemap = sitemap.replace('</urlset>', `${bloklar}\n</urlset>`);
  writeFileSync(join(SITE, 'sitemap.xml'), sitemap);
  console.log(`sitemap.xml: ${eksik.length} yeni URL eklendi.`);
} else {
  console.log('sitemap.xml: güncelleme gerekmedi.');
}

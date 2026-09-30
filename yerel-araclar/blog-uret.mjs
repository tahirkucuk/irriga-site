/**
 * blog-uret.mjs — blog/*.html dosyalarını tarayarak posts.json'u yeniden üretir,
 * blog.html'deki kart grid'ini senkronlar.
 * Kullanım: node yerel-araclar/blog-uret.mjs
 */
import { readFileSync, writeFileSync, readdirSync, existsSync } from 'fs';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __dir = dirname(fileURLToPath(import.meta.url));
const SITE  = join(__dir, '..');

const AYLAR_FULL  = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
const AYLAR_SHORT = ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
const BUGUN = new Date().toISOString().slice(0, 10);

const KATEGORI_GORSEL = {
  // Türkçe kategoriler
  'Rehber':            { emoji: '📖', from: '#e7f5ec', to: '#c8e6d0' },
  'Sera':              { emoji: '🌿', from: '#e8f5e9', to: '#a5d6a7' },
  'Fertigasyon':       { emoji: '🌱', from: '#f9fbe7', to: '#dcedc8' },
  'Su Tasarrufu':      { emoji: '💦', from: '#e3f2fd', to: '#bbdefb' },
  'Otomasyon':         { emoji: '⚙️', from: '#ede7f6', to: '#b39ddb' },
  'Bakım':             { emoji: '🔧', from: '#fff3e0', to: '#ffcc80' },
  'Devlet Destekleri': { emoji: '📋', from: '#fce4ec', to: '#f48fb1' },
  'Karşılaştırma':     { emoji: '⚖️', from: '#f3e5f5', to: '#b39ddb' },
  'Meyve Bahçeleri':   { emoji: '🌿', from: '#fff8e1', to: '#ffe082' },
  'Tarla Bitkileri':   { emoji: '🌾', from: '#f1f8e9', to: '#aed581' },
  'Sistem Tasarımı':   { emoji: '📐', from: '#e0f2f1', to: '#80cbc4' },
  'Enerji':            { emoji: '⚡', from: '#fff9c4', to: '#fff176' },
  'Su Kalitesi':       { emoji: '🔬', from: '#e1f5fe', to: '#81d4fa' },
  'Sebze':             { emoji: '🥬', from: '#f1f8e9', to: '#aed581' },
  'Teknikler':         { emoji: '🔩', from: '#fafafa', to: '#e0e0e0' },
  // İngilizce karşılıklar
  'Guide':             { emoji: '📖', from: '#e7f5ec', to: '#c8e6d0' },
  'Greenhouse':        { emoji: '🌿', from: '#e8f5e9', to: '#a5d6a7' },
  'Fertigation':       { emoji: '🌱', from: '#f9fbe7', to: '#dcedc8' },
  'Water Savings':     { emoji: '💦', from: '#e3f2fd', to: '#bbdefb' },
  'Automation':        { emoji: '⚙️', from: '#ede7f6', to: '#b39ddb' },
  'Maintenance':       { emoji: '🔧', from: '#fff3e0', to: '#ffcc80' },
  'Government Grants': { emoji: '📋', from: '#fce4ec', to: '#f48fb1' },
  'Comparison':        { emoji: '⚖️', from: '#f3e5f5', to: '#b39ddb' },
  'Orchards':          { emoji: '🌿', from: '#fff8e1', to: '#ffe082' },
  'Field Crops':       { emoji: '🌾', from: '#f1f8e9', to: '#aed581' },
  'System Design':     { emoji: '📐', from: '#e0f2f1', to: '#80cbc4' },
  'Energy':            { emoji: '⚡', from: '#fff9c4', to: '#fff176' },
  'Water Quality':     { emoji: '🔬', from: '#e1f5fe', to: '#81d4fa' },
  'Vegetables':        { emoji: '🥬', from: '#f1f8e9', to: '#aed581' },
  'Techniques':        { emoji: '🔩', from: '#fafafa', to: '#e0e0e0' },
};
const DEFAULT_GORSEL = { emoji: '💧', from: '#e7f5ec', to: '#c8e6d0' };

function gorsel(kategori) {
  return KATEGORI_GORSEL[kategori] || DEFAULT_GORSEL;
}

function meta(h, adlar) {
  for (const a of adlar) {
    const m = h.match(new RegExp('<meta[^>]*(?:name|property)="' + a + '"[^>]*content="([^"]*)"')) ||
              h.match(new RegExp('<meta[^>]*content="([^"]*)"[^>]*(?:name|property)="' + a + '"'));
    if (m) return m[1].trim();
  }
  return '';
}

// Mevcut posts.json'dan slug→kategori ve slug→kapak tablosu yap (geriye dönük uyumluluk)
const postsPath = join(SITE, 'posts.json');
const mevcutPosts = JSON.parse(readFileSync(postsPath, 'utf8'));
const slugToKat   = {};
const slugToKapak = {};
for (const p of mevcutPosts) {
  const s = (p.url || '').replace('blog/', '').replace('.html', '');
  if (p.kategori) slugToKat[s]   = p.kategori;
  if (p.kapak)    slugToKapak[s] = p.kapak;
}

const dosyalar = readdirSync(join(SITE, 'blog'))
  .filter(f => f.endsWith('.html') && f !== '_sablon.html');

const posts = [];
for (const f of dosyalar) {
  const h    = readFileSync(join(SITE, 'blog', f), 'utf8');
  const slug = f.replace('.html', '');

  const baslik = (meta(h, ['og:title']) ||
    (h.match(/<title>([^<|]+)/) || [])[1] || '').replace(/\s*\|.*$/, '').trim();
  if (!baslik) continue;

  const ozet     = meta(h, ['description']);
  const kategori = meta(h, ['article:section']) || slugToKat[slug] || 'Rehber';

  // Tarih: meta varsa al, yoksa JSON-LD datePublished, yoksa git first-commit, yoksa bugün
  let tarih_iso = meta(h, ['article:published_time']);
  if (!tarih_iso) {
    const m = h.match(/"datePublished"\s*:\s*"(\d{4}-\d{2}-\d{2})"/);
    tarih_iso = m ? m[1] : '';
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(tarih_iso) || tarih_iso === '0000-00-00') {
    try {
      tarih_iso = execSync(
        `git log --diff-filter=A --format=%as -- "blog/${f}" 2>/dev/null | tail -1`,
        { encoding: 'utf8', cwd: SITE }
      ).trim();
    } catch { /* ignore */ }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(tarih_iso)) tarih_iso = BUGUN;
  }

  const [y, mo, d] = tarih_iso.split('-');
  const tarih      = `${+d} ${AYLAR_SHORT[+mo - 1]} ${y}`;
  const tarih_full = `${+d} ${AYLAR_FULL[+mo - 1]} ${y}`;
  const kapak      = slugToKapak[slug] || null;

  posts.push({ url: `blog/${slug}.html`, baslik, ozet, kategori, tarih, tarih_iso, kapak, _tarih_full: tarih_full });
}

posts.sort((a, b) => b.tarih_iso.localeCompare(a.tarih_iso));

// posts.json'a yaz (_tarih_full geçici alanını çıkar)
const jsonPosts = posts.map(({ _tarih_full, ...rest }) => rest);
writeFileSync(postsPath, JSON.stringify(jsonPosts, null, 2), 'utf8');
console.log(`posts.json güncellendi: ${posts.length} makale`);

// blog.html grid'ini güncelle
const esc = s => (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const kartlar = posts.map(p => {
  const g    = gorsel(p.kategori);
  const slug = p.url.replace('blog/', '').replace('.html', '');
  return `      <div class="blog-card" data-url="${esc(p.url)}">
        <div class="blog-thumb" style="background:linear-gradient(135deg,${g.from},${g.to});font-size:44px;">${g.emoji}</div>
        <div class="blog-body">
          <div class="blog-meta">
            <span class="blog-tag">${esc(p.kategori)}</span>
            <span class="blog-date">${esc(p._tarih_full)}</span>
          </div>
          <h3>${esc(p.baslik)}</h3>
          <p>${esc(p.ozet)}</p>
          <a href="${esc(p.url)}" class="blog-read">Devamını Oku →</a>
        </div>
      </div>`;
}).join('\n');

let blog = readFileSync(join(SITE, 'blog.html'), 'utf8');
const mk  = /(<!-- BLOG_ARTICLES_START -->)[\s\S]*?(<!-- BLOG_ARTICLES_END -->)/;
if (!mk.test(blog)) {
  console.error('HATA: BLOG_ARTICLES_START/END marker yok — durduruldu.');
  process.exit(1);
}
blog = blog.replace(mk, `$1\n${kartlar}\n      $2`);
writeFileSync(join(SITE, 'blog.html'), blog);
console.log(`blog.html: ${posts.length} kart güncellendi.`);

// TR→EN kategori çevirisi (EN blog kartlarında emoji eşlemesi için)
const KAT_TR_TO_EN = {
  'Rehber': 'Guide', 'Sera': 'Greenhouse', 'Fertigasyon': 'Fertigation',
  'Su Tasarrufu': 'Water Savings', 'Otomasyon': 'Automation', 'Bakım': 'Maintenance',
  'Devlet Destekleri': 'Government Grants', 'Karşılaştırma': 'Comparison',
  'Meyve Bahçeleri': 'Orchards', 'Tarla Bitkileri': 'Field Crops',
  'Sistem Tasarımı': 'System Design', 'Enerji': 'Energy',
  'Su Kalitesi': 'Water Quality', 'Sebze': 'Vegetables', 'Teknikler': 'Techniques',
};

// ── EN blog grid ─────────────────────────────────────────────────────────
const enBlogDir  = join(SITE, 'en', 'blog');
const enBlogHtml = join(SITE, 'en', 'blog.html');
const enPostsPath = join(SITE, 'en', 'posts.json');

if (existsSync(enBlogDir) && existsSync(enBlogHtml)) {
  const enDosyalar = readdirSync(enBlogDir)
    .filter(f => f.endsWith('.html') && f !== '_sablon.html');

  // en/posts.json'dan kapak tablosu
  const enMevcutPosts = existsSync(enPostsPath)
    ? JSON.parse(readFileSync(enPostsPath, 'utf8')) : [];
  const enSlugToKapak = {};
  for (const p of enMevcutPosts) {
    const s = (p.url || '').replace('en/blog/', '').replace('.html', '');
    if (p.kapak) enSlugToKapak[s] = p.kapak;
  }

  const enPosts = [];
  for (const f of enDosyalar) {
    const h = readFileSync(join(enBlogDir, f), 'utf8');
    const slug = f.replace('.html', '');

    const baslik = (meta(h, ['og:title']) ||
      (h.match(/<title>([^<|]+)/) || [])[1] || '').replace(/\s*\|.*$/, '').trim();
    if (!baslik) continue;

    const ozet    = meta(h, ['description']);
    // article:section önce EN'den al, yoksa TR'den al ve EN'e çevir
    const rawKat  = meta(h, ['article:section']) || slugToKat[slug] || '';
    const kategori = KAT_TR_TO_EN[rawKat] || rawKat;

    let tarih_iso = meta(h, ['article:published_time']);
    if (!tarih_iso) {
      const m = h.match(/"datePublished"\s*:\s*"(\d{4}-\d{2}-\d{2})"/);
      tarih_iso = m ? m[1] : BUGUN;
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(tarih_iso)) tarih_iso = BUGUN;

    const [y, mo, d] = tarih_iso.split('-');
    const tarih_full = `${+d} ${AYLAR_FULL[+mo - 1]} ${y}`;
    const kapak = enSlugToKapak[slug] || slugToKapak[slug] || null;

    enPosts.push({ url: `en/blog/${slug}.html`, baslik, ozet, kategori, tarih: tarih_full, tarih_iso, kapak, _tarih_full: tarih_full });
  }

  enPosts.sort((a, b) => b.tarih_iso.localeCompare(a.tarih_iso));

  // en/posts.json güncelle
  const enJsonPosts = enPosts.map(({ _tarih_full, ...rest }) => rest);
  writeFileSync(enPostsPath, JSON.stringify(enJsonPosts, null, 2), 'utf8');
  console.log(`en/posts.json güncellendi: ${enPosts.length} makale`);

  // en/blog.html grid güncelle
  const enKartlar = enPosts.map(p => {
    const g    = gorsel(p.kategori);
    return `      <div class="blog-card" data-url="${esc(p.url)}">
        <div class="blog-thumb" style="background:linear-gradient(135deg,${g.from},${g.to});font-size:44px;">${g.emoji}</div>
        <div class="blog-body">
          <div class="blog-meta">
            <span class="blog-tag">${esc(p.kategori || 'Guide')}</span>
            <span class="blog-date">${esc(p._tarih_full)}</span>
          </div>
          <h3>${esc(p.baslik)}</h3>
          <p>${esc(p.ozet)}</p>
          <a href="blog/${esc(p.url.replace('en/blog/', ''))}" class="blog-read">Read More →</a>
        </div>
      </div>`;
  }).join('\n');

  let enBlog = readFileSync(enBlogHtml, 'utf8');
  if (mk.test(enBlog)) {
    enBlog = enBlog.replace(mk, `$1\n${enKartlar}\n      $2`);
    writeFileSync(enBlogHtml, enBlog);
    console.log(`en/blog.html: ${enPosts.length} kart güncellendi.`);
  } else {
    console.warn('UYARI: en/blog.html içinde BLOG_ARTICLES_START/END marker yok — atlandı.');
  }
} else {
  console.log('en/ klasörü henüz oluşturulmamış — EN grid atlandı.');
}

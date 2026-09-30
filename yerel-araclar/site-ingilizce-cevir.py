#!/usr/bin/env python3
"""
site-ingilizce-cevir.py — irriga.com.tr tüm sayfalarını İngilizceye çevirir.
Sonuçları en/ (kök sayfalar) ve en/blog/ (blog makaleleri) altına yazar.
Hem TR hem EN sayfaların header'ına dil seçici ekler.

Kullanım:
  python3 yerel-araclar/site-ingilizce-cevir.py
"""
import os, re, sys, json, time
from pathlib import Path

# Repo kökü (script yerel-araclar/ altında)
ROOT = Path(__file__).parent.parent
sys.path.insert(0, str(ROOT / "scripts"))

from dotenv import load_dotenv
load_dotenv(ROOT / "scripts" / ".env")
import anthropic

CLIENT = anthropic.Anthropic(api_key=os.environ["ANTHROPIC_API_KEY"])

# ── Çevrilecek sayfalar ─────────────────────────────────────────────────
# kvkk.html atlanıyor (Türkiye'ye özgü yasal metin, EN versiyonu anlamsız)
ATLA = {"kvkk.html", "_sablon.html", "robots.txt"}

ROOT_PAGES = sorted(
    f for f in ROOT.glob("*.html")
    if f.name not in ATLA
)
BLOG_PAGES = sorted(
    f for f in (ROOT / "blog").glob("*.html")
    if f.name not in ATLA
)

# ── Yol düzeltme ────────────────────────────────────────────────────────
def fix_paths_root(html: str) -> str:
    """en/*.html için: assets/ → ../assets/, canonical/og url ekle."""
    html = re.sub(r'(href|src)="assets/', r'\1="../assets/', html)
    html = re.sub(r'(href|src)="(?!http|#|mailto|tel|\.\.)(.*?\.(?:css|js|png|jpg|svg|ico|webp))"',
                  lambda m: f'{m.group(1)}="../{m.group(2)}"', html)
    return html

def fix_paths_blog(html: str) -> str:
    """en/blog/*.html için: ../assets/ → ../../assets/"""
    html = re.sub(r'(href|src)="\.\./assets/', r'\1="../../assets/', html)
    html = re.sub(r'(href|src)="assets/', r'\1="../../assets/', html)
    return html

# ── Dil seçici HTML ─────────────────────────────────────────────────────
def lang_switcher(tr_href: str, en_href: str, active: str) -> str:
    tr_cls = "lang-btn active" if active == "tr" else "lang-btn"
    en_cls = "lang-btn active" if active == "en" else "lang-btn"
    return (f'<div class="lang-switch">'
            f'<a href="{tr_href}" class="{tr_cls}">TR</a>'
            f'<a href="{en_href}" class="{en_cls}">EN</a>'
            f'</div>')

def inject_switcher(html: str, switcher: str) -> str:
    """nav-cta'dan önce switcher'ı ekle; yoksa </nav> öncesine."""
    match = re.search(r'(<a [^>]*class="nav-cta")', html)
    if match:
        return html[:match.start()] + switcher + '\n    ' + html[match.start():]
    return html.replace('</nav>', f'{switcher}\n  </nav>', 1)

# ── Claude çevirisi ──────────────────────────────────────────────────────
SYSTEM = """You are translating a Turkish static HTML page to English for an irrigation engineering company.

Rules:
1. Translate ALL visible Turkish text (titles, descriptions, paragraphs, buttons, labels, alt text, meta content).
2. Keep ALL HTML tags, attributes, class names, IDs, href/src values EXACTLY unchanged.
3. Keep company name "Irriga" unchanged.
4. Keep ALL HTML comments EXACTLY unchanged (e.g. <!-- BLOG_ARTICLES_START --> must stay as-is).
5. Use proper English irrigation/agriculture terminology:
   sulama=irrigation, damla sulama=drip irrigation, yağmurlama=sprinkler,
   fertigasyon=fertigation, sera=greenhouse, tarla=field, hibe=grant/subsidy,
   proje=project, keşif=site survey, teklif=quote/proposal.
6. Keep phone numbers, emails, addresses unchanged.
7. "Ücretsiz keşif" → "Free site survey", "Projenizi konuşalım" → "Let's discuss your project".
8. Return ONLY the translated HTML — no markdown, no explanations."""

def translate(html: str, filename: str) -> str:
    print(f"  → Çevriliyor: {filename}", flush=True)
    for attempt in range(1, 4):
        try:
            resp = CLIENT.messages.create(
                model="claude-opus-4-8",
                max_tokens=16000,
                system=SYSTEM,
                messages=[{"role": "user", "content": html}],
            )
            result = resp.content[0].text.strip()
            result = re.sub(r'^```(?:html)?\s*', '', result)
            result = re.sub(r'\s*```$', '', result)
            return result
        except Exception as e:
            if attempt < 3 and "overloaded" in str(e).lower():
                print(f"  ⚠️  API yoğun, 30sn bekleniyor (deneme {attempt}/3)...", flush=True)
                time.sleep(30)
            else:
                raise

# ── hreflang meta ekleme ────────────────────────────────────────────────
def add_hreflang(html: str, tr_url: str, en_url: str) -> str:
    hreflang = (
        f'<link rel="alternate" hreflang="tr" href="{tr_url}">\n'
        f'<link rel="alternate" hreflang="en" href="{en_url}">\n'
        f'<link rel="alternate" hreflang="x-default" href="{tr_url}">\n'
    )
    return html.replace('</head>', hreflang + '</head>', 1)

BASE = "https://irriga.com.tr"

# ── Ana işlev ────────────────────────────────────────────────────────────
def process_root_page(src: Path, yeniden_cevir: bool = False):
    name = src.name
    en_dir = ROOT / "en"
    en_dir.mkdir(exist_ok=True)
    dst = en_dir / name

    if dst.exists() and not yeniden_cevir:
        print(f"  ⏭  Atlandı (zaten var): en/{name}")
        return

    html = src.read_text(encoding="utf-8")

    # EN sayfası için yolları düzelt
    en_html = fix_paths_root(html)

    # Dil seçici — en/*.html'de TR linki ../[sayfa].html
    sw = lang_switcher(tr_href=f"../{name}", en_href=name, active="en")
    en_html = inject_switcher(en_html, sw)

    # hreflang
    slug = name.replace(".html", "")
    tr_url = f"{BASE}/{name}" if name != "index.html" else f"{BASE}/"
    en_url = f"{BASE}/en/{name}"
    en_html = add_hreflang(en_html, tr_url, en_url)

    # html lang="tr" → lang="en", og:locale TR→EN
    en_html = en_html.replace('<html lang="tr">', '<html lang="en">', 1)
    en_html = en_html.replace('content="tr_TR"', 'content="en_US"')

    # canonical ve og:url → EN versiyonuna (doğrudan en_url kullan)
    en_html = re.sub(
        r'rel="canonical"\s+href="[^"]*"',
        f'rel="canonical" href="{en_url}"',
        en_html
    )
    en_html = re.sub(
        r'property="og:url"\s+content="[^"]*"',
        f'property="og:url" content="{en_url}"',
        en_html
    )

    # Çevir
    en_html = translate(en_html, name)
    dst.write_text(en_html, encoding="utf-8")

    # TR sayfasına dil seçici ekle (yoksa)
    if 'lang-switch' not in html:
        sw_tr = lang_switcher(tr_href=name, en_href=f"en/{name}", active="tr")
        html = inject_switcher(html, sw_tr)
        html = add_hreflang(html, tr_url, en_url)
        src.write_text(html, encoding="utf-8")
        print(f"  ✓ TR güncelendi: {name}")

    print(f"  ✅ EN oluşturuldu: en/{name}")
    time.sleep(1)  # rate limit


def process_blog_page(src: Path, yeniden_cevir: bool = False):
    name = src.name
    en_blog_dir = ROOT / "en" / "blog"
    en_blog_dir.mkdir(parents=True, exist_ok=True)
    dst = en_blog_dir / name

    if dst.exists() and not yeniden_cevir:
        print(f"  ⏭  Atlandı (zaten var): en/blog/{name}")
        return

    html = src.read_text(encoding="utf-8")

    en_html = fix_paths_blog(html)

    slug = name.replace(".html", "")
    sw = lang_switcher(tr_href=f"../../blog/{name}", en_href=name, active="en")
    en_html = inject_switcher(en_html, sw)

    tr_url = f"{BASE}/blog/{name}"
    en_url = f"{BASE}/en/blog/{name}"
    en_html = add_hreflang(en_html, tr_url, en_url)
    en_html = en_html.replace('<html lang="tr">', '<html lang="en">', 1)
    en_html = en_html.replace('content="tr_TR"', 'content="en_US"')

    # canonical ve og:url → EN versiyonuna (doğrudan en_url kullan)
    en_html = re.sub(
        r'rel="canonical"\s+href="[^"]*"',
        f'rel="canonical" href="{en_url}"',
        en_html
    )
    en_html = re.sub(
        r'property="og:url"\s+content="[^"]*"',
        f'property="og:url" content="{en_url}"',
        en_html
    )

    en_html = translate(en_html, f"blog/{name}")
    dst.write_text(en_html, encoding="utf-8")

    # TR blog sayfasına dil seçici ekle
    if 'lang-switch' not in html:
        sw_tr = lang_switcher(tr_href=f"../blog/{name}", en_href=f"../en/blog/{name}", active="tr")
        html = inject_switcher(html, sw_tr)
        html = add_hreflang(html, tr_url, en_url)
        src.write_text(html, encoding="utf-8")

    print(f"  ✅ EN blog: en/blog/{name}")
    time.sleep(1)


def build_en_posts_json():
    """posts.json'dan en/posts.json üret (başlık/özet Türkçe — otomasyon EN üretecek)."""
    posts = json.loads((ROOT / "posts.json").read_text(encoding="utf-8"))
    en_posts = []
    for p in posts:
        ep = dict(p)
        ep["url"] = "en/" + p["url"]
        en_posts.append(ep)
    en_dir = ROOT / "en"
    en_dir.mkdir(exist_ok=True)
    (en_dir / "posts.json").write_text(
        json.dumps(en_posts, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    print("  ✅ en/posts.json oluşturuldu")


if __name__ == "__main__":
    print(f"\n{'='*50}")
    print(f"Irriga — İngilizce Site Üretimi")
    print(f"Kök sayfalar: {len(ROOT_PAGES)}  |  Blog: {len(BLOG_PAGES)}")
    print(f"{'='*50}\n")

    print("📄 Kök sayfalar çevriliyor...")
    for page in ROOT_PAGES:
        process_root_page(page)

    print("\n📝 Blog makaleleri çevriliyor...")
    for page in BLOG_PAGES:
        process_blog_page(page)

    print("\n📋 en/posts.json üretiliyor...")
    build_en_posts_json()

    print(f"\n✅ Tamamlandı! en/ klasörü oluşturuldu.")
    print("Sonraki adım: blog-yayinla.yml ve content_agent.py güncellenecek.")

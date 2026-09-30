#!/usr/bin/env python3
"""
site-ingilizce-cevir.py — irriga.com.tr sayfalarını EN yapısına hazırlar.
API kullanmaz; yol düzeltme, hreflang, dil seçici ve lang nitelikleri ekler.
Metin çevirisi Claude Code oturumu içinde yapılır.

Kullanım:
  python3 yerel-araclar/site-ingilizce-cevir.py
"""
import re, json
from pathlib import Path

ROOT = Path(__file__).parent.parent

ATLA = {"kvkk.html", "_sablon.html", "robots.txt"}
ROOT_PAGES = sorted(f for f in ROOT.glob("*.html") if f.name not in ATLA)
BLOG_PAGES = sorted(f for f in (ROOT / "blog").glob("*.html") if f.name not in ATLA)
BASE = "https://irriga.com.tr"


def fix_paths_root(html):
    html = re.sub(r'(href|src)="assets/', r'\1="../assets/', html)
    html = re.sub(
        r'(href|src)="(?!http|#|mailto|tel|\.\.)(.*?\.(?:css|js|png|jpg|svg|ico|webp))"',
        lambda m: f'{m.group(1)}="../{m.group(2)}"', html
    )
    return html


def fix_paths_blog(html):
    html = re.sub(r'(href|src)="\.\./assets/', r'\1="../../assets/', html)
    html = re.sub(r'(href|src)="assets/', r'\1="../../assets/', html)
    return html


def lang_switcher(tr_href, en_href, active):
    tr_cls = "lang-btn active" if active == "tr" else "lang-btn"
    en_cls = "lang-btn active" if active == "en" else "lang-btn"
    return (f'<div class="lang-switch">'
            f'<a href="{tr_href}" class="{tr_cls}" title="Türkçe">🇹🇷</a>'
            f'<a href="{en_href}" class="{en_cls}" title="English">🇬🇧</a>'
            f'</div>')


def inject_switcher(html, switcher):
    m = re.search(r'(<a [^>]*class="nav-cta")', html)
    if m:
        return html[:m.start()] + switcher + '\n    ' + html[m.start():]
    return html.replace('</nav>', f'{switcher}\n  </nav>', 1)


def add_hreflang(html, tr_url, en_url):
    tags = (
        f'<link rel="alternate" hreflang="tr" href="{tr_url}">\n'
        f'<link rel="alternate" hreflang="en" href="{en_url}">\n'
        f'<link rel="alternate" hreflang="x-default" href="{tr_url}">\n'
    )
    return html.replace('</head>', tags + '</head>', 1)


def structural_en(html, name, is_blog=False):
    """Tüm yapısal dönüşümleri uygula (metin çevirisi hariç)."""
    if is_blog:
        en_html = fix_paths_blog(html)
        slug = name.replace(".html", "")
        sw = lang_switcher(f"../../blog/{name}", name, "en")
        tr_url = f"{BASE}/blog/{name}"
        en_url = f"{BASE}/en/blog/{name}"
    else:
        en_html = fix_paths_root(html)
        sw = lang_switcher(f"../{name}", name, "en")
        tr_url = f"{BASE}/{name}" if name != "index.html" else f"{BASE}/"
        en_url = f"{BASE}/en/{name}"

    en_html = inject_switcher(en_html, sw)
    en_html = add_hreflang(en_html, tr_url, en_url)
    en_html = en_html.replace('<html lang="tr">', '<html lang="en">', 1)
    en_html = en_html.replace('content="tr_TR"', 'content="en_US"')
    en_html = re.sub(r'rel="canonical"\s+href="[^"]*"',
                     f'rel="canonical" href="{en_url}"', en_html)
    en_html = re.sub(r'property="og:url"\s+content="[^"]*"',
                     f'property="og:url" content="{en_url}"', en_html)
    return en_html, tr_url, en_url


def process_root_page(src, yeniden=False):
    name = src.name
    dst = ROOT / "en" / name
    (ROOT / "en").mkdir(exist_ok=True)

    if dst.exists() and not yeniden:
        print(f"  ⏭  Atlandı: en/{name}")
        return False

    html = src.read_text(encoding="utf-8")
    en_html, tr_url, en_url = structural_en(html, name, is_blog=False)
    dst.write_text(en_html, encoding="utf-8")

    if 'lang-switch' not in html:
        sw_tr = lang_switcher(name, f"en/{name}", "tr")
        html = inject_switcher(html, sw_tr)
        html = add_hreflang(html, tr_url, en_url)
        src.write_text(html, encoding="utf-8")
        print(f"  ✓ TR güncellendi: {name}")

    print(f"  📝 Hazır (metin çevirisi bekliyor): en/{name}")
    return True


def process_blog_page(src, yeniden=False):
    name = src.name
    en_dir = ROOT / "en" / "blog"
    en_dir.mkdir(parents=True, exist_ok=True)
    dst = en_dir / name

    if dst.exists() and not yeniden:
        print(f"  ⏭  Atlandı: en/blog/{name}")
        return False

    html = src.read_text(encoding="utf-8")
    en_html, tr_url, en_url = structural_en(html, name, is_blog=True)
    dst.write_text(en_html, encoding="utf-8")

    if 'lang-switch' not in html:
        sw_tr = lang_switcher(f"../blog/{name}", f"../en/blog/{name}", "tr")
        html = inject_switcher(html, sw_tr)
        html = add_hreflang(html, tr_url, en_url)
        src.write_text(html, encoding="utf-8")

    print(f"  📝 Hazır (metin çevirisi bekliyor): en/blog/{name}")
    return True


def build_en_posts_json():
    posts = json.loads((ROOT / "posts.json").read_text(encoding="utf-8"))
    en_posts = [dict(p, url="en/" + p["url"]) for p in posts]
    (ROOT / "en" / "posts.json").write_text(
        json.dumps(en_posts, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    print("  ✅ en/posts.json oluşturuldu")


if __name__ == "__main__":
    print(f"\n{'='*50}")
    print(f"Irriga — EN Yapısal Hazırlık (API'siz)")
    print(f"Kök: {len(ROOT_PAGES)}  |  Blog: {len(BLOG_PAGES)}")
    print(f"{'='*50}\n")

    print("📄 Kök sayfalar...")
    new_root = sum(1 for p in ROOT_PAGES if process_root_page(p))

    print("\n📝 Blog makaleleri...")
    new_blog = sum(1 for p in BLOG_PAGES if process_blog_page(p))

    print("\n📋 en/posts.json...")
    build_en_posts_json()

    print(f"\n✅ Tamamlandı! {new_root} kök + {new_blog} blog hazırlandı.")
    if new_root + new_blog > 0:
        print("→ Metin çevirisi için Claude Code oturumunda çeviri yapılacak.")

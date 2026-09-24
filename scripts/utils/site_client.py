"""
Irriga Statik Site İstemcisi
HTML dosyaları, posts.json ve blog.html yönetimi.
"""
import json
import logging
from datetime import date
from pathlib import Path

logger = logging.getLogger(__name__)

TR_MONTHS_FULL = [
    "Ocak", "Şubat", "Mart", "Nisan", "Mayıs", "Haziran",
    "Temmuz", "Ağustos", "Eylül", "Ekim", "Kasım", "Aralık",
]
TR_MONTHS_SHORT = [
    "Oca", "Şub", "Mar", "Nis", "May", "Haz",
    "Tem", "Ağu", "Eyl", "Eki", "Kas", "Ara",
]


class SiteClient:
    def __init__(self):
        self.repo_root = Path(__file__).parent.parent.parent
        self.blog_dir = self.repo_root / "blog"
        self.posts_json = self.repo_root / "posts.json"
        self.blog_html = self.repo_root / "blog.html"
        self.sablon = self.blog_dir / "_sablon.html"

    # ─── OKUMA ───────────────────────────────────────────────────
    def get_existing_posts(self) -> list:
        return json.loads(self.posts_json.read_text(encoding="utf-8"))

    def get_existing_titles(self) -> list[str]:
        return [p["baslik"] for p in self.get_existing_posts()]

    def slug_exists(self, slug: str) -> bool:
        return (self.blog_dir / f"{slug}.html").exists()

    def test_connection(self) -> bool:
        ok = self.sablon.exists() and self.posts_json.exists() and self.blog_html.exists()
        if ok:
            logger.info("✅ Site dosyaları erişilebilir")
        else:
            logger.error("❌ Site dosyalarına erişilemiyor")
        return ok

    # ─── KAYDETME ────────────────────────────────────────────────
    def save_article(self, article: dict, topic: dict) -> dict:
        """Makaleyi kaydet: HTML + posts.json + blog.html güncelle."""
        today = date.today()
        tarih_iso = today.strftime("%Y-%m-%d")
        tarih_full = f"{today.day} {TR_MONTHS_FULL[today.month - 1]} {today.year}"
        tarih_short = f"{today.day} {TR_MONTHS_SHORT[today.month - 1]} {today.year}"
        slug = topic["slug"]

        # TOC HTML
        toc_items = "\n".join(
            f'            <li><a href="#{item["id"]}">{item["baslik"]}</a></li>'
            for item in article["toc"]
        )

        # Şablon doldur
        html = self.sablon.read_text(encoding="utf-8")
        html = html.replace("{{BASLIK}}", article["title"])
        html = html.replace("{{OZET}}", article["ozet"])
        html = html.replace("{{ANAHTAR_KELIMELER}}", article["anahtar_kelimeler_str"])
        html = html.replace("{{SLUG}}", slug)
        html = html.replace("{{TARIH_ISO}}", tarih_iso)
        html = html.replace("{{TARIH}}", tarih_full)
        html = html.replace("{{KATEGORI}}", topic["kategori"])
        html = html.replace("{{OKUMA_SURESI}}", str(article["okuma_suresi"]))
        html = html.replace("            {{TOC_ITEMS}}", toc_items)
        html = html.replace("          {{ARTICLE_BODY}}", article["article_body_html"])

        out_path = self.blog_dir / f"{slug}.html"
        out_path.write_text(html, encoding="utf-8")
        logger.info(f"✅ Makale yazıldı: {out_path.name}")

        # posts.json — en yeni başa
        posts = self.get_existing_posts()
        posts.insert(0, {
            "url": f"blog/{slug}.html",
            "baslik": article["title"],
            "ozet": article["ozet"],
            "kategori": topic["kategori"],
            "tarih": tarih_short,
            "tarih_iso": tarih_iso,
            "kapak": None,
        })
        self.posts_json.write_text(
            json.dumps(posts, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        logger.info("✅ posts.json güncellendi")

        # blog.html — kart en başa ekle
        card = f"""
      <div class="blog-card" data-url="blog/{slug}.html">
        <div class="blog-thumb" style="background:linear-gradient(135deg,{topic['gradient_from']},{topic['gradient_to']});font-size:44px;">{topic['emoji']}</div>
        <div class="blog-body">
          <div class="blog-meta">
            <span class="blog-tag">{topic['kategori']}</span>
            <span class="blog-date">{tarih_full}</span>
          </div>
          <h3>{article['title']}</h3>
          <p>{article['ozet']}</p>
          <a href="blog/{slug}.html" class="blog-read">Devamını Oku →</a>
        </div>
      </div>
"""
        blog_html = self.blog_html.read_text(encoding="utf-8")
        blog_html = blog_html.replace(
            "      <!-- BLOG_ARTICLES_START -->",
            f"      <!-- BLOG_ARTICLES_START -->{card}",
        )
        self.blog_html.write_text(blog_html, encoding="utf-8")
        logger.info("✅ blog.html güncellendi")

        url = f"https://irriga.com.tr/blog/{slug}.html"
        logger.info(f"🌐 URL: {url}")
        return {
            "slug": slug,
            "title": article["title"],
            "url": url,
            "tarih_iso": tarih_iso,
        }

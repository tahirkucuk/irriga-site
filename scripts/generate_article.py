#!/usr/bin/env python3
import anthropic
import json
import os
import re
import sys
from datetime import date
from pathlib import Path

REPO_ROOT = Path(__file__).parent.parent
TOPICS_FILE = Path(__file__).parent / "topics.json"
BLOG_DIR = REPO_ROOT / "blog"
POSTS_JSON = REPO_ROOT / "posts.json"
BLOG_HTML = REPO_ROOT / "blog.html"
SABLON = BLOG_DIR / "_sablon.html"

TR_MONTHS_FULL = [
    "Ocak", "Şubat", "Mart", "Nisan", "Mayıs", "Haziran",
    "Temmuz", "Ağustos", "Eylül", "Ekim", "Kasım", "Aralık"
]
TR_MONTHS_SHORT = [
    "Oca", "Şub", "Mar", "Nis", "May", "Haz",
    "Tem", "Ağu", "Eyl", "Eki", "Kas", "Ara"
]


def set_output(name: str, value: str):
    gho = os.environ.get("GITHUB_OUTPUT")
    if gho:
        with open(gho, "a", encoding="utf-8") as f:
            f.write(f"{name}={value}\n")
    print(f"[output] {name}={value}")


def main():
    topics_data = json.loads(TOPICS_FILE.read_text(encoding="utf-8"))
    pending = topics_data.get("pending", [])

    if not pending:
        print("⚠️  Konu listesi tükendi — topics.json'a yeni konular ekleyin.")
        set_output("article_generated", "no_topics")
        return

    topic = pending[0]
    today = date.today()
    tarih_iso = today.strftime("%Y-%m-%d")
    tarih_full = f"{today.day} {TR_MONTHS_FULL[today.month - 1]} {today.year}"
    tarih_short = f"{today.day} {TR_MONTHS_SHORT[today.month - 1]} {today.year}"

    print(f"📝 Makale oluşturuluyor: {topic['baslik']}")

    client = anthropic.Anthropic()

    prompt = f"""Sen irriga.com.tr için içerik yazan kıdemli bir sulama mühendisisin. Türk çiftçilere, tarım mühendislerine ve tarımsal girişimcilere yönelik profesyonel, SEO uyumlu blog makaleleri yazıyorsun.

Şu konuda Türkçe kapsamlı bir blog makalesi yaz: "{topic['baslik']}"

Anahtar kelimeler: {', '.join(topic['anahtar_kelimeler'])}
Kategori: {topic['kategori']}

SADECE geçerli bir JSON nesnesi döndür. Başka hiçbir metin, açıklama veya markdown bloğu ekleme.

Döndüreceğin JSON yapısı:
{{
  "ozet": "155 karakteri geçmeyen, arama motoru meta açıklaması",
  "anahtar_kelimeler_str": "virgülle ayrılmış 6-8 anahtar kelime",
  "okuma_suresi": 7,
  "toc": [
    {{"id": "bolum-1", "baslik": "Bölüm başlığı"}},
    {{"id": "bolum-2", "baslik": "Bölüm başlığı"}},
    {{"id": "bolum-3", "baslik": "Bölüm başlığı"}},
    {{"id": "bolum-4", "baslik": "Bölüm başlığı"}},
    {{"id": "bolum-5", "baslik": "Bölüm başlığı"}}
  ],
  "article_body_html": "<p>Giriş paragrafı...</p><h2 id=\\"bolum-1\\">Başlık</h2>..."
}}

article_body_html kuralları:
- Güçlü giriş paragrafıyla başla (h2 heading olmadan)
- Her bölüm <h2 id="bolum-N"> ile başlasın (toc id'leriyle birebir eşleşsin)
- 4-6 ana bölüm olsun
- En az bir: <div class="info-callout"><strong>💡 Uzman Tavsiyesi:</strong><p>...</p></div>
- En az bir <ul> veya karşılaştırma tablosu (<table class="compare-table">)
- Somut rakamlar kullan: yüzde, debi (L/saat), basınç (bar), maliyet tahmini
- Türkiye'ye özgü: iklim bölgeleri, devlet destekleri, yerli/bölgesel uygulamalar
- Geçerli HTML, inline style kesinlikle yok, 800-1200 kelime arası
"""

    message = client.messages.create(
        model="claude-opus-4-8",
        max_tokens=4096,
        messages=[{"role": "user", "content": prompt}]
    )

    raw = message.content[0].text.strip()
    # Strip possible markdown fences
    raw = re.sub(r"^```(?:json)?\s*", "", raw)
    raw = re.sub(r"\s*```$", "", raw)

    try:
        data = json.loads(raw)
    except json.JSONDecodeError as e:
        print(f"❌ API yanıtı JSON parse hatası: {e}")
        print(f"Yanıtın ilk 600 karakteri:\n{raw[:600]}")
        sys.exit(1)

    for key in ["ozet", "anahtar_kelimeler_str", "okuma_suresi", "toc", "article_body_html"]:
        if key not in data:
            print(f"❌ API yanıtında '{key}' anahtarı eksik")
            sys.exit(1)

    # Build TOC HTML
    toc_items = "\n".join(
        f'            <li><a href="#{item["id"]}">{item["baslik"]}</a></li>'
        for item in data["toc"]
    )

    # Fill template
    sablon = SABLON.read_text(encoding="utf-8")
    html = sablon
    html = html.replace("{{BASLIK}}", topic["baslik"])
    html = html.replace("{{OZET}}", data["ozet"])
    html = html.replace("{{ANAHTAR_KELIMELER}}", data["anahtar_kelimeler_str"])
    html = html.replace("{{SLUG}}", topic["slug"])
    html = html.replace("{{TARIH_ISO}}", tarih_iso)
    html = html.replace("{{TARIH}}", tarih_full)
    html = html.replace("{{KATEGORI}}", topic["kategori"])
    html = html.replace("{{OKUMA_SURESI}}", str(data["okuma_suresi"]))
    html = html.replace("            {{TOC_ITEMS}}", toc_items)
    html = html.replace("          {{ARTICLE_BODY}}", data["article_body_html"])

    out_path = BLOG_DIR / f"{topic['slug']}.html"
    out_path.write_text(html, encoding="utf-8")
    print(f"✅ Makale yazıldı: {out_path.name}")

    # Update posts.json (newest first)
    posts = json.loads(POSTS_JSON.read_text(encoding="utf-8"))
    posts.insert(0, {
        "url": f"blog/{topic['slug']}.html",
        "baslik": topic["baslik"],
        "ozet": data["ozet"],
        "kategori": topic["kategori"],
        "tarih": tarih_short,
        "tarih_iso": tarih_iso,
        "kapak": None
    })
    POSTS_JSON.write_text(json.dumps(posts, ensure_ascii=False, indent=2), encoding="utf-8")
    print("✅ posts.json güncellendi")

    # Update blog.html — insert card right after BLOG_ARTICLES_START
    card = f"""
      <div class="blog-card" data-url="blog/{topic['slug']}.html">
        <div class="blog-thumb" style="background:linear-gradient(135deg,{topic['gradient_from']},{topic['gradient_to']});font-size:44px;">{topic['emoji']}</div>
        <div class="blog-body">
          <div class="blog-meta">
            <span class="blog-tag">{topic['kategori']}</span>
            <span class="blog-date">{tarih_full}</span>
          </div>
          <h3>{topic['baslik']}</h3>
          <p>{data['ozet']}</p>
          <a href="blog/{topic['slug']}.html" class="blog-read">Devamını Oku →</a>
        </div>
      </div>
"""
    blog_html = BLOG_HTML.read_text(encoding="utf-8")
    blog_html = blog_html.replace(
        "      <!-- BLOG_ARTICLES_START -->",
        f"      <!-- BLOG_ARTICLES_START -->{card}"
    )
    BLOG_HTML.write_text(blog_html, encoding="utf-8")
    print("✅ blog.html güncellendi")

    # Mark topic as published
    topics_data["pending"].pop(0)
    topics_data.setdefault("published", []).append({
        **topic,
        "tarih_iso": tarih_iso
    })
    TOPICS_FILE.write_text(json.dumps(topics_data, ensure_ascii=False, indent=2), encoding="utf-8")
    print("✅ topics.json güncellendi")

    url = f"https://irriga.com.tr/blog/{topic['slug']}.html"
    print(f"🌐 URL: {url}")

    set_output("article_generated", "true")
    set_output("article_slug", topic["slug"])
    set_output("article_url", url)
    # Write title to a file to avoid encoding issues in shell
    (REPO_ROOT / ".article_title.txt").write_text(topic["baslik"], encoding="utf-8")


if __name__ == "__main__":
    main()

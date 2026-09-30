"""
İçerik Üretim Ajanı — Irriga Sulama Sistemleri

Akış:
  1. Konuyu araştır (web search)
  2. Anahtar kelime analizi yap
  3. Kategori belirle
  4. Araştırmaya dayalı HTML makale yaz
  5. Tekrar kontrolü yap
  6. Statik siteye kaydet (TR + EN)
  7. E-posta bildirimi gönder
"""
import json
import logging
import re
from pathlib import Path
from typing import Optional

import anthropic

from config.settings import (
    ANTHROPIC_API_KEY,
    CLAUDE_MODEL,
    SIMILARITY_THRESHOLD,
    get_site_config,
)

logger = logging.getLogger(__name__)


class ContentAgent:
    def __init__(self, site_client, email_notifier, site_key: str = None):
        self.site = site_client
        self.email = email_notifier
        self.claude = anthropic.Anthropic(api_key=ANTHROPIC_API_KEY)
        self.site_config = get_site_config(site_key)
        self.categories = self.site_config["categories"]
        self.content_settings = self.site_config["content_settings"]

    # ─── ANA AKIŞ ────────────────────────────────────────────────
    def create_article(self, topic: dict) -> Optional[dict]:
        """Tam akış: araştır → yaz → kontrol → kaydet → bildir."""
        try:
            logger.info(f"📝 Makale üretimi başladı: {topic['baslik']}")

            # 1. Tekrar kontrolü (slug ve başlık)
            if self.site.slug_exists(topic["slug"]):
                logger.warning(f"⚠️  Slug zaten var: {topic['slug']} — atlandı")
                return None

            # 2. Araştırma
            research = self._research_topic(topic["baslik"])
            logger.info(f"🔍 Araştırma tamamlandı")

            # 3. Anahtar kelime analizi
            kw_data = self._analyze_keywords(topic["baslik"], topic.get("anahtar_kelimeler", []))
            logger.info(f"🔑 Odak kelime: {kw_data.get('primary', topic['baslik'])}")

            # 4. Kategori — topics.json'dan al, gerekirse otomatik belirle
            kategori_slug = self._slug_for_kategori(topic["kategori"])

            # 5. Makale yaz
            article = self._write_article(topic, research, kw_data, kategori_slug)
            logger.info(f"✍️  Makale yazıldı: {article['title']}")

            # 6. Semantik tekrar kontrolü
            if self._check_duplicate(article["title"]):
                logger.warning(f"⚠️  Başlık mevcut içerikle çok benzer — atlandı")
                self.email.send_failure(
                    f"Tekrar yazı tespit edildi: '{article['title']}' mevcut bir makaleyle benziyor."
                )
                return None

            # 7. Kaydet (TR)
            saved = self.site.save_article(article, topic)
            logger.info(f"💾 Kaydedildi: {saved['url']}")

            # 8. İngilizce versiyonu kaydet
            try:
                en_saved = self._translate_and_save_en(topic["slug"], saved["tarih_iso"])
                if en_saved:
                    logger.info(f"🇬🇧 EN kaydedildi: en/blog/{topic['slug']}.html")
            except Exception as e_en:
                logger.warning(f"⚠️  EN çeviri başarısız (ana akış devam ediyor): {e_en}")

            # 9. Başarı bildirimi
            self.email.send_success(
                title=article["title"],
                url=saved["url"],
                kategori=topic["kategori"],
                okuma_suresi=article["okuma_suresi"],
            )

            return saved

        except Exception as e:
            logger.error(f"❌ İçerik üretim hatası: {e}", exc_info=True)
            self.email.send_failure(str(e))
            return None

    # ─── ARAŞTIRMA ───────────────────────────────────────────────
    def _research_topic(self, topic: str) -> str:
        """Web search ile konu araştır."""
        try:
            response = self.claude.messages.create(
                model=CLAUDE_MODEL,
                max_tokens=3000,
                tools=[{"type": "web_search_20250305", "name": "web_search"}],
                messages=[{
                    "role": "user",
                    "content": f"""Sulama mühendisliği araştırmacısı olarak şu konuyu araştır:

KONU: {topic}

Araştırma odak noktaları:
1. Türkiye'deki güncel uygulamalar ve tarımsal koşullar
2. Teknik veriler: debi (L/saat), basınç (bar), verim artış yüzdeleri
3. Devlet destekleri, hibe programları (TKDK, Tarım Bakanlığı, IPARD)
4. Pratik kurulum ve bakım ipuçları
5. Karşılaştırmalı veriler (su tasarrufu, maliyet, ROI)

Araştırma sonuçlarını şu başlıklarla ver:
- TÜRKİYE KOŞULLARI: ...
- TEKNİK VERİLER: ...
- DEVLET DESTEKLERİ: ...
- PRATİK İPUÇLARI: ...
- ANAHTAR BULGULAR: ...""",
                }],
            )
            return "\n".join(
                block.text for block in response.content if hasattr(block, "text")
            )
        except Exception as e:
            logger.warning(f"⚠️  Web search başarısız ({e}) — araştırmasız devam ediliyor")
            return f"Konu: {topic}\n(Araştırma yapılamadı — genel bilgiye dayanılarak yazılacak)"

    # ─── ANAHTAR KELİME ANALİZİ ─────────────────────────────────
    def _analyze_keywords(self, topic: str, manual_keywords: list = None) -> dict:
        response = self.claude.messages.create(
            model=CLAUDE_MODEL,
            max_tokens=800,
            messages=[{
                "role": "user",
                "content": f"""Tarımsal sulama SEO uzmanı olarak şu konu için anahtar kelime analizi yap:

KONU: {topic}
{f"MEVCUT ANAHTAR KELİMELER: {', '.join(manual_keywords)}" if manual_keywords else ""}

SADECE geçerli JSON döndür:
{{
    "primary": "ana odak anahtar kelime",
    "secondary": ["ikincil 1", "ikincil 2", "ikincil 3"],
    "long_tail": ["uzun kuyruk 1", "uzun kuyruk 2"],
    "questions": ["sık sorulan soru 1", "sık sorulan soru 2"]
}}""",
            }],
        )
        text = re.sub(r"```(?:json)?\s*", "", response.content[0].text.strip())
        text = re.sub(r"\s*```", "", text)
        try:
            return json.loads(text)
        except json.JSONDecodeError:
            return {"primary": topic, "secondary": [], "long_tail": [], "questions": []}

    # ─── KATEGORİ ────────────────────────────────────────────────
    def _slug_for_kategori(self, kategori_display: str) -> str:
        for slug, name in self.categories.items():
            if name == kategori_display:
                return slug
        return "rehber"

    # ─── MAKALE YAZMA ────────────────────────────────────────────
    def _write_article(self, topic: dict, research: str, kw_data: dict, category_slug: str) -> dict:
        cs = self.content_settings
        primary_kw = kw_data.get("primary", topic["baslik"])
        secondary = ", ".join(kw_data.get("secondary", []))
        long_tail = ", ".join(kw_data.get("long_tail", []))
        questions = kw_data.get("questions", [])

        prompt = f"""Sen "{cs['author_name']}" adına yazan kıdemli bir sulama mühendisisin.
Hedef kitle: {cs['target_audience']}
Yazı tonu: {cs['tone']}

Aşağıdaki araştırmayı kullanarak SEO optimize, teknik bir blog makalesi yaz.

═══ ARAŞTIRMA ═══
{research}

═══ ANAHTAR KELİMELER ═══
Odak: {primary_kw}
İkincil: {secondary}
Uzun Kuyruk: {long_tail}

═══ YAZIM KURALLARI ═══

1. YAPI VE SEO:
   - İlk 100 kelimede odak anahtar kelimeyi kullan
   - H2 başlıklarında ikincil anahtar kelimeleri dağıt
   - Meta description: 155 karakter, pratik fayda vurgula

2. İÇERİK:
   - {cs['min_word_count']}-{cs['max_word_count']} kelime
   - Somut rakamlar: debi (L/saat), basınç (bar), su tasarrufu (%), maliyet (₺)
   - En az 1 karşılaştırma tablosu veya madde listesi
   - En az 1 uzman tavsiyesi kutusu
   - Türkiye'ye özgü koşullar, devlet destekleri dahil et
   - SSS bölümü ekle (şu sorulardan esinlen: {questions})
   - Sonunda CTA: {cs['cta_type']}

3. İNSANLAŞTIRMA:
   - Doğal, samimi Türkçe — "sen" dili
   - Kısa-uzun cümle karışımı, retorik sorular
   - Robotik geçişlerden kaçın ("ilk olarak, ikinci olarak")

═══ ÇIKTI FORMATI (SADECE JSON) ═══
{{
    "title": "SEO optimize başlık",
    "ozet": "Meta description (max 155 karakter)",
    "anahtar_kelimeler_str": "virgülle ayrılmış 6-8 anahtar kelime",
    "okuma_suresi": 7,
    "toc": [
        {{"id": "bolum-1", "baslik": "Başlık 1"}},
        {{"id": "bolum-2", "baslik": "Başlık 2"}},
        {{"id": "bolum-3", "baslik": "Başlık 3"}},
        {{"id": "bolum-4", "baslik": "Başlık 4"}},
        {{"id": "bolum-5", "baslik": "Başlık 5"}}
    ],
    "article_body_html": "<p>Giriş paragrafı...</p><h2 id=\\"bolum-1\\">...</h2>..."
}}

article_body_html kuralları:
- Başlıksız giriş paragrafıyla başla
- Her bölüm <h2 id="bolum-N"> ile başlasın
- <div class="info-callout"><strong>💡 Uzman Tavsiyesi:</strong><p>...</p></div> ekle
- SSS: <h2 id="bolum-sss">Sıkça Sorulan Sorular</h2> + dl/dt/dd yapısı
- Geçerli HTML, inline style yok
"""

        response = self.claude.messages.create(
            model=CLAUDE_MODEL,
            max_tokens=8000,
            messages=[{"role": "user", "content": prompt}],
        )

        raw = re.sub(r"^```(?:json)?\s*", "", response.content[0].text.strip())
        raw = re.sub(r"\s*```$", "", raw)

        try:
            article = json.loads(raw)
        except json.JSONDecodeError as e:
            logger.error(f"❌ JSON parse hatası: {e}\nYanıt başı: {raw[:400]}")
            raise

        for key in ["title", "ozet", "anahtar_kelimeler_str", "okuma_suresi", "toc", "article_body_html"]:
            if key not in article:
                raise ValueError(f"API yanıtında '{key}' eksik")

        return article

    # ─── TEKRAR KONTROLÜ ─────────────────────────────────────────
    def _check_duplicate(self, title: str) -> bool:
        existing = self.site.get_existing_titles()
        title_lower = title.lower()

        for existing_title in existing:
            if self._jaccard(title_lower, existing_title.lower()) > SIMILARITY_THRESHOLD:
                return True

        if not existing:
            return False

        # Claude ile semantik kontrol
        response = self.claude.messages.create(
            model=CLAUDE_MODEL,
            max_tokens=50,
            messages=[{
                "role": "user",
                "content": f"""Yeni başlık mevcut başlıklardan biriyle aynı konuyu işliyor mu?
SADECE "EVET" veya "HAYIR" yaz.

YENİ: {title}

MEVCUTLAR:
{chr(10).join(f'- {t}' for t in existing[:30])}""",
            }],
        )
        return "EVET" in response.content[0].text.strip().upper()

    # ─── EN ÇEVİRİ ───────────────────────────────────────────────
    _EN_SYSTEM = """You are translating a Turkish static HTML page to English for an irrigation engineering company.

Rules:
1. Translate ALL visible Turkish text (titles, descriptions, paragraphs, buttons, labels, alt text, meta content).
2. Keep ALL HTML tags, attributes, class names, IDs, href/src values EXACTLY unchanged.
3. Keep company name "Irriga" unchanged.
4. Keep HTML comments EXACTLY unchanged.
5. Use proper English irrigation/agriculture terminology:
   sulama=irrigation, damla sulama=drip irrigation, yağmurlama=sprinkler,
   fertigasyon=fertigation, sera=greenhouse, tarla=field, hibe=grant/subsidy,
   proje=project, keşif=site survey, teklif=quote/proposal.
6. Keep phone numbers, emails, addresses unchanged.
7. "Ücretsiz keşif" → "Free site survey", "Projenizi konuşalım" → "Let's discuss your project".
8. Return ONLY the translated HTML — no markdown, no explanations."""

    def _translate_and_save_en(self, slug: str, tarih_iso: str) -> Optional[dict]:
        repo_root = Path(self.site.repo_root)
        tr_path = repo_root / "blog" / f"{slug}.html"
        if not tr_path.exists():
            return None

        html = tr_path.read_text(encoding="utf-8")

        # Yol düzeltmeleri: blog/slug.html → en/blog/slug.html seviyesine
        html = re.sub(r'(href|src)="\.\./assets/', r'\1="../../assets/', html)
        html = re.sub(r'(href|src)="assets/', r'\1="../../assets/', html)

        # Canonical ve og:url → EN versiyonuna çevir
        html = re.sub(
            r'(href="https://irriga\.com\.tr/)(blog/)',
            r'\1en/blog/', html
        )
        html = re.sub(
            r'(content="https://irriga\.com\.tr/)(blog/)',
            r'\1en/blog/', html
        )

        # og:locale TR → EN, html lang
        html = html.replace('content="tr_TR"', 'content="en_US"')
        html = html.replace('<html lang="tr">', '<html lang="en">')

        # hreflang meta ekle
        tr_url = f"https://irriga.com.tr/blog/{slug}.html"
        en_url = f"https://irriga.com.tr/en/blog/{slug}.html"
        hreflang = (
            f'<link rel="alternate" hreflang="tr" href="{tr_url}">\n'
            f'<link rel="alternate" hreflang="en" href="{en_url}">\n'
            f'<link rel="alternate" hreflang="x-default" href="{tr_url}">\n'
        )
        html = html.replace('</head>', hreflang + '</head>', 1)

        # Dil seçici ekle
        switcher = (
            f'<div class="lang-switch">'
            f'<a href="../../blog/{slug}.html" class="lang-btn" title="Türkçe">🇹🇷</a>'
            f'<a href="{slug}.html" class="lang-btn active" title="English">🇬🇧</a>'
            f'</div>'
        )
        nav_match = re.search(r'(<a [^>]*class="nav-cta")', html)
        if nav_match:
            html = html[:nav_match.start()] + switcher + '\n    ' + html[nav_match.start():]
        else:
            html = html.replace('</nav>', f'{switcher}\n  </nav>', 1)

        # TR sayfasına da dil seçici ekle (yoksa)
        tr_html = tr_path.read_text(encoding="utf-8")
        if 'lang-switch' not in tr_html:
            tr_switcher = (
                f'<div class="lang-switch">'
                f'<a href="../blog/{slug}.html" class="lang-btn active" title="Türkçe">🇹🇷</a>'
                f'<a href="../en/blog/{slug}.html" class="lang-btn" title="English">🇬🇧</a>'
                f'</div>'
            )
            tr_nav = re.search(r'(<a [^>]*class="nav-cta")', tr_html)
            if tr_nav:
                tr_html = tr_html[:tr_nav.start()] + tr_switcher + '\n    ' + tr_html[tr_nav.start():]
            else:
                tr_html = tr_html.replace('</nav>', f'{tr_switcher}\n  </nav>', 1)
            tr_path.write_text(tr_html, encoding="utf-8")

        # Claude ile çevir
        resp = self.claude.messages.create(
            model="claude-opus-4-8",
            max_tokens=16000,
            system=self._EN_SYSTEM,
            messages=[{"role": "user", "content": html}],
        )
        en_html = resp.content[0].text.strip()
        en_html = re.sub(r'^```(?:html)?\s*', '', en_html)
        en_html = re.sub(r'\s*```$', '', en_html)

        # EN başlık ve özet çıkar
        title_m = re.search(r'<title>([^<|]+)', en_html)
        en_title = (title_m.group(1) if title_m else slug).strip()
        desc_m = re.search(r'<meta\s+name="description"\s+content="([^"]*)"', en_html)
        en_ozet = (desc_m.group(1) if desc_m else "").strip()

        # Kaydet
        en_blog_dir = repo_root / "en" / "blog"
        en_blog_dir.mkdir(parents=True, exist_ok=True)
        (en_blog_dir / f"{slug}.html").write_text(en_html, encoding="utf-8")

        # en/posts.json güncelle
        en_posts_path = repo_root / "en" / "posts.json"
        if en_posts_path.exists():
            en_posts = json.loads(en_posts_path.read_text(encoding="utf-8"))
        else:
            en_posts = []

        en_url_rel = f"en/blog/{slug}.html"
        en_posts = [p for p in en_posts if p.get("url") != en_url_rel]
        en_posts.insert(0, {
            "url": en_url_rel,
            "baslik": en_title,
            "ozet": en_ozet,
            "kategori": "",
            "tarih": tarih_iso,
            "tarih_iso": tarih_iso,
            "kapak": None,
        })
        en_posts_path.write_text(
            json.dumps(en_posts, ensure_ascii=False, indent=2), encoding="utf-8"
        )

        return {"url": en_url_rel, "title": en_title}

    @staticmethod
    def _jaccard(a: str, b: str) -> float:
        wa, wb = set(a.split()), set(b.split())
        if not wa or not wb:
            return 0.0
        return len(wa & wb) / len(wa | wb)

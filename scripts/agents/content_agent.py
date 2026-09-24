"""
İçerik Üretim Ajanı — Irriga Sulama Sistemleri

Akış:
  1. Konuyu araştır (web search)
  2. Anahtar kelime analizi yap
  3. Kategori belirle
  4. Araştırmaya dayalı HTML makale yaz
  5. Tekrar kontrolü yap
  6. Statik siteye kaydet
  7. E-posta bildirimi gönder
"""
import json
import logging
import re
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

            # 7. Kaydet
            saved = self.site.save_article(article, topic)
            logger.info(f"💾 Kaydedildi: {saved['url']}")

            # 8. Başarı bildirimi
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

    @staticmethod
    def _jaccard(a: str, b: str) -> float:
        wa, wb = set(a.split()), set(b.split())
        if not wa or not wb:
            return 0.0
        return len(wa & wb) / len(wa | wb)

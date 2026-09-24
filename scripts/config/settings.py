"""
Irriga Sulama Sistemleri — Otomasyon Ayarları
"""
import os
from pathlib import Path
from dotenv import load_dotenv

load_dotenv(Path(__file__).parent.parent / ".env")

SITES = {
    "irriga": {
        "name": "Irriga Sulama Sistemleri",
        "url": "https://irriga.com.tr",
        "content_settings": {
            "min_word_count": 900,
            "max_word_count": 1300,
            "language": "tr",
            "target_audience": "Türk çiftçiler, tarım mühendisleri ve tarımsal girişimciler",
            "tone": "Kıdemli sulama mühendisi. Teknik ama anlaşılır. Somut veriler ve pratik öneriler.",
            "author_name": "Irriga Mühendislik",
            "cta_type": "ücretsiz keşif talebi",
        },
        "categories": {
            "rehber": "Rehber",
            "sera": "Sera",
            "fertigasyon": "Fertigasyon",
            "meyve-bahceleri": "Meyve Bahçeleri",
            "sebze": "Sebze",
            "otomasyon": "Otomasyon",
            "sistem-tasarimi": "Sistem Tasarımı",
            "su-kalitesi": "Su Kalitesi",
            "devlet-destekleri": "Devlet Destekleri",
            "enerji": "Enerji",
            "bakim": "Bakım",
            "su-tasarrufu": "Su Tasarrufu",
            "tarla-bitkileri": "Tarla Bitkileri",
            "teknikler": "Teknikler",
            "cevre": "Çevre",
        },
    }
}

DEFAULT_SITE = "irriga"

def get_site_config(site_key: str = None) -> dict:
    key = site_key or DEFAULT_SITE
    if key not in SITES:
        raise ValueError(f"Bilinmeyen site: {key}. Mevcut: {list(SITES.keys())}")
    return SITES[key]

ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY", "")
CLAUDE_MODEL = "claude-opus-4-8"

GMAIL_USER = os.getenv("GMAIL_USER", "tahirkucuk@gmail.com")
GMAIL_APP_PASSWORD = os.getenv("GMAIL_APP_PASSWORD", "")
NOTIFY_TO = os.getenv("NOTIFY_TO", "tahirkucuk@gmail.com")

SIMILARITY_THRESHOLD = 0.80

#!/usr/bin/env python3
"""
Irriga Blog Otomasyon — Ana Çalıştırıcı

Kullanım:
  python main.py setup                  # Bağlantı ve dosya testi
  python main.py batch                  # topics.json'dan sıradaki konuyu yayınla
  python main.py write "konu başlığı"  # Belirli bir konu üret ve yayınla
"""
import sys
import json
import argparse
import logging
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from config.settings import SITES
from utils.site_client import SiteClient
from utils.email_notifier import EmailNotifier
from agents.content_agent import ContentAgent

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    handlers=[
        logging.StreamHandler(),
        logging.FileHandler(Path(__file__).parent / "otomasyon.log", encoding="utf-8"),
    ],
)
logger = logging.getLogger("main")

TOPICS_FILE = Path(__file__).parent / "topics.json"


def setup():
    site = SiteClient()
    email = EmailNotifier()
    config = SITES["irriga"]

    logger.info(f"🔧 {config['name']} kurulumu kontrol ediliyor...")

    if not site.test_connection():
        logger.error("❌ Site dosyalarına erişilemiyor!")
        return False

    posts = site.get_existing_posts()
    logger.info(f"✅ posts.json: {len(posts)} makale")

    topics_data = json.loads(TOPICS_FILE.read_text(encoding="utf-8"))
    pending = len(topics_data.get("pending", []))
    published = len(topics_data.get("published", []))
    logger.info(f"✅ Konu kuyruğu: {pending} bekliyor, {published} yayınlandı")
    logger.info("🎉 Kurulum hazır!")
    return True


def batch(github_output_file: str = None):
    topics_data = json.loads(TOPICS_FILE.read_text(encoding="utf-8"))
    pending = topics_data.get("pending", [])

    if not pending:
        logger.warning("⚠️  Konu listesi tükendi!")
        email = EmailNotifier()
        email.send_no_topics()
        _set_output(github_output_file, "article_generated", "no_topics")
        return

    topic = pending[0]
    site = SiteClient()
    email = EmailNotifier()
    agent = ContentAgent(site, email)

    result = agent.create_article(topic)

    if result:
        # Konuyu yayınlandı listesine taşı
        topics_data["pending"].pop(0)
        topics_data.setdefault("published", []).append({
            **topic,
            "tarih_iso": result["tarih_iso"],
        })
        TOPICS_FILE.write_text(
            json.dumps(topics_data, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        logger.info(f"✅ topics.json güncellendi")

        _set_output(github_output_file, "article_generated", "true")
        _set_output(github_output_file, "article_slug", result["slug"])
        _set_output(github_output_file, "article_url", result["url"])
        # Başlığı dosyaya yaz (encoding-safe)
        (Path(__file__).parent / ".article_title.txt").write_text(
            result["title"], encoding="utf-8"
        )
    else:
        _set_output(github_output_file, "article_generated", "false")
        sys.exit(1)


def write_topic(topic_str: str):
    """Serbest konuyla tek makale yaz (topics.json'a eklenmez)."""
    topic = {
        "slug": topic_str.lower().replace(" ", "-").replace("ş", "s").replace("ğ", "g")
                          .replace("ü", "u").replace("ö", "o").replace("ç", "c")
                          .replace("ı", "i")[:60],
        "baslik": topic_str,
        "kategori": "Rehber",
        "emoji": "📖",
        "gradient_from": "#e7f5ec",
        "gradient_to": "#c8e6d0",
        "anahtar_kelimeler": topic_str.lower().split()[:6],
    }
    site = SiteClient()
    email = EmailNotifier()
    agent = ContentAgent(site, email)
    result = agent.create_article(topic)
    if result:
        logger.info(f"✅ Yayınlandı: {result['url']}")
    else:
        logger.error("❌ Makale oluşturulamadı")
        sys.exit(1)


def _set_output(output_file: str, name: str, value: str):
    if output_file:
        with open(output_file, "a", encoding="utf-8") as f:
            f.write(f"{name}={value}\n")
    print(f"[output] {name}={value}")


def main():
    parser = argparse.ArgumentParser(description="Irriga Blog Otomasyon")
    parser.add_argument(
        "command",
        choices=["setup", "batch", "write"],
        help="Çalıştırılacak komut",
    )
    parser.add_argument("topic", nargs="*", help="write komutu için konu")
    parser.add_argument(
        "--github-output",
        default=None,
        help="GitHub Actions GITHUB_OUTPUT dosya yolu",
    )
    args = parser.parse_args()

    if args.command == "setup":
        setup()

    elif args.command == "batch":
        import os
        gho = args.github_output or os.environ.get("GITHUB_OUTPUT")
        batch(gho)

    elif args.command == "write":
        if not args.topic:
            topic_str = input("Konu girin: ").strip()
        else:
            topic_str = " ".join(args.topic)
        write_topic(topic_str)


if __name__ == "__main__":
    main()

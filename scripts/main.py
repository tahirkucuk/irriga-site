#!/usr/bin/env python3
"""
Irriga Blog Otomasyon — Ana Çalıştırıcı

Kullanım:
  python main.py setup          # Bağlantı ve dosya testi
  python main.py batch          # topics.json'dan sıradaki konuyu yayınla
  python main.py write "konu"   # Belirli bir konu üret ve yayınla

Cron örneği (haftada 3 kez, Pzt/Çar/Cum 10:00):
  0 7 * * 1,3,5 cd /Users/tahirkucuk/irriga && python scripts/main.py batch >> /tmp/irriga-blog.log 2>&1
"""
import os
import sys
import json
import subprocess
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

REPO_ROOT = Path(__file__).parent.parent
TOPICS_FILE = Path(__file__).parent / "topics.json"


def setup():
    site = SiteClient()
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


def git_commit_push(title: str):
    """Değişiklikleri commit et ve push yap.
    GitHub Actions'ta (GITHUB_ACTIONS=true) push atlanır — workflow halleder.
    """
    in_ci = os.environ.get("GITHUB_ACTIONS") == "true"
    try:
        subprocess.run(
            ["git", "add", "blog/", "posts.json", "blog.html", "scripts/topics.json"],
            cwd=REPO_ROOT, check=True,
        )
        subprocess.run(
            ["git", "commit", "-m", f"Blog: {title}"],
            cwd=REPO_ROOT, check=True,
        )
        if in_ci:
            logger.info("✅ Git commit tamamlandı (CI: push workflow tarafından yapılacak)")
            return True
        # Push: remote öndeyse rebase yap, 3 deneme
        for attempt in range(1, 4):
            result = subprocess.run(
                ["git", "push", "origin", "main"],
                cwd=REPO_ROOT,
            )
            if result.returncode == 0:
                logger.info("✅ Git commit + push tamamlandı")
                return True
            logger.warning(f"Push denemesi {attempt} başarısız — pull --rebase yapılıyor...")
            subprocess.run(
                ["git", "pull", "--rebase", "-X", "theirs", "origin", "main"],
                cwd=REPO_ROOT, check=True,
            )
        logger.error("❌ Push 3 denemede başarısız oldu.")
        return False
    except subprocess.CalledProcessError as e:
        logger.error(f"❌ Git işlemi başarısız: {e}")
        return False


def batch():
    topics_data = json.loads(TOPICS_FILE.read_text(encoding="utf-8"))
    pending = topics_data.get("pending", [])

    if not pending:
        logger.warning("⚠️  Konu listesi tükendi!")
        EmailNotifier().send_no_topics()
        return

    site = SiteClient()
    email = EmailNotifier()
    agent = ContentAgent(site, email)

    # Başarılı yayın veya gerçek hata bulana kadar konuları dene (max 5)
    for attempt in range(min(5, len(pending))):
        topic = pending[0]
        logger.info(f"🎯 Konu ({attempt+1}. deneme): {topic['baslik']}")

        result = agent.create_article(topic)

        if result:
            # Başarı: konuyu pending'den kaldır, published'e ekle
            topics_data["pending"].pop(0)
            topics_data.setdefault("published", []).append({
                **topic,
                "tarih_iso": result["tarih_iso"],
            })
            TOPICS_FILE.write_text(
                json.dumps(topics_data, ensure_ascii=False, indent=2), encoding="utf-8"
            )
            logger.info("✅ topics.json güncellendi")

            if not git_commit_push(result["title"]):
                email.send_failure("Git commit/push başarısız. Makale oluşturuldu ama deploy edilemedi.")
                sys.exit(1)
            return  # Başarıyla tamamlandı

        # None döndü — duplicate mi hata mı? Her iki durumda da konuyu geç
        logger.warning(f"⏭  Konu atlandı (duplicate veya hata): {topic['baslik']}")
        topics_data["pending"].pop(0)
        topics_data.setdefault("skipped", []).append(topic)
        TOPICS_FILE.write_text(
            json.dumps(topics_data, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        pending = topics_data.get("pending", [])
        if not pending:
            break

    logger.error("❌ 5 konuyu da üretemedi veya konu listesi bitti.")
    sys.exit(1)


def write_topic(topic_str: str):
    """Serbest konuyla tek makale yaz (topics.json'a eklenmez)."""
    topic = {
        "slug": (topic_str.lower()
                 .replace(" ", "-").replace("ş", "s").replace("ğ", "g")
                 .replace("ü", "u").replace("ö", "o").replace("ç", "c")
                 .replace("ı", "i"))[:60],
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
        git_commit_push(result["title"])
    else:
        sys.exit(1)


def main():
    parser = argparse.ArgumentParser(description="Irriga Blog Otomasyon")
    parser.add_argument(
        "command",
        choices=["setup", "batch", "write"],
        help="Çalıştırılacak komut",
    )
    parser.add_argument("topic", nargs="*", help="write komutu için konu")
    args = parser.parse_args()

    if args.command == "setup":
        setup()
    elif args.command == "batch":
        batch()
    elif args.command == "write":
        topic_str = " ".join(args.topic) if args.topic else input("Konu girin: ").strip()
        write_topic(topic_str)


if __name__ == "__main__":
    main()

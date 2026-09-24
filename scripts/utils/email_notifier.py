"""
E-posta Bildirim Servisi
Başarı, hata ve uyarı bildirimlerini Gmail SMTP ile gönderir.
"""
import smtplib
import logging
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText

from config.settings import GMAIL_USER, GMAIL_APP_PASSWORD, NOTIFY_TO

logger = logging.getLogger(__name__)


class EmailNotifier:
    def __init__(self):
        self.smtp_user = GMAIL_USER
        self.smtp_pass = GMAIL_APP_PASSWORD
        self.to = NOTIFY_TO

    def send_message(self, subject: str, body_html: str) -> bool:
        if not self.smtp_pass:
            logger.warning("⚠️  GMAIL_APP_PASSWORD tanımlı değil — e-posta atlandı")
            return False
        try:
            msg = MIMEMultipart("alternative")
            msg["Subject"] = subject
            msg["From"] = f"Irriga Blog <{self.smtp_user}>"
            msg["To"] = self.to
            msg.attach(MIMEText(body_html, "html", "utf-8"))

            with smtplib.SMTP("smtp.gmail.com", 587) as server:
                server.ehlo()
                server.starttls()
                server.login(self.smtp_user, self.smtp_pass)
                server.sendmail(self.smtp_user, self.to, msg.as_string())

            logger.info(f"📧 E-posta gönderildi: {subject}")
            return True
        except Exception as e:
            logger.error(f"❌ E-posta gönderilemedi: {e}")
            return False

    def send_success(self, title: str, url: str, kategori: str, okuma_suresi: int) -> bool:
        subject = f"✅ Yeni Blog Makalesi Yayınlandı: {title}"
        body = f"""
<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
  <h2 style="color:#163325;">✅ Yeni Makale Yayınlandı</h2>
  <table style="border-collapse:collapse;width:100%;">
    <tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Başlık</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;">{title}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Kategori</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;">{kategori}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Okuma Süresi</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;">{okuma_suresi} dk</td></tr>
    <tr><td style="padding:8px;"><strong>URL</strong></td>
        <td style="padding:8px;"><a href="{url}">{url}</a></td></tr>
  </table>
  <p style="margin-top:20px;">
    <a href="{url}" style="background:#163325;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;">Makaleyi Görüntüle →</a>
  </p>
  <p style="color:#888;font-size:12px;margin-top:30px;">Irriga Blog Otomasyonu tarafından gönderilmiştir.</p>
</body></html>
"""
        return self.send_message(subject, body)

    def send_failure(self, error: str) -> bool:
        subject = "❌ Irriga Blog Otomasyonu Başarısız — Müdahale Gerekiyor"
        body = f"""
<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
  <h2 style="color:#c0392b;">❌ Blog Otomasyonu Başarısız</h2>
  <p>Makale oluşturma sırasında bir hata oluştu:</p>
  <pre style="background:#f5f5f5;padding:16px;border-radius:6px;overflow:auto;font-size:13px;">{error}</pre>
  <p>Lütfen <strong>scripts/</strong> klasörünü ve GitHub Actions loglarını inceleyin.</p>
  <p style="color:#888;font-size:12px;margin-top:30px;">Irriga Blog Otomasyonu tarafından gönderilmiştir.</p>
</body></html>
"""
        return self.send_message(subject, body)

    def send_no_topics(self) -> bool:
        subject = "⚠️ Irriga Blog: Konu Listesi Tükendi"
        body = """
<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
  <h2 style="color:#e67e22;">⚠️ Konu Listesi Tükendi</h2>
  <p>Blog otomasyonu çalıştı ancak <code>scripts/topics.json</code> dosyasında bekleyen konu kalmadı.</p>
  <p>Yeni konular eklemek için <strong>topics.json</strong> dosyasındaki <code>"pending"</code> dizisine yeni girişler ekleyin.</p>
  <p style="color:#888;font-size:12px;margin-top:30px;">Irriga Blog Otomasyonu tarafından gönderilmiştir.</p>
</body></html>
"""
        return self.send_message(subject, body)

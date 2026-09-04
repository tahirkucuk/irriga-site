# CLAUDE.md — Irriga Mühendislik Site Projesi

## Proje
- **Müşteri:** Irriga Mühendislik
- **Domain:** irriga.com.tr
- **Hosting:** Natro cPanel (FTP / Git deploy, public_html)
- **Sektör:** Sulama sistemleri mühendisliği

## SMTP
- Sunucu: `mail.irriga.com.tr:465` (SSL)
- Kullanıcı: `noreply@irriga.com.tr`
- Gerçek şifre: `/Users/tahirkucuk/saticiakademisi/musteriler/irriga/.smtp-ayar.json` (gitignore)
- Sunucuda: `/home/<cpanel_kullanicisi>/.smtp-ayar.json` (public_html DIŞI)

## İletişim & Bildirim
- Form bildirimleri → `tahirkucuk@gmail.com`
- ntfy topic → `basariustasi-ajans-2026`
- Müşteri e-posta → `info@irriga.com.tr`

## Airtable
- Base ID: `appfATZOWjl0aLJVo`
- Tablolar: Leads, Görüşmeler, Teklifler, İçerikler

## PHP Dosyaları
| Dosya | Görev |
|---|---|
| iletisim.php | İletişim formu → Airtable + ntfy + SMTP |
| randevu.php | Randevu işleyici |
| sekans.php | 5 adımlı e-posta sekansı |
| bulten-*.php | Bülten abone/onayla/iptal/gönder |
| crm-mail.php | CRM mail tetikleyici |
| yayin-bildirim.php | İçerik yayın bildirimi |
| mail-test.php | SMTP bağlantı testi |

## Davranış
- Kullanıcıdan onay beklemeden devam et.
- Commit öncesi `.smtp-ayar.json` asla staging'e girmesin.

<?php
// İçerik ajanının yayın bildirimi göndermesi için iç uç nokta.
// Token korumalı; SMTP ayarlarını iletisim.php ile aynı dosyadan okur.
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

if (!hash_equals('c2b36078944e76962d30f42b3e1cc6be1de97a4f6872efb2', $_POST['token'] ?? '')) {
    http_response_code(404);
    exit;
}

$baslik = mb_substr(trim($_POST['baslik'] ?? ''), 0, 300);
$url    = mb_substr(trim($_POST['url'] ?? ''), 0, 500);
$ozet   = mb_substr(trim($_POST['ozet'] ?? ''), 0, 2000);
$icerik = mb_substr(trim($_POST['icerik'] ?? ''), 0, 60000);

if ($baslik === '' || $url === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'baslik ve url zorunlu']);
    exit;
}

$cfgPath = dirname(__DIR__) . '/.smtp-ayar.json';
$cfg = is_readable($cfgPath) ? json_decode(file_get_contents($cfgPath), true) : null;
if (!$cfg || empty($cfg['kullanici']) || empty($cfg['sifre'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'smtp ayarı yok']);
    exit;
}

$smtpHost = !empty($cfg['sunucu']) ? $cfg['sunucu'] : 'localhost';
$smtpPort = !empty($cfg['port']) ? (int)$cfg['port'] : 465;

// --- SMTP fonksiyonları ---

function smtp_baglanti($host, $port, $kullanici, $sifre)
{
    $errno = 0; $errstr = '';
    $uri = ($port === 465 ? 'ssl://' : '') . $host;
    $fp = @fsockopen($uri, $port, $errno, $errstr, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 15);
    $oku = function () use ($fp) {
        $d = '';
        while ($l = fgets($fp, 515)) { $d .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $d;
    };
    $komut = function ($c) use ($fp, $oku) { fwrite($fp, $c . "\r\n"); return $oku(); };
    $oku();
    $komut('EHLO irriga.com.tr');
    if ($port === 587) {
        if (strpos($komut('STARTTLS'), '220') !== 0) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
        $komut('EHLO irriga.com.tr');
    }
    $komut('AUTH LOGIN');
    $komut(base64_encode($kullanici));
    if (strpos($komut(base64_encode($sifre)), '235') !== 0) { fclose($fp); return false; }
    return ['fp' => $fp, 'komut' => $komut];
}

function smtp_gonder_metin($conn, $kullanici, $to, $subject, $body)
{
    $komut = $conn['komut'];
    if (strpos($komut('MAIL FROM:<' . $kullanici . '>'), '250') !== 0) return false;
    if (strpos($komut('RCPT TO:<' . $to . '>'), '250') !== 0) { $komut('RSET'); return false; }
    if (strpos($komut('DATA'), '354') !== 0) return false;
    $headers  = "From: Basari Ustasi Icerik Ajani <$kullanici>\r\n";
    $headers .= "To: $to\r\nSubject: $subject\r\n";
    $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    $data = $headers . "\r\n\r\n" . preg_replace('/^\./m', '..', $body) . "\r\n.";
    return strpos($komut($data), '250') === 0;
}

function smtp_gonder_html_metin($conn, $kullanici, $to, $subject, $htmlBody, $textBody)
{
    $komut    = $conn['komut'];
    $boundary = 'bu_' . md5($to . $subject);
    if (strpos($komut('MAIL FROM:<' . $kullanici . '>'), '250') !== 0) return false;
    if (strpos($komut('RCPT TO:<' . $to . '>'), '250') !== 0) { $komut('RSET'); return false; }
    if (strpos($komut('DATA'), '354') !== 0) return false;
    $headers  = "From: =?UTF-8?B?" . base64_encode("Irriga Mühendislik") . "?= <$kullanici>\r\n";
    $headers .= "To: $to\r\nSubject: $subject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textBody . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlBody . "\r\n";
    $body .= "--$boundary--";
    $data = $headers . "\r\n" . preg_replace('/^\./m', '..', $body) . "\r\n.";
    return strpos($conn['komut']($data), '250') === 0;
}

// --- 1. Tahir'e bildirim ---

$conn = smtp_baglanti($smtpHost, $smtpPort, $cfg['kullanici'], $cfg['sifre']);
$tahirOk = false;
if ($conn) {
    $tahirSubject = '=?UTF-8?B?' . base64_encode('Yeni Yazı Yayınlandı: ' . $baslik) . '?=';
    $tahirBody  = "Irriga Mühendislik içerik ajanı yeni bir yazı yayınladı.\n\n";
    $tahirBody .= "Başlık : $baslik\n";
    $tahirBody .= "URL    : $url\n";
    $tahirBody .= "Tarih  : " . date('d.m.Y H:i') . "\n\n";
    if ($ozet !== '')   { $tahirBody .= "--- Özet ---\n$ozet\n\n"; }
    if ($icerik !== '') { $tahirBody .= "--- Yazının Tam Metni ---\n$icerik\n\n"; }
    $tahirBody .= "Düzeltme istersen Claude'a söylemen yeterli.\n";
    $tahirOk = smtp_gonder_metin($conn, $cfg['kullanici'], 'tahirkucuk@gmail.com', $tahirSubject, $tahirBody);
    fwrite($conn['fp'], "QUIT\r\n");
    fclose($conn['fp']);
}

// --- 2. Onaylı abonelere yazı bildirimi ---

$logDir   = dirname(__DIR__) . '/lead-kayitlari';
$dosya    = $logDir . '/aboneler.jsonl';
$aboneler = [];
if (is_readable($dosya)) {
    foreach (file($dosya, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $satir) {
        $k = json_decode($satir, true);
        if ($k && ($k['durum'] ?? '') === 'onaylandi' && !empty($k['eposta'])) {
            $aboneler[] = $k;
        }
    }
}

$bultenBasarili = 0;
$bultenHata     = 0;

// --- GÜVENLİK: Abonelere YALNIZCA gerçek, yayınlanmış bir blog yazısı için mail gider ---
// url posts.json'daki bir yazıya karşılık gelmiyorsa abone gönderimi ATLANIR.
// Bu, iç raporların / otomasyon çıktılarının abonelere sızmasını önler (Tahir'e bildirim yine gider).
// Ayrıca yazı henüz deploy olmadıysa link 404 vereceğinden gönderim doğru şekilde beklenir.
$gecerliYazi = false;
$path = parse_url($url, PHP_URL_PATH);
$slug = strtolower(basename($path !== false && $path !== null ? $path : ''));
$postsPath = __DIR__ . '/posts.json';
if ($slug !== '' && substr($slug, -5) === '.html' && is_readable($postsPath)) {
    $posts = json_decode(file_get_contents($postsPath), true);
    if (is_array($posts)) {
        foreach ($posts as $p) {
            $pu = isset($p['url']) ? strtolower(basename($p['url'])) : '';
            if ($pu !== '' && $pu === $slug) { $gecerliYazi = true; break; }
        }
    }
}

if (!empty($aboneler) && $gecerliYazi) {
    $bultenSubject = '=?UTF-8?B?' . base64_encode('Yeni Rehber: ' . $baslik) . '?=';

    foreach ($aboneler as $abone) {
        $iptalLink = 'https://irriga.com.tr/bulten-iptal.php?token=' . ($abone['token'] ?? '');

        $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:580px;margin:0 auto;background:#f8fafc;color:#334155;">
<div style="background:#0F172A;padding:24px 32px;border-radius:12px 12px 0 0;">
  <span style="font-weight:800;font-size:18px;color:#fff;letter-spacing:-0.01em;">Irriga Mühendislik</span>
  <span style="display:block;font-size:12px;color:#64748B;margin-top:4px;">Trendyol &amp; E-ticaret Rehberleri</span>
</div>
<div style="background:#fff;padding:32px;border:1px solid #E2E8F0;border-top:none;">
  <p style="font-size:13px;font-weight:600;color:#7C3AED;text-transform:uppercase;letter-spacing:0.05em;margin:0 0 12px;">Yeni Rehber</p>
  <h2 style="font-size:22px;font-weight:700;color:#0F172A;margin:0 0 16px;line-height:1.35;letter-spacing:-0.02em;">' . htmlspecialchars($baslik) . '</h2>'
  . ($ozet !== '' ? '<p style="font-size:15px;line-height:1.7;color:#475569;margin:0 0 24px;">' . htmlspecialchars($ozet) . '</p>' : '')
  . '<a href="' . htmlspecialchars($url) . '"
     style="display:inline-block;padding:14px 28px;background:#7C3AED;color:#fff;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none;letter-spacing:-0.01em;">
    Yazıyı Oku →
  </a>
</div>
<div style="background:#F1F5F9;padding:14px 32px;border-radius:0 0 12px 12px;border:1px solid #E2E8F0;border-top:none;">
  <p style="font-size:12px;color:#94A3B8;margin:0;">
    <a href="https://irriga.com.tr" style="color:#7C3AED;text-decoration:none;">irriga.com.tr</a>
    &nbsp;·&nbsp;
    <a href="' . htmlspecialchars($iptalLink) . '" style="color:#94A3B8;">Abonelikten çık</a>
  </p>
</div>
</body></html>';

        $textBody  = "Yeni Rehber: $baslik\n\n";
        if ($ozet !== '') { $textBody .= "$ozet\n\n"; }
        $textBody .= "Yazıyı oku: $url\n\n";
        $textBody .= "---\nBasariustasi.com - Trendyol & E-ticaret Rehberleri\n";
        $textBody .= "Abonelikten çıkmak için: $iptalLink\n";

        $conn2 = smtp_baglanti($smtpHost, $smtpPort, $cfg['kullanici'], $cfg['sifre']);
        if ($conn2) {
            $ok2 = smtp_gonder_html_metin($conn2, $cfg['kullanici'], $abone['eposta'], $bultenSubject, $htmlBody, $textBody);
            fwrite($conn2['fp'], "QUIT\r\n");
            fclose($conn2['fp']);
            if ($ok2) {
                $bultenBasarili++;
            } else {
                $bultenHata++;
                @file_put_contents($logDir . '/smtp-hatalar.log',
                    date('c') . ' [bulten-yayin] ' . $abone['eposta'] . "\n", FILE_APPEND | LOCK_EX);
            }
        } else {
            $bultenHata++;
        }
    }
}

echo json_encode([
    'ok'             => (bool)$tahirOk,
    'bulten_gonderildi' => $bultenBasarili,
    'bulten_hata'    => $bultenHata,
    'abone_atlandi'  => !$gecerliYazi,
    'not'            => $gecerliYazi ? '' : 'url posts.json ile eslesmiyor; abone gonderimi atlandi (yalniz gercek blog yazisi abonelere gider).',
]);

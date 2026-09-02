<?php
// Bülten aboneliği — double opt-in: onay maili gönderir, tıklayınca aktif olur
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

// Honeypot
if (!empty($_POST['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
if (!$email) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Geçerli bir e-posta girin.']);
    exit;
}
$email = strtolower($email);

$logDir = dirname(__DIR__) . '/lead-kayitlari';
if (!is_dir($logDir)) { @mkdir($logDir, 0700, true); }
$dosya = $logDir . '/aboneler.jsonl';

// Kilitli okuma-değiştirme-yazma: eşzamanlı abonelik/onay/iptal işlemleri
// birbirini ezmesin (flock tüm yazarlar tarafından paylaşılır).
$fh = fopen($dosya, 'c+');
if ($fh === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Kayıt açılamadı. Lütfen tekrar deneyin.']);
    exit;
}
flock($fh, LOCK_EX);

// Tüm kayıtları kilit altında oku
$kayitlar = [];
rewind($fh);
while (($satir = fgets($fh)) !== false) {
    $satir = trim($satir);
    if ($satir === '') continue;
    $k = json_decode($satir, true);
    if ($k) $kayitlar[] = $k;
}

// Mevcut durumu kontrol et
$mevcutOnaylandi = false;
$mevcutBeklemede = null;
foreach ($kayitlar as $k) {
    if (strtolower($k['eposta'] ?? '') !== $email) continue;
    $durum = $k['durum'] ?? 'onaylandi'; // eski kayıtlar (durum yok) = onaylı sayılır
    if ($durum === 'onaylandi') { $mevcutOnaylandi = true; break; }
    if ($durum === 'beklemede') { $mevcutBeklemede = $k; break; }
}

if ($mevcutOnaylandi) {
    // Zaten onaylı — sessizce başarı dön (kullanıcı zaten listede)
    flock($fh, LOCK_UN);
    fclose($fh);
    echo json_encode(['ok' => true, 'mesaj' => 'Onay e-postası gönderildi. Gelen kutunuzu kontrol edin.']);
    exit;
}

// Yeni token üret
$token = bin2hex(random_bytes(16));

if ($mevcutBeklemede) {
    // Mevcut bekleyen kaydın token'ını güncelle (dosyayı kilit altında yeniden yaz)
    $yeni = [];
    foreach ($kayitlar as $k) {
        if (strtolower($k['eposta'] ?? '') === $email && ($k['durum'] ?? '') === 'beklemede') {
            $k['token'] = $token;
            $k['tarih'] = date('c');
        }
        $yeni[] = json_encode($k, JSON_UNESCAPED_UNICODE);
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, implode("\n", $yeni) . "\n");
} else {
    // Yeni kayıt ekle (kilit altında dosya sonuna)
    $kayit = [
        'eposta' => $email,
        'tarih'  => date('c'),
        'durum'  => 'beklemede',
        'token'  => $token,
        'sayfa'  => mb_substr(trim(strip_tags($_POST['page'] ?? '')), 0, 300),
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    fseek($fh, 0, SEEK_END);
    fwrite($fh, json_encode($kayit, JSON_UNESCAPED_UNICODE) . "\n");
}

fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);
// Kilit burada bırakıldı — SMTP gönderimi (yavaş ağ I/O) kilitsiz yapılır.

// SMTP ayarlarını oku
$cfgPath = dirname(__DIR__) . '/.smtp-ayar.json';
$cfg = is_readable($cfgPath) ? json_decode(file_get_contents($cfgPath), true) : null;
if (!$cfg || empty($cfg['kullanici']) || empty($cfg['sifre'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mail gönderilemedi. Lütfen daha sonra tekrar deneyin.']);
    exit;
}

$onayLink  = 'https://irriga.com.tr/bulten-onayla.php?token=' . $token;
$iptalLink = 'https://irriga.com.tr/bulten-iptal.php?token=' . $token;

$subject = '=?UTF-8?B?' . base64_encode('Bülten aboneliğinizi onaylayın — Irriga Mühendislik') . '?=';

$bodyHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#f8fafc;color:#334155;">
<div style="background:#0F172A;padding:28px 32px;border-radius:12px 12px 0 0;">
  <span style="font-weight:800;font-size:18px;color:#fff;letter-spacing:-0.01em;">Irriga Mühendislik</span>
  <span style="display:block;font-size:12px;color:#64748B;margin-top:4px;">irriga.com.tr</span>
</div>
<div style="background:#fff;padding:32px;border:1px solid #E2E8F0;border-top:none;">
  <h2 style="font-size:20px;font-weight:700;color:#0F172A;margin:0 0 16px;line-height:1.3;">Bülten aboneliğinizi onaylayın</h2>
  <p style="font-size:15px;line-height:1.7;color:#475569;margin:0 0 24px;">
    Merhaba,<br><br>
    <strong>irriga.com.tr</strong> bültenine bu e-posta adresiyle abone olma isteği aldık.
    Trendyol ve e-ticaret güncellemelerini almak için aşağıdaki butona tıklayarak aboneliğinizi onaylayın.
  </p>
  <a href="' . htmlspecialchars($onayLink) . '"
     style="display:inline-block;padding:14px 28px;background:#7C3AED;color:#fff;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none;letter-spacing:-0.01em;">
    ✓ Aboneliği Onayla
  </a>
  <p style="font-size:13px;color:#94A3B8;margin:28px 0 0;line-height:1.6;">
    Bu isteği siz yapmadıysanız bu e-postayı görmezden gelebilirsiniz — hiçbir şey olmaz.<br>
    Biriyle kayıt yaptırıldıysa hemen iptal etmek için:
    <a href="' . htmlspecialchars($iptalLink) . '" style="color:#7C3AED;font-weight:600;">Aboneliği iptal et</a>
  </p>
</div>
<div style="background:#F1F5F9;padding:14px 32px;border-radius:0 0 12px 12px;border:1px solid #E2E8F0;border-top:none;">
  <p style="font-size:12px;color:#94A3B8;margin:0;">
    Trendyol ve pazaryerleri rehberleri &nbsp;·&nbsp;
    <a href="https://irriga.com.tr" style="color:#7C3AED;text-decoration:none;">irriga.com.tr</a>
  </p>
</div>
</body></html>';

$bodyText  = "Bülten aboneliğinizi onaylayın — Irriga Mühendislik\n\n";
$bodyText .= "Bu e-posta ile irriga.com.tr bültenine abone olma isteği aldık.\n\n";
$bodyText .= "Onaylamak için: " . $onayLink . "\n\n";
$bodyText .= "Bu isteği siz yapmadıysanız bu maili silebilirsiniz.\n";
$bodyText .= "Hemen iptal etmek için: " . $iptalLink . "\n";

function smtp_onayla($host, $port, $kullanici, $sifre, $to, $subject, $htmlBody, $textBody)
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
    if (strpos($komut('MAIL FROM:<' . $kullanici . '>'), '250') !== 0) { fclose($fp); return false; }
    if (strpos($komut('RCPT TO:<' . $to . '>'), '250') !== 0) { fclose($fp); return false; }
    if (strpos($komut('DATA'), '354') !== 0) { fclose($fp); return false; }

    $boundary = 'boundary_' . md5($to . time());
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
    if (strpos($komut($data), '250') !== 0) { fclose($fp); return false; }
    $komut('QUIT');
    fclose($fp);
    return true;
}

$smtpHost = !empty($cfg['sunucu']) ? $cfg['sunucu'] : 'localhost';
$smtpPort = !empty($cfg['port']) ? (int)$cfg['port'] : 465;
$ok = smtp_onayla($smtpHost, $smtpPort, $cfg['kullanici'], $cfg['sifre'], $email, $subject, $bodyHtml, $bodyText);

if (!$ok) {
    // Mail gönderilemedi — SMTP log
    @file_put_contents($logDir . '/smtp-hatalar.log',
        date('c') . ' [bulten-onayla] ' . $email . "\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ok' => false, 'error' => 'Onay e-postası gönderilemedi. Lütfen daha sonra tekrar deneyin.']);
    exit;
}

echo json_encode(['ok' => true, 'mesaj' => 'Onay e-postası gönderildi. Gelen kutunuzu kontrol edin.']);

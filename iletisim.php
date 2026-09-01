<?php
// Danışmanlık formu — SMTP ile mail bildirimi
// Not: Bu sunucuda mail() devre dışı (disable_functions), bu yüzden SMTP kullanılıyor.
// SMTP ayarları webroot DIŞINDA tutulur: /home/<kullanici>/.smtp-ayar.json
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Geçersiz istek.']);
    exit;
}

// Honeypot: botlar bu gizli alanı doldurur — sessizce "başarılı" dön
if (!empty($_POST['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$name    = trim(strip_tags(isset($_POST['name']) ? $_POST['name'] : ''));
$email   = filter_var(trim(isset($_POST['email']) ? $_POST['email'] : ''), FILTER_VALIDATE_EMAIL);
$phone   = trim(strip_tags(isset($_POST['phone']) ? $_POST['phone'] : ''));
$store   = trim(strip_tags(isset($_POST['store']) ? $_POST['store'] : ''));
$message = trim(strip_tags(isset($_POST['message']) ? $_POST['message'] : ''));

// Attribution (ilk temas) verileri
$landing  = mb_substr(trim(strip_tags(isset($_POST['landing']) ? $_POST['landing'] : '')), 0, 500);
$referrer = mb_substr(trim(strip_tags(isset($_POST['referrer']) ? $_POST['referrer'] : '')), 0, 500);
$page     = mb_substr(trim(strip_tags(isset($_POST['page']) ? $_POST['page'] : '')), 0, 500);

if ($name === '' || !$email || $message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Lütfen zorunlu alanları doldurun.']);
    exit;
}

// Header injection koruması
$name  = str_replace(["\r", "\n"], ' ', $name);
$phone = str_replace(["\r", "\n"], ' ', $phone);

if (mb_strlen($name) > 120 || mb_strlen($message) > 5000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Alan uzunluğu sınırı aşıldı.']);
    exit;
}

// --- Lead'i her durumda sunucuya kaydet (webroot dışına) ---
$leadKaydi = [
    'tarih'    => date('c'),
    'ad'       => $name,
    'eposta'   => $email,
    'telefon'  => $phone,
    'magaza'   => $store,
    'mesaj'    => $message,
    'landing'  => $landing,
    'referrer' => $referrer,
    'sayfa'    => $page,
    'ip'       => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
];
$logDir = dirname(__DIR__) . '/lead-kayitlari';
if (!is_dir($logDir)) { @mkdir($logDir, 0700, true); }
@file_put_contents($logDir . '/leads.jsonl',
    json_encode($leadKaydi, JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX);

// --- ntfy.sh anlık bildirimi (sunucudan doğrudan, CCR gerektirmez) ---
$ntfyMsg = "Yeni lead: $name | $email" . ($phone !== '' ? " | $phone" : '') . ($store !== '' ? " | $store" : '');
@file_get_contents('https://ntfy.sh/basariustasi-ajans-2026', false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Title: Yeni Danismanlik Talebi — Basari Ustasi\r\nPriority: high\r\nContent-Type: text/plain\r\n",
        'content' => $ntfyMsg,
        'timeout' => 5,
        'ignore_errors' => true,
    ],
]));

// --- Airtable CRM push (config varsa doğrudan yazar, CCR gerektirmez) ---
$atCfgPath = dirname(__DIR__) . '/.airtable-ayar.json';
if (is_readable($atCfgPath)) {
    $atCfg = json_decode(file_get_contents($atCfgPath), true);
    if (!empty($atCfg['pat'])) {
        // Alan ID'leri kullanılır (Türkçe karakter sorunundan kaçınmak için)
        $mesajTam = $message . ($store !== '' ? "\n\nMağaza: $store" : '');
        $atFields = [
            'fldsMezwOgFCm5r40' => $name,
            'fldDjZ4TmrZJJ6RZU' => $email,
            'fldcCzbVHlBHRXBuY' => $mesajTam,
            'fldSS0XEAVp8iJZeh' => 'Yeni',
        ];
        if ($phone !== '')    $atFields['fld2dUNXuqbaRYg3t'] = $phone;
        if ($referrer !== '') $atFields['fldd32i82wadUTrj0']  = $referrer;
        if ($landing !== '')  $atFields['fld0FES5N4dMe2GSd']  = (strpos($landing, '://') !== false ? $landing : 'https://' . $landing);
        if ($page !== '')     $atFields['fldIqUBOKwYMyUrxQ']  = (strpos($page, '://') !== false ? $page : 'https://' . $page);
        $atBody = json_encode(['fields' => $atFields], JSON_UNESCAPED_UNICODE);
        @file_get_contents(
            'https://api.airtable.com/v0/appLJcreVfkrOhWn6/tblaUQpYLp5gjDpIx',
            false,
            stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Authorization: Bearer {$atCfg['pat']}\r\nContent-Type: application/json\r\n",
                'content'       => $atBody,
                'timeout'       => 8,
                'ignore_errors' => true,
            ]])
        );
    }
}

// --- Otomatik yanıt — lead'e hemen onay gönder ---
$autoReplyBody  = "Merhaba $name,\n\n";
$autoReplyBody .= "Danışmanlık talebinizi aldık. En geç 24 saat içinde size dönüş yapacağız.\n\n";
$autoReplyBody .= "Acil bir durumunuz varsa bize doğrudan yazabilirsiniz: tahirkucuk@gmail.com\n\n";
$autoReplyBody .= "İyi günler,\nTahir Küçük\nIrriga Mühendislik";

// --- SMTP ayarlarını oku ---
$cfgPath = dirname(__DIR__) . '/.smtp-ayar.json';
$cfg = null;
if (is_readable($cfgPath)) {
    $cfg = json_decode(file_get_contents($cfgPath), true);
}
if (!$cfg || empty($cfg['kullanici']) || empty($cfg['sifre'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mail yapılandırması eksik. Lütfen telefonla ulaşın.']);
    exit;
}
$smtpHost = !empty($cfg['sunucu']) ? $cfg['sunucu'] : 'localhost';
$smtpPort = !empty($cfg['port']) ? (int)$cfg['port'] : 465;

// --- Mail içeriği ---
$to      = 'tahirkucuk@gmail.com';
$subject = '=?UTF-8?B?' . base64_encode('Yeni Danışmanlık Talebi — ' . $name) . '?=';

$body  = "Yeni danışmanlık görüşme talebi:\n\n";
$body .= "Ad Soyad : $name\n";
$body .= "E-posta  : $email\n";
$body .= "Telefon  : " . ($phone !== '' ? $phone : '-') . "\n";
$body .= "Mağaza   : " . ($store !== '' ? $store : '-') . "\n\n";
$body .= "Mesaj:\n$message\n\n";
$body .= "--- Kaynak Bilgisi (Attribution) ---\n";
$body .= "Giriş sayfası : " . ($landing !== '' ? $landing : '-') . "\n";
$body .= "Geldiği yer   : " . ($referrer !== '' ? $referrer : '-') . "\n";
$body .= "Form sayfası  : " . ($page !== '' ? $page : '-') . "\n\n";
$body .= "---\n";
$body .= "IP    : " . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-') . "\n";
$body .= "Tarih : " . date('d.m.Y H:i:s') . "\n";

/**
 * Minimal SMTP istemcisi (PHP 7.3 uyumlu, harici kütüphane gerektirmez).
 * Başarıda true, hatada açıklayıcı string döner.
 */
function smtp_gonder($host, $port, $kullanici, $sifre, $to, $subject, $body, $replyTo)
{
    $errno = 0; $errstr = '';
    $uri = ($port === 465 ? 'ssl://' : '') . $host;
    $fp = @fsockopen($uri, $port, $errno, $errstr, 15);
    if (!$fp) return "bağlantı kurulamadı: $errstr ($errno)";
    stream_set_timeout($fp, 15);

    $oku = function () use ($fp) {
        $d = '';
        while ($l = fgets($fp, 515)) {
            $d .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        return $d;
    };
    $komut = function ($c) use ($fp, $oku) {
        fwrite($fp, $c . "\r\n");
        return $oku();
    };

    $oku(); // karşılama
    $komut('EHLO irrigatr.com');

    if ($port === 587) {
        $r = $komut('STARTTLS');
        if (strpos($r, '220') !== 0) { fclose($fp); return 'STARTTLS reddedildi: ' . trim($r); }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp); return 'TLS başlatılamadı';
        }
        $komut('EHLO irrigatr.com');
    }

    $komut('AUTH LOGIN');
    $komut(base64_encode($kullanici));
    $r = $komut(base64_encode($sifre));
    if (strpos($r, '235') !== 0) { fclose($fp); return 'kimlik doğrulama başarısız: ' . trim($r); }

    $r = $komut('MAIL FROM:<' . $kullanici . '>');
    if (strpos($r, '250') !== 0) { fclose($fp); return 'MAIL FROM reddedildi: ' . trim($r); }

    $r = $komut('RCPT TO:<' . $to . '>');
    if (strpos($r, '250') !== 0) { fclose($fp); return 'RCPT TO reddedildi: ' . trim($r); }

    $r = $komut('DATA');
    if (strpos($r, '354') !== 0) { fclose($fp); return 'DATA reddedildi: ' . trim($r); }

    $headers  = "From: Basari Ustasi <$kullanici>\r\n";
    $headers .= "Reply-To: $replyTo\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit";

    // SMTP nokta kaçışı (satır başındaki "." ikilenir)
    $data = $headers . "\r\n\r\n" . preg_replace('/^\./m', '..', $body) . "\r\n.";
    $r = $komut($data);
    if (strpos($r, '250') !== 0) { fclose($fp); return 'gönderim reddedildi: ' . trim($r); }

    $komut('QUIT');
    fclose($fp);
    return true;
}

$sonuc = smtp_gonder($smtpHost, $smtpPort, $cfg['kullanici'], $cfg['sifre'], $to, $subject, $body, $email);

// Otomatik yanıt gönder (lead'e) — hata olsa bile Tahir'e bildirim zaten gitti
$autoSubject = '=?UTF-8?B?' . base64_encode('Talebinizi aldık — Irriga Mühendislik') . '?=';
@smtp_gonder($smtpHost, $smtpPort, $cfg['kullanici'], $cfg['sifre'], $email, $autoSubject, $autoReplyBody, $cfg['kullanici']);

if ($sonuc === true) {
    echo json_encode(['ok' => true]);
} else {
    // Hata detayını sunucu loguna yaz, ziyaretçiye genel mesaj göster
    @file_put_contents($logDir . '/smtp-hatalar.log',
        date('c') . ' ' . $sonuc . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mail gönderilemedi. Lütfen daha sonra tekrar deneyin.']);
}

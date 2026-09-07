<?php
/**
 * Irriga Sulama Sistemleri — Contact Form Handler
 * Returns JSON: {"ok":true} or {"ok":false,"msg":"..."}
 *
 * SMTP config: place .smtp-ayar.json in the cPanel HOME directory
 *   (one level above public_html — NEVER inside public_html).
 * Server path: /home/<cpanel-kullanici>/.smtp-ayar.json
 */

/* ── CONFIGURATION ──────────────────────────────────────────── */
define('RECIPIENT_EMAIL', 'info@irriga.com.tr');
define('SITE_NAME',       'Irriga Sulama Sistemleri');
define('ALLOWED_ORIGIN',  'https://irriga.com.tr');

// SMTP config file path — cPanel home dizini (public_html üstü)
$smtpConfigPath = (getenv('HOME') ?: dirname(__DIR__)) . '/.smtp-ayar.json';
/* ────────────────────────────────────────────────────────────── */

header('Content-Type: application/json; charset=utf-8');

/* --- CORS ---------------------------------------------------- */
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [ALLOWED_ORIGIN, 'http://localhost', 'http://127.0.0.1'];
if (in_array($origin, $allowed, true) || strpos($origin, 'localhost') !== false) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* --- Method guard -------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']);
    exit;
}

/* --- Helpers ------------------------------------------------- */
function clean(string $v): string {
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}
function fail(string $msg): never {
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

/* --- Required fields ---------------------------------------- */
$name  = clean($_POST['name']  ?? '');
$phone = clean($_POST['phone'] ?? '');
$kvkk  = !empty($_POST['kvkk']);

if ($name === '')  fail('Ad soyad zorunludur.');
if ($phone === '') fail('Telefon numarası zorunludur.');
if (!$kvkk)       fail('KVKK onayı zorunludur.');

/* --- Optional fields ---------------------------------------- */
$email       = clean($_POST['email']       ?? '');
$city        = clean($_POST['city']        ?? '');
$size        = clean($_POST['size']        ?? '');
$system_type = clean($_POST['system_type'] ?? '');
$note        = clean($_POST['note']        ?? '');
$form_type   = clean($_POST['form_type']   ?? 'quote');

/* --- Validation --------------------------------------------- */
$phoneDigits = preg_replace('/\D/', '', $phone);
if (strlen($phoneDigits) < 10) fail('Geçerli bir telefon numarası girin.');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Geçerli bir e-posta adresi girin.');
}

/* --- Rate-limit: 3 / IP / 10 min ---------------------------- */
$rateLimitDir = sys_get_temp_dir() . '/irriga_rl/';
if (!is_dir($rateLimitDir)) @mkdir($rateLimitDir, 0750, true);
$ip      = preg_replace('/[^a-fA-F0-9:.]/', '', $_SERVER['REMOTE_ADDR'] ?? '');
$rlFile  = $rateLimitDir . md5($ip) . '.json';
$window  = 600;
$maxReqs = 3;
$now     = time();
$history = file_exists($rlFile) ? (json_decode(file_get_contents($rlFile), true) ?? []) : [];
$history = array_filter($history, fn($t) => ($now - $t) < $window);
if (count($history) >= $maxReqs) fail('Çok fazla deneme yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.');
$history[] = $now;
file_put_contents($rlFile, json_encode(array_values($history)));

/* --- Build e-mail body -------------------------------------- */
$subject = '[' . SITE_NAME . '] Yeni Teklif Talebi — ' . $name;
$rows = [
    'Ad Soyad'     => $name,
    'Telefon'      => $phone,
    'E-posta'      => $email ?: '—',
    'İl / İlçe'   => $city  ?: '—',
    'Arazi/Sera'   => $size  ?: '—',
    'Sistem Türü'  => $system_type ?: '—',
    'Form Türü'    => $form_type,
    'IP'           => $ip,
    'Tarih'        => date('d.m.Y H:i'),
];
$body  = "Yeni bir teklif/iletişim talebi alındı:\n";
$body .= str_repeat('─', 40) . "\n";
foreach ($rows as $label => $val) {
    $body .= str_pad($label, 14) . ': ' . $val . "\n";
}
if ($note !== '') {
    $body .= str_repeat('─', 40) . "\n";
    $body .= "Not:\n" . wordwrap($note, 72, "\n", true) . "\n";
}
$body .= str_repeat('─', 40) . "\n";
$body .= "KVKK Onayı  : Evet\n";

/* --- SMTP send ---------------------------------------------- */
/**
 * Minimal SSL-SMTP gönderici — dış bağımlılık yok.
 * Port 465 (implicit SSL) ile çalışır.
 */
function smtp_send(string $cfgPath, string $to, string $subject, string $body, string $replyTo = ''): bool {
    if (!file_exists($cfgPath)) {
        error_log('[Irriga] .smtp-ayar.json bulunamadı: ' . $cfgPath);
        return false;
    }
    $c = json_decode(file_get_contents($cfgPath), true);
    if (!$c || empty($c['host']) || empty($c['user']) || empty($c['pass'])) {
        error_log('[Irriga] .smtp-ayar.json eksik veya hatalı.');
        return false;
    }

    $host     = $c['host'];
    $port     = (int)($c['port'] ?? 465);
    $user     = $c['user'];
    $pass     = $c['pass'];
    $fromName = $c['from_name'] ?? SITE_NAME;

    $ctx = stream_context_create(['ssl' => [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'allow_self_signed' => false,
    ]]);

    $conn = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$conn) {
        error_log("[Irriga] SMTP bağlantı hatası {$host}:{$port} — {$errstr}");
        return false;
    }

    $rd = fn() => (string)fgets($conn, 512);
    $wr = fn(string $s) => fputs($conn, $s . "\r\n");

    $rd(); // 220 banner

    $wr('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'irriga.com.tr'));
    while ($line = $rd()) { if (isset($line[3]) && $line[3] === ' ') break; }

    $wr('AUTH LOGIN');
    $rd(); // 334 Username
    $wr(base64_encode($user));
    $rd(); // 334 Password
    $wr(base64_encode($pass));
    $resp = $rd(); // 235 veya hata
    if (strpos($resp, '235') !== 0) {
        error_log('[Irriga] SMTP kimlik doğrulama başarısız: ' . trim($resp));
        fclose($conn);
        return false;
    }

    $wr("MAIL FROM:<{$user}>");
    $rd();
    $wr("RCPT TO:<{$to}>");
    $rd();
    $wr('DATA');
    $rd(); // 354

    $encName    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encSubject = '=?UTF-8?B?' . base64_encode($subject)  . '?=';
    $hdrs  = 'Date: '    . date('r')   . "\r\n";
    $hdrs .= "From: {$encName} <{$user}>\r\n";
    $hdrs .= "To: <{$to}>\r\n";
    $hdrs .= "Subject: {$encSubject}\r\n";
    if ($replyTo) $hdrs .= "Reply-To: <{$replyTo}>\r\n";
    $hdrs .= "MIME-Version: 1.0\r\n";
    $hdrs .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $hdrs .= "Content-Transfer-Encoding: base64\r\n";

    $wr($hdrs . "\r\n" . chunk_split(base64_encode($body)));
    $wr('.');
    $resp = $rd(); // 250 veya hata

    $wr('QUIT');
    fclose($conn);

    return strpos($resp, '250') === 0;
}

$sent = smtp_send($smtpConfigPath, RECIPIENT_EMAIL, $subject, $body, $email ?: '');

if (!$sent) {
    error_log('[Irriga] SMTP gönderim başarısız — IP=' . $ip . ' isim=' . $name);
    fail('E-posta gönderilemedi. Lütfen WhatsApp üzerinden bize ulaşın.');
}

/* --- Success ----------------------------------------------- */
echo json_encode(['ok' => true]);

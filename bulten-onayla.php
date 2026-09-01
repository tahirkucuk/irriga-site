<?php
// Bülten onay sayfası — e-postadaki link buraya gelir
$token = preg_replace('/[^a-f0-9]/', '', strtolower($_GET['token'] ?? ''));

$basari = false;
$zatenOnaylandi = false;
$gecersiz = false;
$onaylananEposta = '';

if (strlen($token) === 32) {
    $dosya = dirname(__DIR__) . '/lead-kayitlari/aboneler.jsonl';
    // Kilitli okuma-değiştirme-yazma (abonelik akışıyla eşzamanlılık koruması)
    $fh = @fopen($dosya, 'c+');
    if ($fh) {
        flock($fh, LOCK_EX);
        $kayitlar = [];
        $bulundu = false;
        rewind($fh);
        while (($satir = fgets($fh)) !== false) {
            $satir = trim($satir);
            if ($satir === '') continue;
            $k = json_decode($satir, true);
            if (!$k) continue;
            if (($k['token'] ?? '') === $token) {
                $bulundu = true;
                if (($k['durum'] ?? '') === 'onaylandi') {
                    $zatenOnaylandi = true;
                } elseif (($k['durum'] ?? '') === 'beklemede') {
                    $k['durum'] = 'onaylandi';
                    $k['onay_tarihi'] = date('c');
                    $onaylananEposta = $k['eposta'] ?? '';
                    $basari = true;
                }
            }
            $kayitlar[] = json_encode($k, JSON_UNESCAPED_UNICODE);
        }
        if (!$bulundu) { $gecersiz = true; }
        if ($basari) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, implode("\n", $kayitlar) . "\n");
        }
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    } else {
        $gecersiz = true;
    }
} else {
    $gecersiz = true;
}

// Karşılama maili — onaylayan aboneye gönder
if ($basari && $onaylananEposta !== '') {
    $cfgPath = dirname(__DIR__) . '/.smtp-ayar.json';
    $cfg = is_readable($cfgPath) ? json_decode(file_get_contents($cfgPath), true) : null;
    if ($cfg && !empty($cfg['kullanici']) && !empty($cfg['sifre'])) {
        $smtpHost = !empty($cfg['sunucu']) ? $cfg['sunucu'] : 'localhost';
        $smtpPort = !empty($cfg['port']) ? (int)$cfg['port'] : 465;
        $iptalLink = 'https://irrigatr.com/bulten-iptal.php?token=' . $token;

        $subject = '=?UTF-8?B?' . base64_encode('Hoş geldiniz! Bülteniniz aktif — Irriga Mühendislik') . '?=';

        $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#f8fafc;color:#334155;">
<div style="background:#0F172A;padding:28px 32px;border-radius:12px 12px 0 0;">
  <span style="font-weight:800;font-size:18px;color:#fff;letter-spacing:-0.01em;">Irriga Mühendislik</span>
  <span style="display:block;font-size:12px;color:#64748B;margin-top:4px;">irrigatr.com</span>
</div>
<div style="background:#fff;padding:32px;border:1px solid #E2E8F0;border-top:none;">
  <h2 style="font-size:20px;font-weight:700;color:#0F172A;margin:0 0 16px;line-height:1.3;">Bültene hoş geldiniz!</h2>
  <p style="font-size:15px;line-height:1.7;color:#475569;margin:0 0 16px;">
    Aboneliğiniz onaylandı. Bundan böyle Trendyol ve e-ticaret rehberleri doğrudan gelen kutunuza gelecek.
  </p>
  <p style="font-size:15px;line-height:1.7;color:#475569;margin:0 0 24px;">
    Mevcut rehberlerimize göz atmak ister misiniz?
  </p>
  <a href="https://irrigatr.com/blog.html"
     style="display:inline-block;padding:14px 28px;background:#7C3AED;color:#fff;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none;letter-spacing:-0.01em;">
    Rehberleri İncele →
  </a>
</div>
<div style="background:#F1F5F9;padding:14px 32px;border-radius:0 0 12px 12px;border:1px solid #E2E8F0;border-top:none;">
  <p style="font-size:12px;color:#94A3B8;margin:0;">
    <a href="https://irrigatr.com" style="color:#7C3AED;text-decoration:none;">irrigatr.com</a>
    &nbsp;·&nbsp;
    <a href="' . htmlspecialchars($iptalLink) . '" style="color:#94A3B8;">Abonelikten çık</a>
  </p>
</div>
</body></html>';

        $textBody  = "Bültene hoş geldiniz — Irriga Mühendislik\n\n";
        $textBody .= "Aboneliğiniz onaylandı. Trendyol ve e-ticaret rehberleri artık doğrudan gelen kutunuza gelecek.\n\n";
        $textBody .= "Rehberleri inceleyin: https://irrigatr.com/blog.html\n\n";
        $textBody .= "---\nAbonelikten çıkmak için: $iptalLink\n";

        $errno = 0; $errstr = '';
        $uri = ($smtpPort === 465 ? 'ssl://' : '') . $smtpHost;
        $fp = @fsockopen($uri, $smtpPort, $errno, $errstr, 15);
        if ($fp) {
            stream_set_timeout($fp, 15);
            $oku = function () use ($fp) {
                $d = '';
                while ($l = fgets($fp, 515)) { $d .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
                return $d;
            };
            $komut = function ($c) use ($fp, $oku) { fwrite($fp, $c . "\r\n"); return $oku(); };
            $oku();
            $komut('EHLO irrigatr.com');
            // Not: bu satır içi gönderim yalnızca port 465 (ssl://) içindir; STARTTLS eklenmedi.
            $komut('AUTH LOGIN');
            $komut(base64_encode($cfg['kullanici']));
            if (strpos($komut(base64_encode($cfg['sifre'])), '235') === 0) {
                $komut('MAIL FROM:<' . $cfg['kullanici'] . '>');
                $komut('RCPT TO:<' . $onaylananEposta . '>');
                if (strpos($komut('DATA'), '354') === 0) {
                    $boundary = 'bu_' . md5($onaylananEposta . $token);
                    $headers  = "From: =?UTF-8?B?" . base64_encode("Irriga Mühendislik") . "?= <{$cfg['kullanici']}>\r\n";
                    $headers .= "To: $onaylananEposta\r\nSubject: $subject\r\n";
                    $headers .= "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
                    $body  = "--$boundary\r\n";
                    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
                    $body .= $textBody . "\r\n";
                    $body .= "--$boundary\r\n";
                    $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
                    $body .= $htmlBody . "\r\n";
                    $body .= "--$boundary--";
                    $komut($headers . "\r\n" . preg_replace('/^\./m', '..', $body) . "\r\n.");
                }
            }
            fwrite($fp, "QUIT\r\n");
            fclose($fp);
        }
    }
}
?><!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bülten Onayı — Irriga Mühendislik</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',system-ui,sans-serif;background:#F8FAFC;color:#334155;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px}
    .card{background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:48px 40px;max-width:480px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(15,23,42,0.07)}
    .icon{font-size:56px;margin-bottom:20px;line-height:1}
    h1{font-family:'Plus Jakarta Sans',sans-serif;font-size:24px;font-weight:800;color:#0F172A;margin-bottom:12px;letter-spacing:-0.02em}
    p{font-size:15px;line-height:1.7;color:#64748B;margin-bottom:24px}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;background:#7C3AED;color:#fff;border-radius:10px;font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:15px;text-decoration:none;transition:opacity 0.2s}
    .btn:hover{opacity:0.9}
    .logo{font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:15px;color:#0F172A;margin-bottom:32px;opacity:0.5}
  </style>
</head>
<body>
  <div class="logo">Irriga Mühendislik</div>
  <div class="card">
    <?php if ($basari): ?>
      <div class="icon">✅</div>
      <h1>Aboneliğiniz Onaylandı!</h1>
      <p>Trendyol ve e-ticaret güncellemelerini artık doğrudan gelen kutunuzda alacaksınız. İstediğiniz zaman abonelikten çıkabilirsiniz.</p>
      <a href="https://irrigatr.com/blog.html" class="btn">Rehberleri İncele →</a>
    <?php elseif ($zatenOnaylandi): ?>
      <div class="icon">👍</div>
      <h1>Zaten Abonesiniz</h1>
      <p>Bu e-posta adresi zaten bültenimize kayıtlı. Yeni içerikler doğrudan size ulaşıyor.</p>
      <a href="https://irrigatr.com" class="btn">Ana Sayfaya Dön</a>
    <?php else: ?>
      <div class="icon">⚠️</div>
      <h1>Geçersiz veya Süresi Dolmuş Link</h1>
      <p>Bu onay bağlantısı geçersiz veya daha önce kullanılmış. Yeniden abone olmayı deneyin.</p>
      <a href="https://irrigatr.com/blog.html" class="btn">Siteye Dön</a>
    <?php endif; ?>
  </div>
</body>
</html>

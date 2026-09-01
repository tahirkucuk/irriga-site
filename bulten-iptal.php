<?php
// Bülten abonelik iptali — e-postadaki "iptal et" linkine tıklanınca buraya gelir
$token = preg_replace('/[^a-f0-9]/', '', strtolower($_GET['token'] ?? ''));

$basari = false;
$zatenIptal = false;
$gecersiz = false;

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
                $durum = $k['durum'] ?? 'onaylandi';
                if ($durum === 'iptal') {
                    $zatenIptal = true;
                } else {
                    $k['durum'] = 'iptal';
                    $k['iptal_tarihi'] = date('c');
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
?><!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Abonelik İptali — Irriga Mühendislik</title>
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
    .btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;background:#0F172A;color:#fff;border-radius:10px;font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:15px;text-decoration:none;transition:opacity 0.2s}
    .btn:hover{opacity:0.8}
    .btn-ghost{background:transparent;color:#7C3AED;border:1.5px solid #CBD5E1;margin-top:12px}
    .btn-ghost:hover{border-color:#7C3AED;opacity:1}
    .logo{font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:15px;color:#0F172A;margin-bottom:32px;opacity:0.5}
  </style>
</head>
<body>
  <div class="logo">Irriga Mühendislik</div>
  <div class="card">
    <?php if ($basari): ?>
      <div class="icon">👋</div>
      <h1>Aboneliğiniz İptal Edildi</h1>
      <p>Artık bülten almayacaksınız. Pişman olursanız istediğiniz zaman yeniden abone olabilirsiniz.</p>
      <a href="https://irrigatr.com" class="btn">Ana Sayfaya Dön</a><br>
      <a href="https://irrigatr.com/blog.html" class="btn btn-ghost" style="display:inline-flex;margin-top:12px;">Rehberleri İncele</a>
    <?php elseif ($zatenIptal): ?>
      <div class="icon">✓</div>
      <h1>Zaten İptal Edilmiş</h1>
      <p>Bu abonelik daha önce iptal edildi. Artık bülten almıyorsunuz.</p>
      <a href="https://irrigatr.com" class="btn">Ana Sayfaya Dön</a>
    <?php else: ?>
      <div class="icon">⚠️</div>
      <h1>Geçersiz Link</h1>
      <p>Bu iptal bağlantısı geçersiz. Eğer hâlâ bülten alıyorsanız, aldığınız son e-postadaki iptal linkini kullanın.</p>
      <a href="https://irrigatr.com" class="btn">Ana Sayfaya Dön</a>
    <?php endif; ?>
  </div>
</body>
</html>

<?php
// ads-veri.php — Google Ads günlük performans verisi ucu.
// Google Ads Script'i her gün POST eder; Mission Control paneli GET ile okur.
// Kayıtlar tarih bazlı upsert edilir: /home/.../lead-kayitlari/ads-veri.jsonl
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$TOKEN = 'c6cceadc570de767a54b127f940669bc';
$sunulan = $_POST['token'] ?? $_GET['token'] ?? '';
if (!hash_equals($TOKEN, $sunulan)) {
    http_response_code(404);
    exit;
}

$dir = dirname(__DIR__) . '/lead-kayitlari';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
$dosya = $dir . '/ads-veri.jsonl';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['veri'])) {
    // veri: JSON dizi [{tarih:"YYYY-MM-DD", maliyet:12.34, tiklama:5, gosterim:100, donusum:1}, ...]
    $gelen = json_decode($_POST['veri'], true);
    if (!is_array($gelen)) { echo json_encode(['ok' => false, 'error' => 'geçersiz veri']); exit; }

    $fh = fopen($dosya, 'c+');
    if (!$fh) { echo json_encode(['ok' => false, 'error' => 'dosya açılamadı']); exit; }
    flock($fh, LOCK_EX);

    // mevcut kayıtları oku (tarih → satır)
    $mevcut = [];
    rewind($fh);
    while (($satir = fgets($fh)) !== false) {
        $k = json_decode(trim($satir), true);
        if ($k && isset($k['tarih'])) $mevcut[$k['tarih']] = $k;
    }
    // upsert
    $sayi = 0;
    foreach ($gelen as $g) {
        $tarih = $g['tarih'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) continue;
        $mevcut[$tarih] = [
            'tarih'    => $tarih,
            'maliyet'  => round((float)($g['maliyet'] ?? 0), 2),
            'tiklama'  => (int)($g['tiklama'] ?? 0),
            'gosterim' => (int)($g['gosterim'] ?? 0),
            'donusum'  => (float)($g['donusum'] ?? 0),
            'guncellendi' => date('c'),
        ];
        $sayi++;
    }
    ksort($mevcut);
    ftruncate($fh, 0);
    rewind($fh);
    foreach ($mevcut as $k) fwrite($fh, json_encode($k, JSON_UNESCAPED_UNICODE) . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    echo json_encode(['ok' => true, 'islenen' => $sayi, 'toplamGun' => count($mevcut)]);
    exit;
}

// GET → tüm kayıtlar (son 90 gün)
$satirlar = [];
if (is_readable($dosya)) {
    foreach (file($dosya, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $s) {
        $k = json_decode($s, true);
        if ($k) $satirlar[] = $k;
    }
}
$satirlar = array_slice($satirlar, -90);
echo json_encode(['ok' => true, 'gunler' => $satirlar], JSON_UNESCAPED_UNICODE);

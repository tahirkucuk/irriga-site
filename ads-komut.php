<?php
// ads-komut.php — Google Ads komut kuyruğu (panel → Script köprüsü).
// Panel komut yazar (durdur/başlat/bütçe); hesaptaki Google Ads Script periyodik
// çalışıp komutları çeker+uygular ve kampanya listesini raporlar.
// Developer token gerekmez — mevcut Script→PHP hattını kullanır.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$TOKEN = 'c6cceadc570de767a54b127f940669bc';
$sunulan = $_POST['token'] ?? $_GET['token'] ?? '';
if (!hash_equals($TOKEN, $sunulan)) { http_response_code(404); exit; }

$dir = dirname(__DIR__) . '/lead-kayitlari';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
$kuyrukDosya = $dir . '/ads-komut-kuyruk.json';   // bekleyen komutlar
$kampDosya   = $dir . '/ads-kampanyalar.json';     // Script'in raporladığı kampanya listesi

function oku($f) { return is_readable($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function yaz($f, $v) {
    $fh = fopen($f, 'c+'); if (!$fh) return false;
    flock($fh, LOCK_EX); ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($v, JSON_UNESCAPED_UNICODE)); fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    return true;
}

$eylem = $_POST['eylem'] ?? $_GET['eylem'] ?? '';

// ── Panel: komut ekle ──
if ($eylem === 'ekle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $komut = json_decode($_POST['komut'] ?? '', true);
    if (!is_array($komut) || empty($komut['tip'])) { echo json_encode(['ok' => false, 'error' => 'geçersiz komut']); exit; }
    $kuyruk = oku($kuyrukDosya);
    $komut['id'] = uniqid('k', true);
    $komut['eklendi'] = date('c');
    $kuyruk[] = $komut;
    yaz($kuyrukDosya, $kuyruk);
    echo json_encode(['ok' => true, 'bekleyen' => count($kuyruk)]);
    exit;
}

// ── Panel: durum (bekleyen komutlar + kampanya listesi) ──
if ($eylem === 'durum') {
    echo json_encode(['ok' => true, 'bekleyen' => oku($kuyrukDosya), 'kampanyalar' => oku($kampDosya)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Script: bekleyen komutları çek + kuyruğu temizle ──
if ($eylem === 'al') {
    $kuyruk = oku($kuyrukDosya);
    yaz($kuyrukDosya, []);
    echo json_encode(['ok' => true, 'komutlar' => $kuyruk], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Script: kampanya listesini raporla (ad/durum/bütçe) ──
if ($eylem === 'kampanyalar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $liste = json_decode($_POST['kampanyalar'] ?? '', true);
    if (!is_array($liste)) { echo json_encode(['ok' => false, 'error' => 'geçersiz liste']); exit; }
    yaz($kampDosya, $liste);
    echo json_encode(['ok' => true, 'sayi' => count($liste)]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'bilinmeyen eylem']);

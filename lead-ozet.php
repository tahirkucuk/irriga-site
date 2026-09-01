<?php
// Bekçi rutini için özet istatistik ucu (token korumalı, salt okunur)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

// Token GET veya POST ile kabul edilir; POST tercih edilir (log/referer sızıntısını azaltır)
$sunulan = $_POST['token'] ?? $_GET['token'] ?? '';
if (!hash_equals('4eb33efba7c72d0ca2dc66a2f3cc3c95', $sunulan)) {
    http_response_code(404);
    exit;
}

$dir = dirname(__DIR__) . '/lead-kayitlari';

function jsonl_say($yol, $gunSiniri = null) {
    if (!is_readable($yol)) return ['toplam' => 0, 'son7gun' => 0];
    $toplam = 0; $son = 0;
    $esik = time() - 7 * 86400;
    foreach (file($yol, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $satir) {
        $toplam++;
        $k = json_decode($satir, true);
        if ($k && isset($k['tarih']) && strtotime($k['tarih']) >= $esik) $son++;
    }
    return ['toplam' => $toplam, 'son7gun' => $son];
}

$smtpHata = 0;
$hataYolu = $dir . '/smtp-hatalar.log';
if (is_readable($hataYolu)) {
    $esik = time() - 7 * 86400;
    foreach (file($hataYolu, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $satir) {
        $t = strtotime(substr($satir, 0, 25));
        if ($t && $t >= $esik) $smtpHata++;
    }
}

echo json_encode([
    'leadler'        => jsonl_say($dir . '/leads.jsonl'),
    'aboneler'       => jsonl_say($dir . '/aboneler.jsonl'),
    'smtp_hata_7gun' => $smtpHata,
    'sunucu_zamani'  => date('c'),
], JSON_UNESCAPED_UNICODE);

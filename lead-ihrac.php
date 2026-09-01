<?php
// leads.jsonl'ı JSON olarak döndürür — cloud rutin Airtable senkronizasyonu için.
// Token korumalı, salt okunur.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$sunulan = $_POST['token'] ?? $_GET['token'] ?? '';
if (!hash_equals('7f1e76431383b849fee2f2e192c272ae', $sunulan)) {
    http_response_code(404);
    exit;
}

$leadDosya = dirname(__DIR__) . '/lead-kayitlari/leads.jsonl';

if (!is_readable($leadDosya)) {
    echo json_encode(['ok' => true, 'leads' => [], 'toplam' => 0]);
    exit;
}

$leads = [];
foreach (file($leadDosya, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $satir) {
    $k = json_decode($satir, true);
    if ($k) $leads[] = $k;
}

echo json_encode(['ok' => true, 'leads' => $leads, 'toplam' => count($leads)], JSON_UNESCAPED_UNICODE);

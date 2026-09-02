<?php
// randevu.php — Ön görüşme randevu sistemi (self-booking + panel + cloud rutin köprüsü).
// Akış: lead randevu.html'den ya da danışman panelden slot seçer → kuyruğa düşer (bekliyor)
//       → saatlik cloud rutini kuyruğu alır, Google Calendar etkinliği (Meet + davet) oluşturur,
//         Airtable Görüşmeler'e kaydeder, buraya "tamam" bildirir (onaylandi).
// Uygunluk + talep PUBLIC (honeypot/limit korumalı); al/tamam/hata/liste TOKEN ister.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$TOKEN = 'd4ea55f1ef209dfa0cad53eeaa69a4d7';

// ── Uygunluk ayarları (danışman müsaitliği) ──
$GUN_SAYISI   = 14;                            // kaç gün ilerisi gösterilsin
$SLOT_DK      = 30;                            // slot uzunluğu (dk)
$PENCERELER   = [['10:00','12:00'], ['14:00','17:00']]; // günlük müsaitlik pencereleri
$HAFTA_GUNLERI = [1,2,3,4,5];                  // Pzt=1 … Cum=5
$MIN_ON_SURE  = 4 * 3600;                      // en erken now+4 saat

$dir = dirname(__DIR__) . '/lead-kayitlari';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
$dosya = $dir . '/randevular.jsonl';

function kayitlariOku($dosya) {
    $liste = [];
    if (is_readable($dosya)) {
        foreach (file($dosya, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $s) {
            $k = json_decode($s, true);
            if ($k) $liste[] = $k;
        }
    }
    return $liste;
}
function kayitlariYaz($dosya, $liste) {
    $fh = fopen($dosya, 'c+'); if (!$fh) return false;
    flock($fh, LOCK_EX); ftruncate($fh, 0); rewind($fh);
    foreach ($liste as $k) fwrite($fh, json_encode($k, JSON_UNESCAPED_UNICODE)."\n");
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    return true;
}
// Güvenilir SMTP gönderimi (site sunucusu) — Google Calendar davet maili kırılgan olduğu için
// Meet bağlantısı bu kanaldan da gönderilir. Başarılıysa true.
function mailYolla($to, $subj, $html) {
    $cfg = json_decode(@file_get_contents(dirname(__DIR__).'/.smtp-ayar.json'), true);
    if (!$cfg || empty($cfg['kullanici']) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $host = $cfg['sunucu'] ?? 'localhost'; $port = (int)($cfg['port'] ?? 465);
    $fp = @fsockopen(($port===465?'ssl://':'').$host, $port, $e, $s, 12);
    if (!$fp) return false;
    stream_set_timeout($fp, 12);
    $r = function() use ($fp) { $d=''; while($l=fgets($fp,515)){$d.=$l; if(strlen($l)<4||$l[3]===' ')break;} return $d; };
    $c = function($cmd) use ($fp,$r) { fwrite($fp,$cmd."\r\n"); return $r(); };
    $r(); $c('EHLO irriga.com.tr');
    if ($port===587) { $c('STARTTLS'); @stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT); $c('EHLO irriga.com.tr'); }
    $c('AUTH LOGIN'); $c(base64_encode($cfg['kullanici']));
    $ok = false;
    if (strpos($c(base64_encode($cfg['sifre'])),'235')===0
        && strpos($c("MAIL FROM:<{$cfg['kullanici']}>"),'250')===0
        && strpos($c("RCPT TO:<$to>"),'250')===0
        && strpos($c('DATA'),'354')===0) {
        $subjEnc = '=?UTF-8?B?'.base64_encode($subj).'?=';
        $h = "From: =?UTF-8?B?".base64_encode("Irriga Mühendislik")."?= <{$cfg['kullanici']}>\r\nTo: $to\r\nSubject: $subjEnc\r\nDate: ".date('r')."\r\nMessage-ID: <".uniqid('',true)."@irriga.com.tr>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
        $c($h."\r\n".preg_replace('/^\./m','..',$html)."\r\n.");
        $ok = true;
    }
    $c('QUIT'); fclose($fp);
    return $ok;
}
// Müsait slotları üret (dolu olanlar hariç)
function slotlariUret($GUN_SAYISI,$SLOT_DK,$PENCERELER,$HAFTA_GUNLERI,$MIN_ON_SURE,$dosya) {
    $tz = new DateTimeZone('Europe/Istanbul');
    $simdi = new DateTime('now', $tz);
    $esik = (clone $simdi)->modify('+'.$MIN_ON_SURE.' seconds');
    $dolu = [];
    foreach (kayitlariOku($dosya) as $k) {
        if (in_array($k['durum'] ?? '', ['bekliyor','isleniyor','onaylandi'])) $dolu[$k['tarih']] = true;
    }
    $slotlar = [];
    for ($g = 0; $g < $GUN_SAYISI; $g++) {
        $gun = (clone $simdi)->modify("+{$g} days");
        if (!in_array((int)$gun->format('N'), $HAFTA_GUNLERI)) continue;
        foreach ($PENCERELER as [$bas, $bit]) {
            $t = new DateTime($gun->format('Y-m-d').' '.$bas, $tz);
            $son = new DateTime($gun->format('Y-m-d').' '.$bit, $tz);
            while ($t < $son) {
                $iso = $t->format('Y-m-d\TH:i');
                if ($t > $esik && empty($dolu[$iso])) $slotlar[] = $iso;
                $t->modify("+{$SLOT_DK} minutes");
            }
        }
    }
    return $slotlar;
}

$eylem = $_POST['eylem'] ?? $_GET['eylem'] ?? '';

// ── PUBLIC: müsait slotlar ──
if ($eylem === 'uygunluk') {
    echo json_encode(['ok'=>true,'slotDk'=>$SLOT_DK,
        'slotlar'=>slotlariUret($GUN_SAYISI,$SLOT_DK,$PENCERELER,$HAFTA_GUNLERI,$MIN_ON_SURE,$dosya)]);
    exit;
}

// ── PUBLIC: randevu talebi ──
if ($eylem === 'talep' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['website'])) { echo json_encode(['ok'=>true]); exit; } // honeypot
    $ad     = trim($_POST['ad'] ?? '');
    $eposta = strtolower(trim($_POST['eposta'] ?? ''));
    $tel    = trim($_POST['telefon'] ?? '');
    $not    = mb_substr(trim($_POST['not'] ?? ''), 0, 500);
    $tarih  = trim($_POST['tarih'] ?? '');
    $kaynak = ($_POST['kaynak'] ?? '') === 'panel' ? 'panel' : 'self-booking';
    $ciro   = mb_substr(trim($_POST['ciro'] ?? ''), 0, 40);   // ön-yeterlilik
    $magaza = mb_substr(trim($_POST['magaza'] ?? ''), 0, 200);
    // ── Kaynak/atıf omurgası (kaynak.js'ten gelir) — hangi kanal/sayfa lead getiriyor ──
    $atif = array(
        'kanal'        => mb_substr(trim($_POST['kanal'] ?? ''), 0, 30),
        'inis_sayfasi' => mb_substr(trim($_POST['inis_sayfasi'] ?? ''), 0, 120),
        'referrer'     => mb_substr(trim($_POST['referrer'] ?? ''), 0, 200),
        'utm_source'   => mb_substr(trim($_POST['utm_source'] ?? ''), 0, 60),
        'utm_medium'   => mb_substr(trim($_POST['utm_medium'] ?? ''), 0, 40),
        'utm_campaign' => mb_substr(trim($_POST['utm_campaign'] ?? ''), 0, 80),
        'utm_term'     => mb_substr(trim($_POST['utm_term'] ?? ''), 0, 80),
        'zid'          => preg_replace('/[^a-z0-9]/', '', mb_substr($_POST['zid'] ?? '', 0, 24)),
    );
    // sistemini-dene önizlemesinden gelen bağlam (kişisel link onay mailine eklenir)
    $deneListe  = array('tarim','finans','hukuk','saglik','gayrimenkul','teknoloji','egitim','eticaret');
    $deneSektor = in_array($_POST['dene_sektor'] ?? '', $deneListe, true) ? $_POST['dene_sektor'] : '';
    $deneMarka  = mb_substr(trim($_POST['dene_marka'] ?? ''), 0, 26);
    if ($ad === '' || !filter_var($eposta, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'error'=>'ad ve geçerli e-posta gerekli']); exit; }
    $slotlar = slotlariUret($GUN_SAYISI,$SLOT_DK,$PENCERELER,$HAFTA_GUNLERI,$MIN_ON_SURE,$dosya);
    if (!in_array($tarih, $slotlar)) { echo json_encode(['ok'=>false,'error'=>'seçilen saat artık müsait değil']); exit; }
    $liste = kayitlariOku($dosya);
    // limitler: e-posta başına 2 bekleyen, günde 30 talep
    $bekleyenAyni = 0; $bugunkuler = 0; $bugun = date('Y-m-d');
    foreach ($liste as $k) {
        if (($k['eposta'] ?? '') === $eposta && in_array($k['durum'], ['bekliyor','isleniyor'])) $bekleyenAyni++;
        if (substr($k['olusturuldu'] ?? '', 0, 10) === $bugun) $bugunkuler++;
    }
    if ($bekleyenAyni >= 2) { echo json_encode(['ok'=>false,'error'=>'bu e-posta için zaten bekleyen randevu var']); exit; }
    if ($bugunkuler >= 30) { echo json_encode(['ok'=>false,'error'=>'günlük talep limiti doldu, yarın deneyin']); exit; }
    $liste[] = [
        'id' => uniqid('rdv', true), 'ad' => $ad, 'eposta' => $eposta, 'telefon' => $tel,
        'not' => $not, 'tarih' => $tarih, 'kaynak' => $kaynak,
        'ciro' => $ciro, 'magaza' => $magaza, 'atif' => $atif,
        'dene' => ($deneSektor !== '') ? trim($deneSektor . ' · ' . $deneMarka, ' ·') : '',
        'durum' => 'bekliyor', 'olusturuldu' => date('c'),
    ];
    kayitlariYaz($dosya, $liste);

    // Anında admin bildirimi (ntfy) — Tahir saatlik rutini beklemeden lead'i görsün
    $ntfyMsg = "👤 {$ad}\n📅 {$tarih}\n📞 " . ($tel !== '' ? $tel : '—')
             . "\n💰 " . ($ciro !== '' ? $ciro : '—')
             . "\n🔗 {$kaynak}" . ($magaza !== '' ? "\n🏪 {$magaza}" : '');
    $ntfyHdr = "Title: Yeni Randevu Talebi\r\nTags: bell\r\nPriority: high\r\nContent-Type: text/plain; charset=utf-8\r\n";
    if (function_exists('curl_init')) {
        $ch = curl_init('https://ntfy.sh/basariustasi-ajans-2026');
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$ntfyMsg,
            CURLOPT_HTTPHEADER=>['Title: Yeni Randevu Talebi','Tags: bell','Priority: high'],
            CURLOPT_TIMEOUT=>8, CURLOPT_RETURNTRANSFER=>true]);
        @curl_exec($ch); @curl_close($ch);
    } else {
        @file_get_contents('https://ntfy.sh/basariustasi-ajans-2026', false, stream_context_create(
            ['http'=>['method'=>'POST','header'=>$ntfyHdr,'content'=>$ntfyMsg,'timeout'=>8]]));
    }

    // Admin bildirim maili (Tahir) — iletisim formundaki gibi randevu lead'i de maille gelsin (ntfy'ye EK)
    $adminHtml = "<div style='font-family:-apple-system,Arial,sans-serif;font-size:14px;line-height:1.7;color:#334155;'>"
        . "<h2 style='color:#0F172A;margin:0 0 12px;'>🔔 Yeni Randevu Talebi</h2>"
        . "<p style='margin:0'><b>Ad:</b> ".htmlspecialchars($ad)."<br>"
        . "<b>E-posta:</b> ".htmlspecialchars($eposta)."<br>"
        . "<b>Telefon:</b> ".htmlspecialchars($tel!==''?$tel:'—')."<br>"
        . "<b>Tarih:</b> ".htmlspecialchars($tarih)." (Europe/Istanbul)<br>"
        . "<b>Ciro:</b> ".htmlspecialchars($ciro!==''?$ciro:'—')."<br>"
        . "<b>Mağaza:</b> ".htmlspecialchars($magaza!==''?$magaza:'—')."<br>"
        . "<b>Kaynak:</b> ".htmlspecialchars($kaynak)." · <b>Kanal:</b> ".htmlspecialchars($atif['kanal']!==''?$atif['kanal']:'—')."<br>"
        . "<b>Not:</b> ".htmlspecialchars($not!==''?$not:'—')."</p>"
        . "<p style='font-size:12px;color:#64748B;margin-top:14px;'>Takvim daveti + Meet linki saatlik rutinle otomatik oluşturulur; ayrıca lead'e onay maili gönderildi.</p></div>";
    mailYolla('tahirkucuk@gmail.com', "🔔 Yeni Randevu Talebi — {$ad} ({$tarih})", $adminHtml);

    // Anında onay maili (davet saatlik rutinle gelir; kullanıcı boşlukta kalmasın)
    $cfg = json_decode(@file_get_contents(dirname(__DIR__).'/.smtp-ayar.json'), true);
    if ($cfg && !empty($cfg['kullanici'])) {
        $tz = new DateTimeZone('Europe/Istanbul');
        $t = DateTime::createFromFormat('Y-m-d\TH:i', $tarih, $tz);
        $aylar=['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
        $gunler=['','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar'];
        $tStr = $t ? ($t->format('j').' '.$aylar[(int)$t->format('n')].' '.$gunler[(int)$t->format('N')].' '.$t->format('H:i')) : $tarih;
        $ilkAd = htmlspecialchars(explode(' ', $ad)[0]);
        $deneBlok = '';
        if ($deneSektor !== '') {
            $deneUrl = 'https://irriga.com.tr/sistemini-dene.html?sektor=' . $deneSektor
                     . ($deneMarka !== '' ? '&marka=' . rawurlencode($deneMarka) : '');
            $deneKim = ($deneMarka !== '') ? '<b>' . htmlspecialchars($deneMarka) . '</b> markasıyla' : 'sektörünüze özel';
            $deneBlok = "<p style='background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:12px 16px;'>"
                      . "🎛 <b>Görüşme öncesi göz atın:</b> {$deneKim} hazırladığınız sistem önizlemeniz sizi bekliyor. "
                      . "Görüşmede tam da bunu konuşacağız.<br>"
                      . "<a href='{$deneUrl}' style='color:#D97706;font-weight:700;'>Önizlemeni tekrar aç →</a></p>";
        }
        $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:24px 0;background:#F1F5F9;font-family:-apple-system,Arial,sans-serif;'>
<table width='100%'><tr><td align='center'><table width='560' style='max-width:560px;width:100%;'>
<tr><td style='background:#0F172A;padding:20px 26px;border-radius:12px 12px 0 0;'><span style='font-size:17px;font-weight:700;color:#fff;'>Başarı <span style='color:#F59E0B;'>Ustası</span></span></td></tr>
<tr><td style='background:#fff;padding:28px 26px;font-size:14.5px;line-height:1.75;color:#334155;'>
<p><b>{$ilkAd},</b> randevu talebiniz alındı ✅</p>
<p style='background:#F1F5F9;border-radius:10px;padding:12px 16px;'><b>📅 {$tStr}</b> · 30 dk · Google Meet</p>
<p>Google Takvim daveti (Meet bağlantısıyla) <b>genellikle 1 saat içinde</b> bu adrese gelir. Daveti kabul etmeniz yeterli.</p>
{$deneBlok}
<p style='font-size:13px;color:#64748B;'>Saat değişikliği gerekirse bu maile yanıt verebilirsiniz.</p>
</td></tr>
<tr><td style='background:#0F172A;padding:14px 26px;border-radius:0 0 12px 12px;'><p style='margin:0;font-size:11px;color:#475569;'>irriga.com.tr · Trendyol & Pazaryeri Danışmanlığı</p></td></tr>
</table></td></tr></table></body></html>";
        $subj='=?UTF-8?B?'.base64_encode("✅ Randevu talebiniz alındı — {$tStr}").'?=';
        $host=$cfg['sunucu']??'localhost'; $port=(int)($cfg['port']??465);
        $fp=@fsockopen(($port===465?'ssl://':'').$host,$port,$e,$s,12);
        if($fp){
            stream_set_timeout($fp,12);
            $r=function()use($fp){$d='';while($l=fgets($fp,515)){$d.=$l;if(strlen($l)<4||$l[3]===' ')break;}return $d;};
            $c=function($cmd)use($fp,$r){fwrite($fp,$cmd."\r\n");return $r();};
            $r();$c('EHLO irriga.com.tr');
            if($port===587){$c('STARTTLS');@stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);$c('EHLO irriga.com.tr');}
            $c('AUTH LOGIN');$c(base64_encode($cfg['kullanici']));
            if(strpos($c(base64_encode($cfg['sifre'])),'235')===0
               && strpos($c("MAIL FROM:<{$cfg['kullanici']}>"),'250')===0
               && strpos($c("RCPT TO:<$eposta>"),'250')===0
               && strpos($c('DATA'),'354')===0){
                $h="From: =?UTF-8?B?".base64_encode("Irriga Mühendislik")."?= <{$cfg['kullanici']}>\r\nTo: $eposta\r\nSubject: $subj\r\nDate: ".date('r')."\r\nMessage-ID: <".uniqid('',true)."@irriga.com.tr>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
                $c($h."\r\n".preg_replace('/^\./m','..',$html)."\r\n.");
            }
            $c('QUIT'); fclose($fp);
        }
    }
    echo json_encode(['ok'=>true,'mesaj'=>'Randevu talebiniz alındı. Onay e-postası gönderildi.']);
    exit;
}

// ── Buradan sonrası TOKEN ister ──
$sunulan = $_POST['token'] ?? $_GET['token'] ?? '';
if (!hash_equals($TOKEN, $sunulan)) { http_response_code(404); exit; }

// Rutin: bekleyenleri al (isleniyor'a çek)
if ($eylem === 'al') {
    $liste = kayitlariOku($dosya); $alinan = [];
    foreach ($liste as &$k) {
        if ($k['durum'] === 'bekliyor') { $k['durum'] = 'isleniyor'; $alinan[] = $k; }
    }
    unset($k);
    kayitlariYaz($dosya, $liste);
    echo json_encode(['ok'=>true,'randevular'=>$alinan], JSON_UNESCAPED_UNICODE);
    exit;
}
// Rutin: tamamlandı işaretle
if ($eylem === 'tamam' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $meetLink = trim($_POST['meet_link'] ?? '');
    $liste = kayitlariOku($dosya); $bulundu = false; $bilgi = null;
    foreach ($liste as &$k) {
        if ($k['id'] === $id) {
            $k['durum'] = 'onaylandi';
            $k['etkinlik_id'] = $_POST['etkinlik_id'] ?? '';
            $k['meet_link'] = $meetLink;
            $k['onaylandi'] = date('c');
            $bulundu = true;
            $bilgi = ['eposta'=>$k['eposta']??'', 'ad'=>$k['ad']??'', 'tarih'=>$k['tarih']??''];
        }
    }
    unset($k);
    kayitlariYaz($dosya, $liste);

    // Meet bağlantısını lead'e GÜVENİLİR kanaldan (site SMTP) gönder.
    // Google Calendar davet maili API üzerinden kırılgan (spam/gönderilmeme); bu, garanti kanaldır.
    $mailGitti = false;
    if ($bulundu && $meetLink !== '' && $bilgi && filter_var($bilgi['eposta'], FILTER_VALIDATE_EMAIL)) {
        $tz = new DateTimeZone('Europe/Istanbul');
        $t = DateTime::createFromFormat('Y-m-d\TH:i', $bilgi['tarih'], $tz);
        $aylar=['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
        $gunler=['','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar'];
        $tStr = $t ? ($t->format('j').' '.$aylar[(int)$t->format('n')].' '.$gunler[(int)$t->format('N')].' '.$t->format('H:i')) : $bilgi['tarih'];
        $ilkAd = htmlspecialchars(explode(' ', $bilgi['ad'])[0]);
        $meetSafe = htmlspecialchars($meetLink, ENT_QUOTES);
        $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:24px 0;background:#F1F5F9;font-family:-apple-system,Arial,sans-serif;'>
<table width='100%'><tr><td align='center'><table width='560' style='max-width:560px;width:100%;'>
<tr><td style='background:#0F172A;padding:20px 26px;border-radius:12px 12px 0 0;'><span style='font-size:17px;font-weight:700;color:#fff;'>Başarı <span style='color:#F59E0B;'>Ustası</span></span></td></tr>
<tr><td style='background:#fff;padding:28px 26px;font-size:14.5px;line-height:1.75;color:#334155;'>
<p><b>{$ilkAd},</b> görüşmeniz onaylandı ✅</p>
<p style='background:#F1F5F9;border-radius:10px;padding:12px 16px;'><b>📅 {$tStr}</b> · 30 dk · Google Meet</p>
<p style='text-align:center;margin:22px 0;'><a href='{$meetSafe}' style='display:inline-block;background:#25D366;color:#fff;text-decoration:none;font-weight:700;padding:13px 26px;border-radius:10px;'>🎥 Meet Görüşmesine Katıl</a></p>
<p style='font-size:13px;color:#64748B;'>Bağlantı çalışmazsa: <a href='{$meetSafe}' style='color:#2563EB;'>{$meetSafe}</a><br>Görüşme saatinde bu bağlantıya tıklamanız yeterli. Saat değişikliği için bu maile yanıt verebilirsiniz.</p>
</td></tr>
<tr><td style='background:#0F172A;padding:14px 26px;border-radius:0 0 12px 12px;'><p style='margin:0;font-size:11px;color:#475569;'>irriga.com.tr · Trendyol & Pazaryeri Danışmanlığı</p></td></tr>
</table></td></tr></table></body></html>";
        $mailGitti = mailYolla($bilgi['eposta'], "✅ Görüşmeniz onaylandı — Meet bağlantınız hazır", $html);
    }
    echo json_encode(['ok'=>$bulundu, 'mail'=>$mailGitti]);
    exit;
}
// Rutin: hata işaretle (tekrar denenmez, panelde görünür)
if ($eylem === 'hata' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $liste = kayitlariOku($dosya); $bulundu = false;
    foreach ($liste as &$k) {
        if ($k['id'] === $id) { $k['durum'] = 'hata'; $k['hata_neden'] = mb_substr($_POST['neden'] ?? '', 0, 300); $bulundu = true; }
    }
    unset($k);
    kayitlariYaz($dosya, $liste);
    echo json_encode(['ok'=>$bulundu]);
    exit;
}
// Admin: kayıt sil (test temizliği / iptal)
if ($eylem === 'sil' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $liste = kayitlariOku($dosya);
    $yeni = array_values(array_filter($liste, function($k) use ($id) { return $k['id'] !== $id; }));
    kayitlariYaz($dosya, $yeni);
    echo json_encode(['ok'=>count($yeni) < count($liste)]);
    exit;
}
// Panel: son kayıtlar
if ($eylem === 'liste') {
    $liste = kayitlariOku($dosya);
    echo json_encode(['ok'=>true,'randevular'=>array_slice($liste, -50)], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'bilinmeyen eylem']);

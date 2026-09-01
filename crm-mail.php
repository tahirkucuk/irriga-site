<?php
// crm-mail.php — CRM takip mailleri (günlük CRM Bakım rutini çağırır, token'lı).
// tipler: noshow (randevuya gelmeyene yeniden davet), teklif (cevapsız teklif hatırlatma),
//         onboarding (kazanılan müşteriye hoş geldin).
// POST: token, tip, eposta, ad, [ek] (teklif başlığı vb.)
// Dedup: aynı (tip+eposta) 30 gün içinde ikinci kez gönderilmez.
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }
if (!hash_equals('c41af87250434ecadaf9513850a46357', $_POST['token'] ?? '')) { http_response_code(404); exit; }

$SITE = 'https://irrigatr.com';
$tip = $_POST['tip'] ?? '';
$eposta = strtolower(trim($_POST['eposta'] ?? ''));
$ad = trim($_POST['ad'] ?? '');
$ek = trim($_POST['ek'] ?? '');
if (!in_array($tip, ['noshow','teklif','onboarding']) || !filter_var($eposta, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok'=>false,'error'=>'tip (noshow|teklif|onboarding) + geçerli eposta gerekli']); exit;
}

$dir = dirname(__DIR__) . '/lead-kayitlari';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
$logDosya = $dir . '/crm-mail-log.jsonl';

// dedup: 30 gün
$esik = time() - 30*24*3600;
if (is_readable($logDosya)) {
    foreach (file($logDosya, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $s) {
        $k = json_decode($s, true);
        if ($k && $k['tip']===$tip && $k['eposta']===$eposta && strtotime($k['zaman']??'') > $esik) {
            echo json_encode(['ok'=>true,'gonderildi'=>false,'not'=>'30 gün içinde zaten gönderilmiş (dedup)']); exit;
        }
    }
}

$ilkAd = htmlspecialchars(explode(' ', $ad)[0] ?: 'Merhaba');
$btn = function($url,$m){ return "<div style='margin:18px 0;'><a href='{$url}' style='display:inline-block;background:#F59E0B;color:#0F172A;font-weight:700;font-size:14px;padding:12px 22px;border-radius:10px;text-decoration:none;'>{$m}</a></div>"; };

$icerikler = [
  'noshow' => [
    'konu' => "{$ilkAd}, görüşmemizi kaçırdık — yeni saat seçelim mi?",
    'ic' => "<p><b>{$ilkAd},</b> planladığımız ön görüşmede buluşamadık — hiç sorun değil, yoğunluk hepimizde var. 🙂</p>
<p>Mağazan için ayırdığımız değerlendirme hâlâ geçerli. Aşağıdan sana uygun yeni bir saat seçmen yeterli; Google Meet daveti otomatik gelir.</p>
{$btn($SITE.'/randevu.html','📅 Yeni saat seç')}
<p style='font-size:13px;color:#64748B;'>Bu maile yanıt vererek de yazabilirsin.</p>",
  ],
  'teklif' => [
    'konu' => "{$ilkAd}, teklifimiz hakkında sorun var mı?",
    'ic' => "<p><b>{$ilkAd},</b> ".($ek ? "\"".htmlspecialchars($ek)."\" teklifimizi" : "sana ilettiğimiz teklifi")." birkaç gün önce paylaşmıştık.</p>
<p>Karar süreçleri zaman alır, gayet normal. Aklına takılan bir şey varsa — kapsam, süreç, fiyatlandırma — bu maile yanıt yazman yeterli; net şekilde açıklayalım.</p>
<p>İstersen 15 dakikalık kısa bir görüşmeyle de netleştirebiliriz:</p>
{$btn($SITE.'/randevu.html','📅 Kısa görüşme planla')}",
  ],
  'onboarding' => [
    'konu' => "Hoş geldin {$ilkAd}! 🎉 Birlikte çalışma sürecimiz",
    'ic' => "<p><b>{$ilkAd},</b> aramıza hoş geldin! Birlikte çalışacağımız için çok mutluyuz.</p>
<p><b>İlk hafta ne olacak?</b></p>
<p>1. Mağaza erişimlerini alacağız (Satıcı Paneli alt kullanıcı daveti yeterli — şifreni asla istemeyiz)<br>
2. Detaylı mağaza analizi + öncelik haritası çıkaracağız<br>
3. İlk aksiyon planını birlikte netleştireceğiz</p>
<p>Sorularını her zaman bu mail üzerinden ya da WhatsApp'tan iletebilirsin. Hızlı dönüş sözümüz: <b>en geç 24 saat.</b></p>
<p>Bol satışlı bir yolculuk olsun! 🚀<br><b>Irriga Mühendislik Ekibi</b></p>",
  ],
];
$secim = $icerikler[$tip];

$html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:24px 0;background:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='max-width:560px;width:100%;'>
<tr><td style='background:#0F172A;padding:20px 26px;border-radius:12px 12px 0 0;'>
  <span style='font-size:17px;font-weight:700;color:#fff;'>Başarı <span style='color:#F59E0B;'>Ustası</span></span>
</td></tr>
<tr><td style='background:#fff;padding:28px 26px;font-size:14.5px;line-height:1.75;color:#334155;'>{$secim['ic']}</td></tr>
<tr><td style='background:#0F172A;padding:14px 26px;border-radius:0 0 12px 12px;'>
  <p style='margin:0;font-size:11px;color:#475569;'>irrigatr.com · Trendyol & Pazaryeri Danışmanlığı</p>
</td></tr>
</table></td></tr></table></body></html>";

// SMTP
$cfg = json_decode(@file_get_contents(dirname(__DIR__).'/.smtp-ayar.json'), true);
if (!$cfg || empty($cfg['kullanici'])) { echo json_encode(['ok'=>false,'error'=>'smtp yok']); exit; }
$host=$cfg['sunucu']??'localhost'; $port=(int)($cfg['port']??465);
$subj='=?UTF-8?B?'.base64_encode($secim['konu']).'?=';
$fp=@fsockopen(($port===465?'ssl://':'').$host,$port,$e,$s,15);
if(!$fp){ echo json_encode(['ok'=>false,'error'=>'smtp bağlantı']); exit; }
stream_set_timeout($fp,15);
$r=function()use($fp){$d='';while($l=fgets($fp,515)){$d.=$l;if(strlen($l)<4||$l[3]===' ')break;}return $d;};
$c=function($cmd)use($fp,$r){fwrite($fp,$cmd."\r\n");return $r();};
$r();$c('EHLO irrigatr.com');if($port===587){$c('STARTTLS');stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);$c('EHLO irrigatr.com');}
$c('AUTH LOGIN');$c(base64_encode($cfg['kullanici']));
$okAuth = strpos($c(base64_encode($cfg['sifre'])),'235')===0;
$ok = $okAuth
  && strpos($c("MAIL FROM:<{$cfg['kullanici']}>"),'250')===0
  && strpos($c("RCPT TO:<$eposta>"),'250')===0
  && strpos($c('DATA'),'354')===0;
if ($ok) {
    $h="From: =?UTF-8?B?".base64_encode("Irriga Mühendislik")."?= <{$cfg['kullanici']}>\r\nTo: $eposta\r\nSubject: $subj\r\nDate: ".date('r')."\r\nMessage-ID: <".uniqid('',true)."@irrigatr.com>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    $ok = strpos($c($h."\r\n".preg_replace('/^\./m','..',$html)."\r\n."),'250')===0;
}
$c('QUIT'); fclose($fp);

if ($ok) {
    file_put_contents($logDosya, json_encode(['tip'=>$tip,'eposta'=>$eposta,'zaman'=>date('c')], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND|LOCK_EX);
}
echo json_encode(['ok'=>$ok,'gonderildi'=>$ok]);

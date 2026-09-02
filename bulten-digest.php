<?php
// Bülten digest — Webrazzi benzeri çok haberli format
// POST: token=... [gun=4] [limit=5]
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }
if (!hash_equals('e28a5d8319327c9908af21296e0ee6b0', $_POST['token'] ?? '')) { http_response_code(404); exit; }

$gun   = min((int)($_POST['gun']   ?? 4), 14);
$limit = min((int)($_POST['limit'] ?? 5), 10);

// posts.json oku
$jsonPath = __DIR__ . '/posts.json';
if (!is_readable($jsonPath)) { echo json_encode(['ok'=>false,'error'=>'posts.json okunamadı']); exit; }
$tumYazilar = json_decode(file_get_contents($jsonPath), true) ?: [];

$esik    = date('Y-m-d', strtotime("-{$gun} days"));
$yazilar = array_filter($tumYazilar, function($y) use ($esik) { return ($y['date'] ?? '1970-01-01') >= $esik; });
usort($yazilar, function($a,$b) { return strcmp($b['date'], $a['date']); });
$yazilar = array_slice(array_values($yazilar), 0, $limit);

if (empty($yazilar)) { echo json_encode(['ok'=>true,'gonderilen'=>0,'not'=>"Son {$gun} günde yeni yazı yok"]); exit; }

// SMTP
$cfg = json_decode(@file_get_contents(dirname(__DIR__).'/.smtp-ayar.json'), true);
if (!$cfg || empty($cfg['kullanici'])) { echo json_encode(['ok'=>false,'error'=>'smtp yok']); exit; }

// Aboneler
$aboneler = [];
$aboneDosya = dirname(__DIR__).'/lead-kayitlari/aboneler.jsonl';
if (is_readable($aboneDosya)) {
    foreach (file($aboneDosya, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $s) {
        $k = json_decode($s, true);
        if (!$k || empty($k['eposta'])) continue;
        if (($k['durum'] ?? 'onaylandi') !== 'onaylandi') continue;
        $aboneler[strtolower($k['eposta'])] = $k['token'] ?? '';
    }
}
// Test modu: sadece belirtilen adrese gönder, abone listesini atla
$testEposta = trim($_POST['test_eposta'] ?? '');
if ($testEposta !== '') {
    if (!filter_var($testEposta, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'error'=>'geçersiz test_eposta']); exit; }
    $aboneler = [strtolower($testEposta) => ''];
}

if (empty($aboneler)) { echo json_encode(['ok'=>true,'gonderilen'=>0,'not'=>'onaylı abone yok']); exit; }

// Kategori renk haritası
$RENKLER = [
    'Yapay Zeka' => '#7C3AED',
    'Trendyol'   => '#F59E0B',
    'Hepsiburada'=> '#F97316',
    'E-Ticaret'  => '#2563EB',
    'Pazaryeri'  => '#059669',
    'Girişim'    => '#DC2626',
];
function kategoriRenk($kat, $renkler) {
    foreach ($renkler as $k => $renk) { if (mb_stripos($kat, $k) !== false) return $renk; }
    return '#64748B';
}

// Tarih
$aylar = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
$tarih = date('j').' '.$aylar[(int)date('n')].' '.date('Y');
$sayi  = count($yazilar);

// Her haber kartı
function haberKart($y, $renkler) {
    $renk    = kategoriRenk($y['category'] ?? '', $renkler);
    $url     = 'https://irriga.com.tr/' . htmlspecialchars($y['url'] ?? '');
    $baslik  = htmlspecialchars($y['title'] ?? '');
    $ozet    = htmlspecialchars($y['excerpt'] ?? '');
    $kat     = mb_strtoupper($y['category'] ?? 'GÜNCEL');
    $sure    = htmlspecialchars($y['readTime'] ?? '');
    $imgField = $y['image'] ?? '';
    $slug     = str_replace('.html', '', $y['url'] ?? '');
    // Dış URL ise olduğu gibi kullan; iç path ise dosya sunucuda varsa domain ekle;
    // görsel yoksa kart görselsiz basılır (kırık resim ikonu olmasın)
    $thumb = '';
    if (strpos($imgField, 'http') === 0) {
        $thumb = $imgField;
    } elseif ($imgField !== '' && is_file(__DIR__ . '/' . $imgField)) {
        $thumb = 'https://irriga.com.tr/' . $imgField;
    } elseif (is_file(__DIR__ . '/media/thumbnails/thumb-' . $slug . '.jpg')) {
        $thumb = 'https://irriga.com.tr/media/thumbnails/thumb-' . $slug . '.jpg';
    }
    $gorselHucre = $thumb === '' ? '' : "
      <td width='180' style='vertical-align:top;padding:0;'>
        <a href='{$url}' style='display:block;'>
          <img src='{$thumb}' width='180' height='130' alt='' style='display:block;width:180px;height:130px;object-fit:cover;border-radius:0 10px 10px 0;'>
        </a>
      </td>";
    return "
<tr><td style='padding:0 16px 10px;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#fff;border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;'>
    <tr>
      <td style='padding:16px 18px 16px 20px;vertical-align:top;'>
        <div style='margin-bottom:10px;display:flex;align-items:center;gap:6px;'>
          <span style='display:inline-block;width:20px;height:3px;background:{$renk};border-radius:2px;'></span>
          <span style='font-size:10px;font-weight:700;color:{$renk};letter-spacing:0.1em;text-transform:uppercase;'>{$kat}</span>
          " . ($sure ? "<span style='font-size:10px;color:#94A3B8;margin-left:4px;'>{$sure}</span>" : "") . "
        </div>
        <h2 style='margin:0 0 8px;font-size:16px;font-weight:700;color:#0F172A;line-height:1.4;'>
          <a href='{$url}' style='color:#0F172A;text-decoration:none;'>{$baslik}</a>
        </h2>
        <p style='margin:0 0 14px;font-size:13px;color:#64748B;line-height:1.65;font-weight:400;'>{$ozet}</p>
        <a href='{$url}' style='font-size:12px;font-weight:500;color:{$renk};text-decoration:none;'>Devamını oku →</a>
      </td>{$gorselHucre}
    </tr>
  </table>
</td></tr>
<tr><td style='height:6px;'></td></tr>";
}

$kartlar = '';
foreach ($yazilar as $y) $kartlar .= haberKart($y, $RENKLER);

$subj = '=?UTF-8?B?'.base64_encode("Irriga Mühendislik · $tarih — $sayi güncel haber").'?=';

$html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:20px 0;background:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center'>
<table width='620' cellpadding='0' cellspacing='0' style='max-width:620px;width:100%;'>

<tr><td style='background:#0F172A;padding:24px 28px 20px;border-radius:12px 12px 0 0;'>
  <table width='100%'><tr>
    <td><span style='font-size:20px;font-weight:700;color:#FFFFFF;letter-spacing:-0.02em;'>Irriga Mühendislik</span>
      <span style='display:block;font-size:12px;color:#64748B;margin-top:3px;font-weight:400;'>irriga.com.tr · Trendyol & E-Ticaret</span></td>
    <td align='right'><span style='background:#F59E0B;color:#0F172A;font-size:10px;font-weight:600;padding:4px 12px;border-radius:20px;letter-spacing:0.06em;'>BÜLTEN</span></td>
  </tr></table>
</td></tr>

<tr><td style='background:#1E293B;padding:14px 28px;border-bottom:2px solid #F59E0B;'>
  <p style='margin:0;font-size:14px;font-weight:500;color:#E2E8F0;'>Güncel Haberler &nbsp;·&nbsp; $tarih</p>
  <p style='margin:4px 0 0;font-size:12px;color:#64748B;font-weight:400;'>Trendyol ve e-ticaret dünyasından $sayi seçki haber.</p>
</td></tr>

<tr><td style='background:#F8FAFC;padding:16px 0 8px;'>
  <table width='100%' cellpadding='0' cellspacing='0'>$kartlar</table>
</td></tr>

<tr><td style='padding:0 16px 16px;'>
  <table width='100%' cellpadding='0' cellspacing='0'><tr>
    <td style='background:#0F172A;padding:16px 24px;border-radius:10px;text-align:center;'>
      <a href='https://irriga.com.tr' style='font-size:13px;font-weight:500;color:#F59E0B;text-decoration:none;'>Tüm içeriklere git →</a>
    </td>
  </tr></table>
</td></tr>

<tr><td style='background:#0F172A;padding:18px 28px;border-radius:0 0 12px 12px;'>
  <p style='margin:0;font-size:11px;color:#475569;line-height:1.7;'>
    Irriga Mühendislik bültenine abonesin &nbsp;·&nbsp;
    <a href='https://irriga.com.tr' style='color:#64748B;'>irriga.com.tr</a><br>
    %%IPTAL_LINK%%
  </p>
</td></tr>

</table></td></tr></table>
</body></html>";

$text = "Irriga Mühendislik Bülten — $tarih\n\n";
foreach ($yazilar as $y) $text .= "• {$y['title']}\n  https://irriga.com.tr/{$y['url']}\n\n";

// SMTP
function smtp_digest($host,$port,$user,$pass,$to,$subj,$html,$text){
    $fp=@fsockopen(($port===465?'ssl://':'').$host,$port,$e,$s,15);if(!$fp)return false;
    stream_set_timeout($fp,15);
    $r=function()use($fp){$d='';while($l=fgets($fp,515)){$d.=$l;if(strlen($l)<4||$l[3]===' ')break;}return $d;};
    $c=function($cmd)use($fp,$r){fwrite($fp,$cmd."\r\n");return $r();};
    $r();$c('EHLO irriga.com.tr');if($port===587){if(strpos($c('STARTTLS'),'220')!==0){fclose($fp);return false;}if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)){fclose($fp);return false;}$c('EHLO irriga.com.tr');}$c('AUTH LOGIN');$c(base64_encode($user));
    if(strpos($c(base64_encode($pass)),'235')!==0){fclose($fp);return false;}
    if(strpos($c("MAIL FROM:<$user>"),'250')!==0){fclose($fp);return false;}
    if(strpos($c("RCPT TO:<$to>"),'250')!==0){fclose($fp);return false;}
    if(strpos($c('DATA'),'354')!==0){fclose($fp);return false;}
    $b='bd_'.md5($to.microtime());
    $h="From: =?UTF-8?B?".base64_encode("Irriga Mühendislik Bülten")."?= <$user>\r\nTo: $to\r\nSubject: $subj\r\nDate: ".date('r')."\r\nMessage-ID: <".uniqid('',true)."@irriga.com.tr>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n";
    $body="--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$text\r\n--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html\r\n--$b--";
    if(strpos($c($h."\r\n".preg_replace('/^\./m','..',$body)."\r\n."),'250')!==0){fclose($fp);return false;}
    $c('QUIT');fclose($fp);return true;
}

$host=$cfg['sunucu']??'localhost'; $port=(int)($cfg['port']??465);
$gonderilen=0; $basarisiz=0;

foreach($aboneler as $eposta=>$token){
    $iptal = $token ? "https://irriga.com.tr/bulten-iptal.php?token=$token" : 'https://irriga.com.tr/bulten-iptal.php';
    $aboHtml = str_replace('%%IPTAL_LINK%%', "<a href='$iptal' style='color:#475569;'>Abonelikten çık</a>", $html);
    $aboText = $text."---\nAbonelikten çıkmak için: $iptal";
    $ok = smtp_digest($host,$port,$cfg['kullanici'],$cfg['sifre'],$eposta,$subj,$aboHtml,$aboText);
    $ok ? $gonderilen++ : $basarisiz++;
    if(count($aboneler)>1) usleep(350000);
}

echo json_encode(['ok'=>true,'v'=>'thumb-check','gonderilen'=>$gonderilen,'basarisiz'=>$basarisiz,'toplam'=>count($aboneler),'haberSayisi'=>$sayi], JSON_UNESCAPED_UNICODE);

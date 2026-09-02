<?php
// mail-test.php — SMTP teslimat tanısı (geçici araç). Verilen adrese test maili
// gönderir ve SMTP sunucusunun HER yanıtını döndürür — nerede reddedildiği görülür.
// POST: token, eposta
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }
if (!hash_equals('c41af87250434ecadaf9513850a46357', $_POST['token'] ?? '')) { http_response_code(404); exit; }
$eposta = strtolower(trim($_POST['eposta'] ?? ''));
if (!filter_var($eposta, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'error'=>'geçerli eposta gerekli']); exit; }

$cfg = json_decode(@file_get_contents(dirname(__DIR__).'/.smtp-ayar.json'), true);
if (!$cfg || empty($cfg['kullanici'])) { echo json_encode(['ok'=>false,'error'=>'smtp yok']); exit; }
$host=$cfg['sunucu']??'localhost'; $port=(int)($cfg['port']??465); $user=$cfg['kullanici'];

$log = [];
$fp=@fsockopen(($port===465?'ssl://':'').$host,$port,$e,$s,15);
if(!$fp){ echo json_encode(['ok'=>false,'error'=>"bağlantı: $s"]); exit; }
stream_set_timeout($fp,15);
$r=function()use($fp){$d='';while($l=fgets($fp,515)){$d.=$l;if(strlen($l)<4||$l[3]===' ')break;}return trim($d);};
$c=function($cmd,$etiket)use($fp,$r,&$log){fwrite($fp,$cmd."\r\n");$y=$r();$log[]=[$etiket,$y];return $y;};
$log[]=['BAGLANTI',$r()];
$c('EHLO irriga.com.tr','EHLO');
if($port===587){$c('STARTTLS','STARTTLS');@stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);$c('EHLO irriga.com.tr','EHLO2');}
$c('AUTH LOGIN','AUTH');
$c(base64_encode($user),'USER');
$c(base64_encode($cfg['sifre']),'PASS');
$c("MAIL FROM:<$user>",'MAIL-FROM');
$c("RCPT TO:<$eposta>",'RCPT-TO');
$dataY = $c('DATA','DATA');
if (strpos($dataY,'354')===0) {
    $subj='=?UTF-8?B?'.base64_encode('SMTP teslimat testi — Irriga Mühendislik').'?=';
    $mid = '<'.uniqid('',true).'@irriga.com.tr>';
    $h="From: =?UTF-8?B?".base64_encode("Irriga Mühendislik")."?= <$user>\r\nTo: $eposta\r\nSubject: $subj\r\nDate: ".date('r')."\r\nMessage-ID: $mid\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $body="Bu bir SMTP teslimat testidir. Bu maili gorduyseniz teslimat calisiyor.\r\nZaman: ".date('c');
    $c($h."\r\n".$body."\r\n.",'GONDERIM');
}
$c('QUIT','QUIT'); fclose($fp);
echo json_encode(['ok'=>true,'transcript'=>$log], JSON_UNESCAPED_UNICODE);

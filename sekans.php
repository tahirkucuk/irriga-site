<?php
// sekans.php — Lead magnet + nurture e-posta sekansı motoru.
// Akış: rehber sayfasından kayıt (eylem=kayit, public) → anında Rehber maili (adım 0)
//        + aboneler.jsonl'e ekleme (bülten entegrasyonu + iptal token'ı).
//       Günlük rutin eylem=calistir (token) çağırır → sırası gelen adımları gönderir.
// Adımlar: 0=rehber (anında), 1=vaka (2.gün), 2=hatalar (4.gün), 3=randevu (6.gün), 4=kapanış (10.gün)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$TOKEN = 'f7682ccd38a9fc9de745805bfab3d9e9';
$SITE  = 'https://irriga.com.tr';
$PDF   = $SITE . '/media/rehber/sulama-sistemi-kurulum-kontrol-listesi.pdf';

$dir = dirname(__DIR__) . '/lead-kayitlari';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
$dosya = $dir . '/sekans.jsonl';
$aboneDosya = $dir . '/aboneler.jsonl';

function oku($f) { $l=[]; if(is_readable($f)) foreach(file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $s){$k=json_decode($s,true); if($k)$l[]=$k;} return $l; }
function yaz($f,$l){ $fh=fopen($f,'c+'); if(!$fh)return false; flock($fh,LOCK_EX); ftruncate($fh,0); rewind($fh); foreach($l as $k) fwrite($fh,json_encode($k,JSON_UNESCAPED_UNICODE)."\n"); fflush($fh); flock($fh,LOCK_UN); fclose($fh); return true; }

// ── Sekans adımları (gün ofsetleri ve içerik) ──
function adimlar($ad, $iptal) {
    global $SITE, $PDF;
    $ilkAd = htmlspecialchars(explode(' ', trim($ad))[0] ?: 'Merhaba');
    $sarmal = function($baslikSatiri, $icHtml) use ($iptal) {
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:24px 0;background:#F0F7F2;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='max-width:560px;width:100%;'>
<tr><td style='background:#0F2E1D;padding:20px 26px;border-radius:12px 12px 0 0;'>
  <span style='font-size:17px;font-weight:700;color:#fff;'>Irriga <span style='color:#4ADE80;'>Mühendislik</span></span>
  <span style='float:right;font-size:11px;color:#6B7280;padding-top:4px;'>{$baslikSatiri}</span>
</td></tr>
<tr><td style='background:#fff;padding:28px 26px;font-size:14.5px;line-height:1.75;color:#1C3428;'>{$icHtml}</td></tr>
<tr><td style='background:#0F2E1D;padding:14px 26px;border-radius:0 0 12px 12px;'>
  <p style='margin:0;font-size:11px;color:#6B7280;'>irriga.com.tr · <a href='{$iptal}' style='color:#6B7280;'>Abonelikten çık</a></p>
</td></tr>
</table></td></tr></table></body></html>";
    };
    $btn = function($url, $metin) {
        return "<div style='margin:18px 0;'><a href='{$url}' style='display:inline-block;background:#237A4A;color:#fff;font-weight:700;font-size:14px;padding:12px 22px;border-radius:10px;text-decoration:none;'>{$metin}</a></div>";
    };
    $wa = "https://wa.me/905327059400";
    return [
        ['gun'=>0, 'konu'=>'💧 Kontrol listeniz hazır — kuruluma başlamadan bilmeniz gerekenler',
         'html'=>$sarmal('Rehberiniz', "<p><b>{$ilkAd}, hoş geldiniz!</b></p>
<p>İstediğiniz <b>Sulama Sistemi Kurulumu Öncesi Kontrol Listesi</b> hazır: 6 bölümde arazi analizinden devlet destek başvurusuna, teklif karşılaştırmasından bakım planlamasına kadar kurulum öncesi gerçekten bilmeniz gereken her şey.</p>
{$btn($PDF,'📥 Kontrol listesini indir (PDF)')}
<p><b>Tavsiyem:</b> Listeyi açın ve kendi arazinize göre tik atmaya başlayın. Çoğu arazi sahibi en az 3-4 hazırlık adımını atlıyor — bunlar ilerleyen maliyet sürprizlerine dönüşüyor.</p>
<p style='font-size:13px;color:#4A7A5A;'>Önümüzdeki günlerde gerçek bir proje vakası ve sulama sistemlerinde en pahalıya patlayan hataları paylaşacağım. Kısa ve öz — söz.</p>"),
        ],
        ['gun'=>2, 'konu'=>'14 dönüm serada %28 su tasarrufu — nasıl?',
         'html'=>$sarmal('Vaka Çalışması', "<p><b>{$ilkAd},</b> kontrol listesini inceleme fırsatı buldunuz mu?</p>
<p>Bugün sahadan gerçek bir proje: Antalya'da domates üretimi yapan bir sera işletmesi, kurulum öncesinde ciddi su ve gübre kaybı yaşıyordu. <b>14 dönümlük seraya tam otomasyonlu damla sulama + gübre entegrasyonu</b> kurduğumuzda ne oldu?</p>
<ul style='margin:12px 0;padding-left:20px;'>
<li>Su tüketimi <b>%28 azaldı</b></li>
<li>Gübre kullanımı <b>%20 düştü</b></li>
<li>Kurulum <b>5 günde tamamlandı</b>, hasat döngüsünü bozmadı</li>
<li>İlk yıl sonunda sistem kendini <b>tamamen amorti etti</b></li>
</ul>
<p>Bu sonuç ne sihir ne de şans — doğru sistem seçimi ve doğru kurulum. Her arazi için aynı sonuç garantilemesek de bu fark genellikle ulaşılabilir.</p>
{$btn($SITE.'/referanslar.html','Diğer referans projelerimize bakın →')}
<p style='font-size:13px;color:#4A7A5A;'>2 gün sonra: Sulama sistemlerinde en pahalı 3 hata — kurulumdan sonra "keşke önceden bilseydim" dedirten sorunlar.</p>"),
        ],
        ['gun'=>4, 'konu'=>'Sulama sisteminde en pahalı 3 hata',
         'html'=>$sarmal('Pratik Bilgi', "<p><b>{$ilkAd},</b> sahada sık gördüğümüz ve sonradan düzeltmesi pahalıya patlayan 3 hata:</p>
<p><b>1. Yanlış sistem seçimi.</b> Sera içi damla sulama ile tarla yağmurlama sistemi aynı değildir — arazi tipi, bitki çeşidi ve su kaynağı birlikte değerlendirilmeli. Yanlış seçim hem verimsizlik hem ekstra maliyet demek. <a href='{$SITE}/blog.html' style='color:#237A4A;'>Sistem karşılaştırmaları →</a></p>
<p><b>2. Filtrasyon ihmal edilmesi.</b> Filtrasyon olmadan damlatıcılar ortalama 1-2 sezonda tıkanır. Kum, disk ve elek filtre kombinasyonu sistem ömrünü 3-5 katına çıkarır.</p>
<p><b>3. Otomasyon yoksa zamanlama hataları.</b> Manuel sulamada en yaygın sorun: sabah sulama unutulması, aşırı sulama, kuruluk fark edilmeden geç kalınması. Temel bir zamanlayıcı bile bu kayıpları önler.</p>
<p>Kontrol listenizdeki 3, 4 ve 5. bölümler tam bu üç hatayı önlemek için var.</p>
{$btn($SITE.'/iletisim.html','Ücretsiz keşif talep edin')}"),
        ],
        ['gun'=>6, 'konu'=>"{$ilkAd}, araziinize birlikte bakalım mı? (ücretsiz keşif)",
         'html'=>$sarmal('Ücretsiz Keşif', "<p><b>{$ilkAd},</b> birkaç gündür bilgi paylaşıyorum. Şimdi söz sizde:</p>
<p>Araziiniz için <b>ücretsiz keşif ziyareti</b> — su kaynağınıza, arazi büyüklüğünüze ve ihtiyacınıza göre en uygun sistemi birlikte değerlendirelim, 48 saat içinde detaylı fiyat teklifi hazırlayalım.</p>
<p>Keşif ziyareti tamamen ücretsiz, kurulum zorunluluğu yok. Tek amacımız size doğru sistem için doğru bilgiyi vermek.</p>
{$btn($wa.'?text=Merhaba%2C+ücretsiz+keşif+ziyareti+hakkında+bilgi+almak+istiyorum.','💬 WhatsApp\'tan randevu alın')}
{$btn($SITE.'/iletisim.html','Veya form ile keşif talep edin')}
<p style='font-size:13px;color:#4A7A5A;'>Haftalık ziyaret kapasitemiz sınırlı — mevsim yoğunluğu başlamadan önce tarih ayırtmanızı öneririm.</p>"),
        ],
        ['gun'=>10, 'konu'=>'Aklınızda kalan soru var mı?',
         'html'=>$sarmal('Son Not', "<p><b>{$ilkAd},</b> bu kısa serinin son mesajı.</p>
<p>Kontrol listesi, vaka ve en pahalı hatalar sizde. Bundan sonrası pratiğe geçmek — ve bir noktada takıldığınızda iki seçeneğiniz var:</p>
<p>1) Bu maile <b>doğrudan yanıt yazın</b> — sorunuzu okur, kısa da olsa cevaplarım.<br>
2) <a href='{$wa}?text=Merhaba%2C+sulama+sistemi+konusunda+soru+sormak+istiyorum.' style='color:#237A4A;font-weight:700;'>WhatsApp'tan yazın</a> — genellikle aynı gün dönüyoruz.</p>
<p>Sulama sektöründeki gelişmeler, devlet destekleri ve yeni sistem teknolojileri hakkında periyodik güncellemeler için bültenimizde kalabilirsiniz.</p>
<p>İyi hasatlar!<br><b>Irriga Mühendislik Ekibi</b><br><a href='{$SITE}' style='color:#237A4A;'>irriga.com.tr</a></p>"),
        ],
    ];
}

// ── SMTP (bulten-digest ile aynı desen) ──
function smtp_gonder($cfg,$to,$subjRaw,$html){
    $host=$cfg['sunucu']??'localhost'; $port=(int)($cfg['port']??465);
    $user=$cfg['kullanici']; $pass=$cfg['sifre'];
    $subj='=?UTF-8?B?'.base64_encode($subjRaw).'?=';
    $fp=@fsockopen(($port===465?'ssl://':'').$host,$port,$e,$s,15); if(!$fp)return false;
    stream_set_timeout($fp,15);
    $r=function()use($fp){$d='';while($l=fgets($fp,515)){$d.=$l;if(strlen($l)<4||$l[3]===' ')break;}return $d;};
    $c=function($cmd)use($fp,$r){fwrite($fp,$cmd."\r\n");return $r();};
    $r();$c('EHLO irriga.com.tr');if($port===587){if(strpos($c('STARTTLS'),'220')!==0){fclose($fp);return false;}if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)){fclose($fp);return false;}$c('EHLO irriga.com.tr');}
    $c('AUTH LOGIN');$c(base64_encode($user));
    if(strpos($c(base64_encode($pass)),'235')!==0){fclose($fp);return false;}
    if(strpos($c("MAIL FROM:<$user>"),'250')!==0){fclose($fp);return false;}
    if(strpos($c("RCPT TO:<$to>"),'250')!==0){fclose($fp);return false;}
    if(strpos($c('DATA'),'354')!==0){fclose($fp);return false;}
    $h="From: =?UTF-8?B?".base64_encode("Irriga Mühendislik")."?= <$user>\r\nTo: $to\r\nSubject: $subj\r\nDate: ".date('r')."\r\nMessage-ID: <".uniqid('',true)."@irriga.com.tr>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    if(strpos($c($h."\r\n".preg_replace('/^\./m','..',$html)."\r\n."),'250')!==0){fclose($fp);return false;}
    $c('QUIT');fclose($fp);return true;
}
function smtpCfg(){ return json_decode(@file_get_contents(dirname(__DIR__).'/.smtp-ayar.json'), true); }

// Abone tablosundan iptal token'ı + iptal durumu
function aboneBilgi($aboneDosya, $eposta) {
    foreach (oku($aboneDosya) as $a) {
        if (strtolower($a['eposta'] ?? '') === $eposta) return $a;
    }
    return null;
}

$eylem = $_POST['eylem'] ?? $_GET['eylem'] ?? '';

// ── PUBLIC: magnet kaydı ──
if ($eylem === 'kayit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['website'])) { echo json_encode(['ok'=>true]); exit; } // honeypot
    $ad = trim($_POST['ad'] ?? '');
    $eposta = strtolower(trim($_POST['eposta'] ?? ''));
    if (!filter_var($eposta, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'error'=>'geçerli e-posta girin']); exit; }
    $cfg = smtpCfg();
    if (!$cfg || empty($cfg['kullanici'])) { echo json_encode(['ok'=>false,'error'=>'smtp yok']); exit; }

    // aboneler.jsonl'e ekle (yoksa) — bülten entegrasyonu + iptal token'ı
    $aboneler = oku($aboneDosya); $var = false; $tok = '';
    foreach ($aboneler as $a) { if (strtolower($a['eposta'] ?? '') === $eposta) { $var = true; $tok = $a['token'] ?? ''; break; } }
    if (!$var) {
        $tok = bin2hex(random_bytes(16));
        $aboneler[] = ['eposta'=>$eposta,'ad'=>$ad,'durum'=>'onaylandi','kaynak'=>'rehber','token'=>$tok,'tarih'=>date('c')];
        yaz($aboneDosya, $aboneler);
    }
    $iptal = $SITE.'/bulten-iptal.php'.($tok ? "?token=$tok" : '');

    // sekansa ekle (varsa sıfırlama — tekrar indirene seri yeniden başlamaz, sadece rehber maili gider)
    $liste = oku($dosya); $mevcut = null;
    foreach ($liste as &$k) { if (($k['eposta'] ?? '') === $eposta) { $mevcut = &$k; break; } }
    $A = adimlar($ad, $iptal);
    $ok = smtp_gonder($cfg, $eposta, $A[0]['konu'], $A[0]['html']);
    if ($mevcut === null) {
        $liste[] = ['eposta'=>$eposta,'ad'=>$ad,'kayit'=>date('c'),'gonderilen'=>$ok?[0]:[],'son'=>date('c')];
    } elseif ($ok && !in_array(0, $mevcut['gonderilen'] ?? [])) {
        $mevcut['gonderilen'][] = 0; $mevcut['son'] = date('c');
    }
    unset($k);
    yaz($dosya, $liste);
    echo json_encode(['ok'=>$ok,'mesaj'=>$ok?'Rehber e-postanıza gönderildi! Gelen kutunuzu (ve spam klasörünü) kontrol edin.':'gönderim hatası, tekrar deneyin']);
    exit;
}

// ── TOKEN gerektirir ──
$sunulan = $_POST['token'] ?? $_GET['token'] ?? '';
if (!hash_equals($TOKEN, $sunulan)) { http_response_code(404); exit; }

// Rutin: sırası gelen adımları gönder
if ($eylem === 'calistir') {
    $cfg = smtpCfg();
    if (!$cfg || empty($cfg['kullanici'])) { echo json_encode(['ok'=>false,'error'=>'smtp yok']); exit; }
    $liste = oku($dosya); $gonderilen = 0; $detay = [];
    foreach ($liste as &$k) {
        $eposta = $k['eposta'];
        $abone = aboneBilgi($aboneDosya, $eposta);
        if ($abone && ($abone['durum'] ?? '') === 'iptal') continue; // abonelikten çıkan seriden de çıkar
        $iptal = $SITE.'/bulten-iptal.php'.(!empty($abone['token']) ? '?token='.$abone['token'] : '');
        $A = adimlar($k['ad'] ?? '', $iptal);
        $gunFarki = floor((time() - strtotime($k['kayit'])) / 86400);
        foreach ($A as $i => $adim) {
            if (in_array($i, $k['gonderilen'] ?? [])) continue;
            if ($gunFarki < $adim['gun']) break;
            if (smtp_gonder($cfg, $eposta, $adim['konu'], $adim['html'])) {
                $k['gonderilen'][] = $i; $k['son'] = date('c'); $gonderilen++;
                $detay[] = "$eposta→adım$i";
            }
            break; // kişi başına günde en fazla 1 adım
        }
    }
    unset($k);
    yaz($dosya, $liste);
    echo json_encode(['ok'=>true,'gonderilen'=>$gonderilen,'detay'=>$detay,'toplamKisi'=>count($liste)], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kayıt sil (test temizliği)
if ($eylem === 'sil' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $hedef = strtolower(trim($_POST['eposta'] ?? ''));
    $liste = oku($dosya);
    $yeni = array_values(array_filter($liste, function($k) use ($hedef) { return strtolower($k['eposta'] ?? '') !== $hedef; }));
    yaz($dosya, $yeni);
    echo json_encode(['ok'=>count($yeni) < count($liste)]);
    exit;
}
// Durum (panel)
if ($eylem === 'durum') {
    $liste = oku($dosya);
    $ozet = ['toplamKisi'=>count($liste),'tamamlanan'=>0,'aktif'=>0];
    foreach ($liste as $k) { count($k['gonderilen'] ?? []) >= 5 ? $ozet['tamamlanan']++ : $ozet['aktif']++; }
    echo json_encode(['ok'=>true]+$ozet+['son10'=>array_slice($liste,-10)], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'bilinmeyen eylem']);

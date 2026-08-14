<?php
/**
 * SADA Dijital — E-posta gönderimi
 * SMTP (SSL) üzerinden gönderir; SMTP kapalıysa PHP mail() kullanır.
 * Hostinger için: smtp.hostinger.com, port 465 (SSL).
 */

function eposta_gonder(string $alici, string $konu, string $metin): bool {
    $siteAdi = ayar('site_adi', 'SADA Dijital');
    $gonderen = ayar('smtp_gonderen') ?: ayar('smtp_kullanici');

    $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#0a0f1e;border-radius:16px;overflow:hidden">'
        . '<div style="padding:24px 28px;border-bottom:1px solid rgba(248,242,203,.1)">'
        . '<span style="font-size:20px;font-weight:800;letter-spacing:2px;color:#f2f4f8">SADA<span style="color:#b1fb01">.</span></span></div>'
        . '<div style="padding:28px;color:#c9cede;font-size:14px;line-height:1.7">'
        . '<h2 style="color:#f2f4f8;font-size:17px;margin:0 0 12px">' . htmlspecialchars($konu) . '</h2>'
        . nl2br(htmlspecialchars($metin))
        . '</div><div style="padding:16px 28px;border-top:1px solid rgba(248,242,203,.1);color:#8b93ab;font-size:12px">'
        . htmlspecialchars($siteAdi) . ' Yönetim Sistemi — bu e-posta otomatik gönderilmiştir.</div></div>';

    if (ayar('smtp_aktif') !== '1' || !ayar('smtp_host') || !$gonderen) {
        // mail() ile dene
        $basliklar = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        if ($gonderen) $basliklar .= "From: $siteAdi <$gonderen>\r\n";
        return @mail($alici, '=?UTF-8?B?' . base64_encode($konu) . '?=', $html, $basliklar);
    }

    return smtp_gonder($alici, $konu, $html, $gonderen, $siteAdi);
}

function smtp_gonder(string $alici, string $konu, string $html, string $gonderen, string $gonderAd): bool {
    $host = ayar('smtp_host');
    $port = (int)ayar('smtp_port', '465');
    $kullanici = ayar('smtp_kullanici');
    $sifre = ayar('smtp_sifre');

    $adres = ($port === 465 ? 'ssl://' : '') . $host;
    $sock = @fsockopen($adres, $port, $errno, $errstr, 10);
    if (!$sock) return false;

    $oku = function () use ($sock) {
        $veri = '';
        while ($satir = fgets($sock, 515)) {
            $veri .= $satir;
            if (isset($satir[3]) && $satir[3] === ' ') break;
        }
        return $veri;
    };
    $gonder = function ($komut) use ($sock, $oku) {
        fwrite($sock, $komut . "\r\n");
        return $oku();
    };

    try {
        $oku();
        $gonder('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($port === 587) { // STARTTLS
            $gonder('STARTTLS');
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $gonder('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        $gonder('AUTH LOGIN');
        $gonder(base64_encode($kullanici));
        $cevap = $gonder(base64_encode($sifre));
        if (strpos($cevap, '235') !== 0) { fclose($sock); return false; }
        $gonder("MAIL FROM:<$gonderen>");
        $gonder("RCPT TO:<$alici>");
        $gonder('DATA');
        $mesaj = "From: =?UTF-8?B?" . base64_encode($gonderAd) . "?= <$gonderen>\r\n"
            . "To: <$alici>\r\n"
            . "Subject: =?UTF-8?B?" . base64_encode($konu) . "?=\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
            . $html . "\r\n.";
        $cevap = $gonder($mesaj);
        $gonder('QUIT');
        fclose($sock);
        return strpos($cevap, '250') === 0;
    } catch (Throwable $e) {
        @fclose($sock);
        return false;
    }
}

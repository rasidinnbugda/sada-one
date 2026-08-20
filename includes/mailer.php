<?php
/**
 * SADA One — E-posta gönderimi
 * SMTP (SSL) üzerinden gönderir; SMTP kapalıysa PHP mail() kullanır.
 * Hostinger için: smtp.hostinger.com, port 465 (SSL).
 */

function send_email(string $alici, string $topic, string $text): bool {
    $siteName = setting('site_adi', 'SADA One');
    $sender = setting('smtp_gonderen') ?: setting('smtp_kullanici');

    $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#0a0f1e;border-radius:16px;overflow:hidden">'
        . '<div style="padding:24px 28px;border-bottom:1px solid rgba(248,242,203,.1)">'
        . '<span style="font-size:20px;font-weight:800;letter-spacing:2px;color:#f2f4f8">SADA<span style="color:#b1fb01">.</span></span></div>'
        . '<div style="padding:28px;color:#c9cede;font-size:14px;line-height:1.7">'
        . '<h2 style="color:#f2f4f8;font-size:17px;margin:0 0 12px">' . htmlspecialchars($topic) . '</h2>'
        . nl2br(htmlspecialchars($text))
        . '</div><div style="padding:16px 28px;border-top:1px solid rgba(248,242,203,.1);color:#8b93ab;font-size:12px">'
        . htmlspecialchars($siteName) . ' Yönetim Sistemi — bu e-posta otomatik gönderilmiştir.</div></div>';

    if (setting('smtp_aktif') !== '1' || !setting('smtp_host') || !$sender) {
        // mail() ile dene
        $basliklar = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        if ($sender) $basliklar .= "From: $siteName <$sender>\r\n";
        return @mail($alici, '=?UTF-8?B?' . base64_encode($topic) . '?=', $html, $basliklar);
    }

    return smtp_send($alici, $topic, $html, $sender, $siteName);
}

function smtp_send(string $alici, string $topic, string $html, string $sender, string $sendName): bool {
    $host = setting('smtp_host');
    $port = (int)setting('smtp_port', '465');
    $user = setting('smtp_kullanici');
    $password = setting('smtp_sifre');

    $adres = ($port === 465 ? 'ssl://' : '') . $host;
    $sock = @fsockopen($adres, $port, $errno, $errstr, 10);
    if (!$sock) return false;

    $read = function () use ($sock) {
        $data = '';
        while ($row_item = fgets($sock, 515)) {
            $data .= $row_item;
            if (isset($row_item[3]) && $row_item[3] === ' ') break;
        }
        return $data;
    };
    $send = function ($komut) use ($sock, $read) {
        fwrite($sock, $komut . "\r\n");
        return $read();
    };

    try {
        $read();
        $send('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($port === 587) { // STARTTLS
            $send('STARTTLS');
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $send('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        $send('AUTH LOGIN');
        $send(base64_encode($user));
        $reply = $send(base64_encode($password));
        if (strpos($reply, '235') !== 0) { fclose($sock); return false; }
        $send("MAIL FROM:<$sender>");
        $send("RCPT TO:<$alici>");
        $send('DATA');
        $message = "From: =?UTF-8?B?" . base64_encode($sendName) . "?= <$sender>\r\n"
            . "To: <$alici>\r\n"
            . "Subject: =?UTF-8?B?" . base64_encode($topic) . "?=\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
            . $html . "\r\n.";
        $reply = $send($message);
        $send('QUIT');
        fclose($sock);
        return strpos($reply, '250') === 0;
    } catch (Throwable $e) {
        @fclose($sock);
        return false;
    }
}

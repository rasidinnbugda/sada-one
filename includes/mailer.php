<?php
/**
 * SADA One — Email sending
 * Sends via SMTP (SSL); falls back to PHP mail() when SMTP is disabled.
 * For Hostinger: smtp.hostinger.com, port 465 (SSL).
 */

function send_email(string $alici, string $topic, string $text): bool {
    // Security: reject malformed addresses (also blocks CRLF header injection)
    if (!filter_var($alici, FILTER_VALIDATE_EMAIL)) return false;
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
        // Try with mail()
        $basliklar = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        if ($sender) $basliklar .= "From: $siteName <$sender>\r\n";
        return @mail($alici, '=?UTF-8?B?' . base64_encode($topic) . '?=', $html, $basliklar);
    }

    return smtp_send($alici, $topic, $html, $sender, $siteName);
}

/**
 * Send a fully custom HTML e-mail (no wrapper template), optionally from a
 * Send-As alias of the connected Workspace account. Gmail only honours the
 * From when the alias is authorized under "Send mail as" — otherwise it
 * silently rewrites it to the authenticated address.
 */
function send_email_html(string $to, string $subject, string $html, ?string $from = null): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $sender = $from && filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : (setting('smtp_gonderen') ?: setting('smtp_kullanici'));
    $siteName = setting('site_adi', 'SADA One');
    if (setting('smtp_aktif') !== '1' || !setting('smtp_host') || !$sender) {
        $basliklar = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: $siteName <$sender>";
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $basliklar);
    }
    return smtp_send($to, $subject, $html, $sender, $siteName);
}

function smtp_send(string $alici, string $topic, string $html, string $sender, string $sendName): bool {
    $host = setting('smtp_host');
    $port = (int)setting('smtp_port', '465');
    $user = setting('smtp_kullanici');
    $password = setting('smtp_sifre');

    $adres = ($port === 465 ? 'ssl://' : '') . $host;
    $sock = @fsockopen($adres, $port, $errno, $errstr, 10);
    if (!$sock) { $GLOBALS['smtp_last_error'] = "Sunucuya bağlanılamadı ($host:$port): $errstr"; return false; }

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
        if (strpos($reply, '235') !== 0) { $GLOBALS['smtp_last_error'] = 'Kimlik doğrulama reddedildi: ' . trim(mb_substr((string)$reply, 0, 160)); fclose($sock); return false; }
        $send("MAIL FROM:<$sender>");
        $send("RCPT TO:<$alici>");
        $send('DATA');
        // SMTP caps lines at ~1000 octets (RFC 5321) and Gmail enforces it: a
        // several-KB single-line HTML body gets "500 Line too long". Wrap at
        // spaces (whitespace inside HTML/CSS is safe) and dot-stuff leading dots.
        $govde = wordwrap($html, 900, "\r\n", false);
        $govde = preg_replace('/^\./m', '..', $govde);
        $message = "From: =?UTF-8?B?" . base64_encode($sendName) . "?= <$sender>\r\n"
            . "To: <$alici>\r\n"
            . "Subject: =?UTF-8?B?" . base64_encode($topic) . "?=\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
            . $govde . "\r\n.";
        $reply = $send($message);
        $send('QUIT');
        fclose($sock);
        if (strpos($reply, '250') !== 0) {
            // surface the server's actual answer so failures are diagnosable
            $GLOBALS['smtp_last_error'] = trim(mb_substr((string)$reply, 0, 200));
            return false;
        }
        return true;
    } catch (Throwable $e) {
        @fclose($sock);
        return false;
    }
}

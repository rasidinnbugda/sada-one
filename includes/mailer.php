<?php
/**
 * SADA One — Email sending
 * Sends via SMTP (SSL); falls back to PHP mail() when SMTP is disabled.
 * For Hostinger: smtp.hostinger.com, port 465 (SSL).
 *
 * One SMTP connection is opened per request and reused for every message
 * (batches such as the daily reminders used to open/handshake/authenticate a
 * fresh TLS session per mail). Every socket read has a hard timeout, so a
 * silent server can no longer hold a request open for minutes.
 */

const SMTP_IO_TIMEOUT = 20; // seconds a single SMTP response may take

/** The name recipients see as the sender: Settings → SMTP → Gönderen adı, "SADA" when empty */
function mail_from_name(): string {
    // No line breaks or brackets: the name goes into a mail header
    return mb_substr(trim(preg_replace('/[\r\n<>"]+/', '', (string)setting('mail_from_name'))), 0, 60) ?: 'SADA';
}

/** A notification mail: the report mail's look — white card on grey paper, the SADA logo from Settings (else the word), a quiet footer */
function notification_email_html(string $topic, string $text): string {
    $logo = setting('site_logo');
    return '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"></head>'
        . '<body style="margin:0;padding:0;background:#f2f3f6;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f3f6"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e6e8ee;border-radius:16px">'
        . '<tr><td style="padding:26px 32px 0">' . ($logo
            ? '<img src="' . htmlspecialchars(full_url('uploads/' . ltrim($logo, '/'))) . '" alt="SADA" height="26" style="height:26px;width:auto;display:block;border:0">'
            : '<span style="font-size:16px;font-weight:800;letter-spacing:4px;color:#0f1d3a">SADA</span>') . '</td></tr>'
        . '<tr><td style="padding:22px 32px 30px;color:#475069;font-size:15px;line-height:1.7">'
        . '<div style="color:#0f1d3a;font-size:18px;font-weight:700;margin:0 0 12px">' . htmlspecialchars($topic) . '</div>'
        . nl2br(htmlspecialchars($text))
        . '</td></tr></table>'
        . '<div style="max-width:560px;padding:16px 12px;color:#8a90a0;font-size:12px">SADA · bu e-posta otomatik gönderilmiştir.</div>'
        . '</td></tr></table></body></html>';
}

function send_email(string $recipient, string $topic, string $text): bool {
    // Security: reject malformed addresses (also blocks CRLF header injection)
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) return false;
    $siteName = mail_from_name();
    $sender = setting('smtp_sender') ?: setting('smtp_user');
    $html = notification_email_html($topic, $text);

    if (setting('smtp_enabled') !== '1' || !setting('smtp_host') || !$sender) {
        // Try with mail()
        $titles = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        if ($sender) $titles .= "From: =?UTF-8?B?" . base64_encode($siteName) . "?= <$sender>\r\n";
        return @mail($recipient, '=?UTF-8?B?' . base64_encode($topic) . '?=', $html, $titles);
    }

    return smtp_send($recipient, $topic, $html, $sender, $siteName);
}

/**
 * Send a fully custom HTML e-mail (no wrapper template), optionally from a
 * Send-As alias of the connected Workspace account. Gmail only honours the
 * From when the alias is authorized under "Send mail as" — otherwise it
 * silently rewrites it to the authenticated address.
 */
function send_email_html(string $to, string $subject, string $html, ?string $from = null): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $sender = $from && filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : (setting('smtp_sender') ?: setting('smtp_user'));
    $siteName = mail_from_name();
    if (setting('smtp_enabled') !== '1' || !setting('smtp_host') || !$sender) {
        $titles = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: =?UTF-8?B?" . base64_encode($siteName) . "?= <$sender>";
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $titles);
    }
    return smtp_send($to, $subject, $html, $sender, $siteName);
}

/**
 * The request's shared SMTP session. Returns a `send(command): reply` closure,
 * or null when connecting/authenticating failed ($GLOBALS['smtp_last_error']).
 * Pass $close=true to QUIT and drop the connection (done automatically at shutdown).
 */
function smtp_connection(bool $close = false): ?callable {
    static $sock = null, $send = null, $failed = false, $shutdownHooked = false;

    if ($close) {
        if ($sock) { @fwrite($sock, "QUIT\r\n"); @fclose($sock); }
        $sock = null; $send = null;
        return null;
    }
    if ($sock) {
        $meta = stream_get_meta_data($sock);
        if (!$meta['eof'] && !$meta['timed_out']) return $send;
        @fclose($sock); $sock = null; $send = null;
    }
    // A rejected login is not retried within the request: every message in a
    // batch would otherwise hammer the server with the same bad credentials.
    if ($failed) return null;

    $host = setting('smtp_host');
    $port = (int)setting('smtp_port', '465');
    $user = setting('smtp_user');
    $password = setting('smtp_password');

    $address = ($port === 465 ? 'ssl://' : '') . $host;
    // Inside a background-work budget (see init.php) the per-read timeout shrinks to what is left
    $ioTimeout = SMTP_IO_TIMEOUT;
    if (isset($GLOBALS['sada_deadline'])) $ioTimeout = (int)max(3, min(SMTP_IO_TIMEOUT, $GLOBALS['sada_deadline'] - microtime(true)));
    $s = @fsockopen($address, $port, $errno, $errstr, min(10, $ioTimeout));
    if (!$s) { $GLOBALS['smtp_last_error'] = "Sunucuya bağlanılamadı ($host:$port): $errstr"; $failed = true; return null; }
    stream_set_timeout($s, $ioTimeout);

    $read = function () use ($s, $ioTimeout) {
        $data = '';
        $deadline = microtime(true) + $ioTimeout + 5; // absolute cap: a trickling server can't stretch the per-read timeout
        while (microtime(true) < $deadline && ($row_item = fgets($s, 515))) {
            $data .= $row_item;
            if (isset($row_item[3]) && $row_item[3] === ' ') break;
        }
        if ($data === '' && (stream_get_meta_data($s)['timed_out'] ?? false)) {
            $GLOBALS['smtp_last_error'] = 'SMTP sunucusu ' . $ioTimeout . ' sn içinde yanıt vermedi.';
        }
        return $data;
    };
    $sendFn = function (string $command) use ($s, $read) {
        fwrite($s, $command . "\r\n");
        return $read();
    };

    try {
        $read();
        $sendFn('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($port === 587) { // STARTTLS
            $sendFn('STARTTLS');
            stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $sendFn('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        $sendFn('AUTH LOGIN');
        $sendFn(base64_encode($user));
        $reply = $sendFn(base64_encode($password));
        if (strpos($reply, '235') !== 0) {
            $GLOBALS['smtp_last_error'] = $reply === '' ? ($GLOBALS['smtp_last_error'] ?? 'SMTP yanıt vermedi')
                : 'Kimlik doğrulama reddedildi: ' . trim(mb_substr((string)$reply, 0, 160));
            @fclose($s); $failed = true; return null;
        }
    } catch (Throwable $e) {
        @fclose($s); $failed = true;
        $GLOBALS['smtp_last_error'] = 'SMTP bağlantı hatası: ' . $e->getMessage();
        return null;
    }

    $sock = $s; $send = $sendFn;
    if (!$shutdownHooked) { $shutdownHooked = true; register_shutdown_function(fn() => smtp_connection(true)); }
    return $send;
}

function smtp_send(string $recipient, string $topic, string $html, string $sender, string $sendName): bool {
    $send = smtp_connection();
    if (!$send) return false;

    $fail = function (string $reply) {
        $GLOBALS['smtp_last_error'] = trim(mb_substr((string)$reply, 0, 200)) ?: ($GLOBALS['smtp_last_error'] ?? 'SMTP yanıt vermedi');
        // an empty reply means the socket is dead — drop it so the next mail reconnects
        if ($reply === '') smtp_connection(true);
        return false;
    };

    try {
        $send('RSET'); // clear any half-finished transaction of a previous message
        $reply = $send("MAIL FROM:<$sender>");
        if (strpos($reply, '250') !== 0) return $fail($reply);
        $reply = $send("RCPT TO:<$recipient>");
        if (strpos($reply, '250') !== 0 && strpos($reply, '251') !== 0) return $fail($reply);
        $reply = $send('DATA');
        if (strpos($reply, '354') !== 0) return $fail($reply);
        // SMTP caps lines at ~1000 octets (RFC 5321) and Gmail enforces it: a
        // several-KB single-line HTML body gets "500 Line too long". Wrap at
        // spaces (whitespace inside HTML/CSS is safe) and dot-stuff leading dots.
        $body = wordwrap($html, 900, "\r\n", false);
        $body = preg_replace('/^\./m', '..', $body);
        $message = "From: =?UTF-8?B?" . base64_encode($sendName) . "?= <$sender>\r\n"
            . "To: <$recipient>\r\n"
            . "Subject: =?UTF-8?B?" . base64_encode($topic) . "?=\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
            . $body . "\r\n.";
        $reply = $send($message);
        if (strpos($reply, '250') !== 0) return $fail($reply);
        return true;
    } catch (Throwable $e) {
        $GLOBALS['smtp_last_error'] = 'SMTP hatası: ' . $e->getMessage();
        smtp_connection(true);
        return false;
    }
}

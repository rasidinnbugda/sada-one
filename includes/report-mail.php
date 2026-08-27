<?php
/**
 * SADA One — Monthly report → client-facing HTML e-mail.
 * Email-safe markup on purpose: tables + inline styles, 600px, no external CSS.
 * Internal finance figures are deliberately NOT included — this goes to the client.
 */

function report_mail_html(array $report, array $client, string $period): string {
    $siteName = setting('site_adi', 'SADA One');
    [$yil, $ay] = explode('-', $period);
    $periodTag = MONTHS[(int)$ay] . ' ' . $yil;
    $accent = '#b1fb01';
    $koyu = '#0a0f1e';

    $bolum = function (string $tag, string $ikon, ?string $icerik) use ($accent): string {
        if (!trim((string)$icerik)) return '';
        return '<tr><td style="padding:0 32px 26px">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#8a93a8;padding-bottom:9px;border-bottom:2px solid ' . $accent . ';margin-bottom:13px;display:inline-block">' . $ikon . '&nbsp; ' . $tag . '</div>'
            . '<div style="font-size:14.5px;line-height:1.75;color:#2b3245;white-space:pre-wrap">' . nl2br(htmlspecialchars(trim($icerik))) . '</div>'
            . '</td></tr>';
    };

    return '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#eef1f6;font-family:Arial,Helvetica,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f6;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 34px rgba(10,15,30,.10)">'

        // Header
        . '<tr><td style="background:' . $koyu . ';padding:30px 32px">'
        . '<div style="font-size:22px;font-weight:800;letter-spacing:2px;color:#f2f4f8">' . htmlspecialchars($siteName) . '<span style="color:' . $accent . '">.</span></div>'
        . '<div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#8a93a8;margin-top:8px">Aylık Performans Raporu</div>'
        . '</td></tr>'

        // Title band
        . '<tr><td style="padding:30px 32px 8px">'
        . '<div style="font-size:13px;font-weight:700;color:#8a93a8;letter-spacing:.06em;text-transform:uppercase">' . htmlspecialchars($client['name']) . '</div>'
        . '<div style="font-size:26px;font-weight:800;color:' . $koyu . ';margin-top:5px">' . $periodTag . '</div>'
        . '<div style="width:52px;height:5px;border-radius:3px;background:' . $accent . ';margin:14px 0 8px"></div>'
        . '</td></tr>'

        . $bolum('Genel Özet', '📌', $report['summary'] ?? '')
        . $bolum('Yapılan Çalışmalar', '✅', $report['work_done'] ?? '')
        . $bolum('Metrikler & Sonuçlar', '📈', $report['metrics'] ?? '')
        . $bolum('Gelecek Ay Planı', '🎯', $report['plan'] ?? '')

        // Footer
        . '<tr><td style="background:#f5f7fb;padding:22px 32px;border-top:1px solid #e4e8f0">'
        . '<div style="font-size:12.5px;color:#8a93a8;line-height:1.7">Sorularınız için bu e-postayı yanıtlamanız yeterli.<br>'
        . '<b style="color:#2b3245">' . htmlspecialchars($siteName) . '</b> · ' . date('Y') . '</div>'
        . '</td></tr>'

        . '</table></td></tr></table></body></html>';
}

<?php
/**
 * SADA One — Monthly report → client-facing HTML e-mail.
 * Email-safe markup on purpose: tables + inline styles, 600px, no external CSS.
 * Internal finance figures are deliberately NOT included — this goes to the client.
 *
 * Besides the report's four text fields, the design reads monthly_reports.mail_data
 * (JSON, edited in the mail modal): cover image, "favourite of the month" block
 * and up to four stat tiles. Every visual block degrades gracefully when empty.
 */

function report_mail_html(array $report, array $client, string $period): string {
    $siteName = setting('site_adi', 'SADA One');
    [$yil, $ay] = explode('-', $period);
    $periodTag = MONTHS[(int)$ay] . ' ' . $yil;
    $navy = '#101f3c';
    $accent = '#b1fb01';
    $kirmizi = '#e8402a';
    $veri = json_decode((string)($report['mail_data'] ?? ''), true) ?: [];
    $img = fn(?string $yol) => $yol ? full_url($yol) : null;
    $e2 = fn($s) => htmlspecialchars((string)$s);

    /* Navy section band */
    $bant = fn(string $tag) => '<tr><td style="background:' . $navy . ';padding:13px 30px">'
        . '<div style="font-size:16px;font-weight:700;color:#ffffff;font-family:Georgia,serif">' . $tag . '</div></td></tr>';

    $h = '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#e9ecf2;font-family:Arial,Helvetica,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e9ecf2;padding:26px 10px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 12px 36px rgba(16,31,60,.14)">';

    /* ---- Cover: image (optional) + period pill + big title on navy ---- */
    $h .= '<tr><td style="background:' . $navy . ';padding:14px 14px 0">';
    if ($img($veri['hero'] ?? null)) {
        $h .= '<img src="' . $e2($img($veri['hero'])) . '" width="572" alt="" style="width:100%;max-width:572px;height:auto;display:block;border-radius:14px">';
    }
    $h .= '<div style="padding:16px 16px 20px">'
        . '<span style="display:inline-block;background:' . $accent . ';color:' . $navy . ';font-size:11.5px;font-weight:700;letter-spacing:.08em;padding:5px 13px;border-radius:99px;text-transform:uppercase">' . $periodTag . '</span>'
        . '<div style="font-size:29px;font-weight:800;color:#ffffff;font-family:Georgia,serif;margin-top:12px">Aylık Durum Raporu</div>'
        . '</div></td></tr>';

    /* ---- Greeting + summary ---- */
    $h .= '<tr><td style="padding:26px 30px 22px">'
        . '<div style="font-size:16px;font-weight:700;color:' . $navy . ';margin-bottom:10px">Selam ' . $e2($client['name']) . ' ekibi 👋</div>';
    if (trim((string)($report['summary'] ?? ''))) {
        $h .= '<div style="font-size:14.5px;line-height:1.8;color:#3a4256;white-space:pre-wrap">' . nl2br($e2(trim($report['summary']))) . '</div>';
    }
    $h .= '</td></tr>';

    /* ---- Produced this month: work_done lines as a two-column grid ---- */
    $isler = array_values(array_filter(array_map(fn($s) => trim(ltrim(trim($s), '-•* ')), explode("\n", (string)($report['work_done'] ?? '')))));
    if ($isler) {
        $h .= $bant('Markanız İçin Ürettik');
        $h .= '<tr><td style="padding:22px 30px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
        foreach (array_chunk($isler, 2) as $cift) {
            $h .= '<tr>';
            foreach ($cift as $is) {
                $h .= '<td width="50%" valign="top" style="padding:0 12px 18px 0">'
                    . '<div style="font-size:20px;color:' . $navy . '">↘</div>'
                    . '<div style="font-size:13.5px;line-height:1.65;color:#3a4256;border-top:2px solid ' . $navy . ';padding-top:8px;margin-top:6px">' . $e2($is) . '</div></td>';
            }
            if (count($cift) === 1) $h .= '<td width="50%"></td>';
            $h .= '</tr>';
        }
        $h .= '</table></td></tr>';
    }

    /* ---- Favourite of the month (red highlight) ---- */
    $fav = $veri['fav'] ?? [];
    if (!empty($fav['title']) || !empty($fav['img'])) {
        $h .= '<tr><td style="padding:4px 14px 20px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $kirmizi . ';border-radius:14px"><tr>';
        if ($img($fav['img'] ?? null)) {
            $h .= '<td width="200" valign="middle" style="padding:20px 0 20px 20px"><img src="' . $e2($img($fav['img'])) . '" width="180" alt="" style="width:180px;height:auto;display:block;border-radius:10px"></td>';
        }
        $h .= '<td valign="middle" style="padding:22px 22px">'
            . '<div style="font-size:17px;font-weight:800;color:#ffffff;font-family:Georgia,serif;margin-bottom:8px">' . $e2($fav['title'] ?: 'Bu Ayın Favorisi') . '</div>'
            . (trim((string)($fav['text'] ?? '')) ? '<div style="font-size:13.5px;line-height:1.7;color:#ffe3dd">' . nl2br($e2(trim($fav['text']))) . '</div>' : '')
            . (trim((string)($fav['stat'] ?? '')) ? '<div style="font-size:14px;font-weight:800;color:#ffffff;margin-top:9px">Tam olarak <span style="background:rgba(255,255,255,.22);padding:2px 9px;border-radius:7px">' . $e2(trim($fav['stat'])) . '</span></div>' : '')
            . '</td></tr></table></td></tr>';
    }

    /* ---- Stats: structured tiles, else the metrics text ---- */
    $statlar = array_values(array_filter(($veri['stats'] ?? []), fn($s) => trim((string)($s['tag'] ?? '')) !== '' && trim((string)($s['deger'] ?? '')) !== ''));
    if ($statlar || trim((string)($report['metrics'] ?? ''))) {
        $h .= $bant('Biz Susalım, Sayılar Konuşsun');
        $h .= '<tr><td style="padding:20px 30px 6px">';
        if ($statlar) {
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
            foreach (array_chunk($statlar, 2) as $cift) {
                $h .= '<tr>';
                foreach ($cift as $s) {
                    $degisim = trim((string)($s['degisim'] ?? ''));
                    $artis = $degisim !== '' && $degisim[0] !== '-';
                    $h .= '<td width="50%" valign="top" style="padding:0 12px 20px 0">'
                        . '<div style="font-size:19px">📊</div>'
                        . '<div style="font-size:13px;font-weight:700;color:' . $navy . ';margin-top:5px">' . $e2($s['tag']) . '</div>'
                        . '<div style="font-size:22px;font-weight:800;color:' . $navy . ';margin-top:3px">' . $e2($s['deger'])
                        . ($degisim !== '' ? ' <span style="font-size:13px;font-weight:700;color:' . ($artis ? '#1d9e57' : $kirmizi) . '">' . ($artis ? '▲ ' : '▼ ') . $e2(ltrim($degisim, '+-')) . '</span>' : '')
                        . '</div></td>';
                }
                if (count($cift) === 1) $h .= '<td width="50%"></td>';
                $h .= '</tr>';
            }
            $h .= '</table>';
        } else {
            $h .= '<div style="font-size:14px;line-height:1.8;color:#3a4256;white-space:pre-wrap;padding-bottom:16px">' . nl2br($e2(trim($report['metrics']))) . '</div>';
        }
        $h .= '</td></tr>';
    }

    /* ---- Next month plan + closing line ---- */
    if (trim((string)($report['plan'] ?? ''))) {
        $h .= $bant('Önümüzdeki Ay');
        $h .= '<tr><td style="padding:20px 30px 8px"><div style="font-size:14px;line-height:1.8;color:#3a4256;white-space:pre-wrap">' . nl2br($e2(trim($report['plan']))) . '</div></td></tr>';
    }
    $h .= '<tr><td style="padding:14px 30px 24px" align="center"><div style="font-size:13px;color:#8a93a8;font-style:italic">Önümüzdeki ay görüşmek üzere...</div></td></tr>';

    /* ---- Footer: thanks + contact person + wordmark ---- */
    $sorumlu = $client['manager_id'] ? row("SELECT name, email FROM users WHERE id=?", [(int)$client['manager_id']]) : null;
    $h .= '<tr><td style="background:' . $navy . ';padding:26px 30px" align="center">'
        . '<div style="font-size:21px;font-weight:800;color:#ffffff;font-family:Georgia,serif">Teşekkür Ederiz!</div>';
    if ($sorumlu) {
        $h .= '<div style="font-size:13px;font-weight:700;color:#c8d0e2;line-height:1.7;margin-top:10px">Sorularınız olursa dosya sorumlusu ' . $e2($sorumlu['name'])
            . ($sorumlu['email'] ? ' ile<br><a href="mailto:' . $e2($sorumlu['email']) . '" style="color:' . $accent . ';text-decoration:none">' . $e2($sorumlu['email']) . '</a> üzerinden' : ' ile')
            . ' iletişime geçebilirsiniz.</div>';
    }
    $h .= '<div style="border:1px solid rgba(255,255,255,.25);border-radius:10px;display:inline-block;padding:9px 26px;margin-top:16px">'
        . '<span style="font-size:15px;font-weight:800;letter-spacing:2px;color:#ffffff">' . $e2(mb_strtoupper($siteName, 'UTF-8')) . '<span style="color:' . $accent . '">.</span></span></div>'
        . '<div style="font-size:11.5px;color:#8a93a8;margin-top:12px">' . date('Y') . '</div>'
        . '</td></tr>';

    return $h . '</table></td></tr></table></body></html>';
}

<?php
/**
 * SADA One — Monthly report → client-facing HTML e-mail.
 * Email-safe markup on purpose: tables + inline styles, 600px, no external CSS.
 * Internal finance figures are deliberately NOT included — this goes to the client.
 *
 * Besides the report's four text fields, the design reads monthly_reports.mail_data
 * (JSON, edited in the mail modal): cover image, "favourite of the month" block,
 * stat tiles AND every heading/greeting/closing text. Empty fields fall back to
 * sensible defaults, so the mail always reads complete.
 */

function report_mail_html(array $report, array $client, string $period): string {
    $siteName = setting('site_adi', 'SADA One');
    [$yil, $ay] = explode('-', $period);
    $periodTag = MONTHS[(int)$ay] . ' ' . $yil;
    $navy = '#101f3c';
    $accent = '#b1fb01';
    $kirmizi = '#e8402a';
    $serif = "Georgia,'Times New Roman',serif";
    $sans = "'Segoe UI',Arial,Helvetica,sans-serif";
    $veri = json_decode((string)($report['mail_data'] ?? ''), true) ?: [];
    $img = fn(?string $yol) => $yol ? full_url($yol) : null;
    $e2 = fn($s) => htmlspecialchars((string)$s);
    $m = fn(string $key, string $default) => trim((string)($veri['metin'][$key] ?? '')) !== '' ? trim($veri['metin'][$key]) : $default;

    /* Navy section band with a small accent tick */
    $bant = fn(string $tag) => '<tr><td style="padding:6px 14px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="background:' . $navy . ';border-radius:12px;padding:14px 22px">'
        . '<span style="display:inline-block;width:9px;height:9px;background:' . $accent . ';border-radius:2px;margin-right:11px"></span>'
        . '<span style="font-size:17px;font-weight:700;color:#ffffff;font-family:' . $serif . ';letter-spacing:.01em;vertical-align:1px">' . $tag . '</span>'
        . '</td></tr></table></td></tr>';

    $h = '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#e9ecf2;font-family:' . $sans . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e9ecf2;padding:26px 10px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:22px;overflow:hidden;box-shadow:0 14px 40px rgba(16,31,60,.16)">'

        // Thin accent strip on the very top
        . '<tr><td style="background:' . $accent . ';height:6px;font-size:0;line-height:0">&nbsp;</td></tr>';

    /* ---- Cover: image (optional) + period pill + big serif title on navy ---- */
    $h .= '<tr><td style="background:' . $navy . ';padding:16px 16px 0">';
    if ($img($veri['hero'] ?? null)) {
        $h .= '<img src="' . $e2($img($veri['hero'])) . '" width="568" alt="" style="width:100%;max-width:568px;height:auto;display:block;border-radius:14px">';
    }
    $h .= '<div style="padding:18px 14px 24px">'
        . '<span style="display:inline-block;background:' . $accent . ';color:' . $navy . ';font-size:11px;font-weight:700;letter-spacing:.14em;padding:6px 14px;border-radius:99px;text-transform:uppercase;font-family:' . $sans . '">' . $periodTag . '</span>'
        . '<div style="font-size:32px;font-weight:700;color:#ffffff;font-family:' . $serif . ';margin-top:13px;line-height:1.2">' . $e2($m('baslik', 'Aylık Durum Raporu')) . '</div>'
        . '</div></td></tr>';

    /* ---- Greeting + summary ---- */
    $h .= '<tr><td style="padding:28px 32px 22px">'
        . '<div style="font-size:19px;font-weight:700;color:' . $navy . ';font-family:' . $serif . ';margin-bottom:12px">' . $e2($m('selam', 'Selam ' . $client['name'] . ' ekibi 👋')) . '</div>';
    if (trim((string)($report['summary'] ?? ''))) {
        $h .= '<div style="font-size:14.5px;line-height:1.85;color:#3a4256;white-space:pre-wrap">' . nl2br($e2(trim($report['summary']))) . '</div>';
    }
    $h .= '</td></tr>';

    /* ---- Produced this month: work_done lines as a two-column grid ---- */
    $isler = array_values(array_filter(array_map(fn($s) => trim(ltrim(trim($s), '-•* ')), explode("\n", (string)($report['work_done'] ?? '')))));
    if ($isler) {
        $h .= $bant($e2($m('uretim_baslik', 'Markanız İçin Ürettik')));
        $h .= '<tr><td style="padding:24px 32px 6px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
        foreach (array_chunk($isler, 2) as $cift) {
            $h .= '<tr>';
            foreach ($cift as $is) {
                $h .= '<td width="50%" valign="top" style="padding:0 14px 20px 0">'
                    . '<div style="font-size:21px;color:' . $navy . ';font-family:' . $serif . '">↘</div>'
                    . '<div style="font-size:13.5px;line-height:1.7;color:#3a4256;border-top:2px solid ' . $navy . ';padding-top:9px;margin-top:7px">' . $e2($is) . '</div></td>';
            }
            if (count($cift) === 1) $h .= '<td width="50%"></td>';
            $h .= '</tr>';
        }
        $h .= '</table></td></tr>';
    }

    /* ---- Favourite of the month (red highlight) ---- */
    $fav = $veri['fav'] ?? [];
    if (!empty($fav['title']) || !empty($fav['img'])) {
        $h .= '<tr><td style="padding:6px 14px 20px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $kirmizi . ';border-radius:14px"><tr>';
        if ($img($fav['img'] ?? null)) {
            $h .= '<td width="200" valign="middle" style="padding:22px 0 22px 22px"><img src="' . $e2($img($fav['img'])) . '" width="178" alt="" style="width:178px;height:auto;display:block;border-radius:10px"></td>';
        }
        $h .= '<td valign="middle" style="padding:24px 24px">'
            . '<div style="font-size:19px;font-weight:700;color:#ffffff;font-family:' . $serif . ';margin-bottom:9px">' . $e2($fav['title'] ?: 'Bu Ayın Favorisi') . '</div>'
            . (trim((string)($fav['text'] ?? '')) ? '<div style="font-size:13.5px;line-height:1.75;color:#ffe3dd">' . nl2br($e2(trim($fav['text']))) . '</div>' : '')
            . (trim((string)($fav['stat'] ?? '')) ? '<div style="font-size:14px;font-weight:700;color:#ffffff;margin-top:10px">Tam olarak <span style="background:rgba(255,255,255,.22);padding:3px 10px;border-radius:7px">' . $e2(trim($fav['stat'])) . '</span></div>' : '')
            . '</td></tr></table></td></tr>';
    }

    /* ---- Stats: structured tiles, else the metrics text ---- */
    $statlar = array_values(array_filter(($veri['stats'] ?? []), fn($s) => trim((string)($s['tag'] ?? '')) !== '' && trim((string)($s['deger'] ?? '')) !== ''));
    if ($statlar || trim((string)($report['metrics'] ?? ''))) {
        $h .= $bant($e2($m('stat_baslik', 'Biz Susalım, Sayılar Konuşsun')));
        $h .= '<tr><td style="padding:20px 32px 4px">';
        $giris = $m('stat_giris', '');
        if ($giris !== '') $h .= '<div style="font-size:13.5px;color:#6a7288;line-height:1.7;margin-bottom:18px">' . $e2($giris) . '</div>';
        if ($statlar) {
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
            foreach (array_chunk($statlar, 2) as $cift) {
                $h .= '<tr>';
                foreach ($cift as $s) {
                    $degisim = trim((string)($s['degisim'] ?? ''));
                    $artis = $degisim !== '' && $degisim[0] !== '-';
                    $h .= '<td width="50%" valign="top" style="padding:0 14px 18px 0">'
                        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="border:1px solid #e4e8f0;border-radius:12px;padding:15px 17px">'
                        . '<div style="font-size:11.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#8a93a8">' . $e2($s['tag']) . '</div>'
                        . '<div style="font-size:26px;font-weight:700;color:' . $navy . ';font-family:' . $serif . ';margin-top:6px">' . $e2($s['deger'])
                        . ($degisim !== '' ? ' <span style="font-size:13px;font-weight:700;font-family:' . $sans . ';color:' . ($artis ? '#1d9e57' : $kirmizi) . '">' . ($artis ? '▲ ' : '▼ ') . $e2(ltrim($degisim, '+-')) . '</span>' : '')
                        . '</div></td></tr></table></td>';
                }
                if (count($cift) === 1) $h .= '<td width="50%"></td>';
                $h .= '</tr>';
            }
            $h .= '</table>';
        } else {
            $h .= '<div style="font-size:14px;line-height:1.85;color:#3a4256;white-space:pre-wrap;padding-bottom:16px">' . nl2br($e2(trim($report['metrics']))) . '</div>';
        }
        $h .= '</td></tr>';
    }

    /* ---- Next month plan + closing line ---- */
    if (trim((string)($report['plan'] ?? ''))) {
        $h .= $bant($e2($m('plan_baslik', 'Önümüzdeki Ay')));
        $h .= '<tr><td style="padding:22px 32px 8px"><div style="font-size:14px;line-height:1.85;color:#3a4256;white-space:pre-wrap">' . nl2br($e2(trim($report['plan']))) . '</div></td></tr>';
    }
    $h .= '<tr><td style="padding:16px 32px 26px" align="center"><div style="font-size:13.5px;color:#8a93a8;font-style:italic;font-family:' . $serif . '">' . $e2($m('kapanis', 'Önümüzdeki ay görüşmek üzere...')) . '</div></td></tr>';

    /* ---- Footer: thanks + contact person + wordmark ---- */
    $sorumlu = $client['manager_id'] ? row("SELECT name, email FROM users WHERE id=?", [(int)$client['manager_id']]) : null;
    $h .= '<tr><td style="background:' . $navy . ';padding:30px 32px" align="center">'
        . '<div style="font-size:24px;font-weight:700;color:#ffffff;font-family:' . $serif . '">' . $e2($m('tesekkur', 'Teşekkür Ederiz!')) . '</div>';
    if ($sorumlu) {
        $h .= '<div style="font-size:13px;color:#c8d0e2;line-height:1.75;margin-top:12px">Sorularınız olursa dosya sorumlusu <b style="color:#ffffff">' . $e2($sorumlu['name']) . '</b>'
            . ($sorumlu['email'] ? ' ile<br><a href="mailto:' . $e2($sorumlu['email']) . '" style="color:' . $accent . ';text-decoration:none;font-weight:700">' . $e2($sorumlu['email']) . '</a> üzerinden' : ' ile')
            . ' iletişime geçebilirsiniz.</div>';
    }
    $h .= '<div style="border:1px solid rgba(255,255,255,.28);border-radius:10px;display:inline-block;padding:10px 28px;margin-top:18px">'
        . '<span style="font-size:15px;font-weight:800;letter-spacing:2.5px;color:#ffffff;font-family:' . $sans . '">' . $e2(mb_strtoupper($siteName, 'UTF-8')) . '<span style="color:' . $accent . '">.</span></span></div>'
        . '<div style="font-size:11.5px;color:#8a93a8;margin-top:13px">' . date('Y') . '</div>'
        . '</td></tr>';

    return $h . '</table></td></tr></table></body></html>';
}

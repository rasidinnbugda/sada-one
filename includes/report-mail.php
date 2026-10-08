<?php
/**
 * SADA One — Monthly report → client-facing HTML e-mail, and the editor it is written in.
 * Email-safe markup on purpose: tables + inline styles, 600px, no external CSS. Internal finance figures are
 * deliberately NOT included — this goes to the client.
 *
 * The same markup is the editor (monthly-reports.php): with $edit every text is contenteditable (data-edit,
 * data-list, data-stat), picture slots and stat tiles get their controls and empty parts show what goes there.
 * The page reads the edited document back into the report. The mail leaves out whatever was left empty.
 *
 * Report fields: summary, work_done (one line each), metrics (a note under the numbers), plan (one line each).
 * mail_data JSON: hero (cover picture), fav {img, title, text, stat}, text {title, greeting, …}, stats [{label, value, change}].
 */

const REPORT_TEXT_KEYS = ['title', 'greeting', 'production_title', 'stat_title', 'stat_intro', 'fav_label', 'plan_title', 'closing', 'thanks'];
const REPORT_MAX_STATS = 6;

/** "Ekim'de" — the month with its locative suffix */
function report_month_in(int $month): string {
    return MONTHS[$month] . ([1 => "'ta", "'ta", "'ta", "'da", "'ta", "'da", "'da", "'ta", "'de", "'de", "'da", "'ta"][$month] ?? '');
}

/** Short, readable counts for stat tiles: 8.450 · 43,3B · 1,2M */
function report_count(int $n): string {
    if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1, ',', ''), '0'), ',') . 'M';
    if ($n >= 10000) return rtrim(rtrim(number_format($n / 1000, 1, ',', ''), '0'), ',') . 'B';
    return number_format($n, 0, ',', '.');
}

/**
 * A report as the editor posts it (or as stored), cleaned: texts trimmed and capped, pictures only from uploads/.
 * $keepEmpty keeps blank stat tiles (the editor shows a tile the moment it is added).
 */
function report_state_clean(array $s, bool $keepEmpty = false): array {
    $txt = fn($v, int $n) => mb_substr(trim(str_replace("\r", '', (string)$v)), 0, $n);
    $line = fn($v, int $n) => mb_substr(trim(preg_replace('/\s*\n\s*/u', ' ', str_replace("\r", '', (string)$v))), 0, $n);
    $lines = fn($v) => implode("\n", array_slice(array_values(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 300), explode("\n", str_replace("\r", '', (string)$v))), fn($l) => $l !== '')), 0, 40));
    $img = fn($p) => is_string($p) && preg_match('~^uploads/[\w\-/.]+\.(jpe?g|png|gif|webp)$~i', $p) && !str_contains($p, '..') ? $p : null;
    $md = $s['mail_data'] ?? [];
    if (is_string($md)) $md = json_decode($md, true);
    $md = is_array($md) ? $md : [];
    $data = [];
    if ($h = $img($md['hero'] ?? null)) $data['hero'] = $h;
    $fav = is_array($md['fav'] ?? null) ? $md['fav'] : [];
    $data['fav'] = array_filter(['img' => $img($fav['img'] ?? null), 'title' => $line($fav['title'] ?? '', 120),
        'text' => $txt($fav['text'] ?? '', 800), 'stat' => $line($fav['stat'] ?? '', 60)], fn($v) => $v !== null && $v !== '');
    $data['text'] = [];
    foreach (REPORT_TEXT_KEYS as $k) {
        $v = $line($md['text'][$k] ?? '', 300);
        if ($v !== '') $data['text'][$k] = $v;
    }
    $data['stats'] = [];
    foreach (array_slice(array_values(array_filter(is_array($md['stats'] ?? null) ? $md['stats'] : [], 'is_array')), 0, REPORT_MAX_STATS) as $st) {
        $tile = ['label' => $line($st['label'] ?? '', 60), 'value' => $line($st['value'] ?? '', 30), 'change' => $line($st['change'] ?? '', 20)];
        if ($keepEmpty || $tile['label'] !== '' || $tile['value'] !== '') $data['stats'][] = $tile;
    }
    return ['summary' => $txt($s['summary'] ?? '', 5000), 'work_done' => $lines($s['work_done'] ?? ''), 'metrics' => $txt($s['metrics'] ?? '', 3000),
        'plan' => $lines($s['plan'] ?? ''), 'mail_data' => json_encode(['fav' => (object)$data['fav'], 'text' => (object)$data['text']] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}

/**
 * A new report, already filled with the month: what was published and finished, the shoots, the accounts' follower
 * counts against the month before, and what is planned for next month. Everything stays editable.
 */
function report_draft(array $client, string $period): array {
    $cid = (int)$client['id'];
    $month = (int)substr($period, 5, 2);
    $pStart = "$period-01"; $pEnd = date('Y-m-t', strtotime($pStart));
    $nStart = date('Y-m-01', strtotime("$pStart +1 month")); $nEnd = date('Y-m-t', strtotime($nStart));
    $done = rows("SELECT g.title, g.status, g.platforms FROM tasks g JOIN projects p ON p.id=g.project_id
        WHERE p.client_id=? AND g.kind='client' AND " . task_done_sql('g') . "
          AND ((g.publish_date BETWEEN ? AND ?) OR (g.publish_date IS NULL AND g.completion BETWEEN ? AND ?))
        ORDER BY COALESCE(g.publish_date, DATE(g.completion)), g.id", [$cid, $pStart, $pEnd, "$pStart 00:00:00", "$pEnd 23:59:59"]);
    $published = array_values(array_filter($done, fn($t) => $t['status'] === 'published'));
    $finished = array_values(array_filter($done, fn($t) => $t['status'] !== 'published'));
    $shootsIn = fn(string $a, string $b) => rows("SELECT e.title, e.start FROM events e LEFT JOIN projects p ON p.id=e.project_id
        WHERE e.type='shoot' AND (e.client_id=? OR p.client_id=?) AND e.start BETWEEN ? AND ? ORDER BY e.start", [$cid, $cid, "$a 00:00:00", "$b 23:59:59"]);
    $shoots = $shootsIn($pStart, $pEnd);
    $perPlatform = [];
    foreach ($published as $t) foreach (array_filter(array_map('trim', explode(',', (string)$t['platforms']))) as $pl) $perPlatform[$pl] = ($perPlatform[$pl] ?? 0) + 1;
    arsort($perPlatform);
    $day = fn(string $dt) => (int)date('j', strtotime($dt)) . ' ' . MONTHS[(int)date('n', strtotime($dt))];

    $work = [];
    if ($published) $work[] = count($published) . ' içerik yayınlandı' . ($perPlatform ? ' — ' . implode(', ', array_map(fn($k, $v) => (PLATFORMS[$k] ?? $k) . ' ' . $v, array_keys($perPlatform), $perPlatform)) : '');
    foreach ($shoots as $s) $work[] = $s['title'] . ' çekimi yapıldı (' . $day($s['start']) . ')';
    foreach (array_slice($finished, 0, 8) as $t) $work[] = $t['title'];
    if (count($finished) > 8) $work[] = 've ' . (count($finished) - 8) . ' iş daha tamamlandı';

    $said = [];
    if ($published) $said[] = count($published) . ' içerik yayınladık';
    if ($shoots) $said[] = count($shoots) . ' çekim yaptık';
    if ($finished) $said[] = count($finished) . ' işi tamamladık';
    $summary = $said ? 'Bu ay ' . (count($said) > 1 ? implode(', ', array_slice($said, 0, -1)) . ' ve ' . end($said) : $said[0]) . '.' : '';

    $stats = [];
    if ($published) $stats[] = ['label' => 'Yayınlanan içerik', 'value' => (string)count($published), 'change' => ''];
    if ($shoots) $stats[] = ['label' => 'Çekim', 'value' => (string)count($shoots), 'change' => ''];
    foreach (rows("SELECT id, platform FROM social_accounts WHERE client_id=? ORDER BY platform, id", [$cid]) as $acc) {
        if (count($stats) >= REPORT_MAX_STATS) break;
        $now = row("SELECT followers FROM social_metrics WHERE account_id=? AND date<=? ORDER BY date DESC LIMIT 1", [$acc['id'], $pEnd]);
        if (!$now) continue;
        $before = row("SELECT followers FROM social_metrics WHERE account_id=? AND date<? ORDER BY date DESC LIMIT 1", [$acc['id'], $pStart]);
        $change = '';
        if ($before && (int)$before['followers'] > 0 && (int)$before['followers'] !== (int)$now['followers']) {
            $pct = ((int)$now['followers'] - (int)$before['followers']) / (int)$before['followers'] * 100;
            $change = ($pct >= 0 ? '+' : '-') . '%' . number_format(abs($pct), abs($pct) < 10 ? 1 : 0, ',', '');
        }
        $stats[] = ['label' => (PLATFORMS[$acc['platform']] ?? $acc['platform']) . ' takipçi', 'value' => report_count((int)$now['followers']), 'change' => $change];
    }

    $plan = [];
    $nextCount = (int)val("SELECT COUNT(*) FROM tasks g JOIN projects p ON p.id=g.project_id WHERE p.client_id=? AND g.kind='client' AND g.status!='cancelled' AND g.publish_date BETWEEN ? AND ?", [$cid, $nStart, $nEnd]);
    if ($nextCount) $plan[] = MONTHS[(int)date('n', strtotime($nStart))] . ' için ' . $nextCount . ' içerik planlandı';
    foreach ($shootsIn($nStart, $nEnd) as $s) $plan[] = $s['title'] . ' çekimi (' . $day($s['start']) . ')';

    return report_state_clean(['summary' => $summary, 'work_done' => implode("\n", $work), 'metrics' => '', 'plan' => implode("\n", $plan),
        'mail_data' => ['stats' => $stats]]);
}

function report_mail_html(array $report, array $client, string $period, bool $edit = false): string {
    require_once __DIR__ . '/brand.php';
    $siteName = setting('site_name', 'SADA One');
    $month = (int)substr($period, 5, 2);
    $periodTag = mb_strtoupper(MONTHS[$month] . ' ' . substr($period, 0, 4), 'UTF-8');
    // Palette: SADA navy ink on cool paper, the lime highlighter under what matters
    $ink = '#101f3c'; $body = '#3a4459'; $muted = '#7a8398'; $paper = '#eef1f5'; $soft = '#f4f6f9';
    $lime = '#b1fb01'; $up = '#1c8753'; $down = '#c0283b';
    $sans = "'Segoe UI',Helvetica,Arial,sans-serif";
    $data = json_decode((string)($report['mail_data'] ?? ''), true) ?: [];
    $e2 = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $url = fn(?string $path) => $path ? full_url($path) : null;
    $txt = fn(string $k, string $default) => trim((string)($data['text'][$k] ?? '')) !== '' ? trim($data['text'][$k]) : $default;
    // Edit attributes: contenteditable + where the text goes + what to write when it is empty
    $ed = fn(string $key, string $ph, bool $multi = false) => $edit ? ' contenteditable="true" data-edit="' . $key . '" data-ph="' . $e2($ph) . '"' . ($multi ? ' data-multi="1"' : '') : '';
    $multiline = fn(string $s) => nl2br($e2($s), false);
    $lines = fn(string $s) => array_values(array_filter(array_map(fn($l) => trim(preg_replace('/^[\s\-•*]+/u', '', $l)), explode("\n", $s)), fn($l) => $l !== ''));
    $fav = is_array($data['fav'] ?? null) ? $data['fav'] : [];
    $stats = array_values(array_filter(is_array($data['stats'] ?? null) ? $data['stats'] : [], 'is_array'));
    if (!$edit) $stats = array_values(array_filter($stats, fn($s) => trim((string)($s['label'] ?? '')) !== '' && trim((string)($s['value'] ?? '')) !== ''));

    /* Section heading: small lime tick + title */
    $heading = fn(string $key, string $default, string $extra = '') => '<tr><td style="padding:30px 36px 4px">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="width:14px;vertical-align:middle"><div style="width:9px;height:9px;background:' . $lime . ';border-radius:2px;font-size:0;line-height:0">&nbsp;</div></td>'
        . '<td style="vertical-align:middle;font-size:18px;font-weight:800;letter-spacing:-.3px;color:' . $ink . ';font-family:' . $sans . '"><span' . $ed('text.' . $key, $default) . '>' . $e2($txt($key, $default)) . '</span>' . $extra . '</td></tr></table></td></tr>';
    /* One line of a list: lime square (done) or arrow (planned) */
    $listRow = fn(string $text, string $mark, string $ph) => '<tr data-row="1"><td width="26" valign="top" style="padding:9px 0 9px;font-size:14px;line-height:1.6;color:' . $ink . ';font-weight:700">' . $mark . '</td>'
        . '<td valign="top" style="padding:8px 0;font-size:14.5px;line-height:1.6;color:' . $body . ';border-bottom:1px solid #e8ecf2"' . ($edit ? ' contenteditable="true" data-item="1" data-ph="' . $e2($ph) . '"' : '') . '>' . $e2($text) . '</td></tr>';
    $list = function (string $field, string $mark, string $ph) use ($report, $lines, $listRow, $edit): string {
        $items = $lines((string)($report[$field] ?? ''));
        if ($edit && !$items) $items = [''];
        $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"' . ($edit ? ' data-list="' . $field . '"' : '') . '>';
        foreach ($items as $it) $h .= $listRow($it, $mark, $ph);
        $h .= '</table>';
        if ($edit) $h .= '<button type="button" class="ed-btn ed-add" data-op="item-add" data-for="' . $field . '">+ Satır ekle</button>';
        return $h;
    };
    /* Picture slot in the editor: the picture with change / remove, or an empty frame to click */
    $imgSlot = function (string $slot, ?string $path, string $empty, string $imgStyle, int $width) use ($edit, $url, $e2): string {
        // width attribute: Outlook sizes pictures by it, not by CSS
        if (!$edit) return $path ? '<img src="' . $e2($url($path)) . '" alt="" width="' . $width . '" style="' . $imgStyle . '">' : '';
        if (!$path) return '<div class="ed-slot" data-op="img-pick" data-img="' . $slot . '">+ ' . $e2($empty) . '</div>';
        return '<div class="ed-img"><img src="' . $e2($url($path)) . '" alt="" style="' . $imgStyle . '"><div class="ed-img-tools">'
            . '<button type="button" class="ed-chip" data-op="img-pick" data-img="' . $slot . '">Değiştir</button>'
            . '<button type="button" class="ed-chip" data-op="img-remove" data-img="' . $slot . '">Kaldır</button></div></div>';
    };

    $h = '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $e2($client['name'] . ' — ' . MONTHS[$month] . ' raporu') . '</title>';
    if ($edit) {
        $h .= '<style>'
            . 'body{-webkit-font-smoothing:antialiased}'
            . '[contenteditable]{outline:none;border-radius:5px;cursor:text;transition:box-shadow .15s,background-color .15s}'
            . '[contenteditable]:hover{box-shadow:0 0 0 2px rgba(177,251,1,.6)}'
            . '[contenteditable]:focus{box-shadow:0 0 0 2px #b1fb01;background-color:rgba(177,251,1,.09)}'
            . '[contenteditable]:empty::before{content:attr(data-ph);color:#9aa3b5;font-weight:400;font-style:italic;letter-spacing:0;text-transform:none}'
            . '.ed-btn{font:600 12.5px ' . $sans . ';border:1.5px dashed #b9c1cf;background:#fff;color:#4a556e;border-radius:10px;padding:8px 13px;cursor:pointer;margin-top:10px}'
            . '.ed-btn:hover{border-color:' . $ink . ';color:' . $ink . '}'
            . '.ed-slot{border:2px dashed #c3cad6;border-radius:14px;padding:30px 18px;text-align:center;color:#6b7590;cursor:pointer;font:600 13px ' . $sans . ';background:rgba(255,255,255,.04)}'
            . '.ed-slot:hover{border-color:' . $lime . ';color:' . $ink . ';background:rgba(177,251,1,.12)}'
            . '.ed-dark .ed-slot{color:#aab4c8;border-color:rgba(255,255,255,.3)}.ed-dark .ed-slot:hover{color:#fff}'
            . '.ed-img{position:relative}.ed-img-tools{position:absolute;top:10px;right:10px;display:flex;gap:6px;opacity:0;transition:opacity .15s}.ed-img:hover .ed-img-tools{opacity:1}'
            . '.ed-chip{font:600 12px ' . $sans . ';border:0;background:#fff;color:' . $ink . ';border-radius:99px;padding:6px 12px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.2)}'
            . '.ed-stat{position:relative}.ed-x{position:absolute;top:8px;right:8px;width:24px;height:24px;border-radius:50%;border:0;background:#fff;color:' . $down . ';box-shadow:0 1px 5px rgba(0,0,0,.18);cursor:pointer;font-size:12px;line-height:24px;padding:0;opacity:0;transition:opacity .15s}'
            . '.ed-stat:hover .ed-x{opacity:1}'
            . '.ed-tile-add{width:100%;min-height:118px;margin:0}'
            . '.ed-note{font:12px ' . $sans . ';color:#8a93a8;margin-top:6px}'
            . '</style>';
    }
    $h .= '</head><body style="margin:0;padding:0;background:' . $paper . ';font-family:' . $sans . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $paper . ';padding:28px 10px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 18px 44px -24px rgba(16,31,60,.35)">';

    /* ---- Masthead: agency wordmark · client logo ---- */
    $logo = brand_logo_image(brand_data($client)) ?: (!empty($client['logo']) ? 'uploads/' . $client['logo'] : null);
    $h .= '<tr><td style="padding:22px 36px;border-bottom:1px solid #e8ecf2"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td valign="middle" style="font-size:14px;font-weight:800;letter-spacing:3px;color:' . $ink . '">' . $e2(mb_strtoupper($siteName, 'UTF-8')) . '<span style="color:' . $lime . ';background:' . $ink . ';border-radius:3px;padding:0 3px;margin-left:2px">.</span></td>'
        . '<td valign="middle" align="right">' . ($logo ? '<img src="' . $e2($url($logo)) . '" alt="' . $e2($client['name']) . '" height="34" style="height:34px;width:auto;max-width:150px;display:inline-block;vertical-align:middle">'
            : '<span style="font-size:13px;font-weight:700;color:' . $muted . '">' . $e2($client['name']) . '</span>') . '</td>'
        . '</tr></table></td></tr>';

    /* ---- Cover: period, the big question, the picture ---- */
    $h .= '<tr><td style="background:' . $ink . ';padding:34px 36px 32px"' . ($edit ? ' class="ed-dark"' : '') . '>'
        . '<div style="font-size:11.5px;font-weight:700;letter-spacing:2.4px;color:' . $lime . '">' . $periodTag . ' · AYLIK RAPOR</div>'
        . '<div style="font-size:34px;line-height:1.15;font-weight:800;letter-spacing:-.8px;color:#ffffff;margin-top:12px"' . $ed('text.title', report_month_in($month) . ' neler yaptık?') . '>' . $e2($txt('title', report_month_in($month) . ' neler yaptık?')) . '</div>'
        . '<div style="font-size:14px;color:#aab4c8;margin-top:10px">' . $e2($client['name']) . ' için hazırladık</div>';
    $hero = $imgSlot('hero', $data['hero'] ?? null, 'Kapak görseli ekle (isteğe bağlı)', 'width:100%;max-width:528px;height:auto;display:block;border-radius:14px', 528);
    if ($hero !== '') $h .= '<div style="margin-top:24px">' . $hero . '</div>';
    $h .= '</td></tr>';

    /* ---- Greeting + summary ---- */
    $summary = trim((string)($report['summary'] ?? ''));
    $h .= '<tr><td style="padding:32px 36px 6px">'
        . '<div style="font-size:17px;font-weight:700;color:' . $ink . ';margin-bottom:10px"' . $ed('text.greeting', 'Selamlama') . '>' . $e2($txt('greeting', 'Merhaba ' . $client['name'] . ' ekibi,')) . '</div>';
    if ($summary !== '' || $edit) $h .= '<div style="font-size:15px;line-height:1.75;color:' . $body . '"' . $ed('summary', 'Bu ayı birkaç cümleyle özetleyin: öne çıkanlar, gelişmeler, dikkat çeken sonuçlar...', true) . '>' . $multiline($summary) . '</div>';
    $h .= '</td></tr>';

    /* ---- The numbers: tiles with the highlighter under the value ---- */
    $metrics = trim((string)($report['metrics'] ?? ''));
    $intro = trim((string)($data['text']['stat_intro'] ?? ''));
    if ($stats || $metrics !== '' || $edit) {
        $h .= $heading('stat_title', 'Rakamlarla bu ay');
        $h .= '<tr><td style="padding:14px 30px 0">';
        if ($intro !== '') $h .= '<div style="font-size:14px;color:' . $muted . ';line-height:1.7;padding:0 6px 12px"' . $ed('text.stat_intro', 'Giriş cümlesi') . '>' . $e2($intro) . '</div>';
        $cells = [];
        foreach ($stats as $s) {
            $change = trim((string)($s['change'] ?? ''));
            $isDown = $change !== '' && in_array(mb_substr($change, 0, 1), ['-', '−', '▼'], true);
            $changeText = ltrim($change, '+-−▲▼ ');
            $cells[] = '<td width="50%" valign="top" style="padding:0 6px 12px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $soft . ';border-radius:14px"><tr>'
                . '<td style="padding:17px 19px 18px"' . ($edit ? ' class="ed-stat" data-stat="1"' : '') . '>'
                . ($edit ? '<button type="button" class="ed-x" data-op="stat-del" title="Kutuyu kaldır">✕</button>' : '')
                . '<div style="font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:' . $muted . '"' . ($edit ? ' contenteditable="true" data-k="label" data-ph="Neyin sayısı?"' : '') . '>' . $e2($s['label'] ?? '') . '</div>'
                . '<div style="margin-top:9px;line-height:1.1"><span style="font-size:30px;font-weight:800;letter-spacing:-.6px;color:' . $ink . ';border-bottom:5px solid ' . $lime . ';display:inline-block"' . ($edit ? ' contenteditable="true" data-k="value" data-ph="0"' : '') . '>' . $e2($s['value'] ?? '') . '</span></div>'
                . ($edit
                    ? '<div style="font-size:13px;font-weight:700;margin-top:10px;color:' . ($isDown ? $down : $up) . '" contenteditable="true" data-k="change" data-ph="+%0 değişim (isteğe bağlı)">' . $e2($change) . '</div>'
                    : ($change !== '' ? '<div style="font-size:13px;font-weight:700;margin-top:10px;color:' . ($isDown ? $down : $up) . '">' . ($isDown ? '▼ ' : '▲ ') . $e2($changeText) . ' <span style="font-weight:400;color:' . $muted . '">geçen aya göre</span></div>' : ''))
                . '</td></tr></table></td>';
        }
        if ($edit && count($stats) < REPORT_MAX_STATS) $cells[] = '<td width="50%" valign="top" style="padding:0 6px 12px"><button type="button" class="ed-btn ed-tile-add" data-op="stat-add">+ Rakam kutusu ekle</button></td>';
        if ($cells) {
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
            foreach (array_chunk($cells, 2) as $pair) $h .= '<tr>' . implode('', $pair) . (count($pair) === 1 ? '<td width="50%"></td>' : '') . '</tr>';
            $h .= '</table>';
        }
        if ($metrics !== '' || $edit) $h .= '<div style="font-size:14px;line-height:1.75;color:' . $body . ';padding:4px 6px 0"' . $ed('metrics', 'Rakamlara kısa bir yorum (isteğe bağlı)', true) . '>' . $multiline($metrics) . '</div>';
        $h .= '</td></tr>';
    }

    /* ---- What we made this month ---- */
    $workItems = $lines((string)($report['work_done'] ?? ''));
    if ($workItems || $edit) {
        $h .= $heading('production_title', 'Bu ay ürettiklerimiz', $edit ? '' : ' <span style="font-size:13px;font-weight:700;color:' . $muted . ';letter-spacing:0">· ' . count($workItems) . '</span>');
        $h .= '<tr><td style="padding:10px 36px 0">' . $list('work_done', '<span style="display:inline-block;width:8px;height:8px;background:' . $lime . ';border:1.5px solid ' . $ink . ';border-radius:2px"></span>', 'Bu ay ne yapıldı? Örn. 12 gönderi tasarlandı ve yayınlandı') . '</td></tr>';
    }

    /* ---- Favourite of the month ---- */
    $favOn = trim((string)($fav['title'] ?? '')) !== '' || trim((string)($fav['text'] ?? '')) !== '' || !empty($fav['img']);
    if ($favOn || $edit) {
        $favImg = $imgSlot('fav', $fav['img'] ?? null, 'Görsel ekle', 'width:100%;height:auto;display:block;border-radius:12px', 188);
        $h .= '<tr><td style="padding:30px 30px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3fbe0;border-radius:18px;border:1px solid #dff2a8"><tr>';
        if ($favImg !== '') $h .= '<td width="210" valign="top" style="padding:22px 0 22px 22px">' . $favImg . '</td>';
        $favStat = trim((string)($fav['stat'] ?? ''));
        $h .= '<td valign="top" style="padding:24px 24px 24px 22px">'
            . '<div style="font-size:11px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:' . $ink . '"><span style="background:' . $lime . ';padding:3px 8px;border-radius:5px"' . $ed('text.fav_label', 'AYIN FAVORİSİ') . '>' . $e2($txt('fav_label', 'Ayın favorisi')) . '</span></div>'
            . '<div style="font-size:20px;font-weight:800;letter-spacing:-.3px;color:' . $ink . ';margin-top:12px;line-height:1.25"' . $ed('fav.title', 'Ayın en sevdiğimiz işi') . '>' . $e2($fav['title'] ?? '') . '</div>'
            . (trim((string)($fav['text'] ?? '')) !== '' || $edit ? '<div style="font-size:14px;line-height:1.7;color:' . $body . ';margin-top:8px"' . $ed('fav.text', 'Neden öne çıktı? Ne sonuç getirdi?', true) . '>' . $multiline(trim((string)($fav['text'] ?? ''))) . '</div>' : '')
            . ($favStat !== '' || $edit ? '<div style="margin-top:14px"><span style="display:inline-block;font-size:15px;font-weight:800;color:#ffffff;background:' . $ink . ';border-radius:9px;padding:7px 13px"' . $ed('fav.stat', 'Öne çıkan sayı: 113B izlenme') . '>' . $e2($favStat) . '</span></div>' : '')
            . '</td></tr></table>'
            . ($edit ? '<div class="ed-note">Başlık, metin ve görsel boş kalırsa bu bölüm maile girmez.</div>' : '')
            . '</td></tr>';
    }

    /* ---- Next month ---- */
    $planItems = $lines((string)($report['plan'] ?? ''));
    if ($planItems || $edit) {
        $h .= $heading('plan_title', 'Önümüzdeki ay');
        $h .= '<tr><td style="padding:10px 36px 0">' . $list('plan', '→', 'Gelecek ay ne yapılacak?') . '</td></tr>';
    }

    /* ---- Closing + contact person ---- */
    $owner = !empty($client['manager_id']) ? row("SELECT name, email, job_title FROM users WHERE id=?", [(int)$client['manager_id']]) : null;
    $h .= '<tr><td style="padding:30px 36px 32px">'
        . '<div style="font-size:15px;line-height:1.7;color:' . $body . '"' . $ed('text.closing', 'Kapanış cümlesi') . '>' . $e2($txt('closing', 'Önümüzdeki ay görüşmek üzere.')) . '</div>';
    if ($owner) {
        $initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/u', trim($owner['name'])), 0, 2))), 'UTF-8');
        $h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:20px"><tr>'
            . '<td valign="middle" style="width:44px;height:44px;background:' . $ink . ';border-radius:50%;text-align:center;font-size:15px;font-weight:800;color:' . $lime . '">' . $e2($initials) . '</td>'
            . '<td valign="middle" style="padding-left:12px;font-size:13.5px;line-height:1.5;color:' . $body . '"><b style="color:' . $ink . '">' . $e2($owner['name']) . '</b>'
            . (!empty($owner['job_title']) ? '<span style="color:' . $muted . '"> · ' . $e2($owner['job_title']) . '</span>' : '')
            . ($owner['email'] ? '<br><a href="mailto:' . $e2($owner['email']) . '" style="color:' . $ink . ';text-decoration:underline">' . $e2($owner['email']) . '</a>' : '') . '</td>'
            . '</tr></table>';
    }
    $h .= '</td></tr>';

    /* ---- Footer ---- */
    $h .= '<tr><td style="background:' . $ink . ';padding:26px 36px" align="center">'
        . '<div style="font-size:20px;font-weight:800;letter-spacing:-.3px;color:#ffffff"' . $ed('text.thanks', 'Teşekkürler!') . '>' . $e2($txt('thanks', 'Teşekkürler!')) . '</div>'
        . '<div style="font-size:12px;letter-spacing:3px;font-weight:800;color:#ffffff;margin-top:14px">' . $e2(mb_strtoupper($siteName, 'UTF-8')) . '<span style="color:' . $lime . '">.</span>'
        . ' <span style="letter-spacing:1px;font-weight:400;color:#8a93a8">· ' . date('Y') . '</span></div>'
        . '</td></tr>';

    return $h . '</table></td></tr></table></body></html>';
}

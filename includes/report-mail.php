<?php
/**
 * SADA One — Monthly report → client-facing HTML e-mail, and the editor it is written in.
 * Email-safe markup on purpose: tables, inline styles and inline-block columns, 600px wide, no external CSS. Columns
 * wrap two to a row on a phone (min-width) and Outlook gets fixed tables in conditional comments. Internal finance
 * figures are deliberately NOT included — this goes to the client.
 *
 * The same markup is the editor (monthly-reports.php): with $edit every text is contenteditable (data-edit,
 * data-list, data-stat, data-gal), picture slots and tiles get their controls and empty parts show what goes there.
 * The page reads the edited document back into the report. The mail leaves out whatever was left empty.
 *
 * Report fields: summary, work_done (one line each), metrics (a note under the numbers), plan (one line each).
 * mail_data JSON: hero (cover picture), gallery [{img, caption}] (the month's work in pictures), fav {img, title,
 * text, stat}, text {title, greeting, …}, stats [{label, value, change}].
 */

const REPORT_TEXT_KEYS = ['title', 'greeting', 'production_title', 'stat_title', 'stat_intro', 'gallery_title', 'fav_label', 'plan_title', 'closing', 'thanks'];
const REPORT_MAX_STATS = 6;
const REPORT_MAX_GALLERY = 6;

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
 * A square crop of an uploaded picture for the mail's picture grid (mail clients cannot crop reliably), made once
 * and kept under uploads/thumbs/. Without GD, or when the picture cannot be read, the picture itself.
 */
function report_thumb(string $path, int $size = 360): string {
    $file = ROOT . '/' . $path;
    if (!function_exists('imagecreatetruecolor') || !is_file($file)) return $path;
    $thumb = 'uploads/thumbs/r' . substr(md5($path . '|' . $size . '|' . filemtime($file)), 0, 16) . '.jpg';
    if (is_file(ROOT . '/' . $thumb)) return $thumb;
    $src = @imagecreatefromstring((string)file_get_contents($file));
    if (!$src) return $path;
    [$w, $h] = [imagesx($src), imagesy($src)];
    $side = min($w, $h);
    $out = imagecreatetruecolor($size, $size);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // transparent PNGs sit on white
    imagecopyresampled($out, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), $size, $size, $side, $side);
    if (!is_dir(ROOT . '/uploads/thumbs')) @mkdir(ROOT . '/uploads/thumbs', 0755, true);
    $ok = @imagejpeg($out, ROOT . '/' . $thumb, 84);
    imagedestroy($src); imagedestroy($out);
    return $ok ? $thumb : $path;
}

/** The agency's own logo from Settings (the dark-background version when asked and set), as an uploads/ path */
function report_site_logo(bool $dark = false): ?string {
    $logo = ($dark ? setting('site_logo_dark') : '') ?: setting('site_logo');
    return $logo ? 'uploads/' . ltrim($logo, '/') : null;
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
    $data['gallery'] = [];
    foreach (array_values(array_filter(is_array($md['gallery'] ?? null) ? $md['gallery'] : [], 'is_array')) as $g) {
        if (count($data['gallery']) >= REPORT_MAX_GALLERY) break;
        if ($p = $img($g['img'] ?? null)) $data['gallery'][] = ['img' => $p, 'caption' => $line($g['caption'] ?? '', 80)];
    }
    return ['summary' => $txt($s['summary'] ?? '', 5000), 'work_done' => $lines($s['work_done'] ?? ''), 'metrics' => $txt($s['metrics'] ?? '', 3000),
        'plan' => $lines($s['plan'] ?? ''), 'mail_data' => json_encode(['fav' => (object)$data['fav'], 'text' => (object)$data['text']] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}

/**
 * A new report, already filled with the month: what was published and finished (with the latest picture of each
 * published work for the picture grid), the shoots, the accounts' follower counts against the month before, and
 * what is planned for next month. Everything stays editable.
 */
function report_draft(array $client, string $period): array {
    $cid = (int)$client['id'];
    $pStart = "$period-01"; $pEnd = date('Y-m-t', strtotime($pStart));
    $nStart = date('Y-m-01', strtotime("$pStart +1 month")); $nEnd = date('Y-m-t', strtotime($nStart));
    $done = rows("SELECT g.id, g.title, g.status, g.platforms FROM tasks g JOIN projects p ON p.id=g.project_id
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

    // The month in pictures: the last picture uploaded to each published work (the final version)
    $gallery = [];
    foreach (array_reverse($published) as $t) {
        if (count($gallery) >= REPORT_MAX_GALLERY) break;
        $file = val("SELECT file_path FROM archive WHERE task_id=? AND file_path!='' AND LOWER(extension) IN ('jpg','jpeg','png','webp','gif') ORDER BY id DESC LIMIT 1", [$t['id']]);
        if ($file) $gallery[] = ['img' => 'uploads/' . ltrim($file, '/'), 'caption' => $t['title']];
    }

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
        'mail_data' => ['stats' => $stats, 'gallery' => $gallery]]);
}

function report_mail_html(array $report, array $client, string $period, bool $edit = false): string {
    require_once __DIR__ . '/brand.php';
    $month = (int)substr($period, 5, 2);
    $periodName = MONTHS[$month] . ' ' . substr($period, 0, 4);
    // Palette: SADA navy ink on white paper, a cool grey around it, the lime highlighter only under what matters
    $ink = '#0f1d3a'; $body = '#475069'; $muted = '#8a90a0'; $line = '#eceef2'; $paper = '#f2f3f6'; $soft = '#f6f7f9';
    $lime = '#b1fb01'; $up = '#1c8753'; $down = '#c0283b';
    $sans = "'Helvetica Neue',Helvetica,Arial,sans-serif";
    $serif = "Georgia,'Times New Roman',serif";
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
    $gallery = array_values(array_filter(is_array($data['gallery'] ?? null) ? $data['gallery'] : [], fn($g) => is_array($g) && !empty($g['img'])));
    $pad = 'padding-left:40px;padding-right:40px';

    /*
     * Columns that sit side by side on a desktop and wrap on a phone: inline-block cells with a min-width, and for
     * Outlook (which ignores both) a real table in conditional comments. $cells: [html, …]; $widths: px per cell.
     */
    $columns = function (array $cells, array $widths, string $class = '', array $mins = []) use ($edit): string {
        $h = '<div' . ($class ? ' class="' . $class . '"' : '') . ' style="font-size:0;line-height:0">';
        if (!$edit) $h .= '<!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><![endif]-->';
        $total = array_sum($widths);
        foreach ($cells as $i => $cell) {
            $w = $widths[$i % count($widths)];
            if (!$edit && $i > 0 && $i % count($widths) === 0) $h .= '<!--[if mso]></tr><tr><![endif]-->';
            if (!$edit) $h .= '<!--[if mso]><td width="' . $w . '" valign="top"><![endif]-->';
            $h .= '<div class="col" style="display:inline-block;vertical-align:top;width:' . round($w / $total * 100, 2) . '%;min-width:' . ($mins[$i % count($widths)] ?? min($w, 150)) . 'px;font-size:14px;line-height:1.5">' . $cell . '</div>';
            if (!$edit) $h .= '<!--[if mso]></td><![endif]-->';
        }
        if (!$edit) $h .= '<!--[if mso]></tr></table><![endif]-->';
        return $h . '</div>';
    };
    /* Section label: small spaced capitals with a hairline running to the edge */
    $heading = fn(string $key, string $default, string $extra = '') => '<tr><td style="padding-top:40px;' . $pad . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="white-space:nowrap;padding-right:14px;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:' . $ink . ';font-family:' . $sans . '">'
        . '<span' . $ed('text.' . $key, $default) . '>' . $e2($txt($key, $default)) . '</span>' . $extra . '</td>'
        . '<td width="100%" style="border-bottom:1px solid ' . $line . ';font-size:0;line-height:0">&nbsp;</td></tr></table></td></tr>';
    /* One line of a list */
    $listRow = fn(string $text, string $mark, string $ph) => '<tr data-row="1"><td width="22" valign="top" style="padding:11px 0;font-size:13px;line-height:1.6;color:' . $ink . '">' . $mark . '</td>'
        . '<td valign="top" style="padding:10px 0;font-size:15px;line-height:1.6;color:' . $body . ';border-bottom:1px solid ' . $line . '"' . ($edit ? ' contenteditable="true" data-item="1" data-ph="' . $e2($ph) . '"' : '') . '>' . $e2($text) . '</td></tr>';
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

    $summary = trim((string)($report['summary'] ?? ''));
    $h = '<!DOCTYPE html><html lang="tr" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting">'
        . '<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light">'
        . '<title>' . $e2($client['name'] . ' — ' . $periodName . ' raporu') . '</title>'
        . '<!--[if mso]><style>*{font-family:Arial,sans-serif!important}</style><![endif]-->'
        // Phones: narrower margins, a smaller title, columns full width where the client allows media queries
        . '<style>:root{color-scheme:light only}@media only screen and (max-width:620px){'
        . '.wrap{padding:0!important}.card{border-radius:0!important}.px{padding-left:22px!important;padding-right:22px!important}'
        . '.title{font-size:28px!important}.stack .col{display:block!important;width:100%!important}.stack .col+.col>div{padding:18px 0 0!important}}</style>';
    if ($edit) {
        $h .= '<style>'
            . 'body{-webkit-font-smoothing:antialiased}'
            . '[contenteditable]{outline:none;border-radius:5px;cursor:text;transition:box-shadow .15s,background-color .15s}'
            . '[contenteditable]:hover{box-shadow:0 0 0 2px rgba(177,251,1,.6)}'
            . '[contenteditable]:focus{box-shadow:0 0 0 2px #b1fb01;background-color:rgba(177,251,1,.09)}'
            . '[contenteditable]:empty::before{content:attr(data-ph);color:#a3a9b8;font-weight:400;font-style:italic;letter-spacing:0;text-transform:none}'
            . '.ed-btn{font:600 12.5px ' . $sans . ';border:1.5px dashed #c3c8d3;background:#fff;color:#4a556e;border-radius:10px;padding:8px 13px;cursor:pointer;margin-top:12px}'
            . '.ed-btn:hover{border-color:' . $ink . ';color:' . $ink . '}'
            . '.ed-slot{border:2px dashed #cdd2db;border-radius:12px;padding:30px 14px;text-align:center;color:#6b7590;cursor:pointer;font:600 13px ' . $sans . ';line-height:1.4}'
            . '.ed-slot:hover{border-color:' . $lime . ';color:' . $ink . ';background:rgba(177,251,1,.12)}'
            . '.ed-img{position:relative}.ed-img-tools{position:absolute;top:8px;right:8px;display:flex;gap:5px;opacity:0;transition:opacity .15s}.ed-img:hover .ed-img-tools{opacity:1}'
            . '.ed-chip{font:600 11.5px ' . $sans . ';border:0;background:#fff;color:' . $ink . ';border-radius:99px;padding:5px 10px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.2)}'
            . '.ed-stat{position:relative}.ed-x{position:absolute;top:2px;right:8px;width:24px;height:24px;border-radius:50%;border:0;background:#fff;color:' . $down . ';box-shadow:0 1px 5px rgba(0,0,0,.18);cursor:pointer;font-size:12px;line-height:24px;padding:0;opacity:0;transition:opacity .15s}'
            . '.ed-stat:hover .ed-x{opacity:1}'
            . '.ed-tile-add{width:calc(100% - 16px);min-height:96px;margin:0 8px}'
            . '.ed-note{font:12px ' . $sans . ';color:#8a93a8;margin-top:8px}'
            . '</style>';
    }
    $h .= '</head><body style="margin:0;padding:0;background:' . $paper . ';font-family:' . $sans . ';-webkit-text-size-adjust:100%">';
    // Inbox preview line
    if (!$edit && $summary !== '') $h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . $e2(mb_strimwidth(preg_replace('/\s+/u', ' ', $summary), 0, 140, '…', 'UTF-8')) . '</div>';
    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $paper . '"><tr><td align="center" class="wrap" style="padding:32px 12px">'
        . '<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0"><tr><td><![endif]-->'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="card" style="max-width:600px;background:#ffffff;border:1px solid #e6e8ee;border-radius:18px">';

    /* ---- Masthead: the SADA logo · the client's logo ---- */
    $sadaLogo = report_site_logo();
    $clientLogo = brand_logo_image(brand_data($client)) ?: (!empty($client['logo']) ? 'uploads/' . $client['logo'] : null);
    $h .= '<tr><td class="px" style="padding-top:30px;padding-bottom:6px;' . $pad . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td valign="middle">' . ($sadaLogo
            ? '<img src="' . $e2($url($sadaLogo)) . '" alt="SADA" height="30" style="height:30px;width:auto;max-width:170px;display:block;border:0">'
            : '<span style="font-size:17px;font-weight:800;letter-spacing:4px;color:' . $ink . '">SADA</span>') . '</td>'
        . '<td valign="middle" align="right">' . ($clientLogo
            ? '<img src="' . $e2($url($clientLogo)) . '" alt="' . $e2($client['name']) . '" height="30" style="height:30px;width:auto;max-width:130px;display:inline-block;border:0">'
            : '<span style="font-size:12px;font-weight:700;letter-spacing:1px;color:' . $muted . '">' . $e2($client['name']) . '</span>') . '</td>'
        . '</tr></table></td></tr>';

    /* ---- Opening: the month, the question, the greeting and summary ---- */
    $h .= '<tr><td class="px" style="padding-top:34px;' . $pad . '">'
        . '<div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:' . $muted . '">'
        . '<span style="background:' . $lime . ';color:' . $ink . ';padding:3px 7px;border-radius:3px;margin-right:8px">Aylık rapor</span>' . $e2($periodName) . '</div>'
        . '<div class="title" style="font-family:' . $serif . ';font-size:34px;line-height:1.18;font-weight:400;letter-spacing:-.4px;color:' . $ink . ';margin-top:18px"'
        . $ed('text.title', report_month_in($month) . ' neler yaptık?') . '>' . $e2($txt('title', report_month_in($month) . ' neler yaptık?')) . '</div>'
        . '<div style="font-size:16px;font-weight:700;color:' . $ink . ';margin-top:26px"' . $ed('text.greeting', 'Selamlama') . '>' . $e2($txt('greeting', 'Merhaba ' . $client['name'] . ' ekibi,')) . '</div>';
    if ($summary !== '' || $edit) $h .= '<div style="font-size:15px;line-height:1.75;color:' . $body . ';margin-top:10px"' . $ed('summary', 'Bu ayı birkaç cümleyle özetleyin: öne çıkanlar, gelişmeler, dikkat çeken sonuçlar...', true) . '>' . $multiline($summary) . '</div>';
    $hero = $imgSlot('hero', $data['hero'] ?? null, 'Kapak görseli ekle (isteğe bağlı)', 'width:100%;max-width:520px;height:auto;display:block;border-radius:12px;border:0', 520);
    if ($hero !== '') $h .= '<div style="margin-top:28px">' . $hero . '</div>';
    $h .= '</td></tr>';

    /* ---- The numbers: three to a row, the highlighter under each value ---- */
    $metrics = trim((string)($report['metrics'] ?? ''));
    $intro = trim((string)($data['text']['stat_intro'] ?? ''));
    if ($stats || $metrics !== '' || $edit) {
        $h .= $heading('stat_title', 'Rakamlarla bu ay');
        $h .= '<tr><td class="px" style="padding-top:22px;padding-left:32px;padding-right:32px">';
        if ($intro !== '') $h .= '<div style="font-size:14px;color:' . $muted . ';line-height:1.7;padding:0 8px 14px"' . $ed('text.stat_intro', 'Giriş cümlesi') . '>' . $e2($intro) . '</div>';
        $cells = [];
        foreach ($stats as $s) {
            $change = trim((string)($s['change'] ?? ''));
            $isDown = $change !== '' && in_array(mb_substr($change, 0, 1), ['-', '−', '▼'], true);
            $changeText = ltrim($change, '+-−▲▼ ');
            $cells[] = '<div style="padding:0 8px 26px"' . ($edit ? ' class="ed-stat" data-stat="1"' : '') . '>'
                . ($edit ? '<button type="button" class="ed-x" data-op="stat-del" title="Kutuyu kaldır">✕</button>' : '')
                . '<div style="font-size:10.5px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:' . $muted . ';min-height:15px"' . ($edit ? ' contenteditable="true" data-k="label" data-ph="Neyin sayısı?"' : '') . '>' . $e2($s['label'] ?? '') . '</div>'
                . '<div style="margin-top:8px;line-height:1.1"><span style="font-size:32px;font-weight:700;letter-spacing:-.8px;color:' . $ink . ';border-bottom:4px solid ' . $lime . ';display:inline-block;padding-bottom:2px"' . ($edit ? ' contenteditable="true" data-k="value" data-ph="0"' : '') . '>' . $e2($s['value'] ?? '') . '</span></div>'
                . ($edit
                    ? '<div style="font-size:12.5px;font-weight:700;margin-top:10px;color:' . ($isDown ? $down : $up) . '" contenteditable="true" data-k="change" data-ph="+%0 değişim (isteğe bağlı)">' . $e2($change) . '</div>'
                    : ($change !== '' ? '<div style="font-size:12.5px;font-weight:700;margin-top:10px;color:' . ($isDown ? $down : $up) . '">' . ($isDown ? '▼ ' : '▲ ') . $e2($changeText) . ' <span style="font-weight:400;color:' . $muted . '">geçen aya göre</span></div>' : ''))
                . '</div>';
        }
        if ($edit && count($stats) < REPORT_MAX_STATS) $cells[] = '<div style="padding:0 0 26px"><button type="button" class="ed-btn ed-tile-add" data-op="stat-add">+ Rakam ekle</button></div>';
        if ($cells) $h .= $columns($cells, [176, 176, 176]);
        if ($metrics !== '' || $edit) $h .= '<div style="font-size:14.5px;line-height:1.75;color:' . $body . ';padding:0 8px"' . $ed('metrics', 'Rakamlara kısa bir yorum (isteğe bağlı)', true) . '>' . $multiline($metrics) . '</div>';
        $h .= '</td></tr>';
    }

    /* ---- The month in pictures ---- */
    if ($gallery || $edit) {
        $h .= $heading('gallery_title', 'Bu ay yayınlananlardan');
        $h .= '<tr><td class="px" style="padding-top:22px;padding-left:34px;padding-right:34px">';
        $cells = [];
        foreach ($gallery as $i => $g) {
            $thumb = report_thumb($g['img']);
            $pic = '<img src="' . $e2($url($thumb)) . '" alt="' . $e2($g['caption'] ?? '') . '" width="162" style="width:100%;max-width:162px;height:auto;display:block;border-radius:10px;border:0">';
            $cells[] = '<div style="padding:0 6px 18px"' . ($edit ? ' data-gal="1" data-path="' . $e2($g['img']) . '"' : '') . '>'
                . ($edit ? '<div class="ed-img">' . $pic . '<div class="ed-img-tools"><button type="button" class="ed-chip" data-op="img-pick" data-img="gal:' . $i . '">Değiştir</button>'
                    . '<button type="button" class="ed-chip" data-op="gal-del">Kaldır</button></div></div>' : $pic)
                . ((trim((string)($g['caption'] ?? '')) !== '' || $edit) ? '<div style="font-size:12.5px;line-height:1.45;color:' . $body . ';margin-top:8px"' . ($edit ? ' contenteditable="true" data-k="caption" data-ph="Açıklama"' : '') . '>' . $e2($g['caption'] ?? '') . '</div>' : '')
                . '</div>';
        }
        if ($edit && count($gallery) < REPORT_MAX_GALLERY) $cells[] = '<div style="padding:0 6px 18px"><div class="ed-slot" data-op="img-pick" data-img="gal-new" style="padding:48px 10px">+ Görsel ekle</div></div>';
        $h .= $columns($cells, [174, 174, 174]);
        if ($edit) $h .= '<div class="ed-note" style="padding:0 6px">Ayın yayınlanan işlerinin son görselleri kendiliğinden gelir; görsel yoksa bu bölüm maile girmez.</div>';
        $h .= '</td></tr>';
    }

    /* ---- What we made this month ---- */
    $workItems = $lines((string)($report['work_done'] ?? ''));
    if ($workItems || $edit) {
        $h .= $heading('production_title', 'Bu ay ürettiklerimiz');
        $h .= '<tr><td class="px" style="padding-top:10px;' . $pad . '">' . $list('work_done', '<span style="display:inline-block;width:6px;height:6px;background:' . $lime . ';border:1.5px solid ' . $ink . ';border-radius:50%"></span>', 'Bu ay ne yapıldı? Örn. 12 gönderi tasarlandı ve yayınlandı') . '</td></tr>';
    }

    /* ---- Favourite of the month ---- */
    $favOn = trim((string)($fav['title'] ?? '')) !== '' || trim((string)($fav['text'] ?? '')) !== '' || !empty($fav['img']);
    if ($favOn || $edit) {
        $favImg = $imgSlot('fav', $fav['img'] ?? null, 'Görsel ekle', 'width:100%;max-width:200px;height:auto;display:block;border-radius:10px;border:0', 200);
        $favStat = trim((string)($fav['stat'] ?? ''));
        $favText = '<div style="padding:4px 4px 4px ' . ($favImg !== '' ? '22px' : '4px') . '">'
            . '<div style="font-size:10.5px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:' . $muted . '"' . $ed('text.fav_label', 'AYIN FAVORİSİ') . '>' . $e2($txt('fav_label', 'Ayın favorisi')) . '</div>'
            . '<div style="font-family:' . $serif . ';font-size:22px;line-height:1.3;color:' . $ink . ';margin-top:10px"' . $ed('fav.title', 'Ayın en sevdiğimiz işi') . '>' . $e2($fav['title'] ?? '') . '</div>'
            . (trim((string)($fav['text'] ?? '')) !== '' || $edit ? '<div style="font-size:14px;line-height:1.7;color:' . $body . ';margin-top:8px"' . $ed('fav.text', 'Neden öne çıktı? Ne sonuç getirdi?', true) . '>' . $multiline(trim((string)($fav['text'] ?? ''))) . '</div>' : '')
            . ($favStat !== '' || $edit ? '<div style="margin-top:14px"><span style="display:inline-block;font-size:14px;font-weight:700;color:' . $ink . ';border-bottom:3px solid ' . $lime . '"' . $ed('fav.stat', 'Öne çıkan sayı: 113B izlenme') . '>' . $e2($favStat) . '</span></div>' : '')
            . '</div>';
        $h .= '<tr><td class="px" style="padding-top:40px;' . $pad . '"><div style="background:' . $soft . ';border-radius:14px;padding:22px">'
            . ($favImg !== '' ? $columns([$favImg, $favText], [200, 276], 'stack', [150, 240]) : $favText)
            . '</div>' . ($edit ? '<div class="ed-note">Başlık, metin ve görsel boş kalırsa bu bölüm maile girmez.</div>' : '') . '</td></tr>';
    }

    /* ---- Next month ---- */
    $planItems = $lines((string)($report['plan'] ?? ''));
    if ($planItems || $edit) {
        $h .= $heading('plan_title', 'Önümüzdeki ay');
        $h .= '<tr><td class="px" style="padding-top:10px;' . $pad . '">' . $list('plan', '→', 'Gelecek ay ne yapılacak?') . '</td></tr>';
    }

    /* ---- Closing, signature, the person to reach ---- */
    $owner = !empty($client['manager_id']) ? row("SELECT name, email, job_title FROM users WHERE id=?", [(int)$client['manager_id']]) : null;
    $h .= '<tr><td class="px" style="padding-top:40px;padding-bottom:36px;' . $pad . '">'
        . '<div style="font-size:15px;line-height:1.75;color:' . $body . '"' . $ed('text.closing', 'Kapanış cümlesi') . '>' . $e2($txt('closing', 'Önümüzdeki ay görüşmek üzere.')) . '</div>'
        . '<div style="font-family:' . $serif . ';font-size:20px;color:' . $ink . ';margin-top:14px"' . $ed('text.thanks', 'Teşekkürler!') . '>' . $e2($txt('thanks', 'Teşekkürler!')) . '</div>';
    if ($owner) {
        $initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/u', trim($owner['name'])), 0, 2))), 'UTF-8');
        $h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:22px"><tr>'
            . '<td valign="middle" width="40" height="40" style="width:40px;height:40px;background:' . $ink . ';border-radius:50%;text-align:center;font-size:13px;font-weight:700;color:#ffffff">' . $e2($initials) . '</td>'
            . '<td valign="middle" style="padding-left:12px;font-size:13.5px;line-height:1.5;color:' . $body . '"><b style="color:' . $ink . '">' . $e2($owner['name']) . '</b>'
            . (!empty($owner['job_title']) ? '<span style="color:' . $muted . '"> · ' . $e2($owner['job_title']) . '</span>' : '')
            . ($owner['email'] ? '<br><a href="mailto:' . $e2($owner['email']) . '" style="color:' . $ink . ';text-decoration:underline">' . $e2($owner['email']) . '</a>' : '') . '</td>'
            . '</tr></table>';
    }
    $h .= '</td></tr>';

    /* ---- Footer: quiet, on the paper ---- */
    $h .= '</table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px"><tr><td align="center" style="padding:26px 20px 8px;font-size:12px;line-height:1.6;color:' . $muted . '">'
        . ($sadaLogo ? '<img src="' . $e2($url($sadaLogo)) . '" alt="SADA" height="18" style="height:18px;width:auto;display:inline-block;border:0;opacity:.75">'
            : '<span style="font-size:12px;font-weight:800;letter-spacing:3px;color:' . $muted . '">SADA</span>')
        . '<div style="margin-top:8px">' . $e2($client['name']) . ' için hazırlanan ' . $e2($periodName) . ' raporu</div></td></tr></table>'
        . '<!--[if mso]></td></tr></table><![endif]-->';

    return $h . '</td></tr></table></body></html>';
}

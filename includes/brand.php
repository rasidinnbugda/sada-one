<?php
/**
 * SADA One — Brand kit of a client file
 * Colours, logos, typefaces, tone of voice and do / don't rules, kept as JSON in clients.brand_data. The free text
 * the file had before 8.1 (clients.brand_kit) stays as the kit's notes. Edited on brand.php, shown on the client
 * file and on the file's work.
 */

const BRAND_LOGO_PREVIEW = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const BRAND_LOGO_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'ai', 'psd'];

/** The kit with every part present: colors [{name, hex}], logos [{id, name, path, ext}], fonts [{name, usage, url}], voice, do [], dont [], folder, notes */
function brand_data(array $client): array {
    $raw = json_decode((string)($client['brand_data'] ?? ''), true);
    $raw = is_array($raw) ? $raw : [];
    $list = fn($k) => array_values(array_filter(is_array($raw[$k] ?? null) ? $raw[$k] : [], 'is_array'));
    $lines = fn($k) => array_values(array_filter(array_map('strval', is_array($raw[$k] ?? null) ? $raw[$k] : []), fn($s) => trim($s) !== ''));
    return [
        'colors' => $list('colors'), 'logos' => $list('logos'), 'fonts' => $list('fonts'),
        'voice' => (string)($raw['voice'] ?? ''), 'do' => $lines('do'), 'dont' => $lines('dont'),
        'folder' => (string)($raw['folder'] ?? ''), 'notes' => trim((string)($client['brand_kit'] ?? '')),
    ];
}

function brand_is_empty(array $b): bool {
    return !$b['colors'] && !$b['logos'] && !$b['fonts'] && trim($b['voice']) === '' && !$b['do'] && !$b['dont'] && $b['folder'] === '' && $b['notes'] === '';
}

/** "#1A2B3C" from "1a2b3c", "#abc" or "#AABBCC"; null when it is not a colour */
function brand_hex(string $v): ?string {
    $v = ltrim(trim($v), '#');
    if (preg_match('/^[0-9a-f]{3}$/i', $v)) $v = $v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2];
    return preg_match('/^[0-9a-f]{6}$/i', $v) ? '#' . strtoupper($v) : null;
}

/** Dark text or light text on this colour */
function brand_ink(string $hex): string {
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#121c33' : '#ffffff';
}

/** Saves the kit (notes go to the old brand_kit column) */
function brand_store(int $clientId, array $b): void {
    $notes = $b['notes'];
    unset($b['notes']);
    update_row('clients', ['brand_data' => json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'brand_kit' => $notes !== '' ? $notes : null], 'id=?', [$clientId]);
}

/** The first logo that can be shown as a picture (for the report mail, the client card) */
function brand_logo_image(array $b): ?string {
    foreach ($b['logos'] as $l) if (in_array($l['ext'] ?? '', BRAND_LOGO_PREVIEW, true)) return $l['path'];
    return null;
}

/** Compact kit: colour chips (click copies the code), logos, typefaces, tone — on the client file and on work */
function brand_kit_compact(array $client, bool $withVoice = true): string {
    $b = brand_data($client);
    $h = '';
    if ($b['colors']) {
        $h .= '<div class="bk-chips">';
        foreach ($b['colors'] as $c) {
            $hex = brand_hex((string)($c['hex'] ?? '')) ?? '#000000';
            $h .= '<button type="button" class="bk-chip" data-copy="' . $hex . '" title="' . e(trim(($c['name'] ?? '') . ' ' . $hex)) . ' — kopyala">'
                . '<span class="bk-chip-dot" style="background:' . $hex . '"></span><span class="bk-chip-hex">' . $hex . '</span></button>';
        }
        $h .= '</div>';
    }
    if ($b['logos']) {
        $h .= '<div class="bk-mini-logos">';
        foreach ($b['logos'] as $l) {
            $img = in_array($l['ext'] ?? '', BRAND_LOGO_PREVIEW, true);
            $h .= '<a class="bk-mini-logo" href="' . e($l['path']) . '" download title="' . e($l['name'] ?? 'Logo') . ' — indir">'
                . ($img ? '<img src="' . e($l['path']) . '" alt="">' : '<span>' . e(strtoupper($l['ext'] ?? '')) . '</span>') . '</a>';
        }
        $h .= '</div>';
    }
    if ($b['fonts']) {
        $h .= '<div class="small text-2 mt-2">' . implode(' · ', array_map(fn($f) => '<b>' . e($f['name'] ?? '') . '</b>' . (!empty($f['usage']) ? ' <span class="text-muted">' . e($f['usage']) . '</span>' : ''), $b['fonts'])) . '</div>';
    }
    if ($withVoice && trim($b['voice']) !== '') $h .= '<div class="small text-2 mt-2" style="white-space:pre-wrap">' . e(mb_strimwidth(trim($b['voice']), 0, 220, '…', 'UTF-8')) . '</div>';
    if ($withVoice && ($b['do'] || $b['dont'])) {
        $h .= '<div class="bk-rules-mini mt-2">';
        foreach (array_slice($b['do'], 0, 4) as $r) $h .= '<div class="small"><span class="bk-yes">✓</span> ' . e($r) . '</div>';
        foreach (array_slice($b['dont'], 0, 4) as $r) $h .= '<div class="small"><span class="bk-no">✕</span> ' . e($r) . '</div>';
        $h .= '</div>';
    }
    // A kit kept as free text before 8.1: its notes are all there is
    if ($h === '' && $b['notes'] !== '') $h .= '<div class="small text-2" style="white-space:pre-wrap">' . e(mb_strimwidth($b['notes'], 0, 400, '…', 'UTF-8')) . '</div>';
    if ($b['folder'] !== '') $h .= '<a class="mini-btn mt-2" style="display:inline-block" href="' . e($b['folder']) . '" target="_blank" rel="noopener">Marka klasörü ↗</a>';
    return $h;
}

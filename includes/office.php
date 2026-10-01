<?php
/**
 * SADA One — Office days
 * Each team member keeps a weekly pattern (which weekdays, from when to when) and changes single days on top of it:
 * "I won't come" or "I'll come at these hours" (a different time, or an extra day). The day's answer is the change if
 * there is one, else the pattern. Shared by the Ofis Günleri page, the Ekip board, Bugün and the Şimdi card.
 */

/** Team members who come to the office (everyone but customers) */
function office_people(): array {
    return rows("SELECT id, name, color, avatar, job_title, role FROM users WHERE role IN ('admin','pm','team','intern','finance') AND is_active=1 ORDER BY name");
}

/** A person's weekly pattern: weekday (1 = Monday … 7 = Sunday) => ['start' => 'HH:MM', 'end' => 'HH:MM'] */
function office_pattern(int $userId): array {
    $out = [];
    foreach (rows("SELECT weekday, start_time, end_time FROM office_schedule WHERE user_id=? ORDER BY weekday", [$userId]) as $r)
        $out[(int)$r['weekday']] = ['start' => substr($r['start_time'], 0, 5), 'end' => substr($r['end_time'], 0, 5)];
    return $out;
}

/**
 * Who is in the office on each day of a date range: [user_id][Y-m-d] => ['start','end','kind'] where kind is
 * 'pattern' (as usual), 'changed' (other hours than usual), 'extra' (a day they don't usually come) or 'out'
 * (they usually come but not that day; start/end are the usual hours). Days with no entry are absent.
 */
function office_range(string $from, string $to, array $userIds): array {
    if (!$userIds) return [];
    [$in, $params] = in_clause(array_map('intval', $userIds));
    $patterns = [];
    foreach (rows("SELECT user_id, weekday, start_time, end_time FROM office_schedule WHERE user_id IN $in", $params) as $r)
        $patterns[(int)$r['user_id']][(int)$r['weekday']] = ['start' => substr($r['start_time'], 0, 5), 'end' => substr($r['end_time'], 0, 5)];
    $changes = [];
    foreach (rows("SELECT user_id, date, kind, start_time, end_time, note FROM office_days WHERE user_id IN $in AND date BETWEEN ? AND ?", array_merge($params, [$from, $to])) as $r)
        $changes[(int)$r['user_id']][$r['date']] = $r;
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
        $date = date('Y-m-d', $t); $weekday = (int)date('N', $t);
        foreach ($userIds as $uid) {
            $usual = $patterns[$uid][$weekday] ?? null;
            $change = $changes[$uid][$date] ?? null;
            if ($change && $change['kind'] === 'out') { if ($usual) $out[$uid][$date] = $usual + ['kind' => 'out', 'note' => $change['note']]; continue; }
            if ($change) {
                $hours = ['start' => substr($change['start_time'], 0, 5), 'end' => substr($change['end_time'], 0, 5)];
                $out[$uid][$date] = $hours + ['kind' => $usual ? ($usual === $hours ? 'pattern' : 'changed') : 'extra', 'note' => $change['note']];
                continue;
            }
            if ($usual) $out[$uid][$date] = $usual + ['kind' => 'pattern', 'note' => null];
        }
    }
    return $out;
}

/** Today in the office: ['in' => [[person, start, end, kind], …] sorted by arrival, 'out' => [[person, note], …]] */
function office_today(): array {
    $people = array_column(office_people(), null, 'id');
    $today = date('Y-m-d');
    $day = office_range($today, $today, array_keys($people));
    $in = []; $out = [];
    foreach ($day as $uid => $dates) {
        $e = $dates[$today];
        if ($e['kind'] === 'out') $out[] = ['person' => $people[$uid], 'note' => $e['note']];
        else $in[] = ['person' => $people[$uid], 'start' => $e['start'], 'end' => $e['end'], 'kind' => $e['kind']];
    }
    usort($in, fn($a, $b) => [$a['start'], $a['person']['name']] <=> [$b['start'], $b['person']['name']]);
    return ['in' => $in, 'out' => $out];
}

/** "10–18", "09:30–18" */
function office_hours(string $start, string $end): string {
    $short = function (string $t): string { [$h, $m] = explode(':', $t) + [1 => '00']; return (int)$h . ($m === '00' ? '' : ':' . $m); };
    return $short($start) . '–' . $short($end);
}

/** Valid "HH:MM" on a 15-minute grid, or null */
function office_time(string $t): ?string {
    if (!preg_match('~^([01]\d|2[0-3]):([0-5]\d)~', $t, $m) || (int)$m[2] % 15) return null;
    return $m[1] . ':' . $m[2];
}

/** Tell the managers (not the person who made the change) */
function office_tell_managers(int $actorId, string $title, string $text): void {
    foreach (rows("SELECT id FROM users WHERE role IN ('admin','pm') AND is_active=1 AND id!=?", [$actorId]) as $m)
        notify((int)$m['id'], $title, $text, 'office.php', 'office');
}

/** The weekly board: people × days of the week starting on $monday (used on Ofis Günleri and Ekip) */
function office_week_board(string $monday, bool $withNav = true, string $baseUrl = 'office.php'): void {
    $people = office_people();
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    $grid = office_range($monday, $sunday, array_column($people, 'id'));
    $days = [];
    for ($i = 0; $i < 7; $i++) $days[] = date('Y-m-d', strtotime($monday . " +$i days"));
    // Weekend columns only when someone comes then
    $weekend = array_filter([5 => $days[5], 6 => $days[6]], fn($d) => array_filter($grid, fn($row) => isset($row[$d]) && $row[$d]['kind'] !== 'out'));
    $shown = array_slice($days, 0, 5, true) + $weekend;
    $today = date('Y-m-d');
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    ?>
    <div class="card office-board">
        <div class="row-flex between wrap mb-2" style="gap:10px">
            <div class="card-title" style="font-size:15px">Ofiste kim, ne zaman <span class="cell-bottom" style="font-weight:400">· <?= format_date($monday) ?> – <?= format_date($sunday) ?></span></div>
            <?php if ($withNav): ?>
            <div class="row-flex" style="gap:6px">
                <a class="btn btn-sm btn-ghost" href="<?= $baseUrl . $sep ?>week=<?= date('Y-m-d', strtotime($monday . ' -7 days')) ?>">← Önceki</a>
                <a class="btn btn-sm" href="<?= $baseUrl ?>">Bu hafta</a>
                <a class="btn btn-sm btn-ghost" href="<?= $baseUrl . $sep ?>week=<?= date('Y-m-d', strtotime($monday . ' +7 days')) ?>">Sonraki →</a>
            </div>
            <?php endif; ?>
        </div>
        <div class="table-wrap" style="box-shadow:none">
            <table class="table office-table">
                <thead><tr><th>Kişi</th><?php foreach ($shown as $i => $d): ?><th class="<?= $d === $today ? 'is-today' : '' ?>"><?= DAYS_SHORT[$i] ?> <span class="office-date"><?= (int)substr($d, 8, 2) ?></span></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($people as $p): ?>
                <tr>
                    <td><div class="row-flex" style="gap:8px"><?= avatar($p, 26) ?><span class="small bold"><?= e($p['name']) ?></span></div></td>
                    <?php foreach ($shown as $d): $e = $grid[$p['id']][$d] ?? null; ?>
                    <td class="<?= $d === $today ? 'is-today' : '' ?>">
                        <?php if (!$e): ?><span class="office-none">—</span>
                        <?php elseif ($e['kind'] === 'out'): ?><span class="office-slot is-out" title="<?= e('Gelmiyor' . ($e['note'] ? ': ' . $e['note'] : '')) ?>">gelmiyor</span>
                        <?php else: ?><span class="office-slot is-<?= $e['kind'] ?>" title="<?= e(['pattern' => 'Her zamanki düzen', 'changed' => 'Bu gün farklı saatte', 'extra' => 'Fazladan gün'][$e['kind']] . ($e['note'] ? ': ' . $e['note'] : '')) ?>"><?= office_hours($e['start'], $e['end']) ?></span><?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="office-legend cell-bottom mt-2"><span class="office-slot is-pattern">10–18</span> her zamanki · <span class="office-slot is-changed">13–18</span> farklı saat · <span class="office-slot is-extra">10–14</span> fazladan gün · <span class="office-slot is-out">gelmiyor</span></div>
    </div>
    <?php
}

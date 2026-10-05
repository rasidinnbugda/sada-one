<?php
/**
 * SADA One — Work log ("Çalışma Defteri")
 * Each entry: a day, a category, the hours worked (may run past midnight), an optional client file / project and what
 * was done. The status is not typed: a meeting is "Katıldım", six hours or more "Tam gün", less "Yarım gün".
 * Replaces logging time on tasks (time_entries stays in the database untouched, no longer shown or written).
 */

/** Minutes between two "HH:MM" times; an end before the start runs into the next day. null when empty or too long. */
function worklog_minutes(string $start, string $end): ?int {
    $toMin = fn(string $t) => (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2);
    $min = $toMin($end) - $toMin($start);
    if ($min < 0) $min += 1440;
    return $min > 0 && $min <= 18 * 60 ? $min : null;
}

/** "Katıldım" / "Tam gün" / "Yarım gün" — [key, label] */
function worklog_status(string $category, int $minutes): array {
    if ($category === 'meeting') return ['attended', 'Katıldım'];
    return $minutes >= 360 ? ['full', 'Tam gün'] : ['half', 'Yarım gün'];
}

/** Durations as on a timesheet: "122:40" */
function worklog_hm(int $minutes): string {
    return intdiv($minutes, 60) . ':' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT);
}

/** Valid "HH:MM" (any minute), or null */
function worklog_time(string $t): ?string {
    return preg_match('~^([01]\d|2[0-3]):([0-5]\d)~', $t, $m) ? $m[1] . ':' . $m[2] : null;
}

/** May the current user see / keep this person's log? Everyone their own, managers everyone's. */
function worklog_can(int $userId): bool {
    return $userId === (int)(user()['id'] ?? 0) || is_pm();
}

/** A month's totals per person: [user_id => ['minutes', 'days', 'by' => [category => minutes]]] */
function worklog_month_totals(string $month, ?int $userId = null): array {
    $params = [$month . '-01', date('Y-m-t', strtotime($month . '-01'))];
    $where = '';
    if ($userId) { $where = ' AND user_id=?'; $params[] = $userId; }
    $out = [];
    foreach (rows("SELECT user_id, category, SUM(minutes) m, COUNT(DISTINCT date) d FROM work_logs WHERE date BETWEEN ? AND ?$where GROUP BY user_id, category", $params) as $r) {
        $uid = (int)$r['user_id'];
        $out[$uid] ??= ['minutes' => 0, 'days' => 0, 'by' => []];
        $out[$uid]['minutes'] += (int)$r['m'];
        $out[$uid]['by'][$r['category']] = (int)$r['m'];
    }
    // distinct days across categories
    foreach (rows("SELECT user_id, COUNT(DISTINCT date) d FROM work_logs WHERE date BETWEEN ? AND ?$where GROUP BY user_id", $params) as $r)
        if (isset($out[(int)$r['user_id']])) $out[(int)$r['user_id']]['days'] = (int)$r['d'];
    return $out;
}

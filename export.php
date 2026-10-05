<?php
/**
 * SADA One — CSV Export
 * type=tasks | finance | worklog (time = old links)
 * UTF-8 BOM + semicolon delimiter are used so Excel opens Turkish characters correctly.
 */
require __DIR__ . '/includes/init.php';
$u = require_staff();

$type = $_GET['type'] ?? 'tasks';

function csv_send(string $clientName, array $titles, array $rows): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $clientName . '_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel compatibility)
    fputcsv($output, $titles, ';');
    foreach ($rows as $s) fputcsv($output, $s, ';');
    fclose($output);
    exit;
}

switch ($type) {
case 'tasks':
    $rows = rows("SELECT g.title, p.name project, d.name client, u.name assignee, g.status, g.priority, g.due_date, g.created, g.completion
        FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id LEFT JOIN users u ON u.id=g.assignee_id
        ORDER BY g.id DESC");
    csv_send('tasks', ['İş', 'Proje', 'Dosya', 'Atanan', 'Durum', 'Öncelik', 'Son Tarih', 'Oluşturulma', 'Tamamlanma'],
        array_map(fn($r) => [$r['title'], $r['project'], $r['client'], $r['assignee'] ?? '', TASK_STATUSES[$r['status']], PRIORITIES[$r['priority']], $r['due_date'] ?? '', substr($r['created'], 0, 10), $r['completion'] ? substr($r['completion'], 0, 10) : ''], $rows));

case 'finance':
    if (!permission('finance')) deny();
    $rows = rows("SELECT o.title, p.name project, d.name client, o.type, o.amount, o.date, o.status, o.description
        FROM payments o JOIN projects p ON p.id=o.project_id JOIN clients d ON d.id=p.client_id ORDER BY o.date DESC");
    csv_send('finance', ['Kayıt', 'Proje', 'Dosya', 'Tür', 'Tutar (TL)', 'Tarih', 'Durum', 'Açıklama'],
        array_map(fn($r) => [$r['title'], $r['project'], $r['client'], (PAYMENT_TYPES[$r['type']] ?? $r['type']), number_format((float)$r['amount'], 2, ',', ''), $r['date'], PAYMENT_STATUSES[$r['status']], $r['description'] ?? ''], $rows));

case 'time': // old links: the work log replaced logging time on tasks
case 'worklog':
    // A month of the work log (everyone, or one person); managers and report / capacity holders
    require_once __DIR__ . '/includes/worklog.php';
    $month = preg_match('~^\d{4}-\d{2}$~', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $only = (int)($_GET['user'] ?? 0);
    if (!is_pm() && !permission('capacity') && !permission('report') && $only !== (int)$u['id']) deny();
    $params = [$month . '-01', date('Y-m-t', strtotime($month . '-01'))];
    if ($only) $params[] = $only;
    $rows = rows("SELECT u.name person, w.date, w.category, w.start_time, w.end_time, w.minutes, c.name client, p.name project, w.note
        FROM work_logs w JOIN users u ON u.id=w.user_id LEFT JOIN clients c ON c.id=w.client_id LEFT JOIN projects p ON p.id=w.project_id
        WHERE w.date BETWEEN ? AND ?" . ($only ? ' AND w.user_id=?' : '') . " ORDER BY u.name, w.date, w.start_time", $params);
    csv_send('calisma_defteri_' . $month, ['Kişi', 'Tarih', 'Kategori', 'Başlangıç', 'Bitiş', 'Toplam', 'Durum', 'Dosya', 'Proje', 'Notlar ve çıktılar'],
        array_map(fn($r) => [$r['person'], $r['date'], WORK_LOG_CATEGORIES[$r['category']] ?? $r['category'], substr($r['start_time'], 0, 5), substr($r['end_time'], 0, 5),
            worklog_hm((int)$r['minutes']), worklog_status($r['category'], (int)$r['minutes'])[1], $r['client'] ?? '', $r['project'] ?? '', $r['note'] ?? ''], $rows));

default:
    header('Location: index.php');
    exit;
}

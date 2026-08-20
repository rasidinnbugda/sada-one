<?php
/**
 * SADA One — CSV Dışa Aktarım
 * tip=gorevler | finans | zaman
 * Excel'in Türkçe karakterleri doğru açması için UTF-8 BOM + noktalı virgül ayracı kullanılır.
 */
require __DIR__ . '/includes/init.php';
$u = require_staff();

$type = $_GET['type'] ?? 'tasks';

function csv_send(string $clientName, array $basliklar, array $satirlar): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $clientName . '_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel uyumu)
    fputcsv($output, $basliklar, ';');
    foreach ($satirlar as $s) fputcsv($output, $s, ';');
    fclose($output);
    exit;
}

switch ($type) {
case 'tasks':
    $veriler = rows("SELECT g.title, p.name project, d.name client, u.name assignee, g.status, g.priority, g.due_date, g.created, g.completion
        FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id LEFT JOIN users u ON u.id=g.assignee_id
        ORDER BY g.id DESC");
    csv_send('tasks', ['Görev', 'Proje', 'Dosya', 'Atanan', 'Durum', 'Öncelik', 'Son Tarih', 'Oluşturulma', 'Tamamlanma'],
        array_map(fn($r) => [$r['title'], $r['project'], $r['client'], $r['assignee'] ?? '', GOREV_DURUMLARI[$r['status']], ONCELIKLER[$r['priority']], $r['due_date'] ?? '', substr($r['created'], 0, 10), $r['completion'] ? substr($r['completion'], 0, 10) : ''], $veriler));

case 'finance':
    if (!permission('finans')) deny();
    $veriler = rows("SELECT o.title, p.name project, d.name client, o.type, o.amount, o.date, o.status, o.description
        FROM payments o JOIN projects p ON p.id=o.project_id JOIN clients d ON d.id=p.client_id ORDER BY o.date DESC");
    csv_send('finance', ['Kayıt', 'Proje', 'Dosya', 'Tür', 'Tutar (TL)', 'Tarih', 'Durum', 'Açıklama'],
        array_map(fn($r) => [$r['title'], $r['project'], $r['client'], $r['type'] === 'fatura' ? 'Fatura' : 'Tahsilat', number_format((float)$r['amount'], 2, ',', ''), $r['date'], ['bekliyor' => 'Bekliyor', 'odendi' => 'Ödendi', 'overdue' => 'Gecikti'][$r['status']], $r['description'] ?? ''], $veriler));

case 'time':
    if (!permission('kapasite') && !permission('rapor')) deny();
    $veriler = rows("SELECT u.name person, g.title task, p.name project, z.minutes, z.date, z.description
        FROM time_entries z JOIN users u ON u.id=z.user_id JOIN tasks g ON g.id=z.task_id JOIN projects p ON p.id=g.project_id
        ORDER BY z.date DESC");
    csv_send('time_report', ['Kişi', 'Görev', 'Proje', 'Süre (dk)', 'Süre', 'Tarih', 'Açıklama'],
        array_map(fn($r) => [$r['person'], $r['task'], $r['project'], $r['minutes'], format_minutes((int)$r['minutes']), $r['date'], $r['description'] ?? ''], $veriler));

default:
    header('Location: index.php');
    exit;
}

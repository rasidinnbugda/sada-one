<?php
/**
 * SADA One — Client Report (printable / PDF)
 * Output is produced via the browser's "Save as PDF" feature.
 */
require __DIR__ . '/includes/init.php';
require_staff();

$projectId = (int)($_GET['project'] ?? 0);
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
$project = row("SELECT p.*, d.name client_name, d.color client_color, d.logo client_logo FROM projects p JOIN clients d ON d.id=p.client_id WHERE p.id=?", [$projectId]);
if (!$project) { header('Location: projects.php'); exit; }

$monthInitial = sprintf('%04d-%02d-01', $year, $month);
$monthLast = date('Y-m-t', strtotime($monthInitial));
$siteName = setting('site_name', 'SADA One');

$completed = rows("SELECT g.*, u.name assignee_name FROM tasks g LEFT JOIN users u ON u.id=g.assignee_id WHERE g.project_id=? AND g.status='completed' AND g.completion BETWEEN ? AND ? ORDER BY g.completion", [$projectId, $monthInitial . ' 00:00:00', $monthLast . ' 23:59:59']);
$ongoingOne = rows("SELECT g.* FROM tasks g WHERE g.project_id=? AND g.is_archived=0 AND g.status!='completed' ORDER BY g.due_date IS NULL, g.due_date", [$projectId]);
$approvals = rows("SELECT * FROM approvals WHERE project_id=? AND created BETWEEN ? AND ? ORDER BY id", [$projectId, $monthInitial . ' 00:00:00', $monthLast . ' 23:59:59']);
$contents = rows("SELECT * FROM contents WHERE project_id=? AND date BETWEEN ? AND ? ORDER BY date", [$projectId, $monthInitial, $monthLast]);
$totalMin = (int)val("SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z JOIN tasks g ON g.id=z.task_id WHERE g.project_id=? AND z.date BETWEEN ? AND ?", [$projectId, $monthInitial, $monthLast]);
$satisfaction = row("SELECT AVG(rating) average, COUNT(*) qty FROM ratings WHERE project_id=?", [$projectId]);
?>
<!DOCTYPE html>
<html lang="tr" data-theme="navy-light">
<head>
<meta charset="UTF-8">
<title><?= e($project['client_name']) ?> — <?= MONTHS[$month] ?> <?= $year ?> Raporu</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&family=Unbounded:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
<style>
body { background: #fff !important; color: #1a2233; }
.report { max-width: 800px; margin: 0 auto; padding: 40px 32px; }
.report-title { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid <?= e($project['client_color']) ?>; padding-bottom: 20px; margin-bottom: 28px; }
.report h2 { font-family: 'Space Grotesk', sans-serif; font-size: 17px; margin: 26px 0 12px; padding-bottom: 6px; border-bottom: 1px solid #e2e6ee; }
.report table { width: 100%; border-collapse: collapse; font-size: 13px; }
.report th { text-align: left; padding: 8px 10px; background: #f0f3f9; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #5a6780; }
.report td { padding: 8px 10px; border-bottom: 1px solid #eef0f5; }
.summary-box { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 20px; }
.summary-box > div { background: #f5f7fb; border-radius: 12px; padding: 14px; text-align: center; }
.summary-value { font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 700; color: #182f5d; }
.summary-label { font-size: 11.5px; color: #6a7590; margin-top: 3px; }
.print-bar { position: sticky; top: 0; background: #182f5d; color: #fff; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; z-index: 10; }
@media print { .print-bar { display: none !important; } .report { padding: 0; } body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
.status-chip { display: inline-block; padding: 2px 9px; border-radius: 99px; font-size: 11px; font-weight: 600; }
</style>
</head>
<body>
<div class="print-bar">
    <span style="font-weight:600">📄 Rapor önizleme — yazdır penceresinden "PDF olarak kaydet" seçin</span>
    <span style="display:flex;gap:10px">
        <select onchange="location.href='report.php?project=<?= $projectId ?>&'+this.value" style="padding:6px 10px;border-radius:8px;border:none">
            <?php for ($i = 0; $i < 6; $i++): $t = strtotime("-$i months"); $pick = ((int)date('n', $t) === $month && (int)date('Y', $t) === $year); ?>
            <option value="ay=<?= date('n', $t) ?>&year=<?= date('Y', $t) ?>" <?= $pick ? 'selected' : '' ?>><?= MONTHS[(int)date('n', $t)] ?> <?= date('Y', $t) ?></option>
            <?php endfor; ?>
        </select>
        <button onclick="window.print()" style="background:#b1fb01;color:#14210a;border:none;padding:8px 18px;border-radius:9px;font-weight:700;cursor:pointer">🖨 Yazdır / PDF</button>
    </span>
</div>

<div class="report">
    <div class="report-title">
        <div>
            <div style="font-family:'Unbounded',sans-serif;font-size:20px;font-weight:700"><?= e($siteName) ?><span style="color:<?= e($project['client_color']) ?>">.</span></div>
            <div style="font-size:12px;color:#6a7590;margin-top:4px">Aylık Çalışma Raporu</div>
        </div>
        <div style="text-align:right">
            <div style="font-family:'Space Grotesk',sans-serif;font-size:19px;font-weight:700"><?= e($project['client_name']) ?></div>
            <div style="font-size:13px;color:#3a466c"><?= e($project['name']) ?></div>
            <div style="font-size:12px;color:#6a7590;margin-top:3px"><?= MONTHS[$month] ?> <?= $year ?> · Rapor tarihi: <?= format_date(date('Y-m-d')) ?></div>
        </div>
    </div>

    <div class="summary-box">
        <div><div class="summary-value"><?= count($completed) ?></div><div class="summary-label">Tamamlanan Görev</div></div>
        <div><div class="summary-value"><?= count($contents) ?></div><div class="summary-label">Planlanan İçerik</div></div>
        <div><div class="summary-value"><?= count(array_filter($approvals, fn($o) => $o['status'] === 'approved')) ?>/<?= count($approvals) ?></div><div class="summary-label">Onaylanan İş</div></div>
        <div><div class="summary-value"><?= $totalMin ? round($totalMin / 60) : 0 ?> sa</div><div class="summary-label">Harcanan Emek</div></div>
    </div>
    <?php if ((int)$satisfaction['qty'] > 0): ?>
    <div style="margin-top:14px;background:#f5f7fb;border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:12px;justify-content:center">
        <span style="color:#f0a020;font-size:16px;letter-spacing:2px"><?php $avgRating = round((float)$satisfaction['average']); for ($i = 1; $i <= 5; $i++) echo '<span style="opacity:' . ($i <= $avgRating ? '1' : '.25') . '">★</span>'; ?></span>
        <span style="font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:18px;color:#182f5d"><?= number_format((float)$satisfaction['average'], 1, ',', '') ?>/5</span>
        <span style="font-size:12px;color:#6a7590">memnuniyet puanınız (<?= $satisfaction['qty'] ?> değerlendirme)</span>
    </div>
    <?php endif; ?>

    <?php if ($completed): ?>
    <h2>✅ Bu Ay Tamamlanan İşler</h2>
    <table><thead><tr><th>İş</th><th>Tamamlanma</th></tr></thead><tbody>
        <?php foreach ($completed as $t): ?>
        <tr><td><?= e($t['title']) ?></td><td><?= format_date(substr($t['completion'], 0, 10)) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <?php if ($contents): ?>
    <h2>📅 İçerik Planı</h2>
    <table><thead><tr><th>İçerik</th><th>Platform</th><th>Tarih</th><th>Durum</th></tr></thead><tbody>
        <?php foreach ($contents as $contentItem):
            $colors = ['published' => '#dcf5e4;color:#1d7a41', 'approved' => '#dcf5e4;color:#1d7a41', 'draft' => '#eef0f5;color:#5a6780'];
            $style = $colors[$contentItem['status']] ?? '#fdf0d9;color:#9a6b10'; ?>
        <tr><td><?= e($contentItem['title']) ?></td><td><?= implode(', ', array_map(fn($pl) => PLATFORMS[trim($pl)] ?? trim($pl), explode(',', $contentItem['platform']))) ?></td><td><?= format_date($contentItem['date']) ?></td><td><span class="status-chip" style="background:<?= $style ?>"><?= CONTENT_STATUSES[$contentItem['status']] ?></span></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <?php if ($approvals): ?>
    <h2>🔏 Onay Süreçleri</h2>
    <table><thead><tr><th>İş</th><th>Gönderim</th><th>Sonuç</th></tr></thead><tbody>
        <?php foreach ($approvals as $o): ?>
        <tr><td><?= e($o['title']) ?></td><td><?= format_date(substr($o['created'], 0, 10)) ?></td><td><?= APPROVAL_STATUSES[$o['status']] ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <?php if ($ongoingOne): ?>
    <h2>🔄 Devam Eden İşler</h2>
    <table><thead><tr><th>İş</th><th>Durum</th><th>Hedef Tarih</th></tr></thead><tbody>
        <?php foreach ($ongoingOne as $d): ?>
        <tr><td><?= e($d['title']) ?></td><td><?= TASK_STATUSES[$d['status']] ?></td><td><?= format_date($d['due_date']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <div style="margin-top:40px;padding-top:16px;border-top:1px solid #e2e6ee;font-size:11.5px;color:#8a93a8;text-align:center">
        Bu rapor <?= e($siteName) ?> yönetim sistemi tarafından <?= format_date(date('Y-m-d'), true) ?> tarihinde otomatik oluşturulmuştur.
    </div>
</div>
</body>
</html>

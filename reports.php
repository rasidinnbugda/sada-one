<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_permission('report');

// General metrics
$totalTask = (int)val("SELECT COUNT(*) FROM tasks");
$doneTask = (int)val("SELECT COUNT(*) FROM tasks WHERE status='completed'");
$overdueTask = (int)val("SELECT COUNT(*) FROM tasks WHERE due_date<CURDATE() AND status!='completed'");
$doneRate = $totalTask ? round($doneTask / $totalTask * 100) : 0;

// Status distribution
$statusDistribution = [];
foreach (TASK_STATUSES as $k => $v) $statusDistribution[$k] = (int)val("SELECT COUNT(*) FROM tasks WHERE status=?", [$k]);
$maxStatus = max(1, max($statusDistribution));

// Per-person performance
$people = rows("SELECT u.id, u.name, u.color,
    (SELECT COUNT(*) FROM tasks g WHERE g.assignee_id=u.id) total,
    (SELECT COUNT(*) FROM tasks g WHERE g.assignee_id=u.id AND g.status='completed') is_done,
    (SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z WHERE z.user_id=u.id) minutes
    FROM users u WHERE u.role IN ('admin','pm','team') AND u.is_active=1 ORDER BY total DESC");

// Project count per client file
$clientDistribution = rows("SELECT d.name, d.color, COUNT(p.id) project FROM clients d LEFT JOIN projects p ON p.client_id=d.id GROUP BY d.id ORDER BY project DESC LIMIT 8");
$maxClient = max(1, max(array_column($clientDistribution, 'project') ?: [1]));

// Approval statistics
$approvalTotal = (int)val("SELECT COUNT(*) FROM approvals");
$approvalApprovedItems = (int)val("SELECT COUNT(*) FROM approvals WHERE status='approved'");
$approvalPending = (int)val("SELECT COUNT(*) FROM approvals WHERE status='pending'");

page_start('Raporlar', 'reports');
?>
<div class="page-top">
    <div><div class="page-title">Raporlar & Analiz</div><div class="page-bottom">Performans ve iş yükü özeti</div></div>
    <div class="page-top-action">
        <a href="export.php?type=tasks" class="btn btn-sm"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M12 15V3m0 12l-4-4m4 4l4-4M3 17v2a2 2 0 002 2h14a2 2 0 002-2v-2"/></svg> Görevler CSV</a>
        <a href="export.php?type=time" class="btn btn-sm"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M12 15V3m0 12l-4-4m4 4l4-4M3 17v2a2 2 0 002 2h14a2 2 0 002-2v-2"/></svg> Zaman CSV</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-value"><?= $doneRate ?>%</div><div class="stat-label">Genel Tamamlanma</div><div class="progress mt-2"><div class="progress-full" data-rate="<?= $doneRate ?>" style="width:0"></div></div></div>
    <div class="stat-card"><div class="stat-value" data-counter="<?= $totalTask ?>">0</div><div class="stat-label">Toplam Görev</div></div>
    <div class="stat-card"><div class="stat-value" data-counter="<?= $doneTask ?>">0</div><div class="stat-label">Tamamlanan</div></div>
    <div class="stat-card"><div class="stat-value" style="color:var(--danger)" data-counter="<?= $overdueTask ?>">0</div><div class="stat-label">Geciken</div></div>
</div>

<div class="grid grid-2">
    <!-- Status distribution -->
    <div class="card">
        <div class="card-title mb-3">Görev Durum Dağılımı</div>
        <div class="vertical" style="gap:14px">
            <?php foreach ($statusDistribution as $k => $count): ?>
            <div>
                <div class="row-flex between mb-2"><span class="row-flex small" style="gap:7px"><span class="label-dot" style="background:<?= TASK_STATUS_COLORS[$k] ?>"></span><?= TASK_STATUSES[$k] ?></span><span class="small bold"><?= $count ?></span></div>
                <div class="progress"><div class="progress-full" data-rate="<?= round($count / $maxStatus * 100) ?>" style="width:0;background:<?= TASK_STATUS_COLORS[$k] ?>"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Projects per client file -->
    <div class="card">
        <div class="card-title mb-3">Dosya Başına Proje</div>
        <div class="vertical" style="gap:14px">
            <?php foreach ($clientDistribution as $d): ?>
            <div>
                <div class="row-flex between mb-2"><span class="row-flex small" style="gap:7px"><span class="label-dot" style="background:<?= e($d['color']) ?>"></span><?= e($d['name']) ?></span><span class="small bold"><?= $d['project'] ?></span></div>
                <div class="progress"><div class="progress-full" data-rate="<?= round($d['project'] / $maxClient * 100) ?>" style="width:0;background:<?= e($d['color']) ?>"></div></div>
            </div>
            <?php endforeach; ?>
            <?php if (!$clientDistribution): ?><div class="text-muted small">Veri yok</div><?php endif; ?>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-title mb-3">Ekip Performansı</div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Kişi</th><th>Toplam Görev</th><th>Tamamlanan</th><th>Tamamlanma</th><th>Kayıtlı Süre</th></tr></thead><tbody>
        <?php foreach ($people as $k):
            $rate = $k['total'] ? round($k['is_done'] / $k['total'] * 100) : 0; ?>
        <tr>
            <td><div class="row-flex" style="gap:9px"><?= avatar(['name' => $k['name'], 'color' => $k['color']], 30) ?><span class="cell-main"><?= e($k['name']) ?></span></div></td>
            <td><?= $k['total'] ?></td>
            <td><?= $k['is_done'] ?></td>
            <td><div class="row-flex" style="gap:10px"><div class="progress" style="flex:1;max-width:120px"><div class="progress-full" data-rate="<?= $rate ?>" style="width:0"></div></div><span class="small bold">%<?= $rate ?></span></div></td>
            <td class="small"><?= format_minutes((int)$k['minutes']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="grid grid-3 mt-3">
    <div class="card orta"><div class="stat-value" data-counter="<?= $approvalTotal ?>">0</div><div class="stat-label">Toplam Onay Süreci</div></div>
    <div class="card orta"><div class="stat-value" style="color:var(--success)" data-counter="<?= $approvalApprovedItems ?>">0</div><div class="stat-label">Onaylanan</div></div>
    <div class="card orta"><div class="stat-value" style="color:var(--warning)" data-counter="<?= $approvalPending ?>">0</div><div class="stat-label">Bekleyen Onay</div></div>
</div>

<!-- CLIENT SATISFACTION -->
<?php
$overallRating = row("SELECT AVG(rating) ort, COUNT(*) qty FROM ratings");
if ((int)$overallRating['qty'] > 0):
    $clientRatings = rows("SELECT d.name, d.color, AVG(pu.rating) ort, COUNT(*) qty
        FROM ratings pu JOIN projects p ON p.id=pu.project_id JOIN clients d ON d.id=p.client_id
        GROUP BY d.id ORDER BY ort DESC");
    $lastComments = rows("SELECT pu.*, us.name customer_name, p.name project_name FROM ratings pu JOIN users us ON us.id=pu.user_id JOIN projects p ON p.id=pu.project_id WHERE pu.comment IS NOT NULL AND pu.comment!='' ORDER BY pu.id DESC LIMIT 6"); ?>
<div class="card mt-3">
    <div class="row-flex between mb-3 wrap" style="gap:10px">
        <div class="card-title">😊 Müşteri Memnuniyeti</div>
        <div class="row-flex" style="gap:10px">
            <?= stars((float)$overallRating['ort'], 18) ?>
            <span class="bold" style="font-family:'Space Grotesk',sans-serif;font-size:20px"><?= number_format((float)$overallRating['ort'], 1, ',', '') ?></span>
            <span class="cell-bottom"><?= $overallRating['qty'] ?> değerlendirme</span>
        </div>
    </div>
    <div class="grid grid-2">
        <div>
            <div class="cell-bottom mb-2">Dosya bazında ortalama</div>
            <?php foreach ($clientRatings as $dp): ?>
            <div class="row-flex between" style="padding:9px 0;border-bottom:1px solid var(--border)">
                <span class="row-flex small" style="gap:7px"><span class="label-dot" style="background:<?= e($dp['color']) ?>"></span><?= e($dp['name']) ?></span>
                <span class="row-flex" style="gap:8px"><?= stars((float)$dp['ort']) ?><span class="small bold" style="<?= $dp['ort'] < 3 ? 'color:var(--danger)' : '' ?>"><?= number_format((float)$dp['ort'], 1, ',', '') ?></span><span class="cell-bottom">(<?= $dp['qty'] ?>)</span></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div>
            <div class="cell-bottom mb-2">Son yorumlar</div>
            <?php if (!$lastComments): ?><div class="text-muted small">Henüz yorumlu değerlendirme yok.</div>
            <?php else: foreach ($lastComments as $sy): ?>
            <div style="padding:9px 12px;background:var(--surface-2);border-radius:10px;margin-bottom:6px">
                <div class="row-flex between"><span class="small bold"><?= e($sy['customer_name']) ?></span><?= stars((float)$sy['rating'], 12) ?></div>
                <div class="small text-2 mt-1">"<?= e(mb_substr($sy['comment'], 0, 140)) ?>"</div>
                <div class="cell-bottom mt-1"><?= e($sy['project_name']) ?> · <?= time_ago($sy['created']) ?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php page_end(); ?>

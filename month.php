<?php
/**
 * SADA One — Month
 * One month of a monthly project: planning → production → closing → closed, with the month's work
 * grouped as planned (the month's plan), agenda (came up during the month) and internal work.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_staff();

$id = (int)($_GET['id'] ?? 0);
$month = row("SELECT d.*, p.name project_name, p.client_id, p.pm_id, c.name client_name, c.plan_approval, c.strategy, pm.name pm_name
    FROM periods d JOIN projects p ON p.id=d.project_id JOIN clients c ON c.id=p.client_id LEFT JOIN users pm ON pm.id=p.pm_id WHERE d.id=?", [$id]);
if (!$month || !project_access((int)$month['project_id'])) { header('Location: projects.php'); exit; }
$projectId = (int)$month['project_id'];
$canRun = is_pm(); // moving a month on is the manager's call
$monthIndex = (int)$month['year'] * 12 + (int)$month['month'];

$tasks = rows("SELECT g.*, u.name assignee_name, u.color assignee_color, u.avatar assignee_avatar,
    (SELECT COUNT(*) FROM task_steps st WHERE st.task_id=g.id) step_total,
    (SELECT COUNT(*) FROM task_steps st WHERE st.task_id=g.id AND st.status='done') step_done,
    (SELECT st.name FROM task_steps st WHERE st.task_id=g.id AND st.status='active' ORDER BY st.sort_order LIMIT 1) active_step
    FROM tasks g LEFT JOIN users u ON u.id=g.assignee_id
    WHERE g.period_id=? AND g.is_archived=0 ORDER BY g.publish_date IS NULL, g.publish_date, g.publish_time, g.id", [$id]);
$live = array_filter($tasks, fn($g) => $g['status'] !== 'cancelled');
$openTasks = array_filter($live, fn($g) => task_is_open($g['status']));
$doneCount = count($live) - count($openTasks);
$rate = $live ? round($doneCount / count($live) * 100) : 0;
$groups = ['planned' => [], 'agenda' => [], 'internal' => []];
foreach ($tasks as $g) $groups[$g['kind'] === 'internal' ? 'internal' : $g['lane']][] = $g;

// Scope signal: this month's client work against the average of the three months before it
$clientCount = count(array_filter($live, fn($g) => $g['kind'] === 'client'));
$history = array_map('intval', array_column(rows("SELECT (SELECT COUNT(*) FROM tasks g WHERE g.period_id=d.id AND g.kind='client' AND g.status!='cancelled' AND g.is_archived=0) n
    FROM periods d WHERE d.project_id=? AND d.year*12+d.month < ? ORDER BY d.year DESC, d.month DESC LIMIT 3", [$projectId, $monthIndex]), 'n'));
$average = $history ? array_sum($history) / count($history) : 0;
$scopeGrowing = $average >= 3 && $clientCount > 1.4 * $average;

$planApprovals = rows("SELECT o.*, u.name sender_name FROM approvals o LEFT JOIN users u ON u.id=o.sender_id WHERE o.period_id=? ORDER BY o.id DESC", [$id]);
$periodKey = sprintf('%04d-%02d', $month['year'], $month['month']);
$report = row("SELECT status, sent_at FROM monthly_reports WHERE client_id=? AND period=?", [$month['client_id'], $periodKey]);
$previous = row("SELECT id, year, month FROM periods WHERE project_id=? AND year*12+month < ? ORDER BY year DESC, month DESC LIMIT 1", [$projectId, $monthIndex]);
$following = row("SELECT id, year, month FROM periods WHERE project_id=? AND year*12+month > ? ORDER BY year, month LIMIT 1", [$projectId, $monthIndex]);
$nextName = MONTHS[(int)$month['month'] % 12 + 1];

$team = rows("SELECT id, name, color FROM users WHERE role IN ('admin','pm','team') AND is_active=1 ORDER BY name");
$templates = rows("SELECT * FROM task_types ORDER BY name");
$periods = rows("SELECT * FROM periods WHERE project_id=? ORDER BY year DESC, month DESC", [$projectId]);

$phases = array_keys(MONTH_PHASES);
$phaseIndex = array_search($month['phase'], $phases, true);
$phaseHelp = [
    'planning' => 'Ayın planı hazırlanıyor: planlı işleri ekleyin, yayın tarihlerini ve platformlarını verin.',
    'production' => 'Üretim sürüyor. Ay içinde çıkan işleri "Gündem işi" olarak ekleyin; plana sayılmazlar.',
    'closing' => 'Ay bitiyor: açık işleri sonraki aya taşıyın, aylık raporu doldurun, sonra ayı kapatın.',
    'closed' => 'Bu ay kapandı' . ($month['closed_at'] ? ' (' . format_date($month['closed_at']) . ')' : '') . '. Değişiklik gerekirse yeniden açabilirsiniz.',
];

$shortDate = fn(string $d) => date('d', strtotime($d)) . ' ' . mb_substr(MONTHS[(int)date('n', strtotime($d))], 0, 3);
$taskRow = function (array $g) use ($shortDate) { ?>
    <a href="task.php?id=<?= $g['id'] ?>" class="month-row<?= $g['status'] === 'cancelled' ? ' is-cancelled' : '' ?>">
        <span class="month-date"><?= $g['publish_date'] ? $shortDate($g['publish_date']) : '—' ?></span>
        <span class="month-title"><span class="cell-main"><?= e($g['title']) ?></span><?php if ($g['platforms']): ?> <span class="month-platforms"><?= platform_badges($g['platforms']) ?></span><?php endif; ?></span>
        <span class="month-state"><?php if ($g['active_step'] && task_is_open($g['status'])): ?><span class="cell-bottom"><?= e($g['active_step']) ?> · <?= $g['step_done'] ?>/<?= $g['step_total'] ?></span><?php else: ?><?= badge($g['status'], TASK_STATUSES) ?><?php endif; ?></span>
        <span class="month-who"><?= $g['assignee_name'] ? avatar(['name' => $g['assignee_name'], 'color' => $g['assignee_color'], 'avatar' => $g['assignee_avatar']], 26) : '<span class="cell-bottom">Havuz</span>' ?></span>
    </a>
<?php };

page_start(period_name($month) . ' — ' . $month['project_name'], 'projects');
?>
<div class="row-flex mb-3" style="gap:10px">
    <a href="project.php?id=<?= $projectId ?>#periods" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="text-muted small"><a href="client.php?id=<?= $month['client_id'] ?>" style="color:inherit"><?= e($month['client_name']) ?></a> / <a href="project.php?id=<?= $projectId ?>" style="color:inherit"><?= e($month['project_name']) ?></a> / Aylar</span>
</div>

<div class="page-top">
    <div>
        <div class="row-flex" style="gap:10px">
            <?= badge($month['phase'], MONTH_PHASES) ?>
            <?php if ($month['plan_approval'] || $month['plan_status'] !== 'none'): ?><span class="badge r-<?= e($month['plan_status']) ?>">Plan: <?= PLAN_STATUSES[$month['plan_status']] ?></span><?php endif; ?>
        </div>
        <div class="page-title mt-1"><?= period_name($month) ?></div>
        <div class="page-bottom"><?= e($month['project_name']) ?><?= $month['pm_name'] ? ' · Proje Yöneticisi: ' . e($month['pm_name']) : '' ?></div>
    </div>
    <div class="page-top-action">
        <?php if ($previous): ?><a href="month.php?id=<?= $previous['id'] ?>" class="btn btn-ghost" title="<?= period_name($previous) ?>">← <?= MONTHS[(int)$previous['month']] ?></a><?php endif; ?>
        <?php if ($following): ?><a href="month.php?id=<?= $following['id'] ?>" class="btn btn-ghost" title="<?= period_name($following) ?>"><?= MONTHS[(int)$following['month']] ?> →</a><?php endif; ?>
        <?php if ($month['phase'] !== 'closed' && permission('task_create')): ?>
        <button class="btn" onclick="monthTask('agenda')">+ Gündem işi</button>
        <button class="btn btn-brand" onclick="monthTask('planned')"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> İş Ekle</button>
        <?php endif; ?>
    </div>
</div>

<!-- Month phases -->
<div class="card mb-3">
    <div class="flow-rail">
        <?php foreach (MONTH_PHASES as $key => $label): $i = array_search($key, $phases, true); ?>
        <div class="flow-step <?= $i < $phaseIndex || $month['phase'] === 'closed' ? 'done' : ($i === $phaseIndex ? 'active' : '') ?>">
            <div class="flow-line"></div>
            <div class="flow-step-inner">
                <span class="flow-circle"><?= $i < $phaseIndex || $month['phase'] === 'closed' ? '✓' : $i + 1 ?></span>
                <div class="flow-name"><?= $label ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="month-band">
        <div class="small text-2" style="flex:1;min-width:220px"><?= e($phaseHelp[$month['phase']]) ?></div>
        <?php if ($canRun): ?>
        <div class="row-flex wrap" style="gap:8px">
            <?php if ($month['phase'] === 'planning'): ?>
                <?php if (permission('approval_send') && $month['plan_status'] !== 'pending'): ?><button class="btn btn-sm" data-modal="modalPlanSend"><?= $month['plan_status'] === 'revision' ? 'Revize planı gönder' : 'Planı müşteriye gönder' ?></button><?php endif; ?>
                <button class="btn btn-sm btn-brand" data-action="month_phase" data-id="<?= $id ?>" data-phase="production"<?= $month['plan_approval'] && $month['plan_status'] !== 'approved' ? ' data-confirm="Plan henüz müşteri onayından geçmedi. Yine de üretime geçilsin mi?"' : '' ?>>Üretime geç →</button>
            <?php elseif ($month['phase'] === 'production'): ?>
                <button class="btn btn-sm btn-ghost" data-action="month_phase" data-id="<?= $id ?>" data-phase="planning">← Planlamaya dön</button>
                <button class="btn btn-sm btn-brand" data-action="month_phase" data-id="<?= $id ?>" data-phase="closing">Kapanışa geç →</button>
            <?php elseif ($month['phase'] === 'closing'): ?>
                <button class="btn btn-sm btn-ghost" data-action="month_phase" data-id="<?= $id ?>" data-phase="production">← Üretime dön</button>
                <?php if ($openTasks): ?><button class="btn btn-sm" data-action="month_carry" data-id="<?= $id ?>" data-confirm="<?= count($openTasks) ?> açık iş <?= $nextName ?> ayına taşınsın mı?"><?= count($openTasks) ?> açık işi <?= $nextName ?> ayına taşı</button><?php endif; ?>
                <button class="btn btn-sm btn-brand" data-action="month_phase" data-id="<?= $id ?>" data-phase="closed" <?= $openTasks ? 'disabled title="Önce açık işleri taşıyın ya da iptal edin"' : '' ?>>Ayı kapat ✓</button>
            <?php else: ?>
                <button class="btn btn-sm" data-action="month_phase" data-id="<?= $id ?>" data-phase="closing">Yeniden aç</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($scopeGrowing): ?>
<div class="card mb-3 scope-signal">
    <div class="row-flex" style="gap:10px"><?= icon('chart', 18) ?><div>
        <div class="bold small">Kapsam büyüyor</div>
        <div class="small text-2">Bu ay <?= $clientCount ?> müşteri işi var; önceki <?= count($history) ?> ayın ortalaması <?= round($average, 1) ?>. <?= count($groups['agenda']) ? count($groups['agenda']) . ' tanesi gündem işi. ' : '' ?>Sözleşme dışına çıkılıyorsa projenin İstasyon sekmesinden ek talep açın.</div>
    </div></div>
</div>
<?php endif; ?>

<div class="month-layout">
    <div>
        <?php foreach (['planned' => ['Planlı işler', 'Ayın planı. Müşteri onayına giden liste bunlardan oluşur.'], 'agenda' => ['Gündem işleri', 'Ay içinde çıkan işler (haber, trend, fırsat). Plana sayılmaz.'], 'internal' => ['İç işler', 'Müşteriye gitmeyen işler.']] as $lane => [$title, $hint]):
            if ($lane === 'internal' && !$groups['internal']) continue; ?>
        <div class="card mb-3">
            <div class="row-flex between mb-2">
                <div><div class="card-title" style="font-size:15px"><?= $title ?> <span class="badge" style="padding:1px 8px"><?= count(array_filter($groups[$lane], fn($g) => $g['status'] !== 'cancelled')) ?></span></div><div class="cell-bottom"><?= $hint ?></div></div>
                <?php if ($lane !== 'internal' && $month['phase'] !== 'closed' && permission('task_create')): ?><button class="mini-btn" onclick="monthTask('<?= $lane ?>')">+ Ekle</button><?php endif; ?>
            </div>
            <?php if (!$groups[$lane]): ?>
            <div class="text-muted small" style="padding:10px 0"><?= $lane === 'planned' ? 'Henüz planlı iş yok. "İş Ekle" ile ayın planını kurun.' : 'Gündem işi yok.' ?></div>
            <?php else: ?>
            <div class="month-list"><?php foreach ($groups[$lane] as $g) $taskRow($g); ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div>
        <div class="card mb-2">
            <div class="card-title mb-2" style="font-size:14px">Ay Özeti</div>
            <div class="progress"><div class="progress-full" data-rate="<?= $rate ?>" style="width:0"></div></div>
            <div class="cell-bottom mt-1"><?= $doneCount ?>/<?= count($live) ?> iş bitti · %<?= $rate ?></div>
            <div class="vertical mt-2" style="gap:8px">
                <div class="row-flex between"><span class="cell-bottom">Planlı</span><span class="small bold"><?= count(array_filter($groups['planned'], fn($g) => $g['status'] !== 'cancelled')) ?></span></div>
                <div class="row-flex between"><span class="cell-bottom">Gündem</span><span class="small bold"><?= count(array_filter($groups['agenda'], fn($g) => $g['status'] !== 'cancelled')) ?></span></div>
                <?php if ($groups['internal']): ?><div class="row-flex between"><span class="cell-bottom">İç iş</span><span class="small bold"><?= count($groups['internal']) ?></span></div><?php endif; ?>
                <?php if ($history): ?><div class="row-flex between"><span class="cell-bottom">Önceki aylar ort.</span><span class="small"><?= round($average, 1) ?> müşteri işi</span></div><?php endif; ?>
            </div>
        </div>

        <div class="card mb-2">
            <div class="card-title mb-2" style="font-size:14px"><?= icon('approval', 15) ?> Plan Onayı</div>
            <?php if (!$planApprovals): ?>
            <div class="text-muted small"><?= $month['plan_approval'] ? 'Bu dosyada aylık plan müşteri onayına gider; henüz gönderilmedi.' : 'Bu dosyada plan onayı istenmiyor. Yine de "Planı müşteriye gönder" ile sorabilirsiniz.' ?></div>
            <?php else: foreach ($planApprovals as $o): ?>
            <div style="padding:8px 0;border-bottom:1px solid var(--border)">
                <div class="row-flex between" style="gap:8px"><span class="small bold"><?= format_date($o['created']) ?></span><?= badge($o['status'], APPROVAL_STATUSES) ?></div>
                <div class="cell-bottom mt-1"><?= e($o['sender_name'] ?? '—') ?> gönderdi</div>
                <?php if ($o['reply_note']): ?><div class="small text-2 mt-1" style="white-space:pre-wrap"><b>Müşteri:</b> <?= e($o['reply_note']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="card mb-2">
            <div class="card-title mb-2" style="font-size:14px"><?= icon('document', 15) ?> Aylık Rapor</div>
            <div class="row-flex between"><span class="cell-bottom"><?= $periodKey ?></span><?= $report ? ($report['sent_at'] ? '<span class="badge r-completed">Gönderildi</span>' : badge($report['status'], ['draft' => 'Taslak', 'completed' => 'Tamamlandı'])) : '<span class="badge">Yazılmadı</span>' ?></div>
            <?php if (!is_intern()): ?><a href="monthly-reports.php?client=<?= $month['client_id'] ?>&period=<?= $periodKey ?>" class="mini-btn mt-2" style="display:inline-block"><?= $report ? 'Raporu aç →' : 'Raporu yaz →' ?></a><?php endif; ?>
        </div>

        <?php if (trim((string)$month['strategy']) !== ''): ?>
        <div class="card mb-2">
            <div class="card-title mb-2" style="font-size:14px">Strateji</div>
            <div class="small text-2" style="white-space:pre-wrap"><?= e(mb_strimwidth($month['strategy'], 0, 420, '…')) ?></div>
            <a href="client.php?id=<?= $month['client_id'] ?>" class="mini-btn mt-2" style="display:inline-block">Dosyada gör →</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (permission('task_create')) task_modal($projectId, $team, $templates, $periods); ?>

<?php if ($canRun && permission('approval_send')): ?>
<div class="modal-overlay" id="modalPlanSend">
    <div class="modal"><div class="modal-top"><div class="modal-title"><?= period_name($month) ?> planını gönder</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="month_plan_send">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="modal-body">
            <div class="small text-2 mb-2">Müşteri <?= count(array_filter($groups['planned'], fn($g) => $g['status'] !== 'cancelled')) ?> planlı işin listesini (yayın tarihi ve platformlarıyla) onay olarak görür. Onaylarsa ay kendiliğinden üretime geçer.</div>
            <div class="form-group"><label class="form-label">Müşteriye not</label><textarea name="description" class="text-area" placeholder="Bu ayın ana teması, öne çıkan günler..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Planı Gönder</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
function monthTask(lane) {
    const m = document.getElementById('modalTask');
    if (!m) return;
    const period = m.querySelector('select[name=period_id]');
    if (period) period.value = '<?= $id ?>';
    const radio = m.querySelector(`input[name=lane][value="${lane}"]`);
    if (radio) radio.checked = true;
    m.querySelector('.modal-title').textContent = lane === 'agenda' ? 'Yeni Gündem İşi — <?= period_name($month) ?>' : 'Yeni İş — <?= period_name($month) ?>';
    modalOpen('modalTask');
}
</script>
<?php page_end(); ?>

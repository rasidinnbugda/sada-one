<?php
/**
 * SADA One — Team Board
 * Who is working on what, who is idle — a live availability view.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/office.php';
require_staff();

$weekHead = date('Y-m-d', strtotime('monday this week'));
$members = rows("SELECT us.id, us.name, us.color, us.avatar, us.job_title, us.role, us.weekly_capacity,
    (SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z WHERE z.user_id=us.id AND z.date=CURDATE()) today_min,
    (SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z WHERE z.user_id=us.id AND z.date>=?) week_min
    FROM users us WHERE us.role IN ('admin','pm','team','finance') AND us.is_active=1 ORDER BY us.name", [$weekHead]);

// Each member's ongoing tasks (via assignee_id OR multi-assignment)
foreach ($members as &$member) {
    $member['ongoing_tasks'] = rows("SELECT g.id, g.title, g.status, g.due_date, p.name project_name, d.color client_color
        FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id
        WHERE g.is_archived=0 AND g.status IN ('in_progress','in_review','awaiting_approval')
        AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees ga WHERE ga.task_id=g.id AND ga.user_id=?))
        ORDER BY FIELD(g.status,'in_progress','in_review','awaiting_approval'), g.due_date IS NULL, g.due_date LIMIT 6", [$member['id'], $member['id']]);
    $member['pending'] = (int)val("SELECT COUNT(*) FROM tasks g WHERE g.is_archived=0 AND g.status='todo'
        AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees ga WHERE ga.task_id=g.id AND ga.user_id=?))", [$member['id'], $member['id']]);
}
unset($member);

$busyCount = count(array_filter($members, fn($m) => $m['ongoing_tasks']));
$idleCount = count($members) - $busyCount;

page_start('Ekip', 'team');
?>
<div class="page-top">
    <div><div class="page-title">Ekip Panosu</div><div class="page-bottom">Şu an kim ne üzerinde çalışıyor — <?= $busyCount ?> meşgul, <?= $idleCount ?> boşta</div></div>
    <div class="page-top-action"><a href="office.php" class="btn">Ofis günlerimi düzenle</a></div>
</div>

<div class="mb-3"><?php office_week_board(date('Y-m-d', strtotime('monday this week', strtotime(preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['week'] ?? '') ? $_GET['week'] : 'today'))), true, 'team.php'); ?></div>

<div class="grid grid-auto">
    <?php foreach ($members as $member):
        $idle = !$member['ongoing_tasks'];
        $targetMin = (int)$member['weekly_capacity'] * 60;
        $rate = $targetMin > 0 ? min(100, round($member['week_min'] / $targetMin * 100)) : 0; ?>
    <div class="card" style="<?= $idle ? 'border-color:rgba(53,198,107,.35)' : '' ?>">
        <div class="row-flex between mb-2">
            <div class="row-flex" style="gap:11px">
                <?= avatar($member, 44) ?>
                <div>
                    <div class="bold"><?= e($member['name']) ?></div>
                    <div class="cell-bottom"><?= $member['job_title'] ? e($member['job_title']) : ROLES[$member['role']] ?></div>
                </div>
            </div>
            <?php if ($idle): ?>
            <span class="badge r-approved">Boşta</span>
            <?php else: ?>
            <span class="badge r-in_progress"><?= count($member['ongoing_tasks']) ?> aktif iş</span>
            <?php endif; ?>
        </div>

        <?php if ($idle): ?>
        <div class="text-muted small" style="padding:10px 0">
            Devam eden işi yok<?= $member['pending'] ? " — sırada {$member['pending']} bekleyen iş var" : '. Yeni iş atanabilir.' ?>
        </div>
        <?php else: ?>
        <div class="vertical mt-1" style="gap:6px">
            <?php foreach ($member['ongoing_tasks'] as $dg):
                $overdue = $dg['due_date'] && $dg['due_date'] < date('Y-m-d'); ?>
            <a href="task.php?id=<?= $dg['id'] ?>" class="row-flex between" style="padding:7px 10px;background:var(--surface-2);border-radius:9px;gap:8px">
                <span class="row-flex small" style="gap:7px;min-width:0">
                    <span class="label-dot" style="width:7px;height:7px;background:<?= e($dg['client_color']) ?>;flex-shrink:0"></span>
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($dg['title']) ?></span>
                </span>
                <?= badge($dg['status'], TASK_STATUSES) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="row-flex between mt-2" style="gap:10px">
            <span class="cell-bottom">Bugün: <b style="color:var(--text)"><?= $member['today_min'] ? format_minutes((int)$member['today_min']) : '—' ?></b></span>
            <div class="row-flex" style="gap:8px;flex:1;max-width:150px">
                <div class="progress" style="flex:1"><div class="progress-full <?= $rate > 100 ? 'over' : ($rate > 80 ? 'busy' : '') ?>" data-rate="<?= $rate ?>" style="width:0"></div></div>
                <span class="cell-bottom">%<?= $rate ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="form-hint mt-2 orta">Haftalık doluluk çubuğu, kayıtlı süre ÷ haftalık kapasite hedefine göre hesaplanır.</div>
<?php page_end(); ?>

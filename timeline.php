<?php
/**
 * SADA One — Timeline (Gantt view)
 * Projects and tasks with due dates are shown as horizontal bars in a 6-week window.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_staff();

// View window: this week's Monday + offset
$scroll = (int)($_GET['scroll'] ?? 0);
$start = strtotime('monday this week') + $scroll * 7 * 86400;
$dayCount = 42; // 6 weeks
$end = $start + ($dayCount - 1) * 86400;
$initial = date('Y-m-d', $start);
$last = date('Y-m-d', $end);

// Projects intersecting the window + their tasks
$projects = rows("SELECT p.*, d.name client_name, d.color client_color FROM projects p JOIN clients d ON d.id=p.client_id
    WHERE p.status IN ('active','on_hold') ORDER BY d.name, p.name");

function gantt_location(int $initialTs, int $dayCount, string $t1, string $t2): ?array {
    $a = strtotime($t1); $b = strtotime($t2);
    if ($b < $initialTs || $a > $initialTs + ($dayCount - 1) * 86400) return null;
    $leftDay = max(0, intdiv($a - $initialTs, 86400));
    $rightDay = min($dayCount - 1, intdiv($b - $initialTs, 86400));
    return [round($leftDay / $dayCount * 100, 2), round(($rightDay - $leftDay + 1) / $dayCount * 100, 2)];
}

$todayLocation = null;
if (time() >= $start && time() <= $end + 86400) {
    $todayLocation = round((intdiv(time() - $start, 86400) + .5) / $dayCount * 100, 2);
}

page_start('Zaman Çizelgesi', 'timeline');
?>
<div class="page-top">
    <div><div class="page-title">Zaman Çizelgesi</div><div class="page-bottom"><?= format_date($initial) ?> — <?= format_date($last) ?> · projeler ve son tarihli görevler</div></div>
    <div class="page-top-action">
        <a href="?scroll=<?= $scroll - 2 ?>" class="btn btn-sm">← Geri</a>
        <a href="?scroll=0" class="btn btn-sm <?= $scroll === 0 ? 'btn-brand' : '' ?>">Bugün</a>
        <a href="?scroll=<?= $scroll + 2 ?>" class="btn btn-sm">İleri →</a>
    </div>
</div>

<div class="card gantt-wrap" style="padding:0">
    <div class="gantt">
        <!-- Day headers (per week) -->
        <div class="gantt-days">
            <div class="gantt-label bold" style="padding:10px 14px">Proje / Görev</div>
            <div class="gantt-day-grid" style="grid-template-columns:repeat(<?= intdiv($dayCount, 7) ?>, 1fr)">
                <?php for ($h = 0; $h < intdiv($dayCount, 7); $h++):
                    $hStart = $start + $h * 7 * 86400;
                    $buWeek = date('o-W', $hStart) === date('o-W'); ?>
                <div class="gantt-day <?= $buWeek ? 'today' : '' ?>"><?= date('j', $hStart) ?> <?= MONTHS[(int)date('n', $hStart)] ?></div>
                <?php endfor; ?>
            </div>
        </div>

        <?php
        $hasRow = false;
        foreach ($projects as $gi => $p):
            // Project bar (if start-end dates exist)
            $projectLocation = ($p['start'] && $p['end']) ? gantt_location($start, $dayCount, $p['start'], $p['end']) : null;
            // Tasks within the window
            $tasks = rows("SELECT g.*, u.name assignee_name FROM tasks g LEFT JOIN users u ON u.id=g.assignee_id
                WHERE g.project_id=? AND g.due_date IS NOT NULL AND g.due_date BETWEEN ? AND ? ORDER BY g.due_date", [$p['id'], $initial, $last]);
            if (!$projectLocation && !$tasks) continue;
            $hasRow = true;
        ?>
        <div class="gantt-row">
            <div class="gantt-label project-title">
                <span class="label-dot" style="background:<?= e($p['client_color']) ?>;margin-right:6px"></span>
                <a href="project.php?id=<?= $p['id'] ?>" style="color:inherit"><?= e($p['name']) ?></a>
            </div>
            <div class="gantt-area">
                <?php if ($todayLocation !== null): ?><div class="gantt-today-line" style="left:<?= $todayLocation ?>%"></div><?php endif; ?>
                <?php if ($projectLocation): ?>
                <div class="gantt-bar" style="left:<?= $projectLocation[0] ?>%;width:<?= $projectLocation[1] ?>%;animation-delay:<?= $gi * 60 ?>ms" onclick="location.href='project.php?id=<?= $p['id'] ?>'" title="<?= e($p['name']) ?>: <?= format_date($p['start']) ?> → <?= format_date($p['end']) ?>"><?= e($p['client_name']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php foreach ($tasks as $ti => $gr):
            $startDate = max($gr['created'] ? substr($gr['created'], 0, 10) : $gr['due_date'], $initial);
            $location = gantt_location($start, $dayCount, $startDate, $gr['due_date']);
            if (!$location) continue;
            $class = $gr['status'] === 'completed' ? 'completed' : ($gr['due_date'] < date('Y-m-d') ? 'overdue' : ''); ?>
        <div class="gantt-row">
            <div class="gantt-label" style="padding-left:32px">└ <?= e($gr['title']) ?></div>
            <div class="gantt-area">
                <?php if ($todayLocation !== null): ?><div class="gantt-today-line" style="left:<?= $todayLocation ?>%"></div><?php endif; ?>
                <div class="gantt-bar <?= $class ?>" style="left:<?= $location[0] ?>%;width:<?= max(3, $location[1]) ?>%;animation-delay:<?= 100 + $ti * 50 ?>ms" onclick="location.href='task.php?id=<?= $gr['id'] ?>'" title="<?= e($gr['title']) ?> · <?= TASK_STATUSES[$gr['status']] ?> · Son: <?= format_date($gr['due_date']) ?>"><?= $gr['assignee_name'] ? e(explode(' ', $gr['assignee_name'])[0]) : '' ?></div>
            </div>
        </div>
        <?php endforeach; endforeach; ?>

        <?php if (!$hasRow): ?>
        <div class="empty-state">
            <div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M4 6h6m-6 6h10M4 18h14M20 6v12"/></svg></div>
            <div class="empty-title">Bu pencerede planlı iş yok</div>
            <div class="empty-text">Projelere başlangıç/bitiş tarihi, görevlere son tarih ekleyin — burada otomatik görünürler.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="row-flex wrap mt-3" style="gap:16px;justify-content:center">
    <span class="row-flex small" style="gap:6px"><span class="label-dot" style="background:var(--brand)"></span>Devam eden</span>
    <span class="row-flex small" style="gap:6px"><span class="label-dot" style="background:var(--success)"></span>Tamamlanan</span>
    <span class="row-flex small" style="gap:6px"><span class="label-dot" style="background:var(--danger)"></span>Geciken</span>
    <span class="row-flex small" style="gap:6px"><span style="width:2px;height:14px;background:var(--brand);display:inline-block"></span>Bugün</span>
</div>
<?php page_end(); ?>

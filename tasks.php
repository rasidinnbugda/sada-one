<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_staff();

$projectFilter = (int)($_GET['project'] ?? 0);
$periodFilter = (int)($_GET['period'] ?? 0);
$filter = $_GET['filter'] ?? '';
$view = in_array($_GET['view'] ?? '', ['kanban', 'table']) ? $_GET['view'] : ($u['task_view'] ?: 'kanban');

$where_sql = $filter === 'archive' ? "g.is_archived=1" : "g.is_archived=0";
$params = [];
// Interns only see tasks assigned to them
if (is_intern()) {
    $where_sql .= " AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees gas WHERE gas.task_id=g.id AND gas.user_id=?))";
    $params[] = $u['id']; $params[] = $u['id'];
}
if ($projectFilter) { $where_sql .= " AND g.project_id=?"; $params[] = $projectFilter; }
if ($periodFilter) { $where_sql .= " AND g.period_id=?"; $params[] = $periodFilter; }
if ($filter === 'mine') { $where_sql .= " AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees gax WHERE gax.task_id=g.id AND gax.user_id=?))"; $params[] = $u['id']; $params[] = $u['id']; }
if ($filter === 'overdue') { $where_sql .= " AND g.due_date<CURDATE() AND g.status!='completed'"; }

$tasks = rows("SELECT g.*, p.name project_name, d.color client_color, uu.name assignee_name, uu.color assignee_color, uu.avatar assignee_avatar,
    bg.status dependency_status, bg.title dependency_title,
    (SELECT COUNT(*) FROM task_checklist k WHERE k.task_id=g.id) check_total,
    (SELECT COUNT(*) FROM task_checklist k WHERE k.task_id=g.id AND k.is_done=1) check_is_done,
    (SELECT COUNT(*) FROM task_steps ga WHERE ga.task_id=g.id) step_total,
    (SELECT COUNT(*) FROM task_steps ga WHERE ga.task_id=g.id AND ga.status='done') step_is_done,
    (SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z WHERE z.task_id=g.id) spent_min,
    (SELECT COUNT(*) FROM task_assignees gaa WHERE gaa.task_id=g.id) assignee_count,
    (SELECT GROUP_CONCAT(u3.name SEPARATOR ', ') FROM task_assignees ga3 JOIN users u3 ON u3.id=ga3.user_id WHERE ga3.task_id=g.id) assignee_names
    FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id
    LEFT JOIN users uu ON uu.id=g.assignee_id LEFT JOIN tasks bg ON bg.id=g.depends_on_id
    WHERE $where_sql ORDER BY g.sort_order, g.due_date IS NULL, g.due_date", $params);

$activeProject = $projectFilter ? row("SELECT name FROM projects WHERE id=?", [$projectFilter]) : null;
$team = rows("SELECT id, name, color FROM users WHERE role IN ('admin','pm','team') AND is_active=1 ORDER BY name");
$templates = rows("SELECT * FROM workflow_templates ORDER BY name");

// Active workflow steps I am responsible for
$stepConditionSql = only_own_steps()
    ? "ga.owner_id=?"
    : "(ga.owner_id=? OR (ga.owner_id IS NULL AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees gat WHERE gat.task_id=g.id AND gat.user_id=?))))";
$stepParam = only_own_steps() ? [$u['id']] : [$u['id'], $u['id'], $u['id']];
$my_steps = rows("SELECT ga.id step_id, ga.name step_name, ga.status step_status, g.id task_id, g.title, p.name project_name
    FROM task_steps ga JOIN tasks g ON g.id=ga.task_id JOIN projects p ON p.id=g.project_id
    WHERE ga.status IN ('active','pending') AND g.is_archived=0 AND g.status!='completed' AND $stepConditionSql
    ORDER BY ga.status='active' DESC, g.due_date IS NULL, g.due_date LIMIT 12", $stepParam);
$my_steps = array_filter($my_steps, fn($a2) => $a2['step_status'] === 'active' || count($my_steps) < 8);

page_start('Görevler', 'tasks');
?>
<?php if ($my_steps):
    $activeStepCount = count(array_filter($my_steps, fn($a3) => $a3['step_status'] === 'active')); ?>
<div class="card mb-3 collapse closed" data-collapse="my_steps" style="border-color:var(--brand)">
    <button class="card-title" data-collapse-btn type="button" style="display:flex;align-items:center;gap:9px;margin:0">
        <span class="collapse-arrow"><svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" width="14"><path d="M19 9l-7 7-7-7"/></svg></span>
        <?= icon('rocket', 16) ?> Adımlarım <span class="badge r-in_progress" style="padding:1px 9px"><?= $activeStepCount ?> sıra sende</span><span class="cell-bottom">· <?= count($my_steps) ?> adım</span>
    </button>
    <div class="vertical collapse-content mt-2" style="gap:6px">
        <?php foreach ($my_steps as $myStep): ?>
        <div class="row-flex between" style="padding:9px 12px;background:var(--surface-2);border-radius:10px;gap:10px">
            <a href="task.php?id=<?= $myStep['task_id'] ?>" class="small" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><b><?= e($myStep['step_name']) ?></b> · <?= e($myStep['title']) ?> <span class="text-muted">(<?= e($myStep['project_name']) ?>)</span></a>
            <?php if ($myStep['step_status'] === 'active'): ?><button class="btn btn-sm btn-brand" data-action="step_complete" data-id="<?= $myStep['step_id'] ?>" style="flex-shrink:0">Tamamla</button><?php else: ?><span class="badge" style="flex-shrink:0">Sırada</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<div class="page-top">
    <div>
        <div class="page-title">Görevler<?= $activeProject ? ' · ' . e($activeProject['name']) : '' ?></div>
        <div class="page-bottom"><?= count($tasks) ?> görev — <?= $view === 'table' ? 'hücrelere tıklayıp doğrudan düzenleyin' : 'panoda sürükleyerek durum değiştirin' ?></div>
    </div>
    <div class="page-top-action">
        <div class="view-switch">
            <button class="view-btn <?= $view === 'kanban' ? 'active' : '' ?>" onclick="viewSelect('kanban')">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 5h4v14H4zM10 5h4v9h-4zM16 5h4v6h-4z"/></svg> Kanban
            </button>
            <button class="view-btn <?= $view === 'table' ? 'active' : '' ?>" onclick="viewSelect('table')">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18M3 4h18v16H3z"/></svg> Tablo
            </button>
        </div>
        <?php if (!is_intern()): ?>
        <button class="btn btn-brand" data-modal="modalTask"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Görev</button>
        <?php endif; ?>
    </div>
</div>

<div class="filter-bar">
    <div class="pill-filter">
        <a href="?<?= http_build_query(array_filter(['project' => $projectFilter, 'view' => $view])) ?>" class="pill <?= !$filter ? 'active' : '' ?>">Tümü</a>
        <a href="?<?= http_build_query(array_filter(['filter' => 'mine', 'project' => $projectFilter, 'view' => $view])) ?>" class="pill <?= $filter === 'mine' ? 'active' : '' ?>">Bana Atanan</a>
        <a href="?<?= http_build_query(array_filter(['filter' => 'overdue', 'project' => $projectFilter, 'view' => $view])) ?>" class="pill <?= $filter === 'overdue' ? 'active' : '' ?>">Geciken</a>
        <a href="?<?= http_build_query(array_filter(['filter' => 'archive', 'project' => $projectFilter, 'view' => $view])) ?>" class="pill <?= $filter === 'archive' ? 'active' : '' ?>">Arşiv</a>
    </div>
    <?php if ($view === 'table'): ?>
    <div class="search-box"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="input" placeholder="Görev ara..." data-search="#taskTable tbody tr"></div>
    <?php endif; ?>
    <?php if ($projectFilter): ?><a href="tasks.php?view=<?= $view ?>" class="btn btn-sm btn-ghost">Filtreyi Temizle ✕</a><?php endif; ?>
</div>

<?php if (!$tasks): ?>
<div class="empty-state"><div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg></div><div class="empty-title">Görev bulunamadı</div><div class="empty-text">Bu filtreye uygun görev yok. Yeni bir görev oluşturabilirsiniz.</div></div>

<?php elseif ($view === 'kanban'): ?>
<?php task_kanban($tasks, $projectFilter); ?>

<?php else: /* ---------- TABLE VIEW ---------- */ ?>
<div class="table-wrap">
<table class="table" id="taskTable">
    <thead><tr>
        <th class="sortable">Görev <span class="order-mark">↕</span></th>
        <th class="sortable">Proje <span class="order-mark">↕</span></th>
        <th>Atanan</th>
        <th>Durum</th>
        <th>Öncelik</th>
        <th class="sortable">Başlangıç <span class="order-mark">↕</span></th>
        <th class="sortable">Son Tarih <span class="order-mark">↕</span></th>
        <th class="sortable">Tahmin/Gerçek <span class="order-mark">↕</span></th>
        <th class="sortable">Akış <span class="order-mark">↕</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($tasks as $gr):
        $locked = !empty($gr['dependency_status']) && $gr['dependency_status'] !== 'completed' && empty($gr['lock_bypassed']);
        $workflowRate = $gr['step_total'] ? round($gr['step_is_done'] / $gr['step_total'] * 100) : null; ?>
    <tr data-search="<?= e($gr['title'] . ' ' . $gr['project_name'] . ' ' . ($gr['tags'] ?? '')) ?>">
        <td style="min-width:220px">
            <a href="task.php?id=<?= $gr['id'] ?>" class="cell-main" style="display:block"><?= $locked ? icon('lock', 12) . ' ' : '' ?><?= $gr['repeat'] !== 'none' ? icon('repeat', 12) . ' ' : '' ?><?= e($gr['title']) ?></a>
            <div class="row-flex wrap mt-1" style="gap:4px"><?= tag_chips($gr['tags']) ?><?php if ($gr['check_total']): ?><span class="kanban-label"><?= icon('approval', 12) ?> <?= $gr['check_is_done'] ?>/<?= $gr['check_total'] ?></span><?php endif; ?></div>
        </td>
        <td class="small" data-sort="<?= e($gr['project_name']) ?>"><span class="label-dot" style="width:8px;height:8px;background:<?= e($gr['client_color']) ?>;margin-right:5px"></span><?= e($gr['project_name']) ?></td>
        <td class="cell-edit">
            <select class="select" data-old="<?= $gr['assignee_id'] ?>" onchange="cellSave(this, <?= $gr['id'] ?>, 'assignee_id')">
                <option value="">—</option>
                <?php foreach ($team as $k): ?><option value="<?= $k['id'] ?>" <?= $k['id'] == $gr['assignee_id'] ? 'selected' : '' ?>><?= e($k['name']) ?></option><?php endforeach; ?>
            </select>
        </td>
        <td class="cell-edit">
            <select class="select" data-old="<?= $gr['status'] ?>" onchange="cellSave(this, <?= $gr['id'] ?>, 'status')">
                <?php foreach (TASK_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $gr['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </td>
        <td class="cell-edit">
            <select class="select" data-old="<?= $gr['priority'] ?>" onchange="cellSave(this, <?= $gr['id'] ?>, 'priority')">
                <?php foreach (PRIORITIES as $k => $v): ?><option value="<?= $k ?>" <?= $gr['priority'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </td>
        <td class="cell-edit" data-sort="<?= e($gr['start_date'] ?? '9999') ?>">
            <input type="date" class="input" value="<?= e($gr['start_date']) ?>" onchange="cellSave(this, <?= $gr['id'] ?>, 'start_date')">
        </td>
        <td class="cell-edit" data-sort="<?= e($gr['due_date'] ?? '9999') ?>">
            <input type="date" class="input" value="<?= e($gr['due_date']) ?>" style="<?= $gr['due_date'] && $gr['due_date'] < date('Y-m-d') && $gr['status'] !== 'completed' ? 'color:var(--danger)' : '' ?>" onchange="cellSave(this, <?= $gr['id'] ?>, 'due_date')">
        </td>
        <td class="small" data-sort="<?= $gr['estimated_minutes'] ?>">
            <span class="cell-edit"><input class="input" style="width:56px" value="<?= $gr['estimated_minutes'] ? round($gr['estimated_minutes'] / 60, 1) : '' ?>" placeholder="sa" onchange="cellSave(this, <?= $gr['id'] ?>, 'estimated_minutes')"></span>
            <span class="text-muted">/ <?= $gr['spent_min'] ? format_minutes((int)$gr['spent_min']) : '—' ?></span>
        </td>
        <td data-sort="<?= $workflowRate ?? -1 ?>">
            <?php if ($workflowRate !== null): ?>
            <div class="row-flex" style="gap:8px"><div class="progress" style="width:56px"><div class="progress-full" style="width:<?= $workflowRate ?>%"></div></div><span class="small"><?= $gr['step_is_done'] ?>/<?= $gr['step_total'] ?></span></div>
            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="form-hint mt-2">💡 Hücrelere tıklayarak doğrudan düzenleyin; sütun başlıklarına tıklayarak sıralayın. Kilitli görevlerde durum değişikliği kurallara takılırsa eski değere döner.</div>
<?php endif; ?>

<?php task_modal($projectFilter, $team, $templates); ?>
<script>
// Live sync: if someone else adds/moves a task, the list refreshes
window.sadaLive = { context: 'list', hash: '<?= live_hash_list() ?>' };
async function viewSelect(g) {
    await api('view_preference', { view: g });
    const url = new URL(location.href);
    url.searchParams.set('view', g);
    location.href = url.toString();
}
</script>
<?php page_end(); ?>

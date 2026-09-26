<?php
/**
 * SADA One — Content Calendar
 * The publish plan of client work: every task (İş) with a publish date, per client file (brand).
 * Staff plan new work on a day, drag it to another day and change its status; customers only look.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; } if ($month > 12) { $month = 1; $year++; }
$clientFilter = (int)($_GET['client'] ?? 0);
$projectFilter = (int)($_GET['project'] ?? 0);

// Accessible client files
if (is_staff()) {
    $clients = rows("SELECT id, name FROM clients WHERE status='active' ORDER BY name");
} else {
    [$in, $p] = in_clause(customer_client_ids());
    $clients = rows("SELECT id, name FROM clients WHERE id IN $in ORDER BY name", $p);
}
$clientIds = array_map('intval', array_column($clients, 'id'));
$projects = is_staff() ? rows("SELECT id, name, client_id, type FROM projects WHERE status='active' ORDER BY name") : [];
if ($projectFilter && !$clientFilter) $clientFilter = (int)val("SELECT client_id FROM projects WHERE id=?", [$projectFilter]);
$canPlan = is_staff() && (permission('task_create') || permission('content_manage'));
$canMove = permission('content_manage');

$firstDay = mktime(0, 0, 0, $month, 1, $year);
$dayCount = (int)date('t', $firstDay);
$startWeek = (int)date('N', $firstDay);
$monthInitial = sprintf('%04d-%02d-01', $year, $month);
$monthLast = sprintf('%04d-%02d-%02d', $year, $month, $dayCount);

$byDay = [];
$items = [];
if ($clientIds) {
    [$inD, $pD] = in_clause($clientIds);
    $params = array_merge($pD, [$monthInitial, $monthLast]);
    $extra = '';
    if ($clientFilter) { $extra .= ' AND p.client_id=?'; $params[] = $clientFilter; }
    if ($projectFilter) { $extra .= ' AND g.project_id=?'; $params[] = $projectFilter; }
    $items = rows("SELECT g.id, g.title, g.description, g.status, g.publish_date, g.publish_time, g.platforms, p.name project_name, d.name client_name
        FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id
        WHERE p.client_id IN $inD AND g.kind='client' AND g.status!='cancelled' AND g.is_archived=0
          AND g.publish_date BETWEEN ? AND ?$extra
        ORDER BY g.publish_date, g.publish_time IS NULL, g.publish_time", $params);
    foreach ($items as $item) $byDay[(int)date('j', strtotime($item['publish_date']))][] = $item;
}
// Internal briefs stay with the team: customers see the plan, not the notes
if (!is_staff()) $items = array_map(fn($i) => array_diff_key($i, ['description' => 1]), $items);

$nav = fn(int $m, int $y) => '?' . http_build_query(array_filter(['month' => $m, 'year' => $y, 'client' => $clientFilter, 'project' => $projectFilter]));
page_start('İçerik Takvimi', 'content');
?>
<div class="page-top">
    <div><div class="page-title">İçerik Takvimi</div><div class="page-bottom">İşlerin yayın planı — dosya (marka) bazlı</div></div>
    <?php if ($canPlan): ?><div class="page-top-action"><button class="btn btn-brand" onclick="planOn('<?= date('Y-m-d') ?>')"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> İş Planla</button></div><?php endif; ?>
</div>

<div class="filter-bar">
    <select class="select" style="max-width:280px" onchange="location.href='?client='+this.value+'&month=<?= $month ?>&year=<?= $year ?>'">
        <option value="0">Tüm Dosyalar</option>
        <?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $clientFilter == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
    <?php if ($projectFilter): ?><a href="?<?= http_build_query(array_filter(['month' => $month, 'year' => $year, 'client' => $clientFilter])) ?>" class="btn btn-sm btn-ghost">Proje filtresini kaldır ✕</a><?php endif; ?>
</div>

<div class="card">
    <div class="calendar-title-bar">
        <div class="row-flex" style="gap:8px">
            <a href="<?= $nav($month - 1, $year) ?>" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
            <div class="calendar-month-name"><?= MONTHS[$month] ?> <?= $year ?></div>
            <a href="<?= $nav($month + 1, $year) ?>" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>
        </div>
        <a href="<?= $nav((int)date('n'), (int)date('Y')) ?>" class="btn btn-sm">Bugün</a>
    </div>
    <div class="calendar-grid">
        <?php foreach (['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $dayName): ?><div class="calendar-day-title"><?= $dayName ?></div><?php endforeach; ?>
        <?php for ($i = 1; $i < $startWeek; $i++): ?><div class="calendar-cell empty"></div><?php endfor; ?>
        <?php for ($day = 1; $day <= $dayCount; $day++):
            $today = ($day == date('j') && $month == date('n') && $year == date('Y'));
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day); ?>
        <div class="calendar-cell <?= $today ? 'today' : '' ?>" data-date="<?= $dateStr ?>" <?= $canPlan ? "onclick=\"planOn('$dateStr')\" style=\"cursor:pointer\"" : '' ?>>
            <div class="calendar-day-number"><?= $day ?></div>
            <?php foreach ($byDay[$day] ?? [] as $item): $color = TASK_STATUS_COLORS[$item['status']]; ?>
            <div class="calendar-event" draggable="<?= $canMove ? 'true' : 'false' ?>" data-task="<?= $item['id'] ?>" onclick="event.stopPropagation();itemShow(<?= $item['id'] ?>)" style="border-color:<?= $color ?>;background:color-mix(in srgb, <?= $color ?> 14%, transparent);color:<?= $color ?>" title="<?= e($item['title']) ?> · <?= e($item['client_name']) ?> · <?= TASK_STATUSES[$item['status']] ?>"><?= platform_badges($item['platforms'], true) ?> <?= e($item['title']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="row-flex wrap mt-3" style="gap:16px;justify-content:center">
    <?php foreach (TASK_STATUSES as $k => $v): if ($k === 'cancelled') continue; ?>
    <span class="row-flex small" style="gap:6px"><span class="label-dot" style="background:<?= TASK_STATUS_COLORS[$k] ?>"></span><?= $v ?></span>
    <?php endforeach; ?>
</div>

<?php if ($canPlan): ?>
<div class="modal-overlay" id="modalPlan">
    <div class="modal"><div class="modal-top"><div class="modal-title">İş Planla</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="task_save" data-refresh="yes" id="planForm">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık <span class="required">*</span></label><input name="title" class="input" required placeholder="Örn. Ekim kampanyası — 1. gönderi"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Dosya (marka)</label><select id="planClient" class="select"><option value="">Tümü</option><?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $clientFilter == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Proje <span class="required">*</span></label><select name="project_id" id="planProject" class="select native-select" required><option value="">Seçin...</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" data-client="<?= $p['client_id'] ?>" <?= $projectFilter == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?><?= $p['type'] === 'monthly' ? ' (aylık)' : '' ?></option><?php endforeach; ?></select><div class="form-hint">Aylık projede iş, yayınlandığı ayın dönemine yazılır.</div></div>
            </div>
            <?php task_publish_fields(['kind' => 'client', 'platforms' => 'instagram', 'publish_date' => date('Y-m-d')], false); ?>
            <div class="form-group"><label class="form-label">Açıklama / Metin</label><textarea name="description" class="text-area" placeholder="Brif, gönderi metni, hashtag'ler..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Planla</button></div>
    </form></div>
</div>
<?php endif; ?>

<!-- Detail of one planned deliverable -->
<div class="modal-overlay" id="modalItem">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="itemTitle"></div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body" id="itemBody"></div>
    </div>
</div>

<script>
const planItems = <?= json_encode(array_column($items, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
const taskStatuses = <?= json_encode(array_diff_key(TASK_STATUSES, ['cancelled' => 1]) + (is_staff() ? ['cancelled' => TASK_STATUSES['cancelled']] : []), JSON_UNESCAPED_UNICODE) ?>;
const platforms = <?= json_encode(PLATFORMS, JSON_UNESCAPED_UNICODE) ?>;
const isStaff = <?= is_staff() ? 'true' : 'false' ?>;
const canMove = <?= $canMove ? 'true' : 'false' ?>;

function planOn(date) {
    const form = document.getElementById('planForm');
    if (!form) return;
    formFieldSet(form, 'publish_date', date);
    modalOpen('modalPlan');
}
// Picking a client narrows the project list; picking a project selects its client
const planClient = document.getElementById('planClient'), planProject = document.getElementById('planProject');
if (planClient && planProject) {
    const filterProjects = () => {
        for (const o of planProject.options) if (o.value) o.hidden = !!planClient.value && o.dataset.client !== planClient.value;
        if (planProject.selectedOptions[0]?.hidden) planProject.value = '';
    };
    planClient.addEventListener('change', filterProjects);
    planProject.addEventListener('change', () => { const c = planProject.selectedOptions[0]?.dataset.client; if (c) planClient.value = c; });
    filterProjects();
}
function itemShow(id) {
    const item = planItems[id]; if (!item) return;
    document.getElementById('itemTitle').textContent = item.title;
    const platformList = (item.platforms || '').split(',').filter(Boolean).map(pl => esc(platforms[pl] || pl)).join(' · ') || '—';
    let status = esc(taskStatuses[item.status] || item.status);
    if (isStaff) {
        status = `<select class="select mt-2" onchange="itemStatus(${id}, this.value)">`;
        for (const k in taskStatuses) status += `<option value="${k}" ${item.status === k ? 'selected' : ''}>${esc(taskStatuses[k])}</option>`;
        status += `</select>`;
    }
    let h = `<div class="vertical" style="gap:12px">
        <div class="row-flex between"><span class="cell-bottom">Dosya</span><span class="small bold">${esc(item.client_name || '—')}</span></div>
        <div class="row-flex between"><span class="cell-bottom">Proje</span><span class="small">${esc(item.project_name || '—')}</span></div>
        <div class="row-flex between"><span class="cell-bottom">Platformlar</span><span class="small">${platformList}</span></div>
        <div class="row-flex between"><span class="cell-bottom">Yayın</span><span class="small">${new Date(item.publish_date).toLocaleDateString('tr-TR', { dateStyle: 'long' })}${item.publish_time ? ' ' + item.publish_time.slice(0, 5) : ''}</span></div>
        <div><div class="cell-bottom mb-2">Durum</div>${status}</div>`;
    if (isStaff && item.description) h += `<div><div class="cell-bottom mb-2">Açıklama</div><div class="small text-2" style="white-space:pre-wrap">${esc(item.description)}</div></div>`;
    if (isStaff) h += `<a href="task.php?id=${id}" class="btn btn-sm mt-2">İşe git →</a>`;
    if (canMove) h += `<div class="row-flex mt-2" style="gap:8px"><input type="date" class="input" id="itemMoveDate" value="${item.publish_date}" style="max-width:150px"><input type="time" class="input" id="itemMoveTime" value="${(item.publish_time || '').slice(0, 5)}" style="max-width:110px"><button class="btn btn-sm" onclick="itemMove(${id})">Tarihi Güncelle</button></div>`;
    h += `</div>`;
    document.getElementById('itemBody').innerHTML = h;
    if (window.customPickerRefresh) customPickerRefresh();
    modalOpen('modalItem');
}
async function itemStatus(id, status) {
    const j = await api('task_status', { id, status });
    if (j.ok) { toast('Durum güncellendi', 'success'); setTimeout(() => location.reload(), 600); }
}
async function itemMove(id) {
    const dEl = document.getElementById('itemMoveDate'), tEl = document.getElementById('itemMoveTime');
    const j = await api('task_publish_move', { id, date: dEl.dataset.value ?? dEl.value, time: tEl.dataset.value ?? tEl.value });
    if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 600); }
}
// Drag and drop: move a deliverable to another day
let draggedTaskId = null;
document.querySelectorAll('.calendar-event[data-task][draggable="true"]').forEach(chip => {
    chip.addEventListener('dragstart', e => { draggedTaskId = chip.dataset.task; e.stopPropagation(); });
});
document.querySelectorAll('.calendar-cell[data-date]').forEach(cell => {
    cell.addEventListener('dragover', e => { if (draggedTaskId) { e.preventDefault(); cell.style.borderColor = 'var(--brand)'; } });
    cell.addEventListener('dragleave', () => cell.style.borderColor = '');
    cell.addEventListener('drop', async e => {
        e.preventDefault(); cell.style.borderColor = '';
        if (!draggedTaskId) return;
        const j = await api('task_publish_move', { id: draggedTaskId, date: cell.dataset.date, time: '' });
        draggedTaskId = null;
        if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 500); }
    });
});
</script>
<?php page_end(); ?>

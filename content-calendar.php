<?php
/**
 * SADA One — Content Calendar
 * Contents are tied to a client file (brand); the project is optional. Multiple platforms are supported.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; } if ($month > 12) { $month = 1; $year++; }
$clientFilter = (int)($_GET['client'] ?? 0);

// Accessible client files
if (is_staff()) {
    $clients = rows("SELECT id, name FROM clients WHERE status='active' ORDER BY name");
} else {
    [$in, $p] = in_clause(customer_client_ids());
    $clients = rows("SELECT id, name FROM clients WHERE id IN $in ORDER BY name", $p);
}
$clientIds = array_map('intval', array_column($clients, 'id'));
$projects = is_staff() ? rows("SELECT id, name, client_id FROM projects WHERE status='active' ORDER BY name") : [];

$firstDay = mktime(0, 0, 0, $month, 1, $year);
$dayCount = (int)date('t', $firstDay);
$startWeek = (int)date('N', $firstDay);

$monthInitial = sprintf('%04d-%02d-01', $year, $month);
$monthLast = sprintf('%04d-%02d-%02d', $year, $month, $dayCount);

$contentDays = [];
$allContents = [];
if ($clientIds) {
    [$inD, $pD] = in_clause($clientIds);
    $params = array_merge($pD, [$monthInitial, $monthLast]);
    $extraCondition = '';
    if ($clientFilter) { $extraCondition = ' AND COALESCE(i.client_id, pr.client_id)=?'; $params[] = $clientFilter; }
    $allContents = rows("SELECT i.*, d.name client_name, pr.name project_name, (SELECT g.id FROM tasks g WHERE g.content_id=i.id LIMIT 1) task_id
        FROM contents i
        LEFT JOIN projects pr ON pr.id=i.project_id
        LEFT JOIN clients d ON d.id=COALESCE(i.client_id, pr.client_id)
        WHERE COALESCE(i.client_id, pr.client_id) IN $inD AND i.date BETWEEN ? AND ?$extraCondition
        ORDER BY i.date, i.time", $params);
    foreach ($allContents as $contentItem) { $g = (int)date('j', strtotime($contentItem['date'])); $contentDays[$g][] = $contentItem; }
}

page_start('İçerik Takvimi', 'content');
?>
<div class="page-top">
    <div><div class="page-title">İçerik Takvimi</div><div class="page-bottom">Dosya (marka) bazlı sosyal medya içerik planı</div></div>
    <?php if (permission('content_manage')): ?><div class="page-top-action"><button class="btn btn-brand" data-modal="modalContent"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> İçerik Planla</button></div><?php endif; ?>
</div>

<div class="filter-bar">
    <select class="select" style="max-width:280px" onchange="location.href='?client='+this.value+'&month=<?= $month ?>&year=<?= $year ?>'">
        <option value="0">Tüm Dosyalar</option>
        <?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $clientFilter == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
</div>

<div class="card">
    <div class="calendar-title-bar">
        <div class="row-flex" style="gap:8px">
            <a href="?month=<?= $month - 1 ?>&year=<?= $year ?>&client=<?= $clientFilter ?>" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
            <div class="calendar-month-name"><?= MONTHS[$month] ?> <?= $year ?></div>
            <a href="?month=<?= $month + 1 ?>&year=<?= $year ?>&client=<?= $clientFilter ?>" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>
        </div>
        <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>&client=<?= $clientFilter ?>" class="btn btn-sm">Bugün</a>
    </div>
    <div class="calendar-grid">
        <?php foreach (['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $g): ?><div class="calendar-day-title"><?= $g ?></div><?php endforeach; ?>
        <?php for ($i = 1; $i < $startWeek; $i++): ?><div class="calendar-cell empty"></div><?php endfor; ?>
        <?php for ($day = 1; $day <= $dayCount; $day++):
            $today = ($day == date('j') && $month == date('n') && $year == date('Y'));
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day); ?>
        <div class="calendar-cell <?= $today ? 'today' : '' ?>" data-date="<?= $dateStr ?>" <?= permission('content_manage') ? "onclick=\"contentAdd('$dateStr')\" style=\"cursor:pointer\"" : '' ?>>
            <div class="calendar-day-number"><?= $day ?></div>
            <?php foreach ($contentDays[$day] ?? [] as $contentItem):
                $statusColor = CONTENT_STATUS_COLORS[$contentItem['status']]; ?>
            <div class="calendar-event" draggable="<?= permission('content_manage') ? 'true' : 'false' ?>" data-content="<?= $contentItem['id'] ?>" onclick="event.stopPropagation();contentShow(<?= $contentItem['id'] ?>)" style="border-color:<?= $statusColor ?>;background:color-mix(in srgb, <?= $statusColor ?> 14%, transparent);color:<?= $statusColor ?>" title="<?= e($contentItem['title']) ?> · <?= e($contentItem['client_name'] ?? '') ?>"><?= platform_badges($contentItem['platform'], true) ?> <?= e($contentItem['title']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="row-flex wrap mt-3" style="gap:16px;justify-content:center">
    <?php foreach (CONTENT_STATUSES as $k => $v):
        $color = CONTENT_STATUS_COLORS[$k]; ?>
    <span class="row-flex small" style="gap:6px"><span class="label-dot" style="background:<?= $color ?>"></span><?= $v ?></span>
    <?php endforeach; ?>
</div>

<?php if (permission('content_manage')): ?>
<div class="modal-overlay" id="modalContent">
    <div class="modal"><div class="modal-top"><div class="modal-title">İçerik Planla</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="content_save" data-refresh="yes" id="contentForm">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık <span class="required">*</span></label><input name="title" class="input" required></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Dosya (marka) <span class="required">*</span></label><select name="client_id" id="internal_client" class="select" required><option value="">Seçin...</option><?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $clientFilter == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Proje (opsiyonel)</label><select name="project_id" id="internal_project" class="select"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" data-client="<?= $p['client_id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group">
                <label class="form-label">Platformlar <span class="text-muted" style="font-weight:400">(birden fazla seçilebilir)</span></label>
                <input type="hidden" name="platforms" id="internal_platforms">
                <div class="row-flex wrap" style="gap:6px">
                    <?php foreach (PLATFORMS as $k => $v): ?>
                    <label class="row-flex small" style="gap:7px;padding:7px 12px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="platform-box" value="<?= $k ?>" <?= $k === 'instagram' ? 'checked' : '' ?>> <?= icon(isset(ICONS[$k]) ? $k : 'other', 14) ?> <?= $v ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tarih <span class="required">*</span></label><input type="date" name="date" class="input" required id="internal_date" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label class="form-label">Saat</label><input type="time" name="time" class="input"></div>
            </div>
            <div class="form-group"><label class="form-label">Durum</label><select name="status" class="select"><?php foreach (CONTENT_STATUSES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Açıklama / Metin</label><textarea name="description" class="text-area" placeholder="Gönderi metni, hashtag'ler..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Planla</button></div>
    </form></div>
</div>
<?php endif; ?>

<!-- Content detail -->
<div class="modal-overlay" id="modalContentDetail">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="idTitle"></div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body" id="idBody"></div>
    </div>
</div>

<script>
const contents = <?= json_encode(array_column($allContents, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
const contentStatuses = <?= json_encode(CONTENT_STATUSES, JSON_UNESCAPED_UNICODE) ?>;
const platforms = <?= json_encode(PLATFORMS, JSON_UNESCAPED_UNICODE) ?>;
const platformIcon = {}; // the detail view uses text tags only
const contentManager = <?= permission('content_manage') ? 'true' : 'false' ?>;

function contentAdd(date) { const el = document.getElementById('internal_date'); if (el) { el.value = date; modalOpen('modalContent'); } }
const contentForm = document.getElementById('contentForm');
if (contentForm) {
    contentForm.addEventListener('submit', () => {
        document.getElementById('internal_platforms').value = JSON.stringify(Array.from(document.querySelectorAll('.platform-box:checked')).map(c => c.value));
    });
    document.getElementById('internal_project').addEventListener('change', function () {
        const client = this.selectedOptions[0]?.dataset.client;
        if (client) document.getElementById('internal_client').value = client;
    });
}
function contentShow(id) {
    const contentItem = contents[id]; if (!contentItem) return;
    document.getElementById('idTitle').textContent = contentItem.title;
    const platformList = (contentItem.platform || '').split(',').map(pl => `${platformIcon[pl] || ''} ${platforms[pl] || pl}`).join(' · ');
    let statusSelect = `<select class="select mt-2" onchange="contentStatusChange(${id},this.value)">`;
    for (const k in contentStatuses) statusSelect += `<option value="${k}" ${contentItem.status === k ? 'selected' : ''}>${contentStatuses[k]}</option>`;
    statusSelect += `</select>`;
    let h = `<div class="vertical" style="gap:12px">
        <div class="row-flex between"><span class="cell-bottom">Dosya</span><span class="small bold">${esc(contentItem.client_name || '—')}</span></div>
        ${contentItem.project_name ? `<div class="row-flex between"><span class="cell-bottom">Proje</span><span class="small">${esc(contentItem.project_name)}</span></div>` : ''}
        <div class="row-flex between"><span class="cell-bottom">Platformlar</span><span class="small">${platformList}</span></div>
        <div class="row-flex between"><span class="cell-bottom">Tarih</span><span class="small">${new Date(contentItem.date).toLocaleDateString('tr-TR', { dateStyle: 'long' })}${contentItem.time ? ' ' + contentItem.time.slice(0, 5) : ''}</span></div>
        <div><div class="cell-bottom mb-2">Durum</div>${statusSelect}</div>`;
    if (contentItem.description) h += `<div><div class="cell-bottom mb-2">İçerik</div><div class="small text-2" style="white-space:pre-wrap">${contentItem.description.replace(/</g, '&lt;')}</div></div>`;
    if (contentItem.task_id) h += `<a href="task.php?id=${contentItem.task_id}" class="btn btn-sm mt-2" style="margin-right:6px">Bağlı göreve git →</a>`;
    if (contentManager) h += `<div class="row-flex mt-2" style="gap:8px"><input type="date" class="input" id="contentMoveDate" value="${contentItem.date}" style="max-width:150px"><input type="time" class="input" id="contentMoveTime" value="${(contentItem.time||'').slice(0,5)}" style="max-width:110px"><button class="btn btn-sm" onclick="contentMove(${id})">Tarihi Güncelle</button></div>`;
    if (contentManager) h += `<button class="btn btn-danger btn-sm mt-2" onclick="contentDelete(${id})">İçeriği Sil</button>`;
    h += `</div>`;
    document.getElementById('idBody').innerHTML = h;
    if (window.customPickerRefresh) customPickerRefresh();
    modalOpen('modalContentDetail');
}
async function contentStatusChange(id, status) { const j = await api('content_status', { id, status }); if (j.ok) toast('Durum güncellendi', 'success'); }
async function contentDelete(id) { if (confirm('İçerik silinsin mi?')) { const j = await api('content_delete', { id }); if (j.ok) location.reload(); } }
async function contentMove(id) {
    const tEl = document.getElementById('contentMoveDate'), sEl = document.getElementById('contentMoveTime');
    const j = await api('content_move', { id, date: tEl.dataset.value ?? tEl.value, time: sEl.dataset.value ?? sEl.value });
    if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 600); }
}
// Drag and drop: move content to another day
let surContent = null;
document.querySelectorAll('.calendar-event[data-content]').forEach(chip => {
    chip.addEventListener('dragstart', e => { surContent = chip.dataset.content; e.stopPropagation(); });
});
document.querySelectorAll('.calendar-cell[data-date]').forEach(cell => {
    cell.addEventListener('dragover', e => { if (surContent) { e.preventDefault(); cell.style.borderColor = 'var(--brand)'; } });
    cell.addEventListener('dragleave', () => cell.style.borderColor = '');
    cell.addEventListener('drop', async e => {
        e.preventDefault(); cell.style.borderColor = '';
        if (!surContent) return;
        const j = await api('content_move', { id: surContent, date: cell.dataset.date, time: '' });
        surContent = null;
        if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 500); }
    });
});
</script>
<?php page_end(); ?>

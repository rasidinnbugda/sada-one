<?php
/**
 * SADA One — Manager Tracking System
 * Task · owner · status · client + a separate note column for each manager/PM
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (!is_admin() && $u['role'] !== 'pm') { header('Location: index.php'); exit; }

$managers = rows("SELECT id, name FROM users WHERE role IN ('admin','pm') AND is_active=1 ORDER BY name");
$tasks = rows("SELECT g.id, g.title, g.status, g.due_date, p.name project_name, d.name client_name,
    uu.name assignee_name,
    (SELECT GROUP_CONCAT(u3.name SEPARATOR ', ') FROM task_assignees ga JOIN users u3 ON u3.id=ga.user_id WHERE ga.task_id=g.id) assignees
    FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id
    LEFT JOIN users uu ON uu.id=g.assignee_id
    WHERE g.is_archived=0 AND g.status!='cancelled' ORDER BY " . task_open_sql('g') . " DESC, g.due_date IS NULL, g.due_date");

// Fetch all notes in a single query: [task_id][user_id] => note
$notes = [];
foreach (rows("SELECT * FROM task_manager_notes") as $n) $notes[$n['task_id']][$n['user_id']] = $n['note'];

page_start('Yönetici Takip', 'manager_tracking');
?>
<div class="page-top">
    <div><div class="page-title">Yönetici Takip Sistemi</div><div class="page-bottom">Tüm işler tek tabloda — her yönetici kendi not kolonunu doldurur</div></div>
</div>

<div class="filter-bar">
    <div class="pill-filter" data-pill-group="#trackTable tbody tr">
        <button class="pill active" data-value="">Tümü</button>
        <?php foreach (TASK_STATUSES as $min => $dv): ?><button class="pill" data-value="<?= $min ?>"><?= $dv ?></button><?php endforeach; ?>
    </div>
    <div class="search-box"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="input" placeholder="İş ara..." data-search="#trackTable tbody tr"></div>
</div>

<div class="table-wrap"><table class="table" id="trackTable">
    <thead><tr>
        <th>İş</th><th>Sahibi</th><th>Durum</th><th>Dosya</th>
        <?php foreach ($managers as $y): ?><th><?= e(explode(' ', $y['name'])[0]) ?> Not</th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($tasks as $gr): ?>
    <tr data-filter="<?= $gr['status'] ?>">
        <td><a href="task.php?id=<?= $gr['id'] ?>" class="cell-main"><?= e($gr['title']) ?></a><div class="cell-bottom"><?= e($gr['project_name']) ?><?= $gr['due_date'] ? ' · ' . format_date($gr['due_date']) : '' ?></div></td>
        <td class="small"><?= e($gr['assignees'] ?: $gr['assignee_name'] ?: '—') ?></td>
        <td><?= badge($gr['status'], TASK_STATUSES) ?></td>
        <td class="small"><?= e($gr['client_name']) ?></td>
        <?php foreach ($managers as $y):
            $noteText = $notes[$gr['id']][$y['id']] ?? '';
            $mine = $y['id'] == $u['id']; ?>
        <td style="max-width:200px;min-width:140px">
            <?php if ($mine): ?>
            <div class="track-note <?= $noteText ? '' : 'empty' ?>" data-task="<?= $gr['id'] ?>" tabindex="0" title="Tıklayıp not yazın"><?= $noteText ? e($noteText) : '+ not ekle' ?></div>
            <?php else: ?>
            <div class="small text-2" style="white-space:pre-wrap"><?= $noteText ? e($noteText) : '<span class="text-muted">—</span>' ?></div>
            <?php endif; ?>
        </td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<style>
.track-note { font-size: 12.5px; padding: 7px 9px; border-radius: 8px; border: 1px dashed var(--border-2); cursor: text; white-space: pre-wrap; transition: border-color var(--transition); }
.track-note.empty { color: var(--muted); }
.track-note:hover, .track-note:focus { border-color: var(--brand); outline: none; }
</style>
<script>
document.querySelectorAll('.track-note').forEach(box => {
    box.addEventListener('click', () => {
        if (box.querySelector('textarea')) return;
        const current = box.classList.contains('empty') ? '' : box.textContent;
        box.innerHTML = '';
        const ta = document.createElement('textarea');
        ta.className = 'text-area'; ta.style.minHeight = '70px'; ta.style.fontSize = '12.5px';
        ta.value = current;
        box.appendChild(ta); ta.focus();
        const save = async () => {
            const j = await api('mnote_save', { task_id: box.dataset.task, note: ta.value.trim() });
            if (j.ok) {
                box.classList.toggle('empty', !ta.value.trim());
                box.textContent = ta.value.trim() || '+ not ekle';
                toast(j.message, 'success');
            }
        };
        ta.addEventListener('blur', save);
        ta.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) ta.blur(); });
    });
});
</script>
<?php page_end(); ?>

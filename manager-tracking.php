<?php
/**
 * SADA One — Manager Tracking System
 * Task · owner · status · client + a separate note column for each manager/PM
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (!is_admin() && $u['role'] !== 'pm') { header('Location: index.php'); exit; }

$managers = rows("SELECT id, name FROM users WHERE role IN ('yonetici','pm') AND is_active=1 ORDER BY name");
$tasks = rows("SELECT g.id, g.title, g.status, g.due_date, p.name project_name, d.name client_name,
    uu.name assignee_name,
    (SELECT GROUP_CONCAT(u3.name SEPARATOR ', ') FROM task_assignees ga JOIN users u3 ON u3.id=ga.user_id WHERE ga.task_id=g.id) assignees
    FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id
    LEFT JOIN users uu ON uu.id=g.assignee_id
    WHERE g.is_archived=0 ORDER BY g.status='tamamlandi', g.due_date IS NULL, g.due_date");

// Fetch all notes in a single query: [task_id][user_id] => note
$notes = [];
foreach (rows("SELECT * FROM task_manager_notes") as $n) $notes[$n['task_id']][$n['user_id']] = $n['note'];

page_start('Yönetici Takip', 'ytakip');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Yönetici Takip Sistemi</div><div class="sayfa-alt">Tüm görevler tek tabloda — her yönetici kendi not kolonunu doldurur</div></div>
</div>

<div class="filtre-bar">
    <div class="pill-filtre" data-pill-grup="#ytTablo tbody tr">
        <button class="pill aktif" data-setting_value="">Tümü</button>
        <?php foreach (TASK_STATUSES as $min => $dv): ?><button class="pill" data-setting_value="<?= $min ?>"><?= $dv ?></button><?php endforeach; ?>
    </div>
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="Görev ara..." data-search="#ytTablo tbody tr"></div>
</div>

<div class="tablo-sar"><table class="tablo" id="ytTable">
    <thead><tr>
        <th>Görev</th><th>Sahibi</th><th>Durum</th><th>Dosya</th>
        <?php foreach ($managers as $y): ?><th><?= e(explode(' ', $y['name'])[0]) ?> Not</th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($tasks as $gr): ?>
    <tr data-filter="<?= $gr['status'] ?>">
        <td><a href="task.php?id=<?= $gr['id'] ?>" class="hucre-ana"><?= e($gr['title']) ?></a><div class="hucre-alt"><?= e($gr['project_name']) ?><?= $gr['due_date'] ? ' · ' . format_date($gr['due_date']) : '' ?></div></td>
        <td class="kucuk"><?= e($gr['assignees'] ?: $gr['assignee_name'] ?: '—') ?></td>
        <td><?= badge($gr['status'], TASK_STATUSES) ?></td>
        <td class="kucuk"><?= e($gr['client_name']) ?></td>
        <?php foreach ($managers as $y):
            $notText = $notes[$gr['id']][$y['id']] ?? '';
            $mine = $y['id'] == $u['id']; ?>
        <td style="max-width:200px;min-width:140px">
            <?php if ($mine): ?>
            <div class="yt-not <?= $notText ? '' : 'bos' ?>" data-task="<?= $gr['id'] ?>" tabindex="0" title="Tıklayıp not yazın"><?= $notText ? e($notText) : '+ not ekle' ?></div>
            <?php else: ?>
            <div class="kucuk metin-2" style="white-space:pre-wrap"><?= $notText ? e($notText) : '<span class="metin-muted">—</span>' ?></div>
            <?php endif; ?>
        </td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<style>
.yt-not { font-size: 12.5px; padding: 7px 9px; border-radius: 8px; border: 1px dashed var(--border-2); cursor: text; white-space: pre-wrap; transition: border-color var(--gecis); }
.yt-not.bos { color: var(--muted); }
.yt-not:hover, .yt-not:focus { border-color: var(--marka); outline: none; }
</style>
<script>
document.querySelectorAll('.yt-not').forEach(box => {
    box.addEventListener('click', () => {
        if (box.querySelector('textarea')) return;
        const mevcut = box.classList.contains('bos') ? '' : box.textContent;
        box.innerHTML = '';
        const ta = document.createElement('textarea');
        ta.className = 'metin-alani'; ta.style.minHeight = '70px'; ta.style.fontSize = '12.5px';
        ta.value = mevcut;
        box.appendChild(ta); ta.focus();
        const save = async () => {
            const j = await api('mnote_save', { task_id: box.dataset.task, note: ta.value.trim() });
            if (j.ok) {
                box.classList.toggle('bos', !ta.value.trim());
                box.textContent = ta.value.trim() || '+ not ekle';
                toast(j.message, 'basari');
            }
        };
        ta.addEventListener('blur', save);
        ta.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) ta.blur(); });
    });
});
</script>
<?php page_end(); ?>

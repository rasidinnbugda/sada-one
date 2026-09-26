<?php
/**
 * SADA One — Tower (Kule)
 * The managers' view of the work, not of people: load per skill and pool pressure, work that is stuck, the health of
 * each client file and where every monthly project's month stands. The "Notlar" tab keeps the managers' notes table.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (!is_pm()) { header('Location: index.php'); exit; }
$tab = ($_GET['tab'] ?? '') === 'notes' ? 'notes' : 'state';
$openWork = "t.is_archived=0 AND " . task_open_sql('t');

// Load per skill: active steps, how many sit in the pool, how many people carry the skill
$skills = rows("SELECT k.id, k.name,
    (SELECT COUNT(*) FROM task_steps s JOIN tasks t ON t.id=s.task_id WHERE s.skill_id=k.id AND s.status='active' AND $openWork) active_steps,
    (SELECT COUNT(*) FROM task_steps s JOIN tasks t ON t.id=s.task_id WHERE s.skill_id=k.id AND s.status='active' AND s.owner_id IS NULL AND $openWork) pool,
    (SELECT COUNT(*) FROM user_skills us JOIN users u ON u.id=us.user_id WHERE us.skill_id=k.id AND u.is_active=1) holders
    FROM skills k ORDER BY k.sort_order, k.name");
$maxLoad = max(1, ...array_map(fn($k) => $k['holders'] ? $k['active_steps'] / $k['holders'] : $k['active_steps'], $skills ?: [['active_steps' => 0, 'holders' => 1]]));

// Stuck: steps waiting 3+ days, overdue work, client answers pending 3+ days, shoots not in Drive a day after
$stuckSteps = rows("SELECT s.name step_name, s.activated_at, s.owner_id, u.name owner_name, k.name skill_name, t.id task_id, t.title, c.name client_name
    FROM task_steps s JOIN tasks t ON t.id=s.task_id JOIN projects p ON p.id=t.project_id JOIN clients c ON c.id=p.client_id
    LEFT JOIN users u ON u.id=s.owner_id LEFT JOIN skills k ON k.id=s.skill_id
    WHERE s.status='active' AND s.kind!='client_approval' AND s.activated_at < DATE_SUB(NOW(), INTERVAL 3 DAY) AND $openWork
    ORDER BY s.activated_at LIMIT 30");
$overdue = rows("SELECT t.id task_id, t.title, t.due_date, c.name client_name, (SELECT s.name FROM task_steps s WHERE s.task_id=t.id AND s.status='active' LIMIT 1) step_name
    FROM tasks t JOIN projects p ON p.id=t.project_id JOIN clients c ON c.id=p.client_id
    WHERE $openWork AND t.due_date < CURDATE() ORDER BY t.due_date LIMIT 30");
$waitingApprovals = rows("SELECT o.id, o.title, o.created, o.task_id, o.period_id, c.name client_name FROM approvals o JOIN projects p ON p.id=o.project_id JOIN clients c ON c.id=p.client_id
    WHERE o.status='pending' AND o.created < DATE_SUB(NOW(), INTERVAL 3 DAY) ORDER BY o.created LIMIT 30");
$waitingApprovals = array_values(array_filter($waitingApprovals, fn($o) => approval_is_current(row("SELECT * FROM approvals WHERE id=?", [$o['id']]))));
$driveLate = rows("SELECT e.id, e.title, e.start FROM events e WHERE e.type='shoot' AND e.drive_status='pending'
    AND COALESCE(e.`end`, e.start) < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND e.start > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY e.start");

// Client health: open work, overdue, the oldest client answer we wait for, revision share of the last 60 days
$clients = rows("SELECT c.id, c.name,
    (SELECT COUNT(*) FROM tasks t JOIN projects p ON p.id=t.project_id WHERE p.client_id=c.id AND $openWork) open_work,
    (SELECT COUNT(*) FROM tasks t JOIN projects p ON p.id=t.project_id WHERE p.client_id=c.id AND $openWork AND t.due_date < CURDATE()) overdue,
    (SELECT MIN(o.created) FROM approvals o JOIN projects p ON p.id=o.project_id WHERE p.client_id=c.id AND o.status='pending') oldest_pending,
    (SELECT COUNT(*) FROM approvals o JOIN projects p ON p.id=o.project_id WHERE p.client_id=c.id AND o.status!='pending' AND o.reply_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)) answered,
    (SELECT COUNT(*) FROM approvals o JOIN projects p ON p.id=o.project_id WHERE p.client_id=c.id AND o.status IN ('revision','rejected') AND o.reply_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)) revised
    FROM clients c WHERE c.status='active' ORDER BY c.name");
foreach ($clients as &$c) {
    $c['wait_days'] = $c['oldest_pending'] ? (int)floor((time() - strtotime($c['oldest_pending'])) / 86400) : 0;
    $c['revision_rate'] = $c['answered'] >= 4 ? $c['revised'] / $c['answered'] : null;
    $c['health'] = ($c['overdue'] >= 3 || $c['wait_days'] > 5 || ($c['revision_rate'] ?? 0) > .5) ? 'red'
        : (($c['overdue'] >= 1 || $c['wait_days'] > 3 || ($c['revision_rate'] ?? 0) > .3) ? 'amber' : 'green');
}
unset($c);
usort($clients, fn($a, $b) => [array_search($a['health'], ['red', 'amber', 'green']), $a['name']] <=> [array_search($b['health'], ['red', 'amber', 'green']), $b['name']]);

// Months: where each active monthly project's current month stands
$months = rows("SELECT p.id project_id, p.name project_name, c.name client_name, d.id period_id, d.phase, d.plan_status,
    (SELECT COUNT(*) FROM tasks t WHERE t.period_id=d.id AND t.is_archived=0 AND t.status!='cancelled') work,
    (SELECT COUNT(*) FROM tasks t WHERE t.period_id=d.id AND t.is_archived=0 AND " . task_done_sql('t') . ") done
    FROM projects p JOIN clients c ON c.id=p.client_id LEFT JOIN periods d ON d.project_id=p.id AND d.year=? AND d.month=?
    WHERE p.type='monthly' AND p.status='active' ORDER BY c.name, p.name", [(int)date('Y'), (int)date('n')]);
$unclosed = (int)val("SELECT COUNT(*) FROM periods d JOIN projects p ON p.id=d.project_id WHERE p.status='active' AND d.phase!='closed' AND d.year*12+d.month < ?", [(int)date('Y') * 12 + (int)date('n')]);

page_start('Kule', 'tower');
$days = fn(?string $since) => $since ? max(0, (int)floor((time() - strtotime($since)) / 86400)) : 0;
?>
<div class="page-top">
    <div><div class="page-title">Kule</div><div class="page-bottom">İşin durumu: uzmanlık yükü, takılanlar, dosyaların sağlığı ve ayların hali</div></div>
</div>
<div class="tabs">
    <a class="tab <?= $tab === 'state' ? 'active' : '' ?>" href="tower.php">Durum</a>
    <a class="tab <?= $tab === 'notes' ? 'active' : '' ?>" href="tower.php?tab=notes">Yönetici notları</a>
</div>

<?php if ($tab === 'state'): ?>
<div class="tower-grid">
    <div class="card">
        <div class="card-title mb-2" style="font-size:15px">Uzmanlık yükü</div>
        <div class="cell-bottom mb-2">Aktif adım / uzmanlığı taşıyan kişi. Havuz: sahipsiz bekleyen.</div>
        <?php foreach ($skills as $k): $perPerson = $k['holders'] ? $k['active_steps'] / $k['holders'] : $k['active_steps']; ?>
        <div class="tower-skill">
            <div class="row-flex between"><span class="small bold"><?= e($k['name']) ?></span><span class="cell-bottom"><?= $k['active_steps'] ?> adım · <?= $k['holders'] ?> kişi<?= $k['pool'] ? ' · <b style="color:var(--warning)">havuzda ' . $k['pool'] . '</b>' : '' ?></span></div>
            <div class="progress mt-1"><div class="progress-full" data-rate="<?= round($perPerson / $maxLoad * 100) ?>" style="width:0<?= !$k['holders'] && $k['active_steps'] ? ';background:var(--danger)' : '' ?>"></div></div>
            <?php if (!$k['holders'] && $k['active_steps']): ?><div class="cell-bottom mt-1" style="color:var(--danger)">Bu uzmanlığı taşıyan kimse yok.</div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-title mb-2" style="font-size:15px">Takılanlar</div>
        <?php if (!$stuckSteps && !$overdue && !$waitingApprovals && !$driveLate): ?><div class="text-muted small">Takılan bir şey yok. 🎉</div><?php endif; ?>
        <?php if ($stuckSteps): ?><div class="cell-bottom mb-1">3 gündür aynı adımda</div>
        <?php foreach ($stuckSteps as $s): ?>
        <a class="tower-row" href="task.php?id=<?= $s['task_id'] ?>"><span class="cell-main"><?= e($s['step_name']) ?> — <?= e($s['title']) ?></span><span class="cell-bottom"><?= e($s['client_name']) ?> · <?= $s['owner_name'] ? e($s['owner_name']) : e($s['skill_name'] ?? '') . ' havuzu' ?> · <?= $days($s['activated_at']) ?> gün</span></a>
        <?php endforeach; endif; ?>
        <?php if ($overdue): ?><div class="cell-bottom mb-1 mt-2">Son tarihi geçen</div>
        <?php foreach ($overdue as $o): ?>
        <a class="tower-row" href="task.php?id=<?= $o['task_id'] ?>"><span class="cell-main"><?= e($o['title']) ?></span><span class="cell-bottom"><?= e($o['client_name']) ?><?= $o['step_name'] ? ' · ' . e($o['step_name']) : '' ?> · <span style="color:var(--danger)"><?= format_date($o['due_date']) ?></span></span></a>
        <?php endforeach; endif; ?>
        <?php if ($waitingApprovals): ?><div class="cell-bottom mb-1 mt-2">3 gündür müşteride</div>
        <?php foreach ($waitingApprovals as $o): ?>
        <a class="tower-row" href="<?= $o['task_id'] ? 'task.php?id=' . $o['task_id'] : ($o['period_id'] ? 'month.php?id=' . $o['period_id'] : 'approvals.php') ?>"><span class="cell-main"><?= e($o['title']) ?></span><span class="cell-bottom"><?= e($o['client_name']) ?> · <?= $days($o['created']) ?> gün</span></a>
        <?php endforeach; endif; ?>
        <?php if ($driveLate): ?><div class="cell-bottom mb-1 mt-2">Drive'a aktarılmamış çekim</div>
        <?php foreach ($driveLate as $e): ?>
        <a class="tower-row" href="shoot-list.php"><span class="cell-main"><?= e($e['title']) ?></span><span class="cell-bottom"><?= format_date($e['start'], true) ?></span></a>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-title mb-2" style="font-size:15px">Dosyaların sağlığı</div>
    <div class="cell-bottom mb-2">Kırmızı: 3+ geciken iş, 5 günden uzun bekleyen onay ya da son 60 günde cevapların yarısından fazlası revize. Sarı: daha hafifi.</div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Dosya</th><th>Açık iş</th><th>Geciken</th><th>Bekleyen onay</th><th>Revize oranı (60 gün)</th></tr></thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
        <tr>
            <td><span class="health-dot is-<?= $c['health'] ?>"></span><a href="client.php?id=<?= $c['id'] ?>" class="cell-main"><?= e($c['name']) ?></a></td>
            <td class="small"><?= $c['open_work'] ?></td>
            <td class="small" style="<?= $c['overdue'] ? 'color:var(--danger);font-weight:600' : '' ?>"><?= $c['overdue'] ?></td>
            <td class="small"><?= $c['oldest_pending'] ? ($c['wait_days'] ? $c['wait_days'] . ' gündür' : 'bugün gönderildi') : '—' ?></td>
            <td class="small"><?= $c['revision_rate'] === null ? '<span class="text-muted">az cevap</span>' : '%' . round($c['revision_rate'] * 100) . ' (' . $c['revised'] . '/' . $c['answered'] . ')' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="card mt-3">
    <div class="row-flex between mb-2"><div class="card-title" style="font-size:15px"><?= MONTHS[(int)date('n')] ?> ayı</div><?php if ($unclosed): ?><span class="badge r-pending"><?= $unclosed ?> geçmiş ay kapanmadı</span><?php endif; ?></div>
    <?php if (!$months): ?><div class="text-muted small">Aktif aylık proje yok.</div>
    <?php else: ?>
    <div class="grid grid-3">
        <?php foreach ($months as $m): $rate = $m['work'] ? round($m['done'] / $m['work'] * 100) : 0; ?>
        <a href="<?= $m['period_id'] ? 'month.php?id=' . $m['period_id'] : 'project.php?id=' . $m['project_id'] . '#periods' ?>" class="card card-tick" style="padding:14px">
            <div class="row-flex between"><span class="small bold"><?= e($m['client_name']) ?></span><?= $m['period_id'] ? badge($m['phase'], MONTH_PHASES) : '<span class="badge r-overdue">açılmadı</span>' ?></div>
            <div class="cell-bottom mt-1"><?= e($m['project_name']) ?></div>
            <?php if ($m['period_id']): ?>
            <div class="progress mt-2"><div class="progress-full" data-rate="<?= $rate ?>" style="width:0"></div></div>
            <div class="cell-bottom mt-1"><?= $m['done'] ?>/<?= $m['work'] ?> iş<?= $m['plan_status'] !== 'none' ? ' · Plan: ' . PLAN_STATUSES[$m['plan_status']] : '' ?></div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php else:
    // The managers' notes table (formerly "Yönetici Takip"): open work, one note column per manager
    $managers = rows("SELECT id, name FROM users WHERE role IN ('admin','pm') AND is_active=1 ORDER BY name");
    $tasks = rows("SELECT t.id, t.title, t.status, t.due_date, p.name project_name, c.name client_name, uu.name assignee_name,
        (SELECT GROUP_CONCAT(u3.name SEPARATOR ', ') FROM task_assignees ga JOIN users u3 ON u3.id=ga.user_id WHERE ga.task_id=t.id) assignees
        FROM tasks t JOIN projects p ON p.id=t.project_id JOIN clients c ON c.id=p.client_id LEFT JOIN users uu ON uu.id=t.assignee_id
        WHERE $openWork ORDER BY t.due_date IS NULL, t.due_date");
    $notes = [];
    foreach (rows("SELECT * FROM task_manager_notes") as $n) $notes[$n['task_id']][$n['user_id']] = $n['note'];
?>
<div class="filter-bar">
    <div class="search-box"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="input" placeholder="İş ara..." data-search="#trackTable tbody tr"></div>
</div>
<div class="table-wrap"><table class="table" id="trackTable">
    <thead><tr><th>İş</th><th>Sahibi</th><th>Durum</th><th>Dosya</th><?php foreach ($managers as $y): ?><th><?= e(explode(' ', $y['name'])[0]) ?> Not</th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($tasks as $gr): ?>
    <tr>
        <td><a href="task.php?id=<?= $gr['id'] ?>" class="cell-main"><?= e($gr['title']) ?></a><div class="cell-bottom"><?= e($gr['project_name']) ?><?= $gr['due_date'] ? ' · ' . format_date($gr['due_date']) : '' ?></div></td>
        <td class="small"><?= e($gr['assignees'] ?: $gr['assignee_name'] ?: '—') ?></td>
        <td><?= badge($gr['status'], TASK_STATUSES) ?></td>
        <td class="small"><?= e($gr['client_name']) ?></td>
        <?php foreach ($managers as $y): $noteText = $notes[$gr['id']][$y['id']] ?? ''; ?>
        <td style="max-width:200px;min-width:140px">
            <?php if ((int)$y['id'] === (int)$u['id']): ?>
            <div class="track-note <?= $noteText ? '' : 'empty' ?>" data-task="<?= $gr['id'] ?>" tabindex="0" title="Tıklayıp not yazın"><?= $noteText ? e($noteText) : '+ not ekle' ?></div>
            <?php else: ?><div class="small text-2" style="white-space:pre-wrap"><?= $noteText ? e($noteText) : '<span class="text-muted">—</span>' ?></div><?php endif; ?>
        </td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
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
        ta.addEventListener('blur', async () => {
            const j = await api('mnote_save', { task_id: box.dataset.task, note: ta.value.trim() });
            if (!j.ok) return;
            box.classList.toggle('empty', !ta.value.trim());
            box.textContent = ta.value.trim() || '+ not ekle';
            toast(j.message, 'success');
        });
        ta.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) ta.blur(); });
    });
});
</script>
<?php endif; ?>
<?php page_end(); ?>

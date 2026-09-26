<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

// Widget personalization: sections chosen by the user (all enabled by default)
const PANEL_WIDGETS = ['announcements' => 'Duyurular', 'upcoming' => 'Yaklaşanlar (7 gün)', 'stats' => 'İstatistik kartları', 'team' => 'Ekip durumu', 'my_tasks' => 'İşlerim', 'activity' => 'Son hareketler', 'warnings' => 'Uyarılar (geciken/talep)'];
$openWidgets = json_decode($u['widgets'] ?? '', true);
if (!is_array($openWidgets)) $openWidgets = array_keys(PANEL_WIDGETS);
$wOpen = fn($k) => in_array($k, $openWidgets);

page_start('Panel', 'panel');

/* ---------- Monthly-report duty warning (window: last 3 / first 4 days) ---------- */
$reportWarnings = [];
if (is_staff()) {
    $day = (int)date('j'); $lastDay = (int)date('t');
    $warnPeriod = $day >= $lastDay - 2 ? date('Y-m') : ($day <= 4 ? date('Y-m', strtotime('first day of last month')) : null);
    if ($warnPeriod) {
        $reportWarnings = rows("SELECT c.id, c.name, r.status FROM clients c
            LEFT JOIN monthly_reports r ON r.client_id=c.id AND r.period=?
            WHERE c.status='active' AND c.manager_id=? AND (r.id IS NULL OR r.status='draft')", [$warnPeriod, $u['id']]);
    }
}
if ($reportWarnings): ?>
<div class="card mb-3" style="border-color:var(--danger);background:linear-gradient(135deg,var(--surface),rgba(240,79,79,.06))">
    <div class="card-title mb-1" style="font-size:15px">📊 Aylık rapor sırası sende</div>
    <div class="small text-2 mb-2"><b><?= e($warnPeriod) ?></b> dönemi için sorumlusu olduğun şu dosyaların raporu bekliyor:</div>
    <div class="row-flex wrap" style="gap:8px">
        <?php foreach ($reportWarnings as $rw): ?>
        <a href="monthly-reports.php?client=<?= $rw['id'] ?>&period=<?= $warnPeriod ?>" class="btn btn-sm"><?= e($rw['name']) ?><?= $rw['status'] === 'draft' ? ' (taslak)' : '' ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif;

/* ---------- Release notes (dismissible) ---------- */
if (is_admin() && ($u['seen_version'] ?? '') !== APP_VERSION && isset(VERSION_NOTES[APP_VERSION])): ?>
<div class="card mb-3" id="versionCard" style="border-color:var(--brand);background:linear-gradient(135deg,var(--surface),var(--bright))">
    <div class="row-flex between" style="align-items:flex-start;gap:12px">
        <div>
            <div class="card-title"><?= icon('rocket', 17) ?> Yenilikler — sürüm <?= APP_VERSION ?></div>
            <ul class="small text-2 mt-2" style="list-style:none;display:flex;flex-direction:column;gap:6px">
                <?php foreach (VERSION_NOTES[APP_VERSION] as $noteRow): ?><li><?= $noteRow ?></li><?php endforeach; ?>
            </ul>
        </div>
        <button class="btn btn-sm" onclick="versionClose()" style="flex-shrink:0">Kapat ✕</button>
    </div>
</div>
<script>
async function versionClose() {
    const j = await api('version_close');
    if (j.ok) document.getElementById('versionCard').remove();
}
</script>
<?php endif;

if (is_staff()) {
    /* ---------- TEAM DASHBOARD ---------- */
    $clientCount = (int)val("SELECT COUNT(*) FROM clients WHERE status='active'");
    $projectCount = (int)val("SELECT COUNT(*) FROM projects WHERE status='active'");
    $mineTask = (int)val("SELECT COUNT(*) FROM tasks WHERE assignee_id=? AND is_archived=0 AND " . task_open_sql(), [$u['id']]);
    $pendingApproval = (int)val("SELECT COUNT(*) FROM approvals WHERE status='pending'");
    $overdue = (int)val("SELECT COUNT(*) FROM tasks WHERE due_date<CURDATE() AND is_archived=0 AND " . task_open_sql());
    $newRequest = (int)val("SELECT COUNT(*) FROM requests WHERE status='new'");
?>
<div class="page-top">
    <div>
        <div class="page-title">Merhaba, <?= e(explode(' ', $u['name'])[0]) ?> 👋</div>
        <div class="page-bottom"><?= DAYS[(int)date('N') - 1] ?>, <?= format_date(date('Y-m-d')) ?> — bugünün özeti</div>
    </div>
    <div class="page-top-action">
        <button class="btn btn-ghost" data-modal="modalWidget" title="Panel görünümünü kişiselleştir">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h7"/></svg> Paneli Düzenle
        </button>
        <?php if (permission('client_manage')): ?>
        <button class="btn btn-brand" data-modal="modalProject">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Proje
        </button>
        <?php endif; ?>
    </div>
</div>

<?php
// Unread announcements
$announcements = rows("SELECT d.*, us.name creator_name FROM announcements d LEFT JOIN users us ON us.id=d.created_by
    WHERE NOT EXISTS(SELECT 1 FROM announcement_readers o WHERE o.announcement_id=d.id AND o.user_id=?)
    ORDER BY d.is_important DESC, d.id DESC LIMIT 3", [$u['id']]);
if ($announcements && $wOpen('announcements')): ?>
<div class="vertical mb-3" style="gap:10px">
    <?php foreach ($announcements as $dy): ?>
    <div class="card row-flex between wrap" style="gap:12px;border-color:<?= $dy['is_important'] ? 'var(--warning)' : 'var(--border-2)' ?>;<?= $dy['is_important'] ? 'background:linear-gradient(135deg,var(--surface),rgba(245,165,36,.06))' : '' ?>">
        <div class="row-flex" style="gap:12px;min-width:0">
            <span class="file-avatar" style="width:38px;height:38px;background:var(--bright);color:<?= $dy['is_important'] ? 'var(--warning)' : 'var(--brand)' ?>;flex-shrink:0"><?= icon($dy['is_important'] ? 'megaphone' : 'pin', 18) ?></span>
            <div style="min-width:0">
                <div class="bold"><?= e($dy['title']) ?></div>
                <?php if ($dy['text']): ?><div class="small text-2 mt-1"><?= nl2br(e(mb_substr($dy['text'], 0, 220))) ?></div><?php endif; ?>
                <div class="cell-bottom mt-1"><?= e($dy['creator_name']) ?> · <?= time_ago($dy['created']) ?></div>
            </div>
        </div>
        <button class="btn btn-sm" data-action="announcement_read" data-id="<?= $dy['id'] ?>">Okudum ✓</button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="stat-grid <?= $wOpen('stats') ? '' : 'widget-collapsed' ?>">
    <?php
    $stats = [
        ['value' => $clientCount, 'label' => 'Aktif Dosya', 'icon' => 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z', 'link' => 'clients.php'],
        ['value' => $projectCount, 'label' => 'Aktif Proje', 'icon' => 'M9 12h6m-6 4h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z', 'link' => 'projects.php'],
        ['value' => $mineTask, 'label' => 'Bekleyen İşlerim', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2', 'link' => 'tasks.php'],
        ['value' => $pendingApproval, 'label' => 'Bekleyen Onay', 'icon' => 'M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'link' => 'approvals.php'],
    ];
    foreach ($stats as $s): ?>
    <a href="<?= $s['link'] ?>" class="stat-card">
        <div class="stat-icon"><svg fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="<?= $s['icon'] ?>"/></svg></div>
        <div class="stat-value" data-counter="<?= $s['value'] ?>">0</div>
        <div class="stat-label"><?= $s['label'] ?></div>
    </a>
    <?php endforeach; ?>
</div>

<div class="grid grid-2">
    <!-- Tasks assigned to me -->
    <div class="card <?= $wOpen('my_tasks') ? '' : 'widget-collapsed' ?>">
        <div class="card-top">
            <div class="card-title">
                <svg width="18" fill="none" stroke="var(--brand)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                İşlerim
            </div>
            <a href="tasks.php" class="mini-btn">Tümü →</a>
        </div>
        <?php
        $my_tasks = rows("SELECT g.*, p.name project_name FROM tasks g JOIN projects p ON p.id=g.project_id WHERE g.assignee_id=? AND " . task_open_sql('g') . " AND g.is_archived=0 ORDER BY g.due_date IS NULL, g.due_date ASC LIMIT 6", [$u['id']]);
        $pStepCondition = only_own_steps() ? "ga.owner_id=?" : "(ga.owner_id=? OR (ga.owner_id IS NULL AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees gat WHERE gat.task_id=g.id AND gat.user_id=?))))";
        $pStepParam = only_own_steps() ? [$u['id']] : [$u['id'], $u['id'], $u['id']];
        $panelSteps = rows("SELECT ga.name step_name, g.id gid, g.title FROM task_steps ga JOIN tasks g ON g.id=ga.task_id WHERE ga.status='active' AND g.is_archived=0 AND $pStepCondition LIMIT 4", $pStepParam);
        if ($panelSteps): ?>
        <div class="collapse closed" data-collapse="panelSteps" style="border-bottom:1px solid var(--border)">
            <button data-collapse-btn type="button" class="row-flex" style="gap:8px;padding:10px 0;width:100%">
                <span class="collapse-arrow"><svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" width="12"><path d="M19 9l-7 7-7-7"/></svg></span>
                <span class="small bold"><?= icon('rocket', 13) ?> Adımlarım</span>
                <span class="badge r-in_progress" style="padding:1px 8px"><?= count($panelSteps) ?> sıra sende</span>
            </button>
            <div class="collapse-content">
        <?php foreach ($panelSteps as $pa): ?>
        <a href="task.php?id=<?= $pa['gid'] ?>" class="row-flex between" style="padding:11px 0;border-bottom:1px solid var(--border)">
            <div style="min-width:0"><div class="cell-main" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= icon('rocket', 12) ?> <?= e($pa['step_name']) ?></div><div class="cell-bottom"><?= e($pa['title']) ?> · akış adımı</div></div>
            <span class="badge r-in_progress">Sıra sende</span>
        </a>
        <?php endforeach; ?>
            </div>
        </div>
        <?php endif;
        if (!$my_tasks && !$panelSteps): ?>
            <div class="text-muted small" style="padding:20px 0;text-align:center">Bekleyen işin yok 🎉</div>
        <?php else: foreach ($my_tasks as $gr):
            $overdue = $gr['due_date'] && $gr['due_date'] < date('Y-m-d'); ?>
        <a href="task.php?id=<?= $gr['id'] ?>" class="row-flex between" style="padding:11px 0;border-bottom:1px solid var(--border)">
            <div style="min-width:0">
                <div class="cell-main" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($gr['title']) ?></div>
                <div class="cell-bottom"><?= e($gr['project_name']) ?><?php if ($gr['due_date']): ?> · <span style="color:<?= $overdue ? 'var(--danger)' : 'inherit' ?>"><?= format_date($gr['due_date']) ?></span><?php endif; ?></div>
            </div>
            <?= badge($gr['status'], TASK_STATUSES) ?>
        </a>
        <?php endforeach; endif; ?>
    </div>

    <!-- Recent activities -->
    <div class="card <?= $wOpen('activity') ? '' : 'widget-collapsed' ?>">
        <div class="card-top">
            <div class="card-title">
                <svg width="18" fill="none" stroke="var(--brand)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Son Hareketler
            </div>
        </div>
        <?php
        $activities = rows("SELECT a.*, u.name, u.color FROM activities a JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 8");
        if (!$activities): ?>
            <div class="text-muted small" style="padding:20px 0;text-align:center">Henüz hareket yok</div>
        <?php else: ?>
        <div class="activity-feed" style="margin-top:4px">
            <?php foreach ($activities as $a): ?>
            <div class="feed-item">
                <div class="feed-text"><b><?= e($a['name']) ?></b> <?= e($a['description']) ?></div>
                <div class="feed-time"><?= time_ago($a['created']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($wOpen('upcoming')):
    // Items for the next 7 days: my work + my meetings + events + planned publishing
    $today = date('Y-m-d'); $sevenDays = date('Y-m-d', strtotime('+7 days'));
    $upcoming = [];
    foreach (rows("SELECT g.id, g.title, g.due_date date FROM tasks g WHERE g.is_archived=0 AND " . task_open_sql('g') . " AND g.due_date BETWEEN ? AND ?
        AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees ga WHERE ga.task_id=g.id AND ga.user_id=?)) ORDER BY g.due_date LIMIT 8", [$today, $sevenDays, $u['id'], $u['id']]) as $r)
        $upcoming[] = ['date' => $r['date'], 'time' => null, 'icon' => icon('approval', 15), 'text' => $r['title'], 'bottom' => 'İş teslimi', 'link' => 'task.php?id=' . $r['id']];
    foreach (rows("SELECT e.id, e.title, DATE(e.start) date, TIME(e.start) time, e.online_link FROM events e WHERE e.type='meeting' AND DATE(e.start) BETWEEN ? AND ?
        AND (e.created_by=? OR EXISTS(SELECT 1 FROM event_participants ek WHERE ek.event_id=e.id AND ek.user_id=?)) ORDER BY e.start LIMIT 8", [$today, $sevenDays, $u['id'], $u['id']]) as $r)
        $upcoming[] = ['date' => $r['date'], 'time' => substr($r['time'], 0, 5), 'icon' => icon('people', 15), 'text' => $r['title'], 'bottom' => 'Toplantı' . ($r['online_link'] ? ' (online)' : ''), 'link' => 'meetings.php'];
    foreach (rows("SELECT id, title, DATE(start) date, TIME(start) time, type FROM events WHERE type!='meeting' AND DATE(start) BETWEEN ? AND ? ORDER BY start LIMIT 6", [$today, $sevenDays]) as $r)
        $upcoming[] = ['date' => $r['date'], 'time' => substr($r['time'], 0, 5), 'icon' => icon('video', 15), 'text' => $r['title'], 'bottom' => EVENT_TYPES[$r['type']], 'link' => 'calendar.php'];
    foreach (rows("SELECT id, title, publish_date date, publish_time time, platforms FROM tasks WHERE kind='client' AND is_archived=0 AND publish_date BETWEEN ? AND ? AND " . task_open_sql() . " ORDER BY publish_date LIMIT 6", [$today, $sevenDays]) as $r)
        $upcoming[] = ['date' => $r['date'], 'time' => $r['time'] ? substr($r['time'], 0, 5) : null, 'icon' => icon('calendar', 15), 'text' => $r['title'], 'bottom' => trim(implode(', ', array_map(fn($pl) => PLATFORMS[$pl] ?? $pl, array_filter(explode(',', (string)$r['platforms'])))) . ' yayını'), 'link' => 'content-calendar.php'];
    usort($upcoming, fn($a, $b) => strcmp($a['date'] . ($a['time'] ?? '99'), $b['date'] . ($b['time'] ?? '99')));
    $upcoming = array_slice($upcoming, 0, 10);
    if ($upcoming): ?>
<div class="card mt-2">
    <div class="card-top">
        <div class="card-title"><svg width="18" fill="none" stroke="var(--brand)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Yaklaşanlar — önümüzdeki 7 gün</div>
    </div>
    <div class="vertical" style="gap:4px">
        <?php $lastDate = '';
        foreach ($upcoming as $y):
            $dayTag = $y['date'] === date('Y-m-d') ? 'Bugün' : ($y['date'] === date('Y-m-d', strtotime('+1 day')) ? 'Yarın' : DAYS[(int)date('N', strtotime($y['date'])) - 1] . ' ' . date('j', strtotime($y['date']))); ?>
        <a href="<?= $y['link'] ?>" class="row-flex" style="gap:11px;padding:8px 10px;border-radius:10px;transition:background .2s" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
            <span class="badge <?= $y['date'] === date('Y-m-d') ? 'badge-type' : '' ?>" style="min-width:76px;justify-content:center"><?= $dayTag ?><?= $y['time'] ? ' ' . $y['time'] : '' ?></span>
            <span style="color:var(--brand);display:inline-flex"><?= $y['icon'] ?></span>
            <span class="small bold" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($y['text']) ?></span>
            <span class="cell-bottom" style="margin-left:auto;flex-shrink:0"><?= $y['bottom'] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; endif; ?>

<?php if ($wOpen('team')):
    $teamStatus = rows("SELECT us.id, us.name, us.color, us.avatar,
        (SELECT g.title FROM tasks g WHERE g.is_archived=0 AND g.status='in_progress' AND (g.assignee_id=us.id OR EXISTS(SELECT 1 FROM task_assignees ga WHERE ga.task_id=g.id AND ga.user_id=us.id)) ORDER BY g.id DESC LIMIT 1) is_active_is
        FROM users us WHERE us.role IN ('admin','pm','team','finance') AND us.is_active=1 ORDER BY us.name LIMIT 10"); ?>
<div class="card mt-2">
    <div class="card-top">
        <div class="card-title"><svg width="18" fill="none" stroke="var(--brand)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg> Ekip Şu An</div>
        <a href="team.php" class="mini-btn">Ekip Panosu →</a>
    </div>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px">
        <?php foreach ($teamStatus as $ed): ?>
        <div class="row-flex" style="gap:9px;padding:8px 10px;background:var(--surface-2);border-radius:10px;min-width:0">
            <?= avatar($ed, 30) ?>
            <div style="min-width:0">
                <div class="small bold"><?= e(explode(' ', $ed['name'])[0]) ?></div>
                <div class="cell-bottom" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $ed['is_active_is'] ? '● ' . e($ed['is_active_is']) : '○ Boşta' ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (($overdue || $newRequest) && $wOpen('warnings')): ?>
<div class="grid grid-2 mt-2">
    <?php if ($overdue): ?>
    <div class="card" style="border-color:rgba(240,79,79,.3)">
        <div class="row-flex between">
            <div class="row-flex">
                <div class="stat-icon" style="margin:0;background:rgba(240,79,79,.14);color:var(--danger);width:38px;height:38px"><svg width="20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/></svg></div>
                <div><div class="bold"><?= $overdue ?> geciken iş</div><div class="cell-bottom">Son tarihi geçmiş, tamamlanmamış</div></div>
            </div>
            <a href="tasks.php?filter=overdue" class="btn btn-sm">Görüntüle</a>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($newRequest && is_pm()): ?>
    <div class="card" style="border-color:var(--border-2)">
        <div class="row-flex between">
            <div class="row-flex">
                <div class="stat-icon" style="margin:0;width:38px;height:38px"><svg width="20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M8 10h8m-8 4h4m9-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                <div><div class="bold"><?= $newRequest ?> yeni talep</div><div class="cell-bottom">Müşterilerden gelen istekler</div></div>
            </div>
            <a href="requests.php" class="btn btn-sm">İncele</a>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Widget personalization modal -->
<div class="modal-overlay" id="modalWidget">
    <div class="modal"><div class="modal-top"><div class="modal-title">Paneli Düzenle</div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body">
        <div class="cell-bottom mb-3">Panelde görmek istediğiniz bölümleri seçin.</div>
        <div class="vertical" style="gap:10px">
            <?php foreach (PANEL_WIDGETS as $wk => $wLabel): ?>
            <label class="row-flex between" style="padding:11px 14px;background:var(--surface-2);border-radius:11px;cursor:pointer">
                <span class="small"><?= $wLabel ?></span>
                <span class="key"><input type="checkbox" class="widget-box" value="<?= $wk ?>" <?= $wOpen($wk) ? 'checked' : '' ?>></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="button" class="btn btn-brand" onclick="widgetSave()">Kaydet</button></div>
    </div>
</div>
<script>
async function widgetSave() {
    const selected = Array.from(document.querySelectorAll('.widget-box:checked')).map(c => c.value);
    const j = await api('widget_save', { widgets: selected });
    if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 550); }
}
</script>

<?php
    // Project creation modal for PMs (required data)
    if (permission('client_manage')) project_modal();

} else {
    /* ---------- CLIENT DASHBOARD ---------- */
    [$in, $p] = in_clause(customer_client_ids());
    $projects = rows("SELECT p2.*, d.name client_name FROM projects p2 JOIN clients d ON d.id=p2.client_id WHERE p2.client_id IN $in ORDER BY d.name, p2.created DESC", $p);
    $pendingApproval = (int)val("SELECT COUNT(*) FROM approvals o JOIN projects pr ON pr.id=o.project_id WHERE pr.client_id IN $in AND o.status='pending'", $p);
    $clientCount = count(customer_client_ids());
    $client = row("SELECT * FROM clients WHERE id=?", [$u['client_id']]);
?>
<div class="page-top">
    <div>
        <div class="page-title">Merhaba, <?= e(explode(' ', $u['name'])[0]) ?> 👋</div>
        <div class="page-bottom"><?= $clientCount > 1 ? $clientCount . ' dosyanız — proje durumunuz' : e($client['name'] ?? '') . ' — proje durumunuz' ?></div>
    </div>
    <div class="page-top-action">
        <a href="requests.php" class="btn btn-brand">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Talep Oluştur
        </a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-icon"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg></div><div class="stat-value" data-counter="<?= count($projects) ?>">0</div><div class="stat-label">Projeleriniz</div></div>
    <a href="approvals.php" class="stat-card"><div class="stat-icon"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="stat-value" data-counter="<?= $pendingApproval ?>">0</div><div class="stat-label">Onayınızı bekleyen</div></a>
    <a href="messages.php" class="stat-card"><div class="stat-icon"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M8 12h8m-8-4h8m-9 8l-4 4V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H7z"/></svg></div><div class="stat-value" style="color:var(--brand)"><?= icon('chat', 28) ?></div><div class="stat-label">Ekiple mesajlaşın</div></a>
</div>

<div class="card">
    <div class="card-top"><div class="card-title">Projeleriniz</div></div>
    <?php if (!$projects): ?>
        <div class="text-muted small orta" style="padding:24px">Henüz projeniz bulunmuyor.</div>
    <?php else: ?>
    <div class="grid grid-auto">
        <?php foreach ($projects as $p):
            $progress = (int)val("SELECT COUNT(*) FROM tasks WHERE project_id=? AND kind='client' AND status!='cancelled'", [$p['id']]);
            $is_done = (int)val("SELECT COUNT(*) FROM tasks WHERE project_id=? AND kind='client' AND " . task_done_sql(), [$p['id']]);
            $rate = $progress ? round($is_done / $progress * 100) : 0; ?>
        <a href="project.php?id=<?= $p['id'] ?>" class="card card-tick" style="padding:16px">
            <div class="row-flex between mb-2">
                <span class="badge badge-type"><?= PROJECT_TYPES[$p['type']] ?></span>
                <?= badge($p['status'], PROJECT_STATUSES) ?>
            </div>
            <div class="card-title" style="font-size:15px"><?= e($p['name']) ?></div>
            <div class="progress mt-2"><div class="progress-full" data-rate="<?= $rate ?>" style="width:0"></div></div>
            <div class="cell-bottom mt-1"><?= $is_done ?>/<?= $progress ?> iş tamamlandı</div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php
}

page_end();

/* ---------- Reusable project modal ---------- */
function project_modal(?int $clientId = null) {
    $clients = rows("SELECT id, name FROM clients WHERE status='active' ORDER BY name");
    $pms = rows("SELECT id, name FROM users WHERE role IN ('admin','pm') AND is_active=1 ORDER BY name");
?>
<div class="modal-overlay" id="modalProject">
    <div class="modal">
        <div class="modal-top"><div class="modal-title">Yeni Proje</div><button class="modal-close" data-modal-close>✕</button></div>
        <form data-ajax="project_save">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Proje Adı <span class="required">*</span></label>
                    <input name="name" class="input" required placeholder="Örn. Instagram İçerik Yönetimi">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Dosya <span class="required">*</span></label>
                        <select name="client_id" class="select" required>
                            <option value="">Seçin...</option>
                            <?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $clientId == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Hizmet Türü</label>
                        <select name="type" class="select">
                            <?php foreach (PROJECT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Başlangıç</label><input type="date" name="start" class="input"></div>
                    <div class="form-group"><label class="form-label">Bitiş</label><input type="date" name="end" class="input"></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Proje Yöneticisi</label>
                        <select name="pm_id" class="select"><option value="">—</option><?php foreach ($pms as $pm): ?><option value="<?= $pm['id'] ?>"><?= e($pm['name']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="form-group"><label class="form-label">Sözleşme Tutarı (₺)</label><input name="contract_amount" class="input" placeholder="0,00"></div>
                </div>
                <div class="form-group"><label class="form-label">Proje Şablonu (opsiyonel)</label><select name="ptemplate_id" class="select"><option value="">— Boş proje</option><?php foreach (rows("SELECT id, name FROM project_templates ORDER BY name") as $templateRow): ?><option value="<?= $templateRow['id'] ?>"><?= e($templateRow['name']) ?></option><?php endforeach; ?></select><div class="form-hint">Seçilirse şablondaki işler akışlarıyla birlikte kurulur.</div></div>
                <?php member_picker(); ?>
                <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" class="text-area" placeholder="Proje kapsamı..."></textarea></div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Oluştur</button></div>
        </form>
    </div>
</div>
<?php }

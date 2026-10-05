<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_staff();

$id = (int)($_GET['id'] ?? 0);
$task = row("SELECT g.*, p.name project_name, p.client_id, d.name client_name, d.brand_kit client_brand_kit, d.contact_email client_email, uu.name assignee_name, uu.color assignee_color, ol.name creator_name, tt.name type_name,
    pd.year period_year, pd.month period_month
    FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id
    LEFT JOIN users uu ON uu.id=g.assignee_id LEFT JOIN users ol ON ol.id=g.created_by LEFT JOIN task_types tt ON tt.id=g.type_id
    LEFT JOIN periods pd ON pd.id=g.period_id WHERE g.id=?", [$id]);
if (!$task) { header('Location: tasks.php'); exit; }

$steps = rows("SELECT ga.*, u.name owner_name, u.color owner_color, k.name skill_name FROM task_steps ga LEFT JOIN users u ON u.id=ga.owner_id LEFT JOIN skills k ON k.id=ga.skill_id WHERE ga.task_id=? ORDER BY ga.sort_order, ga.id", [$id]);
// Open steps per person, shown when handing a step on
$stepLoad = array_column(rows("SELECT owner_id, COUNT(*) n FROM task_steps WHERE status='active' AND owner_id IS NOT NULL GROUP BY owner_id"), 'n', 'owner_id');
$team = rows("SELECT id, name, color FROM users WHERE role IN ('admin','pm','team') AND is_active=1 ORDER BY name");
$checks = rows("SELECT * FROM task_checklist WHERE task_id=? ORDER BY sort_order", [$id]);
$dependent = $task['depends_on_id'] ? row("SELECT id, title, status FROM tasks WHERE id=?", [$task['depends_on_id']]) : null;
$projectTasks = rows("SELECT id, title FROM tasks WHERE project_id=? AND id!=? AND " . task_open_sql() . " ORDER BY title", [$task['project_id'], $id]);
$approvals = rows("SELECT o.*, u.name sender_name FROM approvals o LEFT JOIN users u ON u.id=o.sender_id WHERE o.task_id=? ORDER BY o.id DESC", [$id]);
$sourceRequest = row("SELECT id, title FROM requests WHERE task_id=? LIMIT 1", [$id]);
$attachments = rows("SELECT a.*, us.name uploader_name FROM archive a LEFT JOIN users us ON us.id=a.uploader_id WHERE a.task_id=? ORDER BY a.id DESC", [$id]);
$assignees = rows("SELECT us.id, us.name, us.color, us.avatar FROM task_assignees ga JOIN users us ON us.id=ga.user_id WHERE ga.task_id=? ORDER BY us.name", [$id]);
if (!$assignees && $task['assignee_id'] && $task['assignee_name']) $assignees = [['id' => $task['assignee_id'], 'name' => $task['assignee_name'], 'color' => $task['assignee_color'], 'avatar' => null]];
$assigneeIds = array_column($assignees, 'id');
$watchers = rows("SELECT us.id, us.name, us.color, us.avatar FROM task_watchers gi JOIN users us ON us.id=gi.user_id WHERE gi.task_id=? ORDER BY us.name", [$id]);
$watcherIds = array_column($watchers, 'id');
$projectPeriods = rows("SELECT id, year, month FROM periods WHERE project_id=? ORDER BY year DESC, month DESC", [$task['project_id']]);
// Shoots feeding this work, and the client's shoots around now it could be linked to
$shoots = rows("SELECT e.id, e.title, e.start, e.place, e.drive_status FROM event_tasks et JOIN events e ON e.id=et.event_id WHERE et.task_id=? ORDER BY e.start", [$id]);
$linkableShoots = $task['kind'] === 'client' ? rows("SELECT e.id, e.title, e.start FROM events e LEFT JOIN projects p ON p.id=e.project_id
    WHERE e.type='shoot' AND (e.client_id=? OR p.client_id=?) AND e.start BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND DATE_ADD(NOW(), INTERVAL 90 DAY)
    AND e.id NOT IN (SELECT event_id FROM event_tasks WHERE task_id=?) ORDER BY e.start", [$task['client_id'], $task['client_id'], $id]) : [];

$activeStepIndex = -1;
foreach ($steps as $i => $a) { if ($a['status'] === 'active') { $activeStepIndex = $i; break; } }

page_start($task['title'], 'tasks');
?>
<div class="row-flex mb-3" style="gap:10px">
    <a href="project.php?id=<?= $task['project_id'] ?>#tasks" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="text-muted small"><?= e($task['client_name']) ?> / <a href="project.php?id=<?= $task['project_id'] ?>" style="color:inherit"><?= e($task['project_name']) ?></a></span>
</div>

<div class="page-top">
    <div>
        <div class="row-flex wrap" style="gap:9px">
            <?= badge($task['status'], TASK_STATUSES) ?>
            <?= badge($task['priority'], PRIORITIES, 'priority') ?>
            <?php if ($task['repeat'] !== 'none'): ?><span class="badge badge-type"><?= icon('repeat', 12) ?> <?= REPEAT_OPTIONS[$task['repeat']] ?></span><?php endif; ?>
            <?php if ($task['kind'] === 'internal'): ?><span class="badge badge-type">İç iş</span><?php elseif ($task['lane'] === 'agenda'): ?><span class="badge r-agenda" title="Ay içinde çıkan iş; ay planına sayılmaz">Gündem</span><?php endif; ?>
            <?php if ($dependent && task_is_open($dependent['status']) && !$task['lock_bypassed']): ?>
            <span class="lock-badge" title="Bağlı olduğu iş tamamlanmadan ilerleyemez"><?= icon('lock', 12) ?> <a href="task.php?id=<?= $dependent['id'] ?>" style="color:inherit;text-decoration:underline"><?= e(mb_substr($dependent['title'], 0, 34)) ?></a> bekleniyor</span>
            <?php elseif ($task['lock_bypassed']): ?>
            <span class="badge r-pending" title="Yönetici kilidi devre dışı bıraktı"><?= icon('lock-open', 12) ?> Kilit devre dışı</span>
            <?php endif; ?>
            <?= tag_chips($task['tags']) ?>
        </div>
        <div class="page-title mt-1"><?= e($task['title']) ?></div>
    </div>
    <div class="page-top-action">
        <?php if ($steps): ?>
        <?php if ($task['status'] === 'cancelled'): ?><button class="btn" onclick="statusChange('reopen')">Yeniden aç</button>
        <?php else: ?><button class="btn btn-ghost" onclick="if (confirm('İş iptal edilsin mi? Adımları olduğu gibi durur.')) statusChange('cancelled')">İptal et</button><?php endif; ?>
        <?php else: ?>
        <select class="select" style="width:auto;min-width:160px" id="statusPicker" onchange="statusChange(this.value)">
            <?php foreach (TASK_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $task['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button class="btn" title="İşi ve tartışmayı AI ile özetle" onclick="aiSummary(<?= $id ?>)">🪄</button>
        <button class="btn" onclick="modalOpen('modalTaskEdit')"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
    </div>
</div>

<?php if ($steps):
    $activeStep = null;
    foreach ($steps as $a) if ($a['status'] === 'active') { $activeStep = $a; break; }
    $canAct = $activeStep && step_can_act($activeStep, $u);
    $mySkills = user_skill_ids((int)$u['id']);
    $canClaim = $activeStep && !$activeStep['owner_id'] && $activeStep['skill_id'] && (is_pm() || in_array((int)$activeStep['skill_id'], $mySkills, true)); ?>
<!-- WORKFLOW STEPS -->
<div class="card mb-3">
    <div class="row-flex between mb-3"><div class="card-title">Adımlar<?= $task['type_name'] ? ' <span class="cell-bottom" style="font-weight:400">· ' . e($task['type_name']) . '</span>' : '' ?></div><span class="text-muted small" id="stepCounter"><?= count(array_filter($steps, fn($a) => $a['status'] === 'done')) ?>/<?= count($steps) ?> adım tamamlandı<?= ($skippedCount = count(array_filter($steps, fn($a) => $a['skipped']))) ? " ({$skippedCount} atlandı)" : '' ?></span></div>
    <div class="flow-rail">
        <?php foreach ($steps as $i => $a): ?>
        <div class="flow-step <?= $a['status'] === 'done' ? 'done' : ($a['status'] === 'active' ? 'active' : '') ?><?= $a['skipped'] ? ' skipped' : ($a['optional'] ? ' optional' : '') ?>" data-step="<?= $a['id'] ?>" data-sort_order="<?= $i + 1 ?>">
            <div class="flow-line"></div>
            <div class="flow-step-inner">
                <button class="flow-circle" onclick="stepComplete(<?= $a['id'] ?>)" title="<?= $a['skipped'] ? 'Atlandı — geri al' : ($a['status'] === 'done' ? 'Geri aç' : STEP_KINDS[$a['kind']]) ?>">
                    <?php if ($a['skipped']): ?>»<?php elseif ($a['status'] === 'done'): ?><svg width="20" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg><?php else: ?><?= $i + 1 ?><?php endif; ?>
                </button>
                <div class="flow-name"><?= e($a['name']) ?></div>
                <div class="cell-bottom" style="font-size:11px"><?= e($a['skill_name'] ?? '') ?><?= $a['kind'] !== 'work' ? ($a['skill_name'] ? ' · ' : '') . STEP_KINDS[$a['kind']] : '' ?><?= $a['skipped'] ? ' · atlandı' : ($a['optional'] ? ' · atlanabilir' : '') ?></div>
                <button class="flow-owner" onclick="stepOwner(<?= $a['id'] ?>)" style="cursor:pointer"><?= $a['owner_name'] ? e(explode(' ', $a['owner_name'])[0]) : ($a['skill_name'] ? 'Havuz' : '+ sorumlu') ?></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($activeStep && $task['status'] !== 'cancelled'): ?>
    <!-- Who holds the work now, and what they can do -->
    <div class="row-flex between wrap mt-3" style="gap:10px;padding:12px 14px;background:var(--surface-2);border-radius:12px">
        <div class="small">
            <b>Sıradaki: <?= e($activeStep['name']) ?></b> · <?= STEP_KINDS[$activeStep['kind']] ?>
            <span class="cell-bottom"> — <?= $activeStep['owner_name'] ? e($activeStep['owner_name']) . ' üzerinde' : ($activeStep['skill_name'] ? e($activeStep['skill_name']) . ' havuzunda, sahibi yok' : 'sorumlusu yok') ?></span>
        </div>
        <div class="row-flex wrap" style="gap:6px">
            <?php if ($canClaim): ?><button class="btn btn-sm btn-brand" data-action="step_claim" data-id="<?= $activeStep['id'] ?>" data-refresh="yes">Ben alıyorum</button><?php endif; ?>
            <?php if ($canAct && $activeStep['kind'] === 'work'): ?><button class="btn btn-sm btn-brand" data-modal="modalDeliver">Teslim et</button><button class="btn btn-sm" onclick="stepComplete(<?= $activeStep['id'] ?>)" title="Dosya eklemeden bitir">Bitir</button><?php endif; ?>
            <?php if ($canAct && $activeStep['kind'] === 'review'): ?>
            <button class="btn btn-sm btn-brand" onclick="stepComplete(<?= $activeStep['id'] ?>)">Onayla</button>
            <button class="btn btn-sm" onclick="stepReturn(<?= $activeStep['id'] ?>)">Geri gönder</button>
            <?php endif; ?>
            <?php if ($activeStep['kind'] === 'client_approval' && $task['kind'] === 'client'): ?>
            <?php if (permission('approval_send')): ?><button class="btn btn-sm btn-brand" data-modal="modalSendApproval">Müşteriye gönder</button><?php endif; ?>
            <?php if (is_pm()): ?><button class="btn btn-sm" onclick="if (confirm('Müşteri onayını onun adına kaydediyorsunuz. Devam edilsin mi?')) stepComplete(<?= $activeStep['id'] ?>)" title="Müşteri onayı telefonda/e-postada geldiyse">Onay geldi</button><button class="btn btn-sm" onclick="stepReturn(<?= $activeStep['id'] ?>)">Revize geldi</button><?php endif; ?>
            <?php endif; ?>
            <?php if ($canAct && $activeStep['kind'] === 'publish'): ?><button class="btn btn-sm btn-brand" onclick="stepComplete(<?= $activeStep['id'] ?>)"><?= icon('rocket', 13) ?> Yayınlandı</button><?php endif; ?>
            <?php if ($activeStep['optional'] && ($canAct || is_pm())): ?><button class="btn btn-sm btn-ghost" onclick="stepSkip(<?= $activeStep['id'] ?>)" title="Bu adım zorunlu değil">Atla</button><?php endif; ?>
            <?php if (is_pm() || (int)$activeStep['owner_id'] === (int)$u['id']): ?><button class="btn btn-sm btn-ghost" onclick="stepOwner(<?= $activeStep['id'] ?>)">Devret</button><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:1fr 300px">
    <div>
        <div class="card mb-3">
            <div class="card-title mb-2">Açıklama</div>
            <div class="text-2" style="white-space:pre-wrap"><?= $task['description'] ? e($task['description']) : '<span class="text-muted small">Açıklama eklenmemiş.</span>' ?></div>
        </div>

        <!-- Checklist -->
        <div class="card mb-3">
            <div class="row-flex between mb-2">
                <div class="card-title">Kontrol Listesi</div>
                <span class="text-muted small" id="checkCounter"><?= $checks ? count(array_filter($checks, fn($k) => $k['is_done'])) . '/' . count($checks) : '' ?></span>
            </div>
            <div class="progress mb-2" <?= $checks ? '' : 'style="display:none"' ?>><div class="progress-full" id="checkBar" data-rate="<?= $checks ? round(count(array_filter($checks, fn($k) => $k['is_done'])) / count($checks) * 100) : 0 ?>" style="width:0"></div></div>
            <div class="vertical" style="gap:2px" id="checkList">
                <?php foreach ($checks as $k): ?>
                <div class="check-item <?= $k['is_done'] ? 'done' : '' ?>">
                    <input type="checkbox" <?= $k['is_done'] ? 'checked' : '' ?> onchange="checkToggle(<?= $k['id'] ?>, this)">
                    <span class="check-text"><?= e($k['name']) ?></span>
                    <button class="icon-action danger" style="width:26px;height:26px" data-action="check_delete" data-id="<?= $k['id'] ?>" data-confirm="Madde silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <?php endforeach; ?>
                <?php if (!$checks): ?><div class="text-muted small" style="padding:6px 0">Henüz madde yok. İşi küçük adımlara bölün.</div><?php endif; ?>
            </div>
            <form class="row-flex mt-2" style="gap:8px" onsubmit="return checkAdd(event)">
                <input class="input" id="checkNew" placeholder="Yeni madde ekle...">
                <button type="submit" class="btn btn-sm">Ekle</button>
            </form>
        </div>

        <!-- Attachments -->
        <div class="card mb-3">
            <div class="row-flex between mb-2">
                <div class="card-title">Ekler <?php if ($attachments): ?><span class="badge" style="padding:1px 8px"><?= count($attachments) ?></span><?php endif; ?></div>
                <span class="attachments-action">
                <button class="btn btn-sm" onclick="modalOpen('modalDriveLink')" type="button"><?= icon('web', 13) ?> Drive Linki</button>
                <form data-ajax="archive_upload" style="display:inline">
                    <input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
                    <label class="btn btn-sm" style="cursor:pointer"><?= icon('paperclip', 14) ?> Dosya Ekle<input type="file" name="file" style="display:none" onchange="this.closest('form').requestSubmit()"></label>
                </form>
                </span>
            </div>
            <?php if (!$attachments): ?><div class="text-muted small">Henüz ek yok. Brief, görsel veya video ekleyin.</div>
            <?php else: ?>
            <div class="grid grid-2" style="gap:8px">
                <?php foreach ($attachments as $file):
                    $isImage = in_array($file['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                    $isLink = !empty($file['url']);
                    $href = $isLink ? $file['url'] : 'uploads/' . $file['file_path']; ?>
                <div class="row-flex between" style="padding:8px 10px;background:var(--surface-2);border-radius:10px">
                    <a href="<?= e($href) ?>" target="_blank" class="row-flex" style="gap:9px;min-width:0">
                        <?php if ($isImage): ?><span style="width:34px;height:34px;border-radius:8px;background:url('uploads/<?= e($file['file_path']) ?>') center/cover;flex-shrink:0"></span>
                        <?php elseif ($isLink): ?><span class="file-avatar" style="width:34px;height:34px;background:var(--bright);color:var(--brand)"><?= icon('web', 16) ?></span>
                        <?php else: ?><span class="file-avatar" style="width:34px;height:34px;font-size:10px;background:var(--bright);color:var(--brand)"><?= e(mb_strtoupper($file['extension'] ?: '?')) ?></span><?php endif; ?>
                        <div style="min-width:0"><div class="small bold" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($file['name']) ?></div><div class="cell-bottom"><?= $isLink ? 'Drive bağlantısı' : format_size($file['size']) ?></div></div>
                    </a>
                    <button class="icon-action danger" style="width:26px;height:26px" data-action="archive_delete" data-id="<?= $file['id'] ?>" data-confirm="Ek silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title mb-2">Yorumlar</div>
            <?php comment_feed('task', $id); ?>
        </div>
    </div>

    <div>
        <div class="card mb-2">
            <div class="vertical" style="gap:14px">
                <div><div class="cell-bottom mb-2">Atananlar</div>
                    <?php if (!$assignees): ?><span class="text-muted small">Atanmamış</span>
                    <?php else: foreach ($assignees as $at): ?>
                    <div class="row-flex mt-1" style="gap:9px"><?= avatar($at, 28) ?><span class="small bold"><?= e($at['name']) ?></span></div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="row-flex between"><span class="cell-bottom">Başlangıç</span><span class="small"><?= format_date($task['start_date']) ?></span></div>
                <div class="row-flex between"><span class="cell-bottom">Son Tarih</span><span class="small bold" style="<?= $task['due_date'] && $task['due_date'] < date('Y-m-d') && task_is_open($task['status']) ? 'color:var(--danger)' : '' ?>"><?= format_date($task['due_date']) ?></span></div>
                <?php if ($task['estimated_minutes'] > 0): ?>
                <div class="row-flex between"><span class="cell-bottom">Tahmini süre</span><span class="small bold"><?= format_minutes((int)$task['estimated_minutes']) ?></span></div>
                <?php endif; ?>
                <div class="row-flex between"><span class="cell-bottom">Oluşturan</span><span class="small"><?= e($task['creator_name'] ?? '—') ?></span></div>
                <div class="row-flex between"><span class="cell-bottom">Oluşturulma</span><span class="small"><?= format_date($task['created']) ?></span></div>
                <?php if ($task['period_year']): ?><div class="row-flex between"><span class="cell-bottom">Ay</span><a class="small" href="month.php?id=<?= $task['period_id'] ?>"><?= MONTHS[(int)$task['period_month']] . ' ' . $task['period_year'] ?> →</a></div><?php endif; ?>
                <?php if ($sourceRequest): ?><div class="row-flex between"><span class="cell-bottom">Kaynak</span><a class="small" href="request.php?id=<?= $sourceRequest['id'] ?>">Talep #<?= $sourceRequest['id'] ?> →</a></div><?php endif; ?>
            </div>
        </div>

        <?php if ($task['kind'] === 'client' && trim((string)$task['client_brand_kit']) !== ''): ?>
        <!-- The client's brand kit, where the work is done -->
        <details class="card mb-2 brand-kit">
            <summary class="card-title" style="font-size:14px;cursor:pointer">Marka Kiti</summary>
            <div class="small text-2 mt-2" style="white-space:pre-wrap"><?= e($task['client_brand_kit']) ?></div>
            <a href="client.php?id=<?= $task['client_id'] ?>" class="mini-btn mt-2" style="display:inline-block">Dosyada gör →</a>
        </details>
        <?php endif; ?>

        <?php if ($task['kind'] === 'client'): ?>
        <!-- Shoots feeding this work -->
        <div class="card mb-2">
            <div class="row-flex between mb-2"><div class="card-title" style="font-size:14px"><?= icon('camera', 15) ?> Çekim</div><?php if (permission('calendar_manage')): ?><button class="mini-btn" data-modal="modalShoot">+ Planla</button><?php endif; ?></div>
            <?php if (!$shoots): ?><div class="text-muted small">Bağlı çekim yok. Bağlanan çekimin görüntüleri Drive'a aktarılınca işin çekim adımı kendiliğinden biter.</div>
            <?php else: foreach ($shoots as $sh): ?>
            <div class="row-flex between" style="padding:6px 0;border-bottom:1px solid var(--border);gap:8px">
                <div style="min-width:0"><div class="small bold"><?= e($sh['title']) ?></div><div class="cell-bottom"><?= format_date($sh['start'], true) ?><?= $sh['place'] ? ' · ' . e($sh['place']) : '' ?></div></div>
                <div class="row-flex" style="gap:6px;flex-shrink:0"><?= $sh['drive_status'] === 'transferred' ? '<span class="badge r-completed">Drive\'da</span>' : '<span class="badge r-pending">Aktarılmadı</span>' ?><button class="icon-action danger" style="width:24px;height:24px" data-action="event_task_unlink" data-event_id="<?= $sh['id'] ?>" data-task_id="<?= $id ?>" data-confirm="Bu çekimle bağlantı kaldırılsın mı?" title="Bağlantıyı kaldır">✕</button></div>
            </div>
            <?php endforeach; endif; ?>
            <?php if ($linkableShoots): ?>
            <form class="row-flex mt-2" style="gap:6px" data-ajax="event_task_link"><input type="hidden" name="task_id" value="<?= $id ?>"><select name="event_id" class="select" style="flex:1;min-width:0"><?php foreach ($linkableShoots as $ls): ?><option value="<?= $ls['id'] ?>"><?= format_date($ls['start']) ?> — <?= e($ls['title']) ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-sm">Bağla</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($task['kind'] === 'client'): ?>
        <!-- Publish plan -->
        <div class="card mb-2">
            <div class="card-title mb-2" style="font-size:14px"><?= icon('calendar', 15) ?> Yayın Planı</div>
            <?php if ($task['publish_date']): ?>
            <div class="row-flex wrap" style="gap:5px"><?= platform_badges($task['platforms']) ?: '<span class="text-muted small">Platform seçilmemiş</span>' ?></div>
            <div class="row-flex between mt-2"><span class="cell-bottom">Yayın</span><span class="small bold"><?= format_date($task['publish_date']) ?><?= $task['publish_time'] ? ' ' . substr($task['publish_time'], 0, 5) : '' ?></span></div>
            <a href="content-calendar.php?month=<?= date('n', strtotime($task['publish_date'])) ?>&year=<?= date('Y', strtotime($task['publish_date'])) ?>" class="mini-btn mt-2" style="display:inline-block">İçerik takviminde gör →</a>
            <?php else: ?>
            <div class="text-muted small">Yayın tarihi yok. Düzenle'den tarih ve platform ekleyince içerik takviminde görünür.</div>
            <?php endif; ?>
            <?php if (!in_array($task['status'], ['published', 'cancelled'], true)): ?>
            <button class="btn btn-sm btn-block mt-2" onclick="statusChange('published')"><?= icon('rocket', 13) ?> Yayınlandı olarak işaretle</button>
            <?php endif; ?>
        </div>

        <!-- Client approval history -->
        <div class="card mb-2">
            <div class="row-flex between mb-2">
                <div class="card-title" style="font-size:14px"><?= icon('approval', 15) ?> Müşteri Onayı</div>
                <?php if (permission('approval_send') && task_is_open($task['status']) && (!$steps || ($activeStep['kind'] ?? '') === 'client_approval')): ?><button class="mini-btn" data-modal="modalSendApproval">Müşteriye gönder</button><?php endif; ?>
            </div>
            <?php if (!$approvals): ?><div class="text-muted small">Henüz müşteriye gönderilmedi.</div>
            <?php else: foreach ($approvals as $ai => $o): ?>
            <div style="padding:8px 0;border-bottom:1px solid var(--border)">
                <div class="row-flex between" style="gap:8px"><span class="small bold" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($o['title']) ?></span><?= badge($o['status'], APPROVAL_STATUSES) ?></div>
                <div class="cell-bottom mt-1"><?= e($o['sender_name'] ?? '—') ?> · <?= time_ago($o['created']) ?><?php if ($o['drive_link']): ?> · <a href="<?= e($o['drive_link']) ?>" target="_blank">Drive</a><?php endif; ?></div>
                <?php if ($o['status'] === 'pending' && $ai === 0 && permission('approval_send')): ?><div class="row-flex mt-1" style="gap:6px"><button class="mini-btn" onclick="approvalShare(<?= $o['id'] ?>, 'copy')">Linki kopyala</button><button class="mini-btn" onclick="approvalShare(<?= $o['id'] ?>, 'whatsapp')">WhatsApp</button></div><?php endif; ?>
                <?php if ($o['reply_note']): ?><div class="small text-2 mt-1" style="white-space:pre-wrap"><b><?= $o['reply_name'] ? e($o['reply_name']) . ' (link)' : 'Müşteri' ?>:</b> <?= e($o['reply_note']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <?php endif; ?>

        <!-- Watchers -->
        <div class="card mb-2">
            <div class="row-flex between mb-2">
                <div class="card-title" style="font-size:14px">İzleyiciler</div>
                <div class="dropdown" data-dropdown>
                    <button class="mini-btn" data-dropdown-btn>+ Ekle</button>
                    <div class="dropdown-panel">
                        <?php foreach ($team as $k): if (in_array($k['id'], $watcherIds)) continue; ?>
                        <button class="dropdown-item" style="width:100%;text-align:left" data-action="watcher_toggle" data-task_id="<?= $id ?>" data-user_id="<?= $k['id'] ?>"><?= e($k['name']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php if (!$watchers): ?><div class="text-muted small">İzleyici yok. Eklenenler işteki her gelişmede bildirim alır.</div>
            <?php else: foreach ($watchers as $iz): ?>
            <div class="row-flex between mt-1" style="padding:5px 0">
                <div class="row-flex" style="gap:9px"><?= avatar($iz, 26) ?><span class="small"><?= e($iz['name']) ?></span></div>
                <button class="icon-action danger" style="width:24px;height:24px" data-action="watcher_toggle" data-task_id="<?= $id ?>" data-user_id="<?= $iz['id'] ?>" title="Çıkar"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <?php endforeach; endif; ?>
        </div>

    </div>
</div>

<!-- Add Drive link -->
<div class="modal-overlay" id="modalDriveLink">
    <div class="modal"><div class="modal-top"><div class="modal-title">Drive Linki Ekle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="archive_link_add">
        <input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Bağlantı Adı</label><input name="name" class="input" placeholder="Örn. Kurgu v2 — final klasörü"></div>
            <div class="form-group"><label class="form-label">Drive Linki <span class="required">*</span></label><input name="url" class="input" required placeholder="https://drive.google.com/..."><div class="form-hint">İş teslimlerinde dosya yüklemek yerine Drive klasör/dosya linki bırakın.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Ekle</button></div>
    </form></div>
</div>

<?php if (!empty($canAct) && $activeStep['kind'] === 'work'): ?>
<!-- Hand in the work: files / a link, and the step finishes -->
<div class="modal-overlay" id="modalDeliver">
    <div class="modal"><div class="modal-top"><div class="modal-title">Teslim et — <?= e($activeStep['name']) ?></div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="step_deliver" data-refresh="yes">
        <input type="hidden" name="id" value="<?= $activeStep['id'] ?>">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Dosyalar</label><input type="file" name="file" class="input" multiple><div class="form-hint">Birden fazla dosya seçebilirsiniz (her biri en fazla 50MB); işin eklerine eklenir.</div></div>
            <div class="form-group"><label class="form-label">veya Bağlantı</label><input name="drive_link" class="input" placeholder="https://drive.google.com/..."></div>
            <div class="form-group"><label class="form-label">Not</label><textarea name="note" class="text-area" rows="2" placeholder="Sonraki adıma iletmek istedikleriniz..."></textarea></div>
            <div class="form-hint">Teslim edince "<?= e($activeStep['name']) ?>" adımı biter, iş sıradaki adıma geçer; teslim işin yorumlarına yazılır.</div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Teslim et</button></div>
    </form></div>
</div>
<?php endif; ?>

<?php if ($task['kind'] === 'client' && permission('calendar_manage')): ?>
<!-- Plan a shoot for this work -->
<div class="modal-overlay" id="modalShoot">
    <div class="modal"><div class="modal-top"><div class="modal-title">Çekim Planla</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="event_save" data-refresh="yes">
        <input type="hidden" name="type" value="shoot"><input type="hidden" name="project_id" value="<?= $task['project_id'] ?>"><input type="hidden" name="client_id" value="<?= $task['client_id'] ?>"><input type="hidden" name="task_id" value="<?= $id ?>">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık</label><input name="title" class="input" required value="Çekim: <?= e($task['title']) ?>"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Başlangıç</label><input type="datetime-local" name="start" class="input" required></div>
                <div class="form-group"><label class="form-label">Bitiş</label><input type="datetime-local" name="end" class="input"></div>
            </div>
            <div class="form-group"><label class="form-label">Yer</label><input name="place" class="input"></div>
            <div class="form-hint">Çekim takvimde görünür ve bu işe bağlanır; görüntüler Drive'a aktarılınca işin çekim adımı biter.</div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Planla</button></div>
    </form></div>
</div>
<?php endif; ?>

<!-- Send to the client for approval -->
<div class="modal-overlay" id="modalSendApproval">
    <div class="modal"><div class="modal-top"><div class="modal-title">Müşteriye Gönder</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="approval_send" data-refresh="yes">
        <input type="hidden" name="task_id" value="<?= $id ?>">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık</label><input name="title" class="input" value="<?= e($task['title']) ?>"></div>
            <div class="form-group"><label class="form-label">Müşteriye not</label><textarea name="description" class="text-area" placeholder="Müşteriye iletmek istedikleriniz..."></textarea></div>
            <div class="form-group"><label class="form-label">Dosya Eki</label><input type="file" name="file" class="input"><div class="form-hint">Görsel, PDF, video vb. (max 50MB) — işin eklerine de eklenir.</div></div>
            <div class="form-group"><label class="form-label">veya Drive Linki</label><input name="drive_link" class="input" placeholder="https://drive.google.com/..."></div>
            <?php if ($task['client_email']): ?><div class="form-group"><label class="row-flex small" style="gap:8px;cursor:pointer"><input type="checkbox" name="send_email" value="1"> Onay linkini dosya kişisine e-postayla da gönder (<?= e($task['client_email']) ?>)</label></div><?php endif; ?>
            <div class="form-hint">Gönderince iş "Müşteride" durumuna geçer; müşteri onaylarsa tamamlanır, revize isterse üretime döner. Hesabı olmayan müşteri, onay linkinden (Müşteri Onayı kartında kopyala / WhatsApp) cevap verir.</div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Gönder</button></div>
    </form></div>
</div>

<!-- Modals -->
<div class="modal-overlay" id="modalTaskEdit">
    <div class="modal"><div class="modal-top"><div class="modal-title">İşi Düzenle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="task_save">
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık</label><input name="title" class="input" value="<?= e($task['title']) ?>" required></div>
            <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" class="text-area"><?= e($task['description']) ?></textarea></div>
            <?php task_publish_fields($task); ?>
            <div class="form-group">
                <label class="form-label">Atanan Kişiler <span class="text-muted" style="font-weight:400">(birden fazla seçilebilir)</span></label>
                <input type="hidden" name="assignees" class="assignees-json">
                <div class="grid grid-2" style="gap:6px;max-height:150px;overflow-y:auto;padding:2px">
                    <?php foreach ($team as $k): ?>
                    <label class="row-flex small" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="assigned-box" value="<?= $k['id'] ?>" <?= in_array($k['id'], $assigneeIds) ? 'checked' : '' ?>> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($k['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Öncelik</label><select name="priority" class="select"><?php foreach (PRIORITIES as $k => $v): ?><option value="<?= $k ?>" <?= $task['priority'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <?php if ($projectPeriods): ?><div class="form-group"><label class="form-label">Ay</label><select name="period_id" class="select"><option value="">—</option><?php foreach ($projectPeriods as $d): ?><option value="<?= $d['id'] ?>" <?= (int)$task['period_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= period_name($d) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Başlangıç Tarihi</label><input type="date" name="start_date" class="input" value="<?= e($task['start_date']) ?>"></div>
                <div class="form-group"><label class="form-label">Son Tarih</label><input type="date" name="due_date" class="input" value="<?= e($task['due_date']) ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tahmini Süre (saat)</label><input name="estimated_time" class="input" value="<?= $task['estimated_minutes'] ? round($task['estimated_minutes'] / 60, 1) : '' ?>" placeholder="Örn. 4,5"></div>
                <div class="form-group"><label class="form-label">Tekrar</label><select name="repeat" class="select"><?php foreach (REPEAT_OPTIONS as $k => $v): ?><option value="<?= $k ?>" <?= $task['repeat'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group"><label class="form-label">Etiketler</label><input name="tags" class="input" value="<?= e($task['tags']) ?>" placeholder="video, instagram, acil-revize (virgülle ayırın)"></div>
            <div class="form-group"><label class="form-label">Bağlı Olduğu İş</label><select name="depends_on_id" class="select"><option value="">— Bağımsız</option><?php foreach ($projectTasks as $pg): ?><option value="<?= $pg['id'] ?>" <?= $pg['id'] == $task['depends_on_id'] ? 'selected' : '' ?>><?= e($pg['title']) ?></option><?php endforeach; ?></select><div class="form-hint">Seçilen iş tamamlanmadan bu iş ilerleyemez.</div></div>
            <?php if (is_pm()): ?>
            <div class="form-group">
                <label class="row-flex key" style="gap:10px;cursor:pointer">
                    <input type="checkbox" <?= $task['lock_bypassed'] ? 'checked' : '' ?> onchange="event.preventDefault();lockToggle()">
                    <span class="small"><b>Kilidi devre dışı bırak</b> — akış ve bağımlılık kuralları bu iş için uygulanmaz (loglanır)</span>
                </label>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-alt">
            <button type="button" class="btn btn-danger" data-action="task_delete" data-id="<?= $id ?>" data-confirm="İş silinsin mi?" data-redirect="project.php?id=<?= $task['project_id'] ?>" style="margin-right:auto">Sil</button>
            <button type="button" class="btn" data-action="task_archive" data-id="<?= $id ?>" data-confirm="<?= $task['is_archived'] ? 'İş arşivden çıkarılsın mı?' : 'İş arşive taşınsın mı?' ?>"><?= icon('box', 14) ?> <?= $task['is_archived'] ? 'Arşivden Çıkar' : 'Arşivle' ?></button>
            <button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button>
        </div>
    </form></div>
</div>

<!-- Step owner assignment -->
<div class="modal-overlay" id="modalStepOwner">
    <div class="modal"><div class="modal-top"><div class="modal-title">Adımı Devret</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="step_owner" data-refresh="yes">
        <input type="hidden" name="id" id="stepOwnerId">
        <div class="modal-body"><div class="form-group"><label class="form-label">Kime</label><select name="owner_id" class="select native-select"><option value="">Havuza bırak (uzmanlığı olan alsın)</option><?php foreach ($team as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['name']) ?><?= ($stepLoad[$k['id']] ?? 0) ? ' — ' . $stepLoad[$k['id']] . ' açık adım' : ' — boşta' ?></option><?php endforeach; ?></select><div class="form-hint">Parantezdeki sayı kişinin üzerindeki açık adımları gösterir; karar sizde.</div></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Ata</button></div>
    </form></div>
</div>

<!-- Send back with the reason -->
<div class="modal-overlay" id="modalStepSkip">
    <div class="modal"><div class="modal-top"><div class="modal-title">Adımı Atla</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="step_skip" data-refresh="yes">
        <input type="hidden" name="id" id="stepSkipId">
        <div class="modal-body"><div class="form-group"><label class="form-label">Not <span class="text-muted" style="font-weight:400">(isteğe bağlı)</span></label><textarea name="note" class="text-area" placeholder="Örn. bu işte çekim gerekmedi, arşivden görsel kullanıldı"></textarea><div class="form-hint">Adım atlanmış olarak işaretlenir ve iş sıradaki adıma geçer; not işin yorumlarına düşer. Atlanan adımın simgesine basarak geri alabilirsiniz.</div></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Atla</button></div>
    </form></div>
</div>
<div class="modal-overlay" id="modalStepReturn">
    <div class="modal"><div class="modal-top"><div class="modal-title">Geri Gönder</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="step_return" data-refresh="yes">
        <input type="hidden" name="id" id="stepReturnId">
        <div class="modal-body"><div class="form-group"><label class="form-label">Ne değişmeli? <span class="required">*</span></label><textarea name="note" class="text-area" required placeholder="Örn. renkler marka kitine uymuyor, ilk 3 saniye daha hızlı olmalı"></textarea><div class="form-hint">İş, en son biten üretim adımına döner; not işin tartışmasına düşer.</div></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Geri Gönder</button></div>
    </form></div>
</div>
<script>
// Live sync: if someone else changes this task, the page refreshes
window.sadaLive = { context: 'task', id: <?= $id ?>, hash: '<?= live_hash_task($id) ?>' };

async function statusChange(status) {
    const j = await api('task_status', { id: <?= $id ?>, status });
    if (j.ok) { toast('Durum güncellendi', 'success'); liveRefresh(); setTimeout(() => location.reload(), 450); }
    else if (!['network', 'timeout'].includes(j.error)) setTimeout(() => location.reload(), 1600); // the lock rejected it → revert to the stored value (a network failure must not add a reload to a struggling server)
}
function stepOwner(id) { document.getElementById('stepOwnerId').value = id; modalOpen('modalStepOwner'); }
function stepReturn(id) { document.getElementById('stepReturnId').value = id; modalOpen('modalStepReturn'); }
function stepSkip(id) { document.getElementById('stepSkipId').value = id; modalOpen('modalStepSkip'); }

/* Workflow step: a step changes who holds the work and its status, so the page reloads */
async function stepComplete(id) {
    const j = await api('step_complete', { id });
    if (!j.ok) return;
    toast(j.message + (j.task_status_tag ? ' · İş: ' + j.task_status_tag : ''), 'success', 1800);
    liveRefresh();
    setTimeout(() => location.reload(), 700);
}

/* Checklist: update without a page reload */
function checkSummary() {
    const total = document.querySelectorAll('#checkList .check-item').length;
    const doneCount = document.querySelectorAll('#checkList .check-item.done').length;
    document.getElementById('checkCounter').textContent = total ? doneCount + '/' + total : '';
    const bar = document.getElementById('checkBar');
    bar.parentElement.style.display = total ? '' : 'none';
    bar.style.width = (total ? Math.round(doneCount / total * 100) : 0) + '%';
}
async function checkAdd(e) {
    e.preventDefault();
    const input = document.getElementById('checkNew');
    const name = input.value.trim(); if (!name) return false;
    const j = await api('check_add', { task_id: <?= $id ?>, name });
    if (j.ok) {
        input.value = '';
        const list = document.getElementById('checkList');
        const empty = list.querySelector('.text-muted'); if (empty) empty.remove();
        const div = document.createElement('div');
        div.className = 'check-item';
        div.innerHTML = `<input type="checkbox" onchange="checkToggle(${j.id}, this)"><span class="check-text"></span><button class="icon-action danger" style="width:26px;height:26px" data-action="check_delete" data-id="${j.id}" data-confirm="Madde silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
        div.querySelector('.check-text').textContent = j.name;
        list.appendChild(div);
        checkSummary();
        liveRefresh();
    }
    return false;
}
async function checkToggle(id, box) {
    const j = await api('check_toggle', { id });
    if (j.ok) { box.closest('.check-item').classList.toggle('done', box.checked); checkSummary(); liveRefresh(); }
    else box.checked = !box.checked;
}
async function lockToggle() {
    const j = await api('lock_toggle', { id: <?= $id ?> });
    if (j.ok) { toast(j.message, 'success'); liveRefresh(); setTimeout(() => location.reload(), 650); }
}
</script>
<div class="modal-overlay" id="modalAiSummary">
    <div class="modal"><div class="modal-top"><div class="modal-title">🪄 İş Özeti</div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body"><div class="small text-2" id="aiSummaryText" style="white-space:pre-wrap;line-height:1.7">Özet hazırlanıyor...</div></div></div>
</div>
<script>
async function aiSummary(taskId) {
    modalOpen('modalAiSummary');
    const box = document.getElementById('aiSummaryText');
    box.textContent = 'Özet hazırlanıyor... (~15 sn)';
    const j = await api('ai_summarize', { task_id: taskId });
    box.textContent = j.ok ? j.summary : (j.error || 'Özet üretilemedi.');
}
</script>
<?php page_end(); ?>

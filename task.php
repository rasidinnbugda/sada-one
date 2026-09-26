<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_staff();

$id = (int)($_GET['id'] ?? 0);
$task = row("SELECT g.*, p.name project_name, p.client_id, d.name client_name, uu.name assignee_name, uu.color assignee_color, ol.name creator_name
    FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id
    LEFT JOIN users uu ON uu.id=g.assignee_id LEFT JOIN users ol ON ol.id=g.created_by WHERE g.id=?", [$id]);
if (!$task) { header('Location: tasks.php'); exit; }

$steps = rows("SELECT ga.*, u.name owner_name, u.color owner_color FROM task_steps ga LEFT JOIN users u ON u.id=ga.owner_id WHERE ga.task_id=? ORDER BY ga.sort_order", [$id]);
$times = rows("SELECT z.*, u.name FROM time_entries z JOIN users u ON u.id=z.user_id WHERE z.task_id=? ORDER BY z.date DESC, z.id DESC", [$id]);
$totalMin = (int)val("SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE task_id=?", [$id]);
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
            <?php if ($task['kind'] === 'internal'): ?><span class="badge badge-type">İç iş</span><?php endif; ?>
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
        <select class="select" style="width:auto;min-width:160px" id="statusPicker" onchange="statusChange(this.value)">
            <?php foreach (TASK_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $task['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
        <button class="btn" title="İşi ve tartışmayı AI ile özetle" onclick="aiSummary(<?= $id ?>)">🪄</button>
        <button class="btn" onclick="modalOpen('modalTaskEdit')"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
    </div>
</div>

<?php if ($steps): ?>
<!-- WORKFLOW STEPS -->
<div class="card mb-3">
    <div class="row-flex between mb-3"><div class="card-title">Adımlar</div><span class="text-muted small" id="stepCounter"><?= count(array_filter($steps, fn($a) => $a['status'] === 'done')) ?>/<?= count($steps) ?> adım tamamlandı</span></div>
    <div class="flow-rail">
        <?php foreach ($steps as $i => $a): ?>
        <div class="flow-step <?= $a['status'] === 'done' ? 'done' : ($a['status'] === 'active' ? 'active' : '') ?>" data-step="<?= $a['id'] ?>" data-sort_order="<?= $i + 1 ?>">
            <div class="flow-line"></div>
            <div class="flow-step-inner">
                <button class="flow-circle" onclick="stepComplete(<?= $a['id'] ?>)" title="Tamamla / geri al">
                    <?php if ($a['status'] === 'done'): ?><svg width="20" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg><?php else: ?><?= $i + 1 ?><?php endif; ?>
                </button>
                <div class="flow-name"><?= e($a['name']) ?></div>
                <button class="flow-owner" onclick="stepOwner(<?= $a['id'] ?>)" style="cursor:pointer"><?= $a['owner_name'] ? e(explode(' ', $a['owner_name'])[0]) : '+ sorumlu' ?></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
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
                <div class="row-flex between"><span class="cell-bottom">Tahmin / Gerçek</span><span class="small bold" style="<?= $totalMin > $task['estimated_minutes'] ? 'color:var(--danger)' : '' ?>"><?= format_minutes((int)$task['estimated_minutes']) ?> / <?= format_minutes($totalMin) ?></span></div>
                <?php endif; ?>
                <div class="row-flex between"><span class="cell-bottom">Oluşturan</span><span class="small"><?= e($task['creator_name'] ?? '—') ?></span></div>
                <div class="row-flex between"><span class="cell-bottom">Oluşturulma</span><span class="small"><?= format_date($task['created']) ?></span></div>
                <?php if ($sourceRequest): ?><div class="row-flex between"><span class="cell-bottom">Kaynak</span><a class="small" href="request.php?id=<?= $sourceRequest['id'] ?>">Talep #<?= $sourceRequest['id'] ?> →</a></div><?php endif; ?>
            </div>
        </div>

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
                <?php if (permission('approval_send') && task_is_open($task['status'])): ?><button class="mini-btn" data-modal="modalSendApproval">Müşteriye gönder</button><?php endif; ?>
            </div>
            <?php if (!$approvals): ?><div class="text-muted small">Henüz müşteriye gönderilmedi.</div>
            <?php else: foreach ($approvals as $o): ?>
            <div style="padding:8px 0;border-bottom:1px solid var(--border)">
                <div class="row-flex between" style="gap:8px"><span class="small bold" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($o['title']) ?></span><?= badge($o['status'], APPROVAL_STATUSES) ?></div>
                <div class="cell-bottom mt-1"><?= e($o['sender_name'] ?? '—') ?> · <?= time_ago($o['created']) ?><?php if ($o['drive_link']): ?> · <a href="<?= e($o['drive_link']) ?>" target="_blank">Drive</a><?php endif; ?></div>
                <?php if ($o['reply_note']): ?><div class="small text-2 mt-1" style="white-space:pre-wrap"><b>Müşteri:</b> <?= e($o['reply_note']) ?></div><?php endif; ?>
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

        <!-- Time tracking -->
        <div class="card">
            <div class="row-flex between mb-2"><div class="card-title" style="font-size:14px">Zaman Takibi</div><button class="btn btn-sm" data-modal="modalTime"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button></div>
            <div class="orta" style="padding:8px 0"><div class="stat-value" style="font-size:26px"><?= format_minutes($totalMin) ?></div><div class="cell-bottom">toplam kayıtlı süre</div></div>
            <?php if ($times): ?><div class="vertical mt-2" style="gap:8px;max-height:200px;overflow-y:auto">
                <?php foreach ($times as $z): ?>
                <div class="row-flex between small" style="padding:7px 0;border-bottom:1px solid var(--border)"><div><div class="bold"><?= format_minutes($z['minutes']) ?></div><div class="cell-bottom"><?= e($z['name']) ?> · <?= format_date($z['date']) ?></div></div></div>
                <?php endforeach; ?>
            </div><?php endif; ?>
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
            <div class="form-hint">Gönderince iş "Müşteride" durumuna geçer; müşteri onaylarsa tamamlanır, revize isterse üretime döner.</div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Gönder</button></div>
    </form></div>
</div>

<!-- Modals -->
<div class="modal-overlay" id="modalTime">
    <div class="modal"><div class="modal-top"><div class="modal-title">Zaman Kaydı Ekle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="time_add" data-refresh="yes">
        <input type="hidden" name="task_id" value="<?= $id ?>">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Saat</label><input type="number" name="time" class="input" min="0" value="0"></div>
                <div class="form-group"><label class="form-label">Dakika</label><input type="number" name="minutes" class="input" min="0" max="59" value="30"></div>
            </div>
            <div class="form-group"><label class="form-label">Tarih</label><input type="date" name="date" class="input" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label class="form-label">Açıklama</label><input name="description" class="input" placeholder="Ne üzerinde çalıştınız?"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

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
            <div class="form-group"><label class="form-label">Öncelik</label><select name="priority" class="select"><?php foreach (PRIORITIES as $k => $v): ?><option value="<?= $k ?>" <?= $task['priority'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
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
    <div class="modal"><div class="modal-top"><div class="modal-title">Adım Sorumlusu</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="step_owner" data-refresh="yes">
        <input type="hidden" name="id" id="stepOwnerId">
        <div class="modal-body"><div class="form-group"><label class="form-label">Sorumlu Kişi</label><select name="owner_id" class="select"><option value="">— Kaldır</option><?php foreach ($team as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['name']) ?></option><?php endforeach; ?></select></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Ata</button></div>
    </form></div>
</div>

<script>
// Live sync: if someone else changes this task, the page refreshes
window.sadaLive = { context: 'task', id: <?= $id ?>, hash: '<?= live_hash_task($id) ?>' };
const CHECK_SVG = '<svg width="20" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>';

async function statusChange(status) {
    const j = await api('task_status', { id: <?= $id ?>, status });
    if (j.ok) { toast('Durum güncellendi', 'success'); liveRefresh(); setTimeout(() => location.reload(), 450); }
    else if (!['network', 'timeout'].includes(j.error)) setTimeout(() => location.reload(), 1600); // the lock rejected it → revert to the stored value (a network failure must not add a reload to a struggling server)
}
function stepOwner(id) { document.getElementById('stepOwnerId').value = id; modalOpen('modalStepOwner'); }

/* Workflow step: update without a page reload */
async function stepComplete(id) {
    const j = await api('step_complete', { id });
    if (!j.ok) return;
    toast(j.message, 'success', 1800);
    j.steps.forEach(a => {
        const el = document.querySelector(`[data-step="${a.id}"]`);
        if (!el) return;
        el.classList.toggle('done', a.status === 'done');
        el.classList.toggle('active', a.status === 'active');
        el.querySelector('.flow-circle').innerHTML = a.status === 'done' ? CHECK_SVG : el.dataset.sort_order;
    });
    document.getElementById('stepCounter').textContent = j.done_count + '/' + j.total + ' adım tamamlandı';
    // If the task is completed, sync the status picker and badge
    const picker = document.getElementById('statusPicker');
    if (picker && picker.value !== j.task_status) { picker.value = j.task_status; toast('İş durumu: ' + j.task_status_tag, 'success', 2400); }
    liveRefresh();
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

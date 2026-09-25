<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
$project = row("SELECT p.*, d.name client_name, d.color client_color, uu.name pm_name FROM projects p JOIN clients d ON d.id=p.client_id LEFT JOIN users uu ON uu.id=p.pm_id WHERE p.id=?", [$id]);
if (!$project || !project_access($id)) { header('Location: projects.php'); exit; }

$tasks = rows("SELECT g.*, u.name assignee_name, u.color assignee_color, u.avatar assignee_avatar,
    bg.status dependency_status, bg.title dependency_title,
    (SELECT COUNT(*) FROM task_checklist k WHERE k.task_id=g.id) check_total,
    (SELECT COUNT(*) FROM task_checklist k WHERE k.task_id=g.id AND k.is_done=1) check_is_done,
    (SELECT COUNT(*) FROM task_assignees gaa WHERE gaa.task_id=g.id) assignee_count,
    (SELECT GROUP_CONCAT(u3.name SEPARATOR ', ') FROM task_assignees ga3 JOIN users u3 ON u3.id=ga3.user_id WHERE ga3.task_id=g.id) assignee_names
    FROM tasks g LEFT JOIN users u ON u.id=g.assignee_id LEFT JOIN tasks bg ON bg.id=g.depends_on_id
    WHERE g.project_id=? AND g.is_archived=0 ORDER BY g.sort_order, g.due_date IS NULL, g.due_date", [$id]);
$doneTask = count(array_filter($tasks, fn($g) => $g['status'] === 'completed'));
$rate = count($tasks) ? round($doneTask / count($tasks) * 100) : 0;

$contents = rows("SELECT * FROM contents WHERE project_id=? ORDER BY date DESC LIMIT 30", [$id]);
$approvals = rows("SELECT o.*, u.name sender_name FROM approvals o LEFT JOIN users u ON u.id=o.sender_id WHERE o.project_id=? ORDER BY o.id DESC", [$id]);
$archives = rows("SELECT a.*, u.name uploader_name FROM archive a LEFT JOIN users u ON u.id=a.uploader_id WHERE a.project_id=? ORDER BY a.id DESC", [$id]);
$activities = rows("SELECT a.*, u.name FROM activities a JOIN users u ON u.id=a.user_id WHERE (a.ref_type='project' AND a.ref_id=?) ORDER BY a.id DESC LIMIT 30", [$id]);
$periods = $project['type'] === 'monthly' ? rows("SELECT d.*, (SELECT COUNT(*) FROM tasks g WHERE g.period_id=d.id) task_count FROM periods d WHERE d.project_id=? ORDER BY d.year DESC, d.month DESC", [$id]) : [];
$team = rows("SELECT id, name, color FROM users WHERE role IN ('admin','pm','team') AND is_active=1 ORDER BY name");
$templates = rows("SELECT * FROM workflow_templates ORDER BY name");
$projectMembers = rows("SELECT u.id, u.name, u.color, u.avatar, u.job_title FROM project_members pu JOIN users u ON u.id=pu.user_id WHERE pu.project_id=? AND u.is_active=1 ORDER BY u.name", [$id]);

// Station (SOP) data — visible only to staff
$canViewBudget = permission('budget_view');
$checkList = is_staff() ? rows("SELECT k.*, u.name owner_name FROM project_checklist k LEFT JOIN users u ON u.id=k.owner_id WHERE k.project_id=? ORDER BY k.sort_order", [$id]) : [];
$ekRequests = $canViewBudget ? rows("SELECT t.*, u.name creator_name FROM project_extra_requests t LEFT JOIN users u ON u.id=t.created_by WHERE t.project_id=? ORDER BY t.id DESC", [$id]) : [];
$reviews = [];
if (is_staff()) foreach (rows("SELECT * FROM project_review WHERE project_id=?", [$id]) as $dg) $reviews[$dg['type']] = $dg;
$revisionCount = (int)val("SELECT COUNT(*) FROM approvals WHERE project_id=? AND status='revision'", [$id]);
$teamRoles = json_decode($project['team_roles'] ?? '', true) ?: [];

page_start($project['name'], 'projects');
?>
<div class="row-flex mb-3" style="gap:10px">
    <a href="client.php?id=<?= $project['client_id'] ?>" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="text-muted small"><a href="client.php?id=<?= $project['client_id'] ?>" style="color:inherit"><?= e($project['client_name']) ?></a> / <?= e($project['name']) ?></span>
</div>

<div class="page-top">
    <div>
        <div class="row-flex" style="gap:10px">
            <span class="badge badge-type"><?= PROJECT_TYPES[$project['type']] ?></span>
            <?= badge($project['status'], PROJECT_STATUSES) ?>
        </div>
        <div class="page-title mt-1"><?= e($project['name']) ?></div>
        <?php if ($project['pm_name']): ?><div class="page-bottom">Proje Yöneticisi: <?= e($project['pm_name']) ?></div><?php endif; ?>
    </div>
    <?php if (is_staff()): ?>
    <div class="page-top-action">
        <a href="report.php?project=<?= $id ?>" target="_blank" class="btn"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 17h6M9 13h6M9 9h1m4 12H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6a1 1 0 01.7.3l5.4 5.4a1 1 0 01.3.7V19a2 2 0 01-2 2z"/></svg> Rapor</a>
        <button class="btn btn-brand" data-modal="modalTask"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Görev Ekle</button>
        <?php if (permission('client_manage')): ?><button class="btn" onclick="modalOpen('modalProjectEdit')"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="tab-container">
    <div class="tabs">
        <button class="tab active" data-tab="summary">Özet</button>
        <button class="tab" data-tab="tasks">Görevler <span class="badge" style="padding:1px 7px"><?= count($tasks) ?></span></button>
        <?php if ($project['type'] === 'monthly'): ?><button class="tab" data-tab="periods">Dönemler</button><?php endif; ?>
        <button class="tab" data-tab="approvals">Onaylar <?php if ($b = count(array_filter($approvals, fn($o) => $o['status'] === 'pending'))): ?><span class="badge r-pending" style="padding:1px 7px"><?= $b ?></span><?php endif; ?></button>
        <button class="tab" data-tab="content">İçerikler</button>
        <?php if (is_staff()): ?><button class="tab" data-tab="station">İstasyon <?php if ($checkList && ($missingChecks = count(array_filter($checkList, fn($k) => !$k['is_done'])))): ?><span class="badge r-pending" style="padding:1px 7px"><?= $missingChecks ?></span><?php endif; ?></button><?php endif; ?>
        <button class="tab" data-tab="discussion">Tartışma <?php if ($commentCount = (int)val("SELECT COUNT(*) FROM comments WHERE ref_type='project' AND ref_id=?", [$id])): ?><span class="badge" style="padding:1px 7px"><?= $commentCount ?></span><?php endif; ?></button>
        <button class="tab" data-tab="archive">Arşiv</button>
        <button class="tab" data-tab="activity">Aktivite</button>
    </div>

    <!-- SUMMARY -->
    <div class="tab-content active" id="tab-summary">
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-value"><?= $rate ?>%</div><div class="stat-label">Tamamlanma</div><div class="progress mt-2"><div class="progress-full" data-rate="<?= $rate ?>" style="width:0"></div></div></div>
            <div class="stat-card"><div class="stat-value" data-counter="<?= count($tasks) ?>">0</div><div class="stat-label">Toplam Görev</div></div>
            <div class="stat-card"><div class="stat-value" data-counter="<?= count(array_filter($tasks, fn($g) => in_array($g['status'], ['in_progress','in_review','awaiting_approval']))) ?>">0</div><div class="stat-label">Devam Eden</div></div>
            <?php if (is_pm() && $project['contract_amount'] > 0): ?>
            <div class="stat-card"><div class="stat-value" style="font-size:22px"><?= money($project['contract_amount']) ?></div><div class="stat-label">Sözleşme Tutarı</div></div>
            <?php endif; ?>
        </div>
        <div class="grid grid-2">
            <div class="card">
                <div class="card-title mb-2">Proje Bilgileri</div>
                <div class="vertical mt-2" style="gap:12px">
                    <div class="row-flex between"><span class="cell-bottom">Dosya</span><span class="cell-main"><?= e($project['client_name']) ?></span></div>
                    <div class="row-flex between"><span class="cell-bottom">Tür</span><span><?= PROJECT_TYPES[$project['type']] ?></span></div>
                    <div class="row-flex between"><span class="cell-bottom">Başlangıç</span><span><?= format_date($project['start']) ?></span></div>
                    <div class="row-flex between"><span class="cell-bottom">Bitiş</span><span><?= format_date($project['end']) ?></span></div>
                    <?php if ($projectMembers): ?>
                    <div class="row-flex between"><span class="cell-bottom">Atanan Ekip</span><?= member_avatars($projectMembers) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($project['description']): ?><div class="mt-3"><div class="cell-bottom mb-2">Açıklama</div><div class="small text-2"><?= nl2br(e($project['description'])) ?></div></div><?php endif; ?>
            </div>
            <div class="card">
                <div class="card-title mb-2">Yaklaşan Görevler</div>
                <?php $upcoming = array_filter($tasks, fn($g) => $g['status'] !== 'completed' && $g['due_date']);
                usort($upcoming, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));
                $upcoming = array_slice($upcoming, 0, 5);
                if (!$upcoming): ?><div class="text-muted small mt-2">Tarihi belirlenmiş görev yok.</div>
                <?php else: foreach ($upcoming as $gr): $overdue = $gr['due_date'] < date('Y-m-d'); ?>
                <a href="task.php?id=<?= $gr['id'] ?>" class="row-flex between" style="padding:10px 0;border-bottom:1px solid var(--border)">
                    <span class="small bold" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($gr['title']) ?></span>
                    <span class="badge <?= $overdue ? 'r-urgent' : 'r-normal' ?>"><?= format_date($gr['due_date']) ?></span>
                </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <?php if (is_customer()):
        $completed_items = array_filter($tasks, fn($g) => $g['status'] === 'completed');
        $taskRatings = array_column(rows("SELECT ref_id, rating FROM ratings WHERE ref_type='task' AND user_id=? AND project_id=?", [$u['id'], $id]), 'rating', 'ref_id');
        if ($completed_items): ?>
    <!-- Client: rate completed work -->
    <div class="card mt-3" id="tab-summary-rating">
        <div class="card-title mb-2"><?= icon('star', 16) ?> Tamamlanan İşleri Değerlendirin</div>
        <div class="cell-bottom mb-3">Görüşleriniz hizmet kalitemizi doğrudan şekillendirir.</div>
        <div class="vertical" style="gap:6px">
            <?php foreach (array_slice($completed_items, 0, 10) as $tg): ?>
            <div class="row-flex between" style="padding:9px 12px;background:var(--surface-2);border-radius:10px;gap:10px">
                <span class="small bold" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($tg['title']) ?></span>
                <?php if (isset($taskRatings[$tg['id']])): ?>
                <button class="btn btn-sm" onclick="ratingGive('task', <?= $tg['id'] ?>, '<?= e($tg['title']) ?>')" title="Puanı güncelle"><?= stars((float)$taskRatings[$tg['id']], 13) ?></button>
                <?php else: ?>
                <button class="btn btn-brand btn-sm" onclick="ratingGive('task', <?= $tg['id'] ?>, '<?= e($tg['title']) ?>')" style="flex-shrink:0"><?= icon('star', 13) ?> Değerlendir</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; endif; ?>

    <!-- TASKS -->
    <div class="tab-content" id="tab-tasks">
        <div class="row-flex between mb-2">
            <div class="text-muted small">Görevleri sürükleyerek durumlarını değiştirebilirsiniz</div>
            <a href="tasks.php?project=<?= $id ?>" class="btn btn-sm">Tam Kanban Görünümü →</a>
        </div>
        <?php task_kanban($tasks, $id); ?>
    </div>

    <?php if ($project['type'] === 'monthly'): ?>
    <!-- PERIODS -->
    <div class="tab-content" id="tab-periods">
        <div class="row-flex between mb-3">
            <div class="card-title">Aylık Dönemler</div>
            <?php if (is_staff()): ?><button class="btn btn-brand btn-sm" data-modal="modalPeriod"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Dönem Aç</button><?php endif; ?>
        </div>
        <?php if (!$periods): ?>
        <div class="empty-state"><div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div><div class="empty-title">Henüz dönem açılmamış</div><div class="empty-text">Aylık düzenli hizmet için ilk dönemi açın; şablondan görevler otomatik oluşturulabilir.</div></div>
        <?php else: ?>
        <div class="grid grid-3">
            <?php foreach ($periods as $d): ?>
            <a href="tasks.php?project=<?= $id ?>&period=<?= $d['id'] ?>" class="card card-tick">
                <div class="row-flex between mb-2"><div class="card-title" style="font-size:15px"><?= period_name($d) ?></div><?= badge($d['status'], PERIOD_STATUSES) ?></div>
                <div class="cell-bottom"><?= $d['task_count'] ?> görev</div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- APPROVALS -->
    <div class="tab-content" id="tab-approvals">
        <div class="row-flex between mb-3">
            <div class="card-title">Onay Süreçleri</div>
            <?php if (is_staff()): ?><button class="btn btn-brand btn-sm" data-modal="modalApproval"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Onaya Gönder</button><?php endif; ?>
        </div>
        <?php if (!$approvals): ?>
        <div class="text-muted small orta card" style="padding:30px">Henüz onay süreci başlatılmamış.</div>
        <?php else: foreach ($approvals as $o): ?>
        <div class="card mb-2">
            <div class="row-flex between">
                <div style="min-width:0">
                    <div class="row-flex" style="gap:9px"><span class="bold"><?= e($o['title']) ?></span><?= badge($o['status'], APPROVAL_STATUSES) ?></div>
                    <?php if ($o['description']): ?><div class="cell-bottom mt-1"><?= e($o['description']) ?></div><?php endif; ?>
                    <div class="cell-bottom mt-1"><?= e($o['sender_name']) ?> · <?= time_ago($o['created']) ?><?php if ($o['archive_id']): $ar = row("SELECT * FROM archive WHERE id=?", [$o['archive_id']]); if ($ar): ?> · <a href="uploads/<?= e($ar['file_path']) ?>" target="_blank" style="color:var(--brand)"><?= icon('paperclip', 12) ?> <?= e($ar['name']) ?></a><?php endif; endif; ?></div>
                    <?php if ($o['reply_note']): ?><div class="mt-2" style="padding:10px 14px;background:var(--surface-2);border-radius:10px;font-size:13px"><b>Müşteri notu:</b> <?= nl2br(e($o['reply_note'])) ?></div><?php endif; ?>
                </div>
                <?php if ($o['status'] === 'pending' && (is_customer() || is_admin())): ?>
                <div class="row-flex" style="gap:6px;flex-shrink:0">
                    <button class="btn btn-sm" style="color:var(--success)" data-action="approval_reply" data-id="<?= $o['id'] ?>" data-status="approved">Onayla</button>
                    <button class="btn btn-sm" onclick="approvalNot(<?= $o['id'] ?>,'revision')">Revize</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- CONTENTS -->
    <div class="tab-content" id="tab-content">
        <div class="row-flex between mb-3">
            <div class="card-title">İçerikler</div>
            <a href="content-calendar.php?project=<?= $id ?>" class="btn btn-sm">Takvim Görünümü →</a>
        </div>
        <?php if (!$contents): ?>
        <div class="text-muted small orta card" style="padding:30px">Bu proje için içerik planlanmamış.</div>
        <?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>İçerik</th><th>Platform</th><th>Tarih</th><th>Durum</th></tr></thead><tbody>
            <?php foreach ($contents as $contentItem): ?>
            <tr><td class="cell-main"><?= e($contentItem['title']) ?></td><td><?= platform_badges($contentItem['platform']) ?></td><td><?= format_date($contentItem['date']) ?></td><td><?= badge($contentItem['status'], CONTENT_STATUSES) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>

    <!-- DISCUSSION -->
    <?php if (is_staff()): ?>
    <!-- STATION: SOP project brief + budget + technical checks + review -->
    <div class="tab-content" id="tab-station">
        <div class="grid grid-2" style="align-items:start">
            <div class="vertical" style="gap:16px">
                <!-- Project brief -->
                <div class="card">
                    <div class="card-title mb-2">Proje Künyesi</div>
                    <form data-ajax="station_save" data-refresh="no">
                        <input type="hidden" name="project_id" value="<?= $id ?>">
                        <input type="hidden" name="team_roles" id="st_roles">
                        <div class="vertical small mb-2" style="gap:7px">
                            <div class="row-flex between"><span class="text-muted">Müşteri / Marka</span><b><?= e($project['client_name']) ?></b></div>
                            <div class="row-flex between"><span class="text-muted">Proje Tipi</span><b><?= PROJECT_TYPES[$project['type']] ?? $project['type'] ?></b></div>
                            <div class="row-flex between"><span class="text-muted">Tarih Aralığı</span><b><?= $project['start'] ? format_date($project['start']) : '—' ?> → <?= $project['end'] ? format_date($project['end']) : '—' ?></b></div>
                        </div>
                        <div class="form-group"><label class="form-label">Devralma Noktası</label><input name="handover" class="input" value="<?= e($project['handover'] ?? '') ?>" placeholder="Örn. Etkinliğe 2 hafta kala devralındı / Sıfırdan lansman" <?= permission('client_manage') ? '' : 'disabled' ?>></div>
                        <div class="form-group">
                            <label class="form-label">Ekip ve Rol Dağılımı</label>
                            <div class="vertical" id="stRoleList" style="gap:7px"></div>
                            <?php if (permission('client_manage')): ?><button type="button" class="btn btn-sm btn-ghost mt-1" onclick="stRoleAdd()">+ Rol Ekle</button><?php endif; ?>
                        </div>
                        <?php if ($canViewBudget): ?>
                        <div class="form-row">
                            <div class="form-group"><label class="form-label">Onaylı Ana Bütçe (₺)</label><input name="budget" class="input" value="<?= $project['budget'] > 0 ? number_format((float)$project['budget'], 2, '.', '') : '' ?>" placeholder="0.00"></div>
                            <div class="form-group"><label class="form-label">Revize Hakkı</label><input name="revision_limit" type="number" class="input" value="<?= (int)($project['revision_limit'] ?? 2) ?>" min="0" max="20"></div>
                        </div>
                        <?php endif; ?>
                        <?php if (permission('client_manage')): ?><button type="submit" class="btn btn-brand">Künyeyi Kaydet</button><?php endif; ?>
                    </form>
                </div>

                <?php if ($canViewBudget): ?>
                <!-- Budget & Extra Requests (only for those with budget permission) -->
                <div class="card" style="border-color:var(--warning)">
                    <div class="row-flex between mb-2">
                        <div class="card-title">Bütçe & Ek Talepler <span class="badge r-pending" style="padding:1px 8px">Kısıtlı görünüm</span></div>
                        <button class="btn btn-sm" data-modal="modalEkRequest">+ Ek Talep</button>
                    </div>
                    <div class="vertical small mb-2" style="gap:6px">
                        <div class="row-flex between"><span class="text-muted">Onaylı ana bütçe</span><b><?= number_format((float)$project['budget'], 2, ',', '.') ?> ₺</b></div>
                        <div class="row-flex between"><span class="text-muted">Onaylanan ek talepler</span><b>+<?= number_format(array_sum(array_map(fn($t) => $t['status'] === 'approved' ? (float)$t['amount'] : 0, $ekRequests)), 2, ',', '.') ?> ₺</b></div>
                        <div class="row-flex between"><span class="text-muted">Kullanılan revize</span><b style="color:<?= $revisionCount > (int)$project['revision_limit'] ? 'var(--danger)' : 'inherit' ?>"><?= $revisionCount ?> / <?= (int)$project['revision_limit'] ?></b></div>
                    </div>
                    <?php if ($revisionCount > (int)$project['revision_limit']): ?><div class="small mb-2" style="color:var(--danger)">⚠️ Revize limiti aşıldı — SOP gereği sonraki majör revizeler ek bütçeye tabidir.</div><?php endif; ?>
                    <?php if ($ekRequests): ?>
                    <div class="vertical" style="gap:6px">
                        <?php foreach ($ekRequests as $t): ?>
                        <div class="row-flex between small" style="padding:9px 11px;background:var(--surface-2);border-radius:9px;gap:8px">
                            <div style="min-width:0"><b><?= e($t['title']) ?></b><?= $t['out_of_scope'] ? ' <span class="badge badge-type" style="padding:0 7px">Kapsam dışı</span>' : '' ?><div class="cell-bottom"><?= e($t['creator_name']) ?> · <?= format_date($t['created']) ?><?= $t['description'] ? ' — ' . e($t['description']) : '' ?></div></div>
                            <div class="row-flex" style="gap:7px;flex-shrink:0">
                                <b><?= number_format((float)$t['amount'], 2, ',', '.') ?> ₺</b>
                                <?php if ($t['status'] === 'pending'): ?>
                                <button class="mini-btn" style="color:var(--success)" onclick="extraRequestStatus(<?= $t['id'] ?>, 'approved', this)">Onayla</button>
                                <button class="mini-btn" style="color:var(--danger)" onclick="extraRequestStatus(<?= $t['id'] ?>, 'rejected', this)">Reddet</button>
                                <?php else: ?><?= badge($t['status'], EXTRA_REQUEST_STATUSES) ?><?php endif; ?>
                                <button class="icon-action danger" style="width:24px;height:24px" data-action="extra_request_delete" data-id="<?= $t['id'] ?>" data-confirm="Ek talep silinsin mi?"><?= icon('cop', 12) ?></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?><div class="text-muted small">Süreç içi ek talep kaydı yok. Müşteriden gelen kapsam dışı istekleri buraya işleyin.</div><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="vertical" style="gap:16px">
                <!-- Technical Checklist -->
                <div class="card">
                    <div class="row-flex between mb-2">
                        <div class="card-title">Saha & Teknik Kontrol</div>
                        <div class="row-flex" style="gap:6px">
                            <?php if (!$checkList): ?><button class="mini-btn" data-action="pcheck_standard" data-project_id="<?= $id ?>">SOP standart listesini yükle</button><?php endif; ?>
                        </div>
                    </div>
                    <div class="vertical" style="gap:6px" id="pkList">
                        <?php foreach ($checkList as $k): ?>
                        <div class="row-flex small" style="padding:9px 11px;background:var(--surface-2);border-radius:9px;gap:9px">
                            <input type="checkbox" <?= $k['is_done'] ? 'checked' : '' ?> onchange="pkToggle(<?= $k['id'] ?>, 'done')" title="Kontrol tamam">
                            <div style="flex:1;min-width:0">
                                <b <?= $k['is_done'] ? 'style="text-decoration:line-through;opacity:.6"' : '' ?>><?= e($k['item']) ?></b>
                                <?php if ($k['check_note']): ?><div class="cell-bottom"><?= e($k['check_note']) ?></div><?php endif; ?>
                            </div>
                            <button class="mini-btn" onclick="pkOwner(<?= $k['id'] ?>)" title="Sorumlu ata"><?= $k['owner_name'] ? e(explode(' ', $k['owner_name'])[0]) : '+ sorumlu' ?></button>
                            <button class="mini-btn <?= $k['is_delivered'] ? '' : '' ?>" style="color:<?= $k['is_delivered'] ? 'var(--success)' : 'var(--muted)' ?>" onclick="pkToggle(<?= $k['id'] ?>, 'delivery', this)" title="Ekipman teslim edildi mi?"><?= $k['is_delivered'] ? '✓ Teslim' : 'Teslim?' ?></button>
                            <button class="icon-action danger" style="width:24px;height:24px" onclick="pkDelete(<?= $k['id'] ?>, this)"><?= icon('cop', 12) ?></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <form class="row-flex mt-2" style="gap:8px" onsubmit="pkAdd(event)">
                        <input class="input" id="pkNew" placeholder="Yeni kontrol kalemi (ekipman, izin, hazırlık...)" style="flex:1">
                        <button class="btn btn-sm" type="submit">Ekle</button>
                    </form>
                </div>

                <!-- Review (Post-Mortem) -->
                <div class="card">
                    <div class="card-title mb-2">Değerlendirme (Post-Mortem)</div>
                    <?php $reviewTypes = ['internal' => ['İç Değerlendirme (Ekip)', 'Neleri iyi yaptık? Nerede zaman/bütçe/enerji kaybı yaşandı? Bir sonraki projede neyi farklı yapmalıyız? Yaşanan aksilikler...'], 'external' => ['Dış Değerlendirme (Kurum)', 'Müşteri memnuniyeti, alınan olumlu/olumsuz geri bildirimler...'], 'case_study' => ['Web Sitesi Case Study İçeriği', 'Projenin amacı, erişim/etkileşim metrikleri, öne çıkan görsel ve videolar...']];
                    foreach ($reviewTypes as $dtCode => [$dtTitle, $dtHint]): $dg = $reviews[$dtCode] ?? null; ?>
                    <div class="form-group">
                        <label class="form-label"><?= $dtTitle ?><?php if ($dg): ?> <span class="text-muted" style="font-weight:400">· <?= format_date($dg['updated'], true) ?></span><?php endif; ?></label>
                        <textarea class="text-area" rows="3" id="deg_<?= $dtCode ?>" placeholder="<?= e($dtHint) ?>"><?= e($dg['content'] ?? '') ?></textarea>
                        <button class="mini-btn mt-1" onclick="reviewSave('<?= $dtCode ?>')">Kaydet</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="tab-content" id="tab-discussion">
        <div class="card">
            <div class="card-title mb-2">Proje Tartışması</div>
            <div class="cell-bottom mb-3">Ekip ve müşteri bu alanda proje hakkında konuşabilir. @ yazarak birini etiketleyin.</div>
            <?php comment_feed('project', $id); ?>
        </div>
    </div>

    <!-- ARCHIVE -->
    <div class="tab-content" id="tab-archive">
        <div class="row-flex between mb-3">
            <div class="card-title">Dosya Arşivi</div>
            <button class="btn btn-brand btn-sm" data-modal="modalUpload"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Dosya Yükle</button>
        </div>
        <?php if (!$archives): ?>
        <div class="text-muted small orta card" style="padding:30px">Arşivde dosya yok.</div>
        <?php else: ?>
        <div class="grid grid-auto">
            <?php foreach ($archives as $a): ?>
            <div class="card" style="padding:14px">
                <div class="row-flex between">
                    <a href="uploads/<?= e($a['file_path']) ?>" target="_blank" class="row-flex" style="gap:10px;min-width:0">
                        <div class="file-avatar" style="width:36px;height:36px;font-size:11px;background:var(--bright);color:var(--brand)"><?= e(mb_strtoupper($a['extension'] ?: '?')) ?></div>
                        <div style="min-width:0"><div class="cell-main small" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($a['name']) ?></div><div class="cell-bottom"><?= format_size($a['size']) ?> · <?= time_ago($a['created']) ?></div></div>
                    </a>
                    <?php if (is_staff()): ?><button class="icon-action danger" data-action="archive_delete" data-id="<?= $a['id'] ?>" data-confirm="Silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/></svg></button><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ACTIVITY -->
    <div class="tab-content" id="tab-activity">
        <div class="card">
            <div class="card-title mb-3">Proje Geçmişi</div>
            <?php if (!$activities): ?><div class="text-muted small">Henüz aktivite yok.</div>
            <?php else: ?><div class="activity-feed"><?php foreach ($activities as $a): ?>
            <div class="feed-item"><div class="feed-text"><b><?= e($a['name']) ?></b> <?= e($a['description']) ?></div><div class="feed-time"><?= format_date($a['created'], true) ?></div></div>
            <?php endforeach; ?></div><?php endif; ?>
        </div>
    </div>
</div>

<?php
// ---- Modals ----
if (is_staff()) task_modal($id, $team, $templates, $periods);
?>

<!-- Send-for-approval modal -->
<div class="modal-overlay" id="modalApproval">
    <div class="modal"><div class="modal-top"><div class="modal-title">Müşteri Onayına Gönder</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="approval_send" data-refresh="yes">
        <input type="hidden" name="project_id" value="<?= $id ?>">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık <span class="required">*</span></label><input name="title" class="input" required placeholder="Örn. Ekim ayı 1. gönderi tasarımı"></div>
            <div class="form-group"><label class="form-label">Açıklama / Not</label><textarea name="description" class="text-area" placeholder="Müşteriye iletmek istedikleriniz..."></textarea></div>
            <div class="form-group"><label class="form-label">Dosya Eki</label><input type="file" name="file" class="input"><div class="form-hint">Görsel, PDF, video vb. (max 50MB)</div></div>
            <div class="form-group"><label class="form-label">veya Drive Linki</label><input name="drive_link" class="input" placeholder="https://drive.google.com/..."></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Onaya Gönder</button></div>
    </form></div>
</div>

<!-- File upload modal -->
<div class="modal-overlay" id="modalUpload">
    <div class="modal"><div class="modal-top"><div class="modal-title">Dosya Yükle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="archive_upload" data-refresh="yes">
        <input type="hidden" name="project_id" value="<?= $id ?>">
        <div class="modal-body"><div class="form-group"><label class="form-label">Dosya Seç <span class="required">*</span></label><input type="file" name="file" class="input" required></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Yükle</button></div>
    </form></div>
</div>

<?php if ($project['type'] === 'monthly' && is_staff()): ?>
<!-- Open-period modal -->
<div class="modal-overlay" id="modalPeriod">
    <div class="modal"><div class="modal-top"><div class="modal-title">Yeni Dönem Aç</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="period_open" data-refresh="yes">
        <input type="hidden" name="project_id" value="<?= $id ?>">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Ay</label><select name="month" class="select"><?php foreach (MONTHS as $k => $v): ?><option value="<?= $k ?>" <?= $k == date('n') ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Yıl</label><select name="year" class="select"><?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?><option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
            </div>
            <div class="form-group"><label class="form-label">Akış Şablonundan Görev Oluştur</label><select name="template_id" class="select"><option value="">Boş dönem</option><?php foreach ($templates as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select><div class="form-hint">Seçilen şablonun adımları görev akışı olarak eklenir.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Dönemi Aç</button></div>
    </form></div>
</div>
<?php endif; ?>

<?php if (permission('client_manage')):
$clients = rows("SELECT id, name FROM clients WHERE status='active' ORDER BY name");
$pms = rows("SELECT id, name FROM users WHERE role IN ('admin','pm') AND is_active=1 ORDER BY name"); ?>
<!-- Edit project -->
<div class="modal-overlay" id="modalProjectEdit">
    <div class="modal"><div class="modal-top"><div class="modal-title">Projeyi Düzenle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="project_save">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Proje Adı</label><input name="name" class="input" value="<?= e($project['name']) ?>" required></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Dosya</label><select name="client_id" class="select"><?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $d['id'] == $project['client_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Tür</label><select name="type" class="select"><?php foreach (PROJECT_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $project['type'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Durum</label><select name="status" class="select"><?php foreach (PROJECT_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $project['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">PM</label><select name="pm_id" class="select"><option value="">—</option><?php foreach ($pms as $pm): ?><option value="<?= $pm['id'] ?>" <?= $pm['id'] == $project['pm_id'] ? 'selected' : '' ?>><?= e($pm['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Başlangıç</label><input type="date" name="start" class="input" value="<?= e($project['start']) ?>"></div>
                <div class="form-group"><label class="form-label">Bitiş</label><input type="date" name="end" class="input" value="<?= e($project['end']) ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Sözleşme Tutarı (₺)</label><input name="contract_amount" class="input" value="<?= $project['contract_amount'] ?>"></div>
            <?php member_picker(array_column($projectMembers, 'id')); ?>
            <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" class="text-area"><?= e($project['description']) ?></textarea></div>
        </div>
        <div class="modal-alt">
            <?php if (is_admin()): ?><button type="button" class="btn btn-danger" data-action="project_delete" data-id="<?= $id ?>" data-confirm="Proje ve tüm görevleri silinecek. Emin misiniz?" style="margin-right:auto">Sil</button><?php endif; ?>
            <button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button>
        </div>
    </form></div>
</div>
<?php endif; ?>

<?php rating_modal(); ?>

<!-- Approval note (revision) modal -->
<div class="modal-overlay" id="modalApprovalNot">
    <div class="modal"><div class="modal-top"><div class="modal-title">Revize / Not Ekle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="approval_reply">
        <input type="hidden" name="id" id="approvalNotId"><input type="hidden" name="status" id="approvalNotStatus">
        <div class="modal-body"><div class="form-group"><label class="form-label">Notunuz</label><textarea name="note" class="text-area" required placeholder="Değişiklik taleplerinizi yazın..."></textarea></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Gönder</button></div>
    </form></div>
</div>
<script>
function approvalNot(id, status) { document.getElementById('approvalNotId').value = id; document.getElementById('approvalNotStatus').value = status; modalOpen('modalApprovalNot'); }
</script>

<?php if (is_staff()): ?>
<!-- Extra request modal (budget permission) -->
<?php if ($canViewBudget): ?>
<div class="modal-overlay" id="modalEkRequest">
    <div class="modal"><div class="modal-top"><div class="modal-title">Ek Talep Kaydet</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="extra_request_save">
        <input type="hidden" name="project_id" value="<?= $id ?>">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Talep <span class="required">*</span></label><input name="title" class="input" required placeholder="Örn. Ek drone çekimi istendi"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tahmini Tutar (₺)</label><input name="amount" class="input" placeholder="0.00"></div>
                <div class="form-group" style="display:flex;align-items:flex-end"><label class="row-flex" style="gap:8px;cursor:pointer;padding-bottom:10px"><input type="checkbox" name="out_of_scope" value="1" checked> Kapsam dışı (ek bütçe)</label></div>
            </div>
            <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" class="text-area" placeholder="Talebin detayı, kim istedi..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
/* ---- Station: team role distribution editor ---- */
const stTeam = <?= json_encode(array_map(fn($e2) => ['id' => $e2['id'], 'name' => $e2['name']], $team), JSON_UNESCAPED_UNICODE) ?>;
const stRoles = <?= json_encode($teamRoles, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
const stEditable = <?= permission('client_manage') ? 'true' : 'false' ?>;
function stRoleAdd(role = '', person = '') {
    const list = document.getElementById('stRoleList');
    if (!list) return;
    const div = document.createElement('div');
    div.className = 'row-flex station-role';
    div.style.gap = '7px';
    let ops = '<option value="">Kişi seçin</option>';
    stTeam.forEach(k => ops += `<option value="${k.name.replace(/"/g, '&quot;')}" ${k.name === person ? 'selected' : ''}>${k.name}</option>`);
    div.innerHTML = `<input class="input station-role-name" placeholder="Rol (örn. Art Director)" value="${role.replace(/"/g, '&quot;')}" style="flex:1" ${stEditable ? '' : 'disabled'}>
        <select class="select native-select station-role-person" style="flex:1" ${stEditable ? '' : 'disabled'}>${ops}</select>
        ${stEditable ? '<button type="button" class="icon-action danger" onclick="this.parentElement.remove();stRoleWrite()">✕</button>' : ''}`;
    list.appendChild(div);
}
function stRoleWrite() {
    const roles = Array.from(document.querySelectorAll('.station-role')).map(s => ({
        role: s.querySelector('.station-role-name').value.trim(),
        person: s.querySelector('.station-role-person').value,
    })).filter(r => r.role || r.person);
    const hidden = document.getElementById('st_roles');
    if (hidden) hidden.value = JSON.stringify(roles);
}
(stRoles.length ? stRoles : (stEditable ? [{role: '', person: ''}] : [])).forEach(r => stRoleAdd(r.role || '', r.person || ''));
stRoleWrite();
document.getElementById('stRoleList')?.closest('form')?.addEventListener('submit', stRoleWrite);
document.getElementById('stRoleList')?.addEventListener('input', stRoleWrite);
document.getElementById('stRoleList')?.addEventListener('change', stRoleWrite);

/* ---- Station: checklist ---- */
async function pkAdd(e) {
    e.preventDefault();
    const input = document.getElementById('pkNew');
    if (!input.value.trim()) return;
    const j = await api('pcheck_add', { project_id: <?= $id ?>, item: input.value.trim() });
    if (j.ok) location.reload();
}
async function pkToggle(id, field, btn) {
    const j = await api('pcheck_toggle', { id, field });
    if (j.ok && field === 'done') location.reload();
    if (j.ok && btn) { const open = btn.textContent.includes('?'); btn.textContent = open ? '✓ Teslim' : 'Teslim?'; btn.style.color = open ? 'var(--success)' : 'var(--muted)'; }
}
async function pkDelete(id, btn) {
    if (!confirm('Kontrol kalemi silinsin mi?')) return;
    const j = await api('pcheck_delete', { id });
    if (j.ok) btn.closest('.row-flex').remove();
}
async function pkOwner(id) {
    let option = 'Sorumlu seçin:\n0 — Sorumsuz bırak\n';
    stTeam.forEach((k, i) => option += (i + 1) + ' — ' + k.name + '\n');
    const pick = prompt(option);
    if (pick === null) return;
    const idx = parseInt(pick);
    const j = await api('pcheck_owner', { id, owner_id: idx > 0 && stTeam[idx - 1] ? stTeam[idx - 1].id : 0 });
    if (j.ok) location.reload();
}

/* ---- Station: review + extra request ---- */
async function reviewSave(type) {
    const j = await api('review_save', { project_id: <?= $id ?>, type, content: document.getElementById('review_' + type).value });
    if (j.ok) toast(j.message, 'success');
}
async function extraRequestStatus(id, status, btn) {
    const j = await api('extra_request_status', { id, status });
    if (j.ok) location.reload();
}
</script>
<?php endif; ?>

<?php
page_end();

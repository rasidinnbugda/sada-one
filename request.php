<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
$request = row("SELECT t.*, f.name form_name, ug.name sender_name, ug.color sender_color, d.name client_name, p.name project_name, ua.name assignee_name
    FROM requests t JOIN form_templates f ON f.id=t.template_id LEFT JOIN users ug ON ug.id=t.sender_id
    LEFT JOIN clients d ON d.id=t.client_id LEFT JOIN projects p ON p.id=t.project_id LEFT JOIN users ua ON ua.id=t.assignee_id WHERE t.id=?", [$id]);
if (!$request) { header('Location: requests.php'); exit; }
if (is_customer() && $request['sender_id'] != $u['id']) { header('Location: requests.php'); exit; }

$replies = rows("SELECT tc.*, fa.label, fa.type FROM request_replies tc JOIN form_fields fa ON fa.id=tc.field_id WHERE tc.request_id=? ORDER BY fa.sort_order", [$id]);
$projects = rows("SELECT id, name FROM projects WHERE " . ($request['client_id'] ? "client_id=" . (int)$request['client_id'] : "status='active'") . " ORDER BY name");
$team = rows("SELECT id, name FROM users WHERE role IN ('admin','pm','team') AND is_active=1 ORDER BY name");

page_start('Talep Detayı', 'requests');
?>
<div class="row-flex mb-3" style="gap:10px">
    <a href="requests.php" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="text-muted small">Talepler / #<?= $id ?></span>
</div>

<div class="page-top">
    <div>
        <div class="row-flex" style="gap:9px"><span class="badge badge-type"><?= e($request['form_name']) ?></span><?= badge($request['status'], REQUEST_STATUSES) ?></div>
        <div class="page-title mt-1"><?= e($request['title']) ?></div>
        <div class="page-bottom"><?= e($request['sender_name']) ?> · <?= format_date($request['created'], true) ?></div>
    </div>
</div>

<div class="grid" style="grid-template-columns:1fr 300px">
    <div class="card">
        <div class="card-title mb-3">Talep Bilgileri</div>
        <div class="vertical" style="gap:16px">
            <?php foreach ($replies as $c): ?>
            <div><div class="cell-bottom mb-2"><?= e($c['label']) ?></div><div class="text-2" style="white-space:pre-wrap"><?php if (in_array($c['type'], ['file', 'multi_file']) && $c['value']): ?><span class="row-flex wrap" style="gap:6px"><?php foreach (array_filter(explode(',', $c['value'])) as $di => $dp): ?><a href="uploads/<?= e($dp) ?>" target="_blank" class="btn btn-sm"><?= icon('paperclip', 13) ?> Dosya <?= $di + 1 ?></a><?php endforeach; ?></span><?php else: ?><?= $c['value'] ? e($c['value']) : '<span class="text-muted">—</span>' ?><?php endif; ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <?php if (is_pm()): ?>
        <div class="card mb-2">
            <div class="card-title mb-3" style="font-size:14px">Yönetim</div>
            <div class="form-group">
                <label class="form-label">Durum</label>
                <select class="select" onchange="requestStatus(this.value)">
                    <?php foreach (REQUEST_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $request['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if (!$request['project_id']): ?>
            <div class="form-group">
                <label class="form-label">Projeye Bağla</label>
                <select class="select" onchange="requestProject(this.value)">
                    <option value="">Seçin...</option>
                    <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($request['status'] !== 'task_created' && $request['task_id'] === null): ?>
            <button class="btn btn-brand btn-block mt-2" data-action="request_to_task" data-id="<?= $id ?>" data-confirm="Bu talep bir göreve dönüştürülsün mü?">Göreve Dönüştür</button>
            <div class="form-hint">Not: Önce bir proje bağlamanız gerekir.</div>
            <?php elseif ($request['task_id']): ?>
            <a href="task.php?id=<?= $request['task_id'] ?>" class="btn btn-block mt-2">Oluşturulan Görevi Aç →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="card">
            <div class="vertical" style="gap:12px">
                <div class="row-flex between"><span class="cell-bottom">Dosya</span><span class="small"><?= e($request['client_name'] ?? '—') ?></span></div>
                <div class="row-flex between"><span class="cell-bottom">Proje</span><span class="small"><?= e($request['project_name'] ?? '—') ?></span></div>
                <div class="row-flex between"><span class="cell-bottom">Atanan</span><span class="small"><?= e($request['assignee_name'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>
</div>

<script>
async function requestStatus(status) { const j = await api('request_status', {id:<?= $id ?>, status}); if (j.ok) toast('Güncellendi', 'success'); }
async function requestProject(pid) { if (!pid) return; const j = await api('request_project', {id:<?= $id ?>, project_id:pid}); if (j.ok) { toast('Proje bağlandı', 'success'); setTimeout(()=>location.reload(),600); } }
</script>
<?php page_end(); ?>

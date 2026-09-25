<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

if (is_staff()) {
    $projects = rows("SELECT p.*, d.name client_name, d.color client_color, uu.name pm_name,
        (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id) task_count,
        (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id AND g.status='completed') is_done_count
        FROM projects p JOIN clients d ON d.id=p.client_id LEFT JOIN users uu ON uu.id=p.pm_id
        ORDER BY p.status='active' DESC, p.created DESC");
} else {
    $projects = rows("SELECT p.*, d.name client_name, d.color client_color, uu.name pm_name,
        (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id) task_count,
        (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id AND g.status='completed') is_done_count
        FROM projects p JOIN clients d ON d.id=p.client_id LEFT JOIN users uu ON uu.id=p.pm_id
        WHERE p.client_id IN " . in_clause(customer_client_ids())[0] . " ORDER BY d.name, p.created DESC", in_clause(customer_client_ids())[1]);
}

page_start(is_staff() ? 'Projeler' : 'Projelerim', 'projects');
?>
<div class="page-top">
    <div>
        <div class="page-title"><?= is_staff() ? 'Projeler' : 'Projelerim' ?></div>
        <div class="page-bottom"><?= count($projects) ?> proje</div>
    </div>
    <?php if (permission('client_manage')): ?>
    <div class="page-top-action"><button class="btn btn-brand" data-modal="modalProject"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Proje</button></div>
    <?php endif; ?>
</div>

<div class="filter-bar">
    <div class="search-box"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="input" placeholder="Proje ara..." data-search="#projectGrid .project-card"></div>
    <div class="pill-filter" data-pill-group="#projectGrid .project-card">
        <button class="pill active" data-value="">Tümü</button>
        <?php foreach (PROJECT_TYPES as $k => $v): ?><button class="pill" data-value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$projects): ?>
<div class="empty-state">
    <div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg></div>
    <div class="empty-title">Henüz proje yok</div>
    <div class="empty-text"><?= permission('client_manage') ? 'Bir dosya seçip ilk projeyi oluşturun.' : 'Size atanmış bir proje bulunmuyor.' ?></div>
</div>
<?php else: ?>
<div class="grid grid-auto" id="projectGrid">
    <?php foreach ($projects as $p):
        $rate = $p['task_count'] ? round($p['is_done_count'] / $p['task_count'] * 100) : 0; ?>
    <a href="project.php?id=<?= $p['id'] ?>" class="card card-tick project-card" data-filter="<?= $p['type'] ?>" data-search="<?= e($p['name'] . ' ' . $p['client_name']) ?>">
        <div class="row-flex between mb-2">
            <span class="badge badge-type"><?= PROJECT_TYPES[$p['type']] ?></span>
            <?= badge($p['status'], PROJECT_STATUSES) ?>
        </div>
        <div class="card-title" style="font-size:16px"><?= e($p['name']) ?></div>
        <div class="row-flex mt-1" style="gap:7px">
            <span class="label-dot" style="background:<?= e($p['client_color']) ?>"></span>
            <span class="cell-bottom"><?= e($p['client_name']) ?></span>
        </div>
        <div class="progress mt-2"><div class="progress-full" data-rate="<?= $rate ?>" style="width:0"></div></div>
        <div class="row-flex between mt-1"><span class="cell-bottom"><?= $p['is_done_count'] ?>/<?= $p['task_count'] ?> görev</span><span class="cell-bottom bold">%<?= $rate ?></span></div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
if (permission('client_manage')) {
    $clients = rows("SELECT id, name FROM clients WHERE status='active' ORDER BY name");
    $pms = rows("SELECT id, name FROM users WHERE role IN ('admin','pm') AND is_active=1 ORDER BY name");
?>
<div class="modal-overlay" id="modalProject">
    <div class="modal"><div class="modal-top"><div class="modal-title">Yeni Proje</div><button class="modal-close" data-modal-close>✕</button></div>
        <form data-ajax="project_save">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Proje Adı <span class="required">*</span></label><input name="name" class="input" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Dosya <span class="required">*</span></label><select name="client_id" class="select" required><option value="">Seçin...</option><?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Hizmet Türü</label><select name="type" class="select"><?php foreach (PROJECT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Başlangıç</label><input type="date" name="start" class="input"></div>
                    <div class="form-group"><label class="form-label">Bitiş</label><input type="date" name="end" class="input"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Proje Yöneticisi</label><select name="pm_id" class="select"><option value="">—</option><?php foreach ($pms as $pm): ?><option value="<?= $pm['id'] ?>"><?= e($pm['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Sözleşme Tutarı (₺)</label><input name="contract_amount" class="input" placeholder="0,00"></div>
                </div>
                <div class="form-group"><label class="form-label">Proje Şablonu (opsiyonel)</label><select name="ptemplate_id" class="select"><option value="">— Boş proje</option><?php foreach (rows("SELECT id, name FROM project_templates ORDER BY name") as $templateRow): ?><option value="<?= $templateRow['id'] ?>"><?= e($templateRow['name']) ?></option><?php endforeach; ?></select><div class="form-hint">Seçilirse şablondaki görevler akışlarıyla birlikte kurulur.</div></div>
                <?php member_picker(); ?>
                <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" class="text-area"></textarea></div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Oluştur</button></div>
        </form>
    </div>
</div>
<?php } ?>
<?php page_end(); ?>

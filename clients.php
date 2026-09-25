<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

if (is_customer()) {
    // Customer: only the client files they can access
    [$in, $p] = in_clause(customer_client_ids());
    $clients = rows("SELECT d.*,
        (SELECT COUNT(*) FROM projects pr WHERE pr.client_id=d.id) project_count,
        (SELECT COUNT(*) FROM projects pr WHERE pr.client_id=d.id AND pr.status='active') is_active_project
        FROM clients d WHERE d.id IN $in ORDER BY d.name", $p);
} else {
    $clients = rows("SELECT d.*,
        (SELECT COUNT(*) FROM projects p WHERE p.client_id=d.id) project_count,
        (SELECT COUNT(*) FROM projects p WHERE p.client_id=d.id AND p.status='active') is_active_project
        FROM clients d ORDER BY d.status='active' DESC, d.name");
}

page_start(is_customer() ? 'Dosyalarım' : 'Dosyalar', 'clients');
?>
<div class="page-top">
    <div>
        <div class="page-title"><?= is_customer() ? 'Dosyalarım' : 'Dosyalar' ?></div>
        <div class="page-bottom"><?= is_customer() ? 'Ajansımızla yürüttüğünüz dosyalar' : "Markalar, şirketler ve STK'lar" ?> — <?= count($clients) ?> dosya</div>
    </div>
    <?php if (permission('client_manage')): ?>
    <div class="page-top-action">
        <button class="btn btn-brand" data-modal="modalClient" onclick="clientFormReset()">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Dosya
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="filter-bar">
    <div class="search-box">
        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
        <input class="input" placeholder="Dosya ara..." data-search="#clientGrid .client-card">
    </div>
    <div class="pill-filter" data-pill-group="#clientGrid .client-card">
        <button class="pill active" data-value="">Tümü</button>
        <?php foreach (CLIENT_TYPES as $k => $v): ?><button class="pill" data-value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$clients): ?>
<div class="empty-state">
    <div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg></div>
    <div class="empty-title">Henüz dosya yok</div>
    <div class="empty-text">İlk markanızı, şirketinizi veya STK'nızı ekleyerek başlayın.</div>
    <?php if (permission('client_manage')): ?><button class="btn btn-brand" data-modal="modalClient">Yeni Dosya Oluştur</button><?php endif; ?>
</div>
<?php else: ?>
<div class="grid grid-auto" id="clientGrid">
    <?php foreach ($clients as $d): ?>
    <a href="client.php?id=<?= $d['id'] ?>" class="card card-tick client-card" data-filter="<?= $d['type'] ?>" data-search="<?= e($d['name']) ?>">
        <div class="row-flex between mb-2">
            <?= client_logo($d, 40, 15) ?>
            <?php if ($d['status'] === 'inactive'): ?><span class="badge r-cancelled">Pasif</span><?php else: ?><span class="badge badge-type"><?= CLIENT_TYPES[$d['type']] ?></span><?php endif; ?>
        </div>
        <div class="card-title" style="font-size:16px"><?= e($d['name']) ?></div>
        <?php if ($d['contact_name']): ?><div class="cell-bottom mt-1"><?= e($d['contact_name']) ?></div><?php endif; ?>
        <div class="row-flex mt-2" style="gap:16px;color:var(--muted);font-size:12.5px">
            <span><b style="color:var(--text)"><?= $d['is_active_project'] ?></b> aktif proje</span>
            <span><b style="color:var(--text)"><?= $d['project_count'] ?></b> toplam</span>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (permission('client_manage')): ?>
<div class="modal-overlay" id="modalClient">
    <div class="modal">
        <div class="modal-top"><div class="modal-title" id="clientModalTitle">Yeni Dosya</div><button class="modal-close" data-modal-close>✕</button></div>
        <form data-ajax="client_save" id="clientForm">
            <input type="hidden" name="id" id="client_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Dosya Adı <span class="required">*</span></label><input name="name" id="d_name" class="input" required></div>
                    <div class="form-group"><label class="form-label">Tür</label><select name="type" id="d_type" class="select"><?php foreach (CLIENT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Renk</label>
                    <div class="row-flex wrap" id="colorSelect">
                        <?php foreach (['#b1fb01', '#182f5d', '#610714', '#f8f2cb', '#3b9df0', '#35c66b', '#f5a524', '#a58bf0'] as $r): ?>
                        <label style="cursor:pointer"><input type="radio" name="color" value="<?= $r ?>" <?= $r === '#182f5d' ? 'checked' : '' ?> style="display:none" class="color-radio"><span class="label-dot" style="width:28px;height:28px;background:<?= $r ?>;border:2px solid transparent"></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group"><label class="form-label">Logo</label><input type="file" name="logo" class="input" accept="image/*"><div class="form-hint">JPG, PNG veya WebP.</div></div>
                <?php member_picker([], 'Sorumlu Ekip Üyeleri'); ?>
                <div class="form-group"><label class="form-label">Açıklama</label><textarea name="description" id="d_description" class="text-area"></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">İletişim Kişisi</label><input name="contact_name" id="d_contact_name" class="input"></div>
                    <div class="form-group"><label class="form-label">Dosya Yöneticisi</label>
                        <select name="manager_id" id="d_manager_id" class="select">
                            <option value="">— Atanmadı</option>
                            <?php foreach (rows("SELECT id, name FROM users WHERE role IN ('admin','pm','team') AND is_active=1 ORDER BY name") as $m2): ?>
                            <option value="<?= $m2['id'] ?>"><?= e($m2['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint">Aylık raporu doldurmakla yükümlü kişi; hatırlatmalar ona gider.</div>
                    </div>
                    <div class="form-group"><label class="form-label">Telefon</label><input name="contact_phone" id="d_contact_phone" class="input"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">E-posta</label><input type="email" name="contact_email" id="d_contact_email" class="input"></div>
                    <div class="form-group"><label class="form-label">Durum</label><select name="status" id="d_status" class="select"><option value="active">Aktif</option><option value="inactive">Pasif</option></select></div>
                </div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
        </form>
    </div>
</div>
<script>
function clientFormReset() {
    const f = document.getElementById('clientForm'); f.reset();
    document.getElementById('client_id').value = '';
    document.getElementById('clientModalTitle').textContent = 'Yeni Dosya';
    colorHighlight();
}
// Color selection highlight
function colorHighlight() {
    document.querySelectorAll('.color-radio').forEach(r => {
        r.nextElementSibling.style.borderColor = r.checked ? 'var(--text)' : 'transparent';
    });
}
document.getElementById('colorSelect').addEventListener('change', colorHighlight);
colorHighlight();
</script>
<?php endif; ?>
<?php page_end(); ?>

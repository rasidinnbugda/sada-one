<?php
/**
 * SADA One — Talent Pool
 * Freelancers we have worked with or have on record: person · skill · worked before · CV
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (is_intern()) { header('Location: index.php'); exit; }

$people = rows("SELECT h.*, a.file_path cv_path, a.name cv_name, ek.name adder_name FROM talent_pool h
    LEFT JOIN archive a ON a.id=h.cv_archive_id LEFT JOIN users ek ON ek.id=h.added_by ORDER BY h.worked_before DESC, h.name");
$can_manage = is_admin() || $u['role'] === 'pm';

page_start('Çalışan Havuzu', 'pool');
?>
<div class="page-top">
    <div><div class="page-title">Çalışan Havuzu</div><div class="page-bottom">Birlikte çalıştığımız veya bilgisi elimizde olan serbest yetenekler</div></div>
    <div class="page-top-action"><button class="btn btn-brand" onclick="hNew()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Kişi Ekle</button></div>
</div>

<div class="filter-bar">
    <div class="pill-filter" data-pill-group="#poolList tbody tr">
        <button class="pill active" data-value="">Tümü (<?= count($people) ?>)</button>
        <button class="pill" data-value="1">Çalışıldı</button>
        <button class="pill" data-value="0">Henüz Çalışılmadı</button>
    </div>
    <div class="search-box"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="input" placeholder="İsim veya yetkinlik ara..." data-search="#poolList tbody tr"></div>
</div>

<?php if (!$people): ?>
<div class="empty-state">
    <div class="empty-icon"><?= icon('team', 36) ?></div>
    <div class="empty-title">Havuz boş</div>
    <div class="empty-text">Freelance kameraman, editör, tasarımcı... birlikte çalıştığınız ya da CV'si elinizde olan herkesi ekleyin.</div>
    <button class="btn btn-brand" onclick="hNew()">İlk Kişiyi Ekle</button>
</div>
<?php else: ?>
<div class="table-wrap"><table class="table" id="poolList">
    <thead><tr><th>Kişi</th><th>Yetkinlik</th><th>Daha Önce Çalışıldı mı?</th><th>İletişim</th><th>CV</th><th>Not</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $k): ?>
    <tr data-filter="<?= $k['worked_before'] ?>">
        <td><div class="cell-main"><?= e($k['name']) ?></div></td>
        <td class="small"><?= e($k['skill'] ?: '—') ?></td>
        <td><?= $k['worked_before'] ? '<span class="badge r-completed">Evet</span>' : '<span class="badge r-pending">Hayır</span>' ?></td>
        <td class="small"><?= e($k['contact'] ?: '—') ?></td>
        <td><?= $k['cv_path'] ? '<a href="uploads/' . e($k['cv_path']) . '" target="_blank" class="mini-btn">📄 ' . e(mb_substr($k['cv_name'], 0, 22)) . '</a>' : '<span class="text-muted small">—</span>' ?></td>
        <td class="small text-2" style="max-width:220px"><?= e(mb_substr($k['note'] ?? '', 0, 60)) ?: '—' ?></td>
        <td><div class="row-flex" style="gap:4px;justify-content:flex-end">
            <button class="icon-action" onclick='hEdit(<?= json_encode($k, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 15) ?></button>
            <?php if ($can_manage): ?><button class="icon-action danger" data-action="pool_delete" data-id="<?= $k['id'] ?>" data-confirm="<?= e($k['name']) ?> havuzdan silinsin mi?"><?= icon('cop', 15) ?></button><?php endif; ?>
        </div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="modal-overlay" id="modalPool">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="hTitle">Havuza Kişi Ekle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="pool_save">
        <input type="hidden" name="id" id="h_id">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Ad Soyad <span class="required">*</span></label><input name="name" id="h_name" class="input" required></div>
                <div class="form-group"><label class="form-label">İletişim</label><input name="contact" id="h_contact" class="input" placeholder="Telefon / e-posta / instagram"></div>
            </div>
            <div class="form-group"><label class="form-label">Yetkinlik</label><input name="skill" id="h_skill" class="input" placeholder="Örn. kameraman, video editör, grafik tasarım"></div>
            <div class="form-group"><label class="row-flex" style="gap:9px;cursor:pointer"><input type="checkbox" name="worked_before" id="h_worked_before" value="1"> Daha önce birlikte çalışıldı</label></div>
            <div class="form-group"><label class="form-label">CV (PDF/DOC)</label><input type="file" name="cv" class="input" accept=".pdf,.doc,.docx"><div class="form-hint" id="h_cvInfo"></div></div>
            <div class="form-group"><label class="form-label">Not</label><textarea name="note" id="h_note" class="text-area" placeholder="Gözlemler, ücret bilgisi, referans..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<script>
function hNew() {
    const f = document.querySelector('#modalPool form');
    f.reset(); document.getElementById('h_id').value = '';
    document.getElementById('hTitle').textContent = 'Havuza Kişi Ekle';
    document.getElementById('h_cvInfo').textContent = '';
    modalOpen('modalPool');
}
function hEdit(k) {
    document.getElementById('h_id').value = k.id;
    document.getElementById('h_name').value = k.name;
    document.getElementById('h_contact').value = k.contact || '';
    document.getElementById('h_skill').value = k.skill || '';
    document.getElementById('h_worked_before').checked = k.worked_before == 1;
    document.getElementById('h_note').value = k.note || '';
    document.getElementById('h_cvInfo').textContent = k.cv_name ? 'Mevcut CV: ' + k.cv_name + ' (yenisini seçerseniz değişir)' : '';
    document.getElementById('hTitle').textContent = 'Kişiyi Düzenle';
    modalOpen('modalPool');
}
</script>
<?php page_end(); ?>

<?php
/**
 * SADA One — Internal Team Development and Mentorship Tracking Program
 * Member · desired development area · assigned mentor · practice arena/project · output & evaluation
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$can_manage = is_admin() || $u['role'] === 'pm';
$records = rows("SELECT m.*, uu.name member_name, uu.color member_color, uu.avatar member_avatar, mm.name mentor_name, p.name project_name
    FROM mentorship m JOIN users uu ON uu.id=m.member_id LEFT JOIN users mm ON mm.id=m.mentor_id LEFT JOIN projects p ON p.id=m.project_id
    ORDER BY FIELD(m.status,'in_progress','planned','completed'), m.created DESC");
$team = rows("SELECT id, name FROM users WHERE role IN ('admin','pm','team','intern') AND is_active=1 ORDER BY name");
$projects = rows("SELECT id, name FROM projects WHERE status='active' ORDER BY name");

$mBadge = fn($d) => '<span class="badge ' . ['planned' => 'r-pending', 'in_progress' => 'r-in_progress', 'completed' => 'r-completed'][$d] . '">' . MENTORSHIP_STATUSES[$d] . '</span>';

page_start('Gelişim & Mentörlük', 'growth');
?>
<div class="page-top">
    <div><div class="page-title">Gelişim & Mentörlük</div><div class="page-bottom">Ekip içi yetkinlik gelişimi ve mentörlük eşleşmeleri</div></div>
    <?php if ($can_manage): ?><div class="page-top-action"><button class="btn btn-brand" onclick="mNew()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Eşleşme</button></div><?php endif; ?>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Aktif Gelişim Süreci</div><div class="stat-value"><?= count(array_filter($records, fn($k) => $k['status'] === 'in_progress')) ?></div></div>
    <div class="stat-card"><div class="stat-label">Planlanan</div><div class="stat-value"><?= count(array_filter($records, fn($k) => $k['status'] === 'planned')) ?></div></div>
    <div class="stat-card"><div class="stat-label">Tamamlanan</div><div class="stat-value"><?= count(array_filter($records, fn($k) => $k['status'] === 'completed')) ?></div></div>
    <div class="stat-card"><div class="stat-label">Gelişimdeki Kişi</div><div class="stat-value"><?= count(array_unique(array_column($records, 'member_id'))) ?></div></div>
</div>

<?php if (!$records): ?>
<div class="empty-state">
    <div class="empty-icon"><?= icon('rocket', 36) ?></div>
    <div class="empty-title">Henüz mentörlük kaydı yok</div>
    <div class="empty-text">Örn: "İmran → video edit gelişimi, mentör Ömer, uygulama sahası: 1 Ağustos podcast çekimi"</div>
    <?php if ($can_manage): ?><button class="btn btn-brand" onclick="mNew()">İlk Eşleşmeyi Oluştur</button><?php endif; ?>
</div>
<?php else: ?>
<div class="grid grid-2">
    <?php foreach ($records as $k): ?>
    <div class="card">
        <div class="row-flex between mb-2">
            <div class="row-flex" style="gap:10px">
                <?= avatar(['name' => $k['member_name'], 'color' => $k['member_color'], 'avatar' => $k['member_avatar']], 38) ?>
                <div><div class="bold"><?= e($k['member_name']) ?></div><div class="cell-bottom"><?= e($k['field']) ?></div></div>
            </div>
            <div class="row-flex" style="gap:6px">
                <?= $mBadge($k['status']) ?>
                <?php if ($can_manage): ?>
                <button class="icon-action" onclick='mEdit(<?= json_encode($k, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 15) ?></button>
                <button class="icon-action danger" data-action="mentorship_delete" data-id="<?= $k['id'] ?>" data-confirm="Mentörlük kaydı silinsin mi?"><?= icon('cop', 15) ?></button>
                <?php endif; ?>
            </div>
        </div>
        <div class="vertical small" style="gap:7px">
            <div class="row-flex between"><span class="text-muted">Mentör</span><span class="bold"><?= $k['mentor_name'] ? e($k['mentor_name']) : '— belirlenmedi' ?></span></div>
            <div class="row-flex between"><span class="text-muted">Uygulama Sahası</span><span><?= $k['project_name'] ? e($k['project_name']) : e($k['practice_area'] ?: '—') ?></span></div>
        </div>
        <div class="mt-2" style="padding:10px 12px;background:var(--surface-2);border-radius:10px">
            <div class="cell-bottom mb-1">Çıktı & Değerlendirme Notu <?php if ($k['member_id'] == $u['id'] || $can_manage): ?><button class="mini-btn" onclick="mOutput(<?= $k['id'] ?>, this)">Düzenle</button><?php endif; ?></div>
            <div class="small text-2 m-output" style="white-space:pre-wrap"><?= $k['output'] ? e($k['output']) : '<span class="text-muted">Henüz not girilmedi.</span>' ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($can_manage): ?>
<div class="modal-overlay" id="modalMentor">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="mTitle">Yeni Mentörlük Eşleşmesi</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="mentorship_save">
        <input type="hidden" name="id" id="m_id">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Ekip Üyesi <span class="required">*</span></label>
                    <select name="member_id" id="m_member" class="select" required><option value="">Seçin...</option><?php foreach ($team as $e2): ?><option value="<?= $e2['id'] ?>"><?= e($e2['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Atanan Mentör</label>
                    <select name="mentor_id" id="m_mentor" class="select"><option value="">— Belirlenmedi</option><?php foreach ($team as $e2): ?><option value="<?= $e2['id'] ?>"><?= e($e2['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group"><label class="form-label">Gelişim İstenen Alan <span class="required">*</span></label><input name="field" id="m_field" class="input" required placeholder="Örn. video edit ve içerik üretimi, çekim"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Uygulama Projesi</label>
                    <select name="project_id" id="m_project" class="select"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">veya Serbest Saha</label><input name="practice_area" id="m_practice_area" class="input" placeholder="Örn. 1 Ağustos podcast tek başına kurulum"></div>
            </div>
            <div class="form-group"><label class="form-label">Durum</label>
                <select name="status" id="m_status" class="select"><?php foreach (MENTORSHIP_STATUSES as $min => $dv): ?><option value="<?= $min ?>"><?= $dv ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Çıktı & Değerlendirme Notu</label><textarea name="output" id="m_output" class="text-area" placeholder="Süreç sonunda gözlemler, değerlendirme..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
function mNew() {
    const f = document.querySelector('#modalMentor form'); if (!f) return;
    f.reset(); document.getElementById('m_id').value = '';
    document.getElementById('mTitle').textContent = 'Yeni Mentörlük Eşleşmesi';
    if (window.customPickerRefresh) customPickerRefresh();
    modalOpen('modalMentor');
}
function mEdit(k) {
    document.getElementById('m_id').value = k.id;
    document.getElementById('m_member').value = k.member_id;
    document.getElementById('m_mentor').value = k.mentor_id || '';
    document.getElementById('m_field').value = k.field;
    document.getElementById('m_project').value = k.project_id || '';
    document.getElementById('m_practice_area').value = k.practice_area || '';
    document.getElementById('m_status').value = k.status;
    document.getElementById('m_output').value = k.output || '';
    document.getElementById('mTitle').textContent = 'Eşleşmeyi Düzenle';
    ['m_member', 'm_mentor', 'm_project', 'm_status'].forEach(id => document.getElementById(id).dispatchEvent(new Event('change')));
    modalOpen('modalMentor');
}
async function mOutput(id, btn) {
    const box = btn.closest('div').nextElementSibling;
    const current = box.querySelector('.text-muted') ? '' : box.textContent.trim();
    const newNote = prompt('Çıktı & değerlendirme notu:', current);
    if (newNote === null) return;
    const j = await api('mentorship_output', { id, output: newNote });
    if (j.ok) { box.textContent = newNote || 'Henüz not girilmedi.'; toast(j.message, 'success'); }
}
</script>
<?php page_end(); ?>

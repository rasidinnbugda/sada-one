<?php
/**
 * SADA One — Project Templates
 * One-click project setup with ready-made task sets.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$templates = rows("SELECT * FROM project_templates ORDER BY name");
$types = rows("SELECT id, name FROM task_types ORDER BY name");

page_start('Proje Şablonları', 'ptemplates');
?>
<div class="page-top">
    <div><div class="page-title">Proje Şablonları</div><div class="page-bottom">Yeni proje açarken tek tıkla kurulan hazır iş setleri</div></div>
    <div class="page-top-action"><button class="btn btn-brand" data-modal="modalPS" onclick="ptReset()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Şablon</button></div>
</div>

<?php if (!$templates): ?>
<div class="empty-state">
    <div class="empty-icon"><?= icon('document', 36) ?></div>
    <div class="empty-title">Şablon yok</div>
    <div class="empty-text">Örn. "Aylık Sosyal Medya Paketi" şablonu: içerik üretimi, çekim, raporlama işleri akışlarıyla hazır kurulsun.</div>
    <button class="btn btn-brand" data-modal="modalPS" onclick="ptReset()">İlk Şablonu Oluştur</button>
</div>
<?php else: ?>
<div class="grid grid-2">
    <?php foreach ($templates as $ps):
        $taskList = json_decode($ps['tasks'], true) ?: []; ?>
    <div class="card">
        <div class="row-flex between mb-2">
            <div><div class="card-title" style="font-size:16px"><?= e($ps['name']) ?></div><?php if ($ps['description']): ?><div class="cell-bottom mt-1"><?= e($ps['description']) ?></div><?php endif; ?></div>
            <div class="row-flex" style="gap:4px">
                <button class="icon-action" onclick='ptEdit(<?= json_encode($ps, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 16) ?></button>
                <button class="icon-action danger" data-action="ptemplate_delete" data-id="<?= $ps['id'] ?>" data-confirm="Şablon silinsin mi? (Mevcut projeler etkilenmez)"><?= icon('cop', 16) ?></button>
            </div>
        </div>
        <div class="vertical" style="gap:5px">
            <?php foreach ($taskList as $sg): ?>
            <div class="row-flex between small" style="padding:7px 11px;background:var(--surface-2);border-radius:9px">
                <span><?= e($sg['title']) ?></span>
                <span class="row-flex" style="gap:6px">
                    <?php if (($sg['priority'] ?? 'normal') !== 'normal'): ?><?= badge($sg['priority'], PRIORITIES) ?><?php endif; ?>
                    <?php if (!empty($sg['type_id'])): $typeName = ''; foreach ($types as $tt) if ($tt['id'] == $sg['type_id']) $typeName = $tt['name']; ?>
                    <span class="badge badge-type"><?= icon('rocket', 10) ?> <?= e($typeName ?: 'İş türü') ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-overlay" id="modalPS">
    <div class="modal modal-wide"><div class="modal-top"><div class="modal-title" id="ptTitle">Yeni Proje Şablonu</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="ptemplate_save" id="ptForm">
        <input type="hidden" name="id" id="pt_id"><input type="hidden" name="tasks" id="pt_tasks">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Şablon Adı <span class="required">*</span></label><input name="name" id="pt_name" class="input" required placeholder="Örn. Aylık Sosyal Medya Paketi"></div>
                <div class="form-group"><label class="form-label">Açıklama</label><input name="description" id="pt_description" class="input"></div>
            </div>
            <div class="form-group">
                <label class="form-label">İşler</label>
                <div class="vertical" id="ptTaskList" style="gap:8px"></div>
                <button type="button" class="btn btn-sm btn-ghost mt-2" onclick="ptTaskAdd()">+ İş Ekle</button>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<script>
const ptTypes = <?= json_encode($types, JSON_UNESCAPED_UNICODE) ?>;
const ptPriorities = <?= json_encode(PRIORITIES, JSON_UNESCAPED_UNICODE) ?>;
function ptTaskAdd(g = {}) {
    const div = document.createElement('div');
    div.className = 'row-flex pt-row';
    div.style.gap = '8px';
    let typeOps = '<option value="0">Adımsız iş</option>';
    ptTypes.forEach(a => typeOps += `<option value="${a.id}" ${g.type_id == a.id ? 'selected' : ''}>${esc(a.name)}</option>`);
    let priorityOptions = '';
    for (const k in ptPriorities) priorityOptions += `<option value="${k}" ${(g.priority || 'normal') === k ? 'selected' : ''}>${ptPriorities[k]}</option>`;
    div.innerHTML = `<input class="input pt-title" placeholder="İş başlığı" style="flex:2" value="${(g.title || '').replace(/"/g, '&quot;')}">
        <select class="select pt-type" style="flex:1">${typeOps}</select>
        <select class="select pt-priority" style="width:110px">${priorityOptions}</select>
        <button type="button" class="icon-action danger" onclick="this.parentElement.remove()">✕</button>`;
    document.getElementById('ptTaskList').appendChild(div);
    if (window.customPickerRefresh) customPickerRefresh();
}
function ptReset() {
    document.getElementById('ptForm').reset();
    document.getElementById('pt_id').value = '';
    document.getElementById('ptTitle').textContent = 'Yeni Proje Şablonu';
    document.getElementById('ptTaskList').innerHTML = '';
    ptTaskAdd(); ptTaskAdd();
}
function ptEdit(ps) {
    document.getElementById('ptTitle').textContent = 'Şablonu Düzenle';
    document.getElementById('pt_id').value = ps.id;
    document.getElementById('pt_name').value = ps.name;
    document.getElementById('pt_description').value = ps.description || '';
    document.getElementById('ptTaskList').innerHTML = '';
    (JSON.parse(ps.tasks || '[]')).forEach(g => ptTaskAdd(g));
    modalOpen('modalPS');
}
document.getElementById('ptForm').addEventListener('submit', () => {
    const tasks = Array.from(document.querySelectorAll('.pt-row')).map(s => ({
        title: s.querySelector('.pt-title').value.trim(),
        type_id: parseInt(s.querySelector('.pt-type').value) || 0,
        priority: s.querySelector('.pt-priority').value,
    })).filter(g => g.title);
    document.getElementById('pt_tasks').value = JSON.stringify(tasks);
});
</script>
<?php page_end(); ?>

<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$templates = rows("SELECT s.*, (SELECT COUNT(*) FROM template_steps sa WHERE sa.template_id=s.id) step_count, (SELECT COUNT(*) FROM tasks g JOIN task_steps ga ON ga.task_id=g.id WHERE 1=0) usage_count FROM workflow_templates s ORDER BY s.name");
foreach ($templates as &$s) $s['steps'] = rows("SELECT name FROM template_steps WHERE template_id=? ORDER BY sort_order", [$s['id']]);
unset($s);

page_start('Akış Şablonları', 'workflows');
?>
<div class="page-top">
    <div><div class="page-title">Akış Şablonları</div><div class="page-bottom">İşlerin izleyeceği adımları tanımlayın</div></div>
    <div class="page-top-action"><button class="btn btn-brand" data-modal="modalWorkflow" onclick="workflowReset()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Şablon</button></div>
</div>

<?php if (!$templates): ?>
<div class="empty-state"><div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div><div class="empty-title">Şablon yok</div><div class="empty-text">İçerik üretimi, prodüksiyon veya web projeleri için akış şablonları oluşturun.</div></div>
<?php else: ?>
<div class="grid grid-2">
    <?php foreach ($templates as $s): ?>
    <div class="card">
        <div class="row-flex between mb-2">
            <div><div class="card-title" style="font-size:16px"><?= e($s['name']) ?></div><?php if ($s['description']): ?><div class="cell-bottom mt-1"><?= e($s['description']) ?></div><?php endif; ?></div>
            <div class="row-flex" style="gap:4px">
                <button class="icon-action" onclick='workflowEdit(<?= json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                <button class="icon-action danger" data-action="workflow_delete" data-id="<?= $s['id'] ?>" data-confirm="Şablon silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button>
            </div>
        </div>
        <div class="flow-rail" style="margin-top:14px">
            <?php foreach ($s['steps'] as $i => $a): ?>
            <div class="flow-step">
                <div class="flow-line"></div>
                <div class="flow-step-inner"><div class="flow-circle"><?= $i + 1 ?></div><div class="flow-name"><?= e($a['name']) ?></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-overlay" id="modalWorkflow">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="workflowTitle">Yeni Akış Şablonu</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="workflow_save" id="workflowForm">
        <input type="hidden" name="id" id="a_id"><input type="hidden" name="steps" id="a_steps">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Şablon Adı <span class="required">*</span></label><input name="name" id="a_name" class="input" required placeholder="Örn. Sosyal Medya İçerik Üretimi"></div>
            <div class="form-group"><label class="form-label">Açıklama</label><input name="description" id="a_description" class="input"></div>
            <div class="form-group">
                <label class="form-label">Akış Adımları</label>
                <div class="vertical" id="stepList" style="gap:8px"></div>
                <button type="button" class="btn btn-sm btn-ghost mt-2" onclick="stepAdd()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Adım Ekle</button>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<script>
function stepRow(value = '') {
    const div = document.createElement('div');
    div.className = 'row-flex step-row';
    div.style.gap = '8px';
    div.setAttribute('data-sortable', '');
    div.innerHTML = `<span class="order-arrows">
        <button type="button" class="order-arrow" data-sort-dir="up" title="Yukarı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 15l7-7 7 7"/></svg></button>
        <button type="button" class="order-arrow" data-sort-dir="down" title="Aşağı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg></button>
    </span><input class="input step-input" value="${value.replace(/"/g,'&quot;')}" placeholder="Adım adı"><button type="button" class="icon-action danger" onclick="this.parentElement.remove()"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
    document.getElementById('stepList').appendChild(div);
}
function stepAdd() { stepRow(); }
function workflowReset() {
    document.getElementById('workflowForm').reset();
    document.getElementById('a_id').value = '';
    document.getElementById('workflowTitle').textContent = 'Yeni Akış Şablonu';
    document.getElementById('stepList').innerHTML = '';
    stepRow('Brief'); stepRow('Tasarım'); stepRow('İç Onay'); stepRow('Müşteri Onayı');
}
function workflowEdit(s) {
    document.getElementById('workflowTitle').textContent = 'Şablonu Düzenle';
    document.getElementById('a_id').value = s.id;
    document.getElementById('a_name').value = s.name;
    document.getElementById('a_description').value = s.description || '';
    document.getElementById('stepList').innerHTML = '';
    s.steps.forEach(a => stepRow(a.name));
    modalOpen('modalWorkflow');
}
document.getElementById('workflowForm').addEventListener('submit', () => {
    const steps = Array.from(document.querySelectorAll('.step-input')).map(i => i.value.trim()).filter(Boolean);
    document.getElementById('a_steps').value = JSON.stringify(steps);
});
</script>
<?php page_end(); ?>

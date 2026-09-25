<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$forms = rows("SELECT * FROM form_templates ORDER BY name");
foreach ($forms as &$f) $f['fields'] = rows("SELECT * FROM form_fields WHERE template_id=? ORDER BY sort_order", [$f['id']]);
unset($f);
// Type keys are the STORED values (Turkish, like every other stored enum in the panel)
$fieldTypes = ['text' => 'Kısa Metin', 'long_text' => 'Uzun Metin', 'select' => 'Seçim Listesi', 'multi_select' => 'Çoklu Seçim (kutucuklar)', 'date' => 'Tarih', 'number' => 'Sayı', 'file' => 'Dosya Yükleme', 'multi_file' => 'Çoklu Dosya Yükleme', 'section' => '— Bölüm Başlığı —'];

page_start('Form Şablonları', 'forms');
?>
<div class="page-top">
    <div><div class="page-title">Talep Form Şablonları</div><div class="page-bottom">Müşterilerin dolduracağı talep formlarını tasarlayın</div></div>
    <div class="page-top-action"><button class="btn btn-brand" data-modal="modalForm" onclick="formReset()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Form</button></div>
</div>

<div class="grid grid-2">
    <?php foreach ($forms as $f): ?>
    <div class="card" style="<?= $f['is_active'] ? '' : 'opacity:.6' ?>">
        <div class="row-flex between mb-2">
            <div><div class="card-title" style="font-size:16px"><?= e($f['name']) ?> <?php if (!$f['is_active']): ?><span class="badge r-cancelled">Pasif</span><?php endif; ?></div><?php if ($f['description']): ?><div class="cell-bottom mt-1"><?= e($f['description']) ?></div><?php endif; ?></div>
            <div class="row-flex" style="gap:4px">
                <button class="icon-action" onclick='formEdit(<?= json_encode($f, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                <button class="icon-action danger" data-action="form_delete" data-id="<?= $f['id'] ?>" data-confirm="Form silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button>
            </div>
        </div>
        <div class="vertical mt-2" style="gap:6px">
            <?php foreach ($f['fields'] as $a): ?>
            <div class="row-flex between small" style="padding:8px 12px;background:var(--surface-2);border-radius:9px"><span><?= e($a['label']) ?><?php if ($a['is_required']): ?> <span class="required">*</span><?php endif; ?></span><span class="text-muted"><?= $fieldTypes[$a['type']] ?? $a['type'] ?></span></div>
            <?php endforeach; ?>
            <?php if (!$f['fields']): ?><div class="text-muted small">Alan yok</div><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="modal-overlay" id="modalForm">
    <div class="modal modal-wide"><div class="modal-top"><div class="modal-title" id="formTitle">Yeni Form Şablonu</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="form_save" id="formForm">
        <input type="hidden" name="id" id="f_id"><input type="hidden" name="fields" id="f_fields">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Form Adı <span class="required">*</span></label><input name="name" id="f_name" class="input" required></div>
                <div class="form-group"><label class="form-label">Durum</label><select name="is_active" id="f_is_active" class="select"><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
            </div>
            <div class="form-group"><label class="form-label">Açıklama</label><input name="description" id="f_description" class="input"></div>
            <div class="form-group">
                <label class="form-label">Form Alanları</label>
                <div class="vertical" id="fieldList" style="gap:10px"></div>
                <button type="button" class="btn btn-sm btn-ghost mt-2" onclick="fieldAdd()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Alan Ekle</button>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<script>
const fieldTypes = <?= json_encode($fieldTypes, JSON_UNESCAPED_UNICODE) ?>;
function fieldRow(field = {}) {
    const div = document.createElement('div');
    div.className = 'card field-row';
    div.style.padding = '12px';
    let typeOps = ''; for (const t in fieldTypes) typeOps += `<option value="${t}" ${field.type===t?'selected':''}>${fieldTypes[t]}</option>`;
    div.setAttribute('data-sortable', '');
    div.innerHTML = `
        <div class="row-flex" style="gap:8px;align-items:flex-start">
            <span class="order-arrows" style="padding-top:8px">
                <button type="button" class="order-arrow" data-sort-dir="up" title="Yukarı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 15l7-7 7 7"/></svg></button>
                <button type="button" class="order-arrow" data-sort-dir="down" title="Aşağı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg></button>
            </span>
            <div style="flex:1">
                <div class="form-row" style="margin-bottom:8px">
                    <input class="input field-label" placeholder="Alan etiketi" value="${(field.label||'').replace(/"/g,'&quot;')}">
                    <select class="select field-type" onchange="optionShow(this)">${typeOps}</select>
                </div>
                <textarea class="text-area field-options" placeholder="Bölüm açıklaması (isteğe bağlı)" style="min-height:60px;display:${field.type==='section'?'block':'none'}">${field.type==='section'?(field.options||''):''}</textarea>
                <div class="option-box" style="display:${['select','multi_select'].includes(field.type)?'block':'none'}">
                    <div class="option-list"></div>
                    <div class="row-flex wrap mt-1" style="gap:12px">
                        <button type="button" class="mini-btn" onclick="optionAdd(this.closest('.option-box').querySelector('.option-list'), '')">+ Seçenek ekle</button>
                        <label class="row-flex small" style="gap:6px;cursor:pointer"><input type="checkbox" class="field-other"> "Diğer" seçeneği olsun</label>
                    </div>
                </div>
                <label class="row-flex small mt-2 field-required-row" style="gap:7px;cursor:pointer;display:${field.type==='section'?'none':'flex'}"><input type="checkbox" class="field-required" ${field.is_required!=0?'checked':''}> Zorunlu alan</label>
            </div>
            <button type="button" class="icon-action danger" onclick="this.closest('.field-row').remove()"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>`;
    document.getElementById('fieldList').appendChild(div);
    // Expand saved options into rows; the __other__ marker becomes a checkbox
    if (['select', 'multi_select'].includes(field.type)) {
        const list = div.querySelector('.option-list');
        const rows = (field.options || '').split('\n').map(s => s.trim()).filter(Boolean);
        if (rows.includes('__other__')) div.querySelector('.field-other').checked = true;
        const gercek = rows.filter(s => s !== '__other__');
        (gercek.length ? gercek : ['', '']).forEach(s => optionAdd(list, s));
    }
    if (window.customPickerRefresh) customPickerRefresh();
}
function optionAdd(list, value) {
    const item = document.createElement('div');
    item.className = 'row-flex option-item';
    item.innerHTML = `<span class="option-round"></span>
        <input class="input option-input" placeholder="Seçenek ${list.children.length + 1}" value="${(value||'').replace(/"/g,'&quot;')}"
            onkeydown="if(event.key==='Enter'){event.preventDefault();optionAdd(this.closest('.option-list'),'');this.closest('.option-list').lastElementChild.querySelector('input').focus()}">
        <button type="button" class="icon-action" onclick="this.closest('.option-item').remove()" title="Seçeneği kaldır"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="14"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
    list.appendChild(item);
}
function optionShow(sel) {
    const row = sel.closest('.field-row');
    const option = row.querySelector('.field-options');
    const required = row.querySelector('.field-required-row');
    const label = row.querySelector('.field-label');
    const selectBox = row.querySelector('.option-box');
    if (sel.value === 'section') {
        option.style.display = 'block';
        selectBox.style.display = 'none';
        label.placeholder = 'Bölüm başlığı';
        required.style.display = 'none';
    } else {
        option.style.display = 'none';
        selectBox.style.display = ['select', 'multi_select'].includes(sel.value) ? 'block' : 'none';
        if (selectBox.style.display === 'block' && !selectBox.querySelector('.option-item')) {
            optionAdd(selectBox.querySelector('.option-list'), '');
            optionAdd(selectBox.querySelector('.option-list'), '');
        }
        label.placeholder = 'Alan etiketi';
        required.style.display = 'flex';
    }
}
function fieldAdd() { fieldRow(); }
function formReset() {
    document.getElementById('formForm').reset();
    document.getElementById('f_id').value = '';
    document.getElementById('formTitle').textContent = 'Yeni Form Şablonu';
    document.getElementById('fieldList').innerHTML = '';
    fieldRow({label:'Konu', type:'text'}); fieldRow({label:'Açıklama', type:'long_text'});
}
function formEdit(f) {
    document.getElementById('formTitle').textContent = 'Formu Düzenle';
    document.getElementById('f_id').value = f.id;
    document.getElementById('f_name').value = f.name;
    document.getElementById('f_description').value = f.description || '';
    document.getElementById('f_is_active').value = f.is_active;
    document.getElementById('fieldList').innerHTML = '';
    f.fields.forEach(a => fieldRow(a));
    modalOpen('modalForm');
}
document.getElementById('formForm').addEventListener('submit', () => {
    const fields = Array.from(document.querySelectorAll('.field-row')).map(s => {
        const type = s.querySelector('.field-type').value;
        let options = '';
        if (['select', 'multi_select'].includes(type)) {
            options = [...s.querySelectorAll('.option-input')].map(i => i.value.trim()).filter(Boolean).join('\n');
            if (s.querySelector('.field-other').checked) options += (options ? '\n' : '') + '__other__';
        } else if (type === 'section') {
            options = s.querySelector('.field-options').value.trim();
        }
        return { label: s.querySelector('.field-label').value.trim(), type, options,
            is_required: s.querySelector('.field-required').checked ? 1 : 0 };
    }).filter(a => a.label);
    document.getElementById('f_fields').value = JSON.stringify(fields);
});
</script>
<?php page_end(); ?>

<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$forms = rows("SELECT * FROM form_templates ORDER BY name");
foreach ($forms as &$f) $f['fields'] = rows("SELECT * FROM form_fields WHERE template_id=? ORDER BY sort_order", [$f['id']]);
unset($f);
$fieldTipleri = ['text' => 'Kısa Metin', 'long_text' => 'Uzun Metin', 'select' => 'Seçim Listesi', 'date' => 'Tarih', 'count' => 'Sayı', 'client' => 'Dosya Yükleme'];

page_start('Form Şablonları', 'forms');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Talep Form Şablonları</div><div class="sayfa-alt">Müşterilerin dolduracağı talep formlarını tasarlayın</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalForm" onclick="formSifirla()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Form</button></div>
</div>

<div class="izgara izgara-2">
    <?php foreach ($forms as $f): ?>
    <div class="kart" style="<?= $f['is_active'] ? '' : 'opacity:.6' ?>">
        <div class="satir-esnek arasi mb-2">
            <div><div class="kart-baslik" style="font-size:16px"><?= e($f['name']) ?> <?php if (!$f['is_active']): ?><span class="rozet r-iptal">Pasif</span><?php endif; ?></div><?php if ($f['description']): ?><div class="hucre-alt mt-1"><?= e($f['description']) ?></div><?php endif; ?></div>
            <div class="satir-esnek" style="gap:4px">
                <button class="ikon-eylem" onclick='formEdit(<?= json_encode($f, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                <button class="ikon-eylem tehlike" data-action="form_delete" data-id="<?= $f['id'] ?>" data-approval="Form silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button>
            </div>
        </div>
        <div class="dikey mt-2" style="gap:6px">
            <?php foreach ($f['fields'] as $a): ?>
            <div class="satir-esnek arasi kucuk" style="padding:8px 12px;background:var(--surface-2);border-radius:9px"><span><?= e($a['tag']) ?><?php if ($a['is_required']): ?> <span class="zorunlu">*</span><?php endif; ?></span><span class="metin-muted"><?= $fieldTipleri[$a['type']] ?? $a['type'] ?></span></div>
            <?php endforeach; ?>
            <?php if (!$f['fields']): ?><div class="metin-muted kucuk">Alan yok</div><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="modal-katman" id="modalForm">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik" id="formTitle">Yeni Form Şablonu</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="form_save" id="formForm">
        <input type="hidden" name="id" id="f_id"><input type="hidden" name="fields" id="f_fields">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Form Adı <span class="zorunlu">*</span></label><input name="name" id="f_name" class="girdi" required></div>
                <div class="form-grup"><label class="form-etiket">Durum</label><select name="is_active" id="f_is_active" class="secim"><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="description" id="f_description" class="girdi"></div>
            <div class="form-grup">
                <label class="form-etiket">Form Alanları</label>
                <div class="dikey" id="fieldList" style="gap:10px"></div>
                <button type="button" class="btn btn-sm btn-hayalet mt-2" onclick="fieldAdd()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Alan Ekle</button>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
const fieldTipleri = <?= json_encode($fieldTipleri, JSON_UNESCAPED_UNICODE) ?>;
function alanSatiri(field = {}) {
    const div = document.createElement('div');
    div.className = 'kart alan-satir';
    div.style.padding = '12px';
    let typeOps = ''; for (const t in fieldTipleri) typeOps += `<option value="${t}" ${field.type===t?'selected':''}>${fieldTipleri[t]}</option>`;
    div.setAttribute('data-siralanabilir', '');
    div.innerHTML = `
        <div class="satir-esnek" style="gap:8px;align-items:flex-start">
            <span class="sira-oklar" style="padding-top:8px">
                <button type="button" class="sira-ok" data-sort-dir="yukari" title="Yukarı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 15l7-7 7 7"/></svg></button>
                <button type="button" class="sira-ok" data-sort-dir="asagi" title="Aşağı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg></button>
            </span>
            <div style="flex:1">
                <div class="form-satir" style="margin-bottom:8px">
                    <input class="girdi alan-etiket" placeholder="Alan etiketi" value="${(field.tag||'').replace(/"/g,'&quot;')}">
                    <select class="secim alan-tip" onchange="optionShow(this)">${typeOps}</select>
                </div>
                <textarea class="metin-alani alan-secenekler" placeholder="Seçenekler (her satıra bir tane)" style="min-height:60px;display:${field.type==='secim'?'block':'none'}">${field.options||''}</textarea>
                <label class="satir-esnek kucuk mt-2" style="gap:7px;cursor:pointer"><input type="checkbox" class="alan-zorunlu" ${field.is_required!=0?'checked':''}> Zorunlu alan</label>
            </div>
            <button type="button" class="ikon-eylem tehlike" onclick="this.closest('.alan-satir').remove()"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>`;
    document.getElementById('fieldList').appendChild(div);
    if (window.ozelPickerRefresh) ozelPickerRefresh();
}
function optionShow(sel) { sel.closest('.field-row_item').querySelector('.field-options').style.display = sel.value === 'secim' ? 'block' : 'none'; }
function fieldAdd() { alanSatiri(); }
function formSifirla() {
    document.getElementById('formForm').reset();
    document.getElementById('f_id').value = '';
    document.getElementById('formTitle').textContent = 'Yeni Form Şablonu';
    document.getElementById('fieldList').innerHTML = '';
    alanSatiri({tag:'Konu', type:'metin'}); alanSatiri({tag:'Açıklama', type:'uzun_metin'});
}
function formEdit(f) {
    document.getElementById('formTitle').textContent = 'Formu Düzenle';
    document.getElementById('f_id').value = f.id;
    document.getElementById('f_name').value = f.name;
    document.getElementById('f_description').value = f.description || '';
    document.getElementById('f_is_active').value = f.is_active;
    document.getElementById('fieldList').innerHTML = '';
    f.fields.forEach(a => alanSatiri(a));
    modalOpen('modalForm');
}
document.getElementById('formForm').addEventListener('submit', () => {
    const fields = Array.from(document.querySelectorAll('.field-row_item')).map(s => ({
        tag: s.querySelector('.field-tag').value.trim(),
        type: s.querySelector('.field-type').value,
        options: s.querySelector('.field-options').value.trim(),
        is_required: s.querySelector('.field-is_required').checked ? 1 : 0
    })).filter(a => a.tag);
    document.getElementById('f_fields').value = JSON.stringify(fields);
});
</script>
<?php page_end(); ?>

<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$templates = rows("SELECT s.*, (SELECT COUNT(*) FROM template_steps sa WHERE sa.template_id=s.id) step_count, (SELECT COUNT(*) FROM tasks g JOIN task_steps ga ON ga.task_id=g.id WHERE 1=0) kullanim FROM workflow_templates s ORDER BY s.name");
foreach ($templates as &$s) $s['steps'] = rows("SELECT name FROM template_steps WHERE template_id=? ORDER BY sort_order", [$s['id']]);
unset($s);

page_start('Akış Şablonları', 'workflows');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Akış Şablonları</div><div class="sayfa-alt">Görevlerin izleyeceği iş akışı adımlarını tanımlayın</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalWorkflow" onclick="workflowSifirla()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Şablon</button></div>
</div>

<?php if (!$templates): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div><div class="bos-baslik">Şablon yok</div><div class="bos-metin">İçerik üretimi, prodüksiyon veya web projeleri için akış şablonları oluşturun.</div></div>
<?php else: ?>
<div class="izgara izgara-2">
    <?php foreach ($templates as $s): ?>
    <div class="kart">
        <div class="satir-esnek arasi mb-2">
            <div><div class="kart-baslik" style="font-size:16px"><?= e($s['name']) ?></div><?php if ($s['description']): ?><div class="hucre-alt mt-1"><?= e($s['description']) ?></div><?php endif; ?></div>
            <div class="satir-esnek" style="gap:4px">
                <button class="ikon-eylem" onclick='workflowEdit(<?= json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                <button class="ikon-eylem tehlike" data-action="workflow_delete" data-id="<?= $s['id'] ?>" data-approval="Şablon silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button>
            </div>
        </div>
        <div class="akis-ray" style="margin-top:14px">
            <?php foreach ($s['steps'] as $i => $a): ?>
            <div class="akis-adim">
                <div class="akis-cizgi"></div>
                <div class="akis-adim-ic"><div class="akis-yuvarlak"><?= $i + 1 ?></div><div class="akis-ad"><?= e($a['name']) ?></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalWorkflow">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="workflowTitle">Yeni Akış Şablonu</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="workflow_save" id="workflowForm">
        <input type="hidden" name="id" id="a_id"><input type="hidden" name="steps" id="a_steps">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Şablon Adı <span class="zorunlu">*</span></label><input name="name" id="a_name" class="girdi" required placeholder="Örn. Sosyal Medya İçerik Üretimi"></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="description" id="a_description" class="girdi"></div>
            <div class="form-grup">
                <label class="form-etiket">Akış Adımları</label>
                <div class="dikey" id="stepList" style="gap:8px"></div>
                <button type="button" class="btn btn-sm btn-hayalet mt-2" onclick="stepAdd()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Adım Ekle</button>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
function adimSatiri(setting_value = '') {
    const div = document.createElement('div');
    div.className = 'satir-esnek adim-satir';
    div.style.gap = '8px';
    div.setAttribute('data-siralanabilir', '');
    div.innerHTML = `<span class="sira-oklar">
        <button type="button" class="sira-ok" data-sort-dir="yukari" title="Yukarı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 15l7-7 7 7"/></svg></button>
        <button type="button" class="sira-ok" data-sort-dir="asagi" title="Aşağı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg></button>
    </span><input class="girdi adim-input" value="${setting_value.replace(/"/g,'&quot;')}" placeholder="Adım adı"><button type="button" class="ikon-eylem tehlike" onclick="this.parentElement.remove()"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
    document.getElementById('stepList').appendChild(div);
}
function stepAdd() { adimSatiri(); }
function workflowSifirla() {
    document.getElementById('workflowForm').reset();
    document.getElementById('a_id').value = '';
    document.getElementById('workflowTitle').textContent = 'Yeni Akış Şablonu';
    document.getElementById('stepList').innerHTML = '';
    adimSatiri('Brief'); adimSatiri('Tasarım'); adimSatiri('İç Onay'); adimSatiri('Müşteri Onayı');
}
function workflowEdit(s) {
    document.getElementById('workflowTitle').textContent = 'Şablonu Düzenle';
    document.getElementById('a_id').value = s.id;
    document.getElementById('a_name').value = s.name;
    document.getElementById('a_description').value = s.description || '';
    document.getElementById('stepList').innerHTML = '';
    s.steps.forEach(a => adimSatiri(a.name));
    modalOpen('modalWorkflow');
}
document.getElementById('workflowForm').addEventListener('submit', () => {
    const steps = Array.from(document.querySelectorAll('.adim-input')).map(i => i.value.trim()).filter(Boolean);
    document.getElementById('a_steps').value = JSON.stringify(steps);
});
</script>
<?php page_end(); ?>

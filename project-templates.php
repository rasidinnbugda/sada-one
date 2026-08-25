<?php
/**
 * SADA One — Project Templates
 * One-click project setup with ready-made task sets.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$templates = rows("SELECT * FROM project_templates ORDER BY name");
$workflows = rows("SELECT id, name FROM workflow_templates ORDER BY name");

page_start('Proje Şablonları', 'ptemplates');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Proje Şablonları</div><div class="sayfa-alt">Yeni proje açarken tek tıkla kurulan hazır görev setleri</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalPS" onclick="psSifirla()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Şablon</button></div>
</div>

<?php if (!$templates): ?>
<div class="bos-durum">
    <div class="bos-ikon"><?= icon('document', 36) ?></div>
    <div class="bos-baslik">Şablon yok</div>
    <div class="bos-metin">Örn. "Aylık Sosyal Medya Paketi" şablonu: içerik üretimi, çekim, raporlama görevleri akışlarıyla hazır kurulsun.</div>
    <button class="btn btn-marka" data-modal="modalPS" onclick="psSifirla()">İlk Şablonu Oluştur</button>
</div>
<?php else: ?>
<div class="izgara izgara-2">
    <?php foreach ($templates as $ps):
        $taskList = json_decode($ps['tasks'], true) ?: []; ?>
    <div class="kart">
        <div class="satir-esnek arasi mb-2">
            <div><div class="kart-baslik" style="font-size:16px"><?= e($ps['name']) ?></div><?php if ($ps['description']): ?><div class="hucre-alt mt-1"><?= e($ps['description']) ?></div><?php endif; ?></div>
            <div class="satir-esnek" style="gap:4px">
                <button class="ikon-eylem" onclick='psEdit(<?= json_encode($ps, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 16) ?></button>
                <button class="ikon-eylem tehlike" data-action="ptemplate_delete" data-id="<?= $ps['id'] ?>" data-approval="Şablon silinsin mi? (Mevcut projeler etkilenmez)"><?= icon('cop', 16) ?></button>
            </div>
        </div>
        <div class="dikey" style="gap:5px">
            <?php foreach ($taskList as $sg): ?>
            <div class="satir-esnek arasi kucuk" style="padding:7px 11px;background:var(--surface-2);border-radius:9px">
                <span><?= e($sg['title']) ?></span>
                <span class="satir-esnek" style="gap:6px">
                    <?php if (($sg['priority'] ?? 'normal') !== 'normal'): ?><?= badge($sg['priority'], PRIORITIES) ?><?php endif; ?>
                    <?php if (!empty($sg['workflow_id'])): $workflowName = ''; foreach ($workflows as $ak) if ($ak['id'] == $sg['workflow_id']) $workflowName = $ak['name']; ?>
                    <span class="rozet rozet-tur"><?= icon('roket', 10) ?> <?= e($workflowName ?: 'Akış') ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalPS">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik" id="psTitle">Yeni Proje Şablonu</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="ptemplate_save" id="psForm">
        <input type="hidden" name="id" id="ps_id"><input type="hidden" name="tasks" id="ps_tasks">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Şablon Adı <span class="zorunlu">*</span></label><input name="name" id="ps_name" class="girdi" required placeholder="Örn. Aylık Sosyal Medya Paketi"></div>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="description" id="ps_description" class="girdi"></div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Görevler</label>
                <div class="dikey" id="psTaskList" style="gap:8px"></div>
                <button type="button" class="btn btn-sm btn-hayalet mt-2" onclick="psTaskAdd()">+ Görev Ekle</button>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
const psWorkflows = <?= json_encode($workflows, JSON_UNESCAPED_UNICODE) ?>;
const psPriorities = <?= json_encode(PRIORITIES, JSON_UNESCAPED_UNICODE) ?>;
function psTaskAdd(g = {}) {
    const div = document.createElement('div');
    div.className = 'satir-esnek ps-satir';
    div.style.gap = '8px';
    let workflowOps = '<option value="0">Akışsız</option>';
    psWorkflows.forEach(a => workflowOps += `<option value="${a.id}" ${g.workflow_id == a.id ? 'selected' : ''}>${esc(a.name)}</option>`);
    let oncOps = '';
    for (const k in psPriorities) oncOps += `<option value="${k}" ${(g.priority || 'normal') === k ? 'selected' : ''}>${psPriorities[k]}</option>`;
    div.innerHTML = `<input class="girdi ps-baslik" placeholder="Görev başlığı" style="flex:2" value="${(g.title || '').replace(/"/g, '&quot;')}">
        <select class="secim ps-akis" style="flex:1">${workflowOps}</select>
        <select class="secim ps-onc" style="width:110px">${oncOps}</select>
        <button type="button" class="ikon-eylem tehlike" onclick="this.parentElement.remove()">✕</button>`;
    document.getElementById('psTaskList').appendChild(div);
    if (window.ozelPickerRefresh) ozelPickerRefresh();
}
function psSifirla() {
    document.getElementById('psForm').reset();
    document.getElementById('ps_id').value = '';
    document.getElementById('psTitle').textContent = 'Yeni Proje Şablonu';
    document.getElementById('psTaskList').innerHTML = '';
    psTaskAdd(); psTaskAdd();
}
function psEdit(ps) {
    document.getElementById('psTitle').textContent = 'Şablonu Düzenle';
    document.getElementById('ps_id').value = ps.id;
    document.getElementById('ps_name').value = ps.name;
    document.getElementById('ps_description').value = ps.description || '';
    document.getElementById('psTaskList').innerHTML = '';
    (JSON.parse(ps.tasks || '[]')).forEach(g => psTaskAdd(g));
    modalOpen('modalPS');
}
document.getElementById('psForm').addEventListener('submit', () => {
    const tasks = Array.from(document.querySelectorAll('.ps-row_item')).map(s => ({
        title: s.querySelector('.ps-title').value.trim(),
        workflow_id: parseInt(s.querySelector('.ps-workflow').value) || 0,
        priority: s.querySelector('.ps-onc').value,
    })).filter(g => g.title);
    document.getElementById('ps_tasks').value = JSON.stringify(tasks);
});
</script>
<?php page_end(); ?>

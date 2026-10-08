<?php
/**
 * SADA One — Task types (step recipes) and skills
 * A task type is a recipe: the ordered steps a deliverable goes through. Each step has a skill (who can
 * do it), a kind (production / internal review / client approval / publish) and optionally a default person.
 * Types sit in folders; a type can be made for one client file only — it is then offered on that file's work alone.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$skills = rows("SELECT s.id, s.name, (SELECT COUNT(*) FROM user_skills us JOIN users x ON x.id=us.user_id WHERE us.skill_id=s.id AND x.is_active=1) people FROM skills s ORDER BY s.sort_order, s.name");
$skillName = array_column($skills, 'name', 'id');
$team = rows("SELECT id, name FROM users WHERE role IN ('admin','pm','team','intern') AND is_active=1 ORDER BY name");
$teamName = array_column($team, 'name', 'id');
$types = rows("SELECT t.*, c.name client_name, (SELECT COUNT(*) FROM tasks g WHERE g.type_id=t.id) usage_count FROM task_types t LEFT JOIN clients c ON c.id=t.client_id
    ORDER BY t.client_id IS NULL, c.name, t.folder IS NULL, t.folder, t.kind, t.name");
foreach ($types as &$t) $t['steps'] = rows("SELECT id, name, skill_id, kind, owner_id, optional FROM task_type_steps WHERE type_id=? ORDER BY sort_order, id", [$t['id']]);
unset($t);
// Sections: each file's own types, then each folder, then the types without a folder
$typeGroups = [];
foreach ($types as $t) {
    $key = $t['client_id'] ? 'c' . $t['client_id'] : 'f' . ($t['folder'] ?? '');
    $typeGroups[$key] ??= ['label' => $t['client_id'] ? ($t['client_name'] ?? 'Dosya') : (($t['folder'] ?? '') ?: 'Genel'), 'client' => (bool)$t['client_id'], 'types' => []];
    $typeGroups[$key]['types'][] = $t;
}
$folders = array_values(array_unique(array_filter(array_map(fn($t) => (string)$t['folder'], $types))));
$clientList = rows("SELECT id, name FROM clients WHERE status='active' OR id IN (SELECT client_id FROM task_types WHERE client_id IS NOT NULL) ORDER BY name");
$kindIcon = ['work' => '●', 'review' => '◆', 'client_approval' => '✓', 'publish' => '↗'];

page_start('İş Türleri', 'task_types');
?>
<div class="page-top">
    <div><div class="page-title">İş Türleri</div><div class="page-bottom">Her iş türü bir tarif: işin geçeceği adımlar, adımları kimin yapabileceği ve hangi adımda müşteriye gideceği</div></div>
    <div class="page-top-action"><button class="btn btn-brand" onclick="typeReset();modalOpen('modalType')"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni İş Türü</button></div>
</div>

<div class="grid" style="grid-template-columns:minmax(0,1fr) 300px;align-items:start">
    <div>
        <?php if (!$types): ?>
        <div class="empty-state"><div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div><div class="empty-title">Henüz iş türü yok</div><div class="empty-text">"Reels", "Gönderi", "Video" gibi türler tanımlayın; yeni işler adımlarını buradan alır.</div></div>
        <?php else: foreach ($typeGroups as $groupKey => $group):
            if (count($typeGroups) > 1 || $groupKey !== 'f'): ?>
        <div class="type-folder"><?= icon($group['client'] ? 'handshake' : 'folder', 16) ?> <span><?= e($group['label']) ?></span><?= $group['client'] ? '<span class="badge badge-type">bu dosyaya özel</span>' : '' ?><span class="cell-bottom"><?= count($group['types']) ?> tür</span></div>
        <?php endif;
            foreach ($group['types'] as $t): ?>
        <div class="card mb-3">
            <div class="row-flex between mb-2">
                <div>
                    <div class="row-flex" style="gap:8px"><span class="card-title" style="font-size:16px"><?= e($t['name']) ?></span><?php if ($t['kind'] === 'internal'): ?><span class="badge badge-type">İç iş</span><?php endif; ?><?php if ($t['client_id'] && $t['folder']): ?><span class="badge badge-type"><?= e($t['folder']) ?></span><?php endif; ?></div>
                    <div class="cell-bottom mt-1"><?= $t['description'] ? e($t['description']) . ' · ' : '' ?><?= count($t['steps']) ?> adım · <?= (int)$t['usage_count'] ?> işte kullanıldı</div>
                </div>
                <div class="row-flex" style="gap:4px">
                    <button class="icon-action" title="Düzenle" onclick='typeEdit(<?= json_encode($t, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                    <button class="icon-action danger" title="Sil" data-action="task_type_delete" data-id="<?= $t['id'] ?>" data-confirm="İş türü silinsin mi? Bu türle açılmış işler adımlarını korur."><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                </div>
            </div>
            <?php if ($t['steps']): ?>
            <div class="flow-rail" style="margin-top:14px">
                <?php foreach ($t['steps'] as $i => $s): ?>
                <div class="flow-step<?= $s['optional'] ? ' optional' : '' ?>">
                    <div class="flow-line"></div>
                    <div class="flow-step-inner">
                        <div class="flow-circle" title="<?= STEP_KINDS[$s['kind']] ?>"><?= $s['kind'] === 'work' ? $i + 1 : $kindIcon[$s['kind']] ?></div>
                        <div class="flow-name"><?= e($s['name']) ?></div>
                        <div class="cell-bottom" style="font-size:11px"><?= $s['skill_id'] ? e($skillName[$s['skill_id']] ?? '—') : 'Uzmanlık yok' ?><?= $s['owner_id'] ? ' · ' . e(explode(' ', $teamName[$s['owner_id']] ?? '')[0]) : '' ?><?= $s['optional'] ? ' · atlanabilir' : '' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?><div class="text-muted small">Adımsız tür: işler durumlarıyla elle yönetilir.</div><?php endif; ?>
        </div>
        <?php endforeach; endforeach; endif; ?>
    </div>

    <div class="card">
        <div class="card-title mb-1" style="font-size:15px">Uzmanlıklar</div>
        <div class="cell-bottom mb-3">Bir adımı kimin yapabileceğini söyler. Kişilere Kullanıcılar sayfasından verilir; sahipsiz adım uzmanlığın havuzuna düşer.</div>
        <div class="vertical" style="gap:6px">
            <?php foreach ($skills as $s): ?>
            <div class="row-flex between" style="padding:7px 10px;background:var(--surface-2);border-radius:9px">
                <span class="small bold"><?= e($s['name']) ?> <span class="cell-bottom" style="font-weight:400">· <?= (int)$s['people'] ?> kişi</span></span>
                <span class="row-flex" style="gap:2px">
                    <button class="icon-action" style="width:26px;height:26px" title="Yeniden adlandır" onclick="skillRename(<?= $s['id'] ?>, <?= e(json_encode($s['name'], JSON_UNESCAPED_UNICODE)) ?>)"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="13"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                    <button class="icon-action danger" style="width:26px;height:26px" title="Sil" data-action="skill_delete" data-id="<?= $s['id'] ?>" data-confirm="Uzmanlık silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <form data-ajax="skill_save" data-refresh="yes" class="row-flex mt-2" style="gap:8px"><input name="name" class="input" placeholder="Yeni uzmanlık" required><button class="btn btn-sm">Ekle</button></form>
    </div>
</div>

<div class="modal-overlay" id="modalType">
    <div class="modal modal-wide"><div class="modal-top"><div class="modal-title" id="typeTitle">Yeni İş Türü</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="task_type_save" data-refresh="yes" id="typeForm">
        <input type="hidden" name="id" id="t_id"><input type="hidden" name="steps" id="t_steps">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Adı <span class="required">*</span></label><input name="name" id="t_name" class="input" required placeholder="Örn. Reels"></div>
                <div class="form-group"><label class="form-label">Açıklama</label><input name="description" id="t_description" class="input" placeholder="Kısa not (opsiyonel)"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Klasör</label><input name="folder" id="t_folder" class="input" list="typeFolders" maxlength="80" placeholder="Örn. Sosyal Medya, Video, Basılı" autocomplete="off">
                    <datalist id="typeFolders"><?php foreach ($folders as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
                    <div class="form-hint">Yeni iş açarken türler klasörlerine göre gruplanır.</div></div>
                <div class="form-group"><label class="form-label">Hangi dosyalar</label><select name="client_id" id="t_client" class="select"><option value="">Tüm dosyalar</option><?php foreach ($clientList as $c): ?><option value="<?= $c['id'] ?>">Sadece <?= e($c['name']) ?></option><?php endforeach; ?></select>
                    <div class="form-hint">Bir dosyaya özel tür, yalnızca o dosyanın işlerinde çıkar.</div></div>
            </div>
            <div class="form-group">
                <label class="form-label">Varsayılan tür</label>
                <div class="row-flex wrap" style="gap:8px">
                    <?php foreach (TASK_KINDS as $k => $v): ?><label class="row-flex small" style="gap:7px;padding:7px 12px;background:var(--surface-2);border-radius:9px;cursor:pointer"><input type="radio" name="kind" value="<?= $k ?>" <?= $k === 'client' ? 'checked' : '' ?>> <?= $v ?></label><?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Adımlar</label>
                <div class="form-hint mb-2">Üretim: işin yapıldığı adım · İç kontrol: onay ya da geri gönderme · Müşteri onayı: işi müşteriye yollar · Yayın: son adım, iş "Yayınlandı" olur. Kişi boş bırakılırsa adım uzmanlığın havuzuna düşer (Koordinasyon adımları proje yöneticisine gider).</div>
                <div class="vertical" id="typeSteps" style="gap:8px"></div>
                <button type="button" class="btn btn-sm btn-ghost mt-2" onclick="typeStepRow()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Adım Ekle</button>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<script>
const skillOptions = <?= json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']], $skills), JSON_UNESCAPED_UNICODE) ?>;
const teamOptions = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name']], $team), JSON_UNESCAPED_UNICODE) ?>;
const stepKinds = <?= json_encode(STEP_KINDS, JSON_UNESCAPED_UNICODE) ?>;
function typeStepRow(step = {}) {
    const div = document.createElement('div');
    div.className = 'row-flex type-step';
    div.style.gap = '8px';
    div.setAttribute('data-sortable', '');
    const opts = (list, selected, empty) => `<option value="">${empty}</option>` + list.map(o => `<option value="${o.id}" ${String(o.id) === String(selected ?? '') ? 'selected' : ''}>${esc(o.name)}</option>`).join('');
    div.innerHTML = `<span class="order-arrows">
        <button type="button" class="order-arrow" data-sort-dir="up" title="Yukarı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 15l7-7 7 7"/></svg></button>
        <button type="button" class="order-arrow" data-sort-dir="down" title="Aşağı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg></button>
    </span>
    <input class="input ts-name" value="${esc(step.name || '')}" placeholder="Adım adı" style="flex:2">
    <select class="select native-select ts-skill" style="flex:1">${opts(skillOptions, step.skill_id, 'Uzmanlık yok')}</select>
    <select class="select native-select ts-kind" style="flex:1">${Object.entries(stepKinds).map(([k, v]) => `<option value="${k}" ${(step.kind || 'work') === k ? 'selected' : ''}>${v}</option>`).join('')}</select>
    <select class="select native-select ts-owner" style="flex:1">${opts(teamOptions, step.owner_id, 'Kişi: havuz')}</select>
    <label class="row-flex small ts-optional-label" title="Zorunlu değil: iş açılırken çıkarılabilir, sırası gelince atlanabilir"><input type="checkbox" class="ts-optional" ${Number(step.optional) ? 'checked' : ''}> Atlanabilir</label>
    <button type="button" class="icon-action danger" onclick="this.parentElement.remove()" title="Adımı kaldır"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
    document.getElementById('typeSteps').appendChild(div);
}
function typeReset() {
    const f = document.getElementById('typeForm');
    f.reset();
    document.getElementById('t_id').value = '';
    formFieldSet(f, 'client_id', '');
    document.getElementById('typeTitle').textContent = 'Yeni İş Türü';
    document.getElementById('typeSteps').innerHTML = '';
    const skill = n => (skillOptions.find(s => s.name === n) || {}).id;
    [['Brif', 'Koordinasyon', 'work'], ['Üretim', 'Tasarım', 'work'], ['İç kontrol', 'Koordinasyon', 'review'], ['Müşteri onayı', 'Koordinasyon', 'client_approval'], ['Yayın', 'Koordinasyon', 'publish']]
        .forEach(([name, s, kind]) => typeStepRow({ name, skill_id: skill(s), kind }));
}
function typeEdit(t) {
    typeReset();
    document.getElementById('typeTitle').textContent = 'İş Türünü Düzenle';
    document.getElementById('t_id').value = t.id;
    document.getElementById('t_name').value = t.name;
    document.getElementById('t_description').value = t.description || '';
    document.getElementById('t_folder').value = t.folder || '';
    formFieldSet(document.getElementById('typeForm'), 'client_id', t.client_id || '');
    document.querySelectorAll('#typeForm input[name=kind]').forEach(r => { r.checked = r.value === t.kind; });
    document.getElementById('typeSteps').innerHTML = '';
    t.steps.forEach(s => typeStepRow(s));
    modalOpen('modalType');
}
document.getElementById('typeForm').addEventListener('submit', () => {
    document.getElementById('t_steps').value = JSON.stringify(Array.from(document.querySelectorAll('.type-step')).map(r => ({
        name: r.querySelector('.ts-name').value.trim(), skill_id: r.querySelector('.ts-skill').value, kind: r.querySelector('.ts-kind').value, owner_id: r.querySelector('.ts-owner').value, optional: r.querySelector('.ts-optional').checked ? 1 : 0,
    })).filter(s => s.name));
});
async function skillRename(id, name) {
    const newName = prompt('Uzmanlığın yeni adı:', name);
    if (!newName || newName.trim() === name) return;
    const j = await api('skill_save', { id, name: newName.trim() });
    if (j.ok) location.reload();
}
</script>
<?php page_end(); ?>

<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$forms = rows("SELECT * FROM form_templates WHERE is_active=1 ORDER BY name");

if (is_staff()) {
    $requests = rows("SELECT t.*, f.name form_name, ug.name sender_name, d.name client_name, p.name project_name FROM requests t JOIN form_templates f ON f.id=t.template_id LEFT JOIN users ug ON ug.id=t.sender_id LEFT JOIN clients d ON d.id=t.client_id LEFT JOIN projects p ON p.id=t.project_id ORDER BY FIELD(t.status,'new','reviewing','task_created','completed','rejected'), t.id DESC");
} else {
    $requests = rows("SELECT t.*, f.name form_name, ug.name sender_name, p.name project_name FROM requests t JOIN form_templates f ON f.id=t.template_id LEFT JOIN users ug ON ug.id=t.sender_id LEFT JOIN projects p ON p.id=t.project_id WHERE t.sender_id=? ORDER BY t.id DESC", [$u['id']]);
}

// Project list for the customer (to pick in the request form)
$customerProjects = [];
if (is_customer()) {
    [$mdIn, $mdP] = in_clause(customer_client_ids());
    $customerProjects = rows("SELECT id, name FROM projects WHERE client_id IN $mdIn AND status='active' ORDER BY name", $mdP);
}
$allClients = is_staff() ? rows("SELECT id, name FROM clients WHERE status='active' ORDER BY name") : [];

page_start('Talepler', 'requests');
?>
<div class="page-top">
    <div><div class="page-title"><?= is_staff() ? 'Gelen Talepler' : 'Taleplerim' ?></div><div class="page-bottom"><?= is_staff() ? 'Müşteri ve ekip talepleri' : 'Oluşturduğunuz talepler ve durumları' ?></div></div>
    <div class="page-top-action"><button class="btn btn-brand" data-modal="modalNewRequest"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Talep</button></div>
</div>

<?php if (is_staff()): ?>
<div class="filter-bar">
    <div class="pill-filter" data-pill-group="#requestList .request-row">
        <button class="pill active" data-value="">Tümü</button>
        <?php foreach (REQUEST_STATUSES as $k => $v): ?><button class="pill" data-value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$requests): ?>
<div class="empty-state"><div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 10h8m-8 4h4m9-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="empty-title">Talep yok</div><div class="empty-text"><?= is_staff() ? 'Henüz gelen bir talep bulunmuyor.' : 'Bir iş, revizyon veya çekim talebi oluşturmak için başlayın.' ?></div><button class="btn btn-brand" data-modal="modalNewRequest">Yeni Talep Oluştur</button></div>
<?php else: ?>
<div class="table-wrap"><table class="table" id="requestList"><thead><tr><th>Talep</th><?php if (is_staff()): ?><th>Gönderen</th><th>Dosya/Proje</th><?php endif; ?><th>Tarih</th><th>Durum</th><th></th></tr></thead><tbody>
    <?php foreach ($requests as $t): ?>
    <tr class="tick request-row" data-filter="<?= $t['status'] ?>" onclick="location.href='request.php?id=<?= $t['id'] ?>'">
        <td><div class="cell-main"><?= e($t['title']) ?></div><div class="cell-bottom"><?= e($t['form_name']) ?></div></td>
        <?php if (is_staff()): ?>
        <td><?= e($t['sender_name']) ?></td>
        <td class="small"><?= e($t['client_name'] ?? '—') ?><?= $t['project_name'] ? ' / ' . e($t['project_name']) : '' ?></td>
        <?php endif; ?>
        <td class="small"><?= format_date($t['created']) ?></td>
        <td><?= badge($t['status'], REQUEST_STATUSES) ?></td>
        <td><svg width="16" fill="none" stroke="var(--muted)" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></td>
    </tr>
    <?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<!-- New request modal: pick form type → fill in the fields -->
<div class="modal-overlay" id="modalNewRequest">
    <div class="modal modal-wide"><div class="modal-top"><div class="modal-title">Yeni Talep</div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body">
        <div id="requestStep1">
            <div class="cell-bottom mb-3">Ne tür bir talep oluşturmak istiyorsunuz?</div>
            <div class="grid grid-2">
                <?php foreach ($forms as $f): ?>
                <button class="card card-tick" style="text-align:left;padding:16px" onclick="requestFormOpen(<?= $f['id'] ?>)">
                    <div class="bold"><?= e($f['name']) ?></div>
                    <?php if ($f['description']): ?><div class="cell-bottom mt-1"><?= e($f['description']) ?></div><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div id="requestStep2" style="display:none">
            <button class="btn btn-sm btn-ghost mb-3" onclick="requestBack()">← Geri</button>
            <form data-ajax="request_send" id="requestForm">
                <input type="hidden" name="template_id" id="requestTemplateId">
                <?php if ($customerProjects): ?>
                <div class="form-group"><label class="form-label">İlgili Proje</label><select name="project_id" class="select"><option value="">— Genel</option><?php foreach ($customerProjects as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
                <?php elseif ($allClients): ?>
                <div class="form-group"><label class="form-label">Dosya</label><select name="client_id" class="select"><option value="">—</option><?php foreach ($allClients as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div id="requestFields"></div>
                <button type="button" class="btn btn-brand btn-block mt-2" id="rtNextBtn" style="display:none" onclick="rtNext()">Sonraki Bölüm →</button>
                <button type="submit" class="btn btn-brand btn-block mt-2" id="rtSendBtn">Talebi Gönder</button>
            </form>
        </div>
    </div>
    </div>
</div>

<script>
const formFields = <?= json_encode(array_reduce($forms, function ($acc, $f) {
    $acc[$f['id']] = ['name' => $f['name'], 'fields' => rows("SELECT * FROM form_fields WHERE template_id=? ORDER BY sort_order", [$f['id']])];
    return $acc;
}, []), JSON_UNESCAPED_UNICODE) ?>;

function requestFormOpen(id) {
    const f = formFields[id]; if (!f) return;
    document.getElementById('requestTemplateId').value = id;
    // Sections become tabs: one tab per section, the fields before the first one go to "Genel"
    const sections = f.fields.filter(a => a.type === 'section');
    const tabbed = sections.length > 0;
    let h = '';
    let panelNo = 0;
    if (tabbed) {
        const names = f.fields[0].type === 'section' ? [] : ['Genel'];
        sections.forEach(b => names.push(b.label));
        h += `<div class="rt-tabs">` + names.map((ad, i) =>
            `<button type="button" class="rt-tab${i === 0 ? ' active' : ''}" onclick="rtTabSelect(this, ${i})"><span class="rt-no">${i + 1}</span><span>${esc(ad)}</span></button>`).join('') + `</div>`;
        h += `<div class="rt-progress"><div class="rt-progress-full" style="width:0%"></div></div><div class="rt-progress-text cell-bottom mb-2"></div>`;
        h += `<div class="rt-panel${panelNo === 0 ? ' active' : ''}" data-rt="${panelNo}"><div class="request-grid">`;
    }
    // Short fields sit in two columns; long fields and sections take the full width
    const shortTypes = ['text', 'date', 'number', 'select'];
    f.fields.forEach((a, fi) => {
        const is_required = a.is_required == 1 ? ' required' : '';
        const star = a.is_required == 1 ? ' <span class="required">*</span>' : '';
        if (a.type === 'section') {
            if (tabbed) {
                // close the previous panel, open this section's panel
                if (!(fi === 0)) h += `</div></div>`;
                else if (panelNo === 0 && fi === 0) h = h.replace(`<div class="rt-panel active" data-rt="0"><div class="request-grid">`, '');
                panelNo = (fi === 0) ? 0 : panelNo + 1;
                h += `<div class="rt-panel${(fi === 0 && panelNo === 0) ? ' active' : ''}" data-rt="${panelNo}">`;
                if ((a.options || '').trim()) h += `<div class="cell-bottom mb-2" style="white-space:pre-wrap">${esc(a.options.trim())}</div>`;
                h += `<div class="request-grid">`;
            } else {
                h += `<div class="request-section"><div class="request-section-title">${esc(a.label)}</div>`;
                if ((a.options || '').trim()) h += `<div class="cell-bottom mt-1" style="white-space:pre-wrap">${esc(a.options.trim())}</div>`;
                h += `</div>`;
            }
            return;
        }
        h += `<div class="form-group${shortTypes.includes(a.type) ? '' : ' request-wide'}"><label class="form-label">${esc(a.label)}${star}</label>`;
        if (a.type === 'long_text') h += `<textarea name="field_${a.id}" class="text-area"${is_required}></textarea>`;
        else if (a.type === 'select') {
            const rows = (a.options || '').split('\n').map(s => s.trim()).filter(Boolean);
            const hasOther = rows.includes('__other__');
            const optionList = rows.filter(s => s !== '__other__');
            if (hasOther) {
                // Picking "Diğer" opens a free-text box; the real value travels in a hidden field
                h += `<input type="hidden" name="field_${a.id}" class="other-value">`;
                h += `<select class="select other-select" onchange="selectOther(this)"${is_required}><option value="">— Seçin</option>`;
                optionList.forEach(s => { h += `<option value="${esc(s)}">${esc(s)}</option>`; });
                h += `<option value="__other__">Diğer...</option></select>`;
                h += `<input type="text" class="input mt-1 other-text" placeholder="Lütfen belirtin..." style="display:none" oninput="selectOther(this.closest('.form-group').querySelector('.other-select'))">`;
            } else {
                h += `<select name="field_${a.id}" class="select"${is_required}><option value="">— Seçin</option>`;
                optionList.forEach(s => { h += `<option value="${esc(s)}">${esc(s)}</option>`; });
                h += `</select>`;
            }
        }
        else if (a.type === 'multi_select') {
            const rows = (a.options || '').split('\n').map(s => s.trim()).filter(Boolean);
            const hasOther = rows.includes('__other__');
            h += `<input type="hidden" name="field_${a.id}" class="ms-value">`;
            h += `<div class="grid grid-2" style="gap:6px">`;
            rows.filter(s => s !== '__other__').forEach(s => {
                h += `<label class="row-flex small" style="gap:8px;padding:8px 11px;background:var(--surface-2);border-radius:9px;cursor:pointer"><input type="checkbox" class="ms-box" value="${esc(s)}" onchange="msUpdate(this)"> <span>${esc(s)}</span></label>`;
            });
            if (hasOther) {
                h += `<label class="row-flex small" style="gap:8px;padding:8px 11px;background:var(--surface-2);border-radius:9px;cursor:pointer"><input type="checkbox" class="ms-box ms-other" value="" onchange="msUpdate(this)"> <span style="flex-shrink:0">Diğer:</span><input type="text" class="input ms-other-text" style="padding:4px 8px;font-size:12.5px" oninput="msUpdate(this)"></label>`;
            }
            h += `</div>`;
        }
        else if (a.type === 'date') h += `<input type="date" name="field_${a.id}" class="input"${is_required}>`;
        else if (a.type === 'number') h += `<input type="number" name="field_${a.id}" class="input"${is_required}>`;
        else if (a.type === 'file') h += `<input type="file" name="field_${a.id}" class="input"${is_required}>`;
        else if (a.type === 'multi_file') h += `<input type="file" name="field_${a.id}" class="input" multiple${is_required}><div class="form-hint">Birden fazla dosyayı Ctrl ile seçebilirsiniz.</div>`;
        else h += `<input type="text" name="field_${a.id}" class="input"${is_required}>`;
        h += `</div>`;
    });
    if (tabbed) h += `</div></div>`;
    document.getElementById('requestFields').innerHTML = h;
    document.getElementById('requestFields').className = tabbed ? '' : 'request-grid';
    if (tabbed) {
        const container = document.getElementById('requestFields');
        container.querySelector('.rt-panel')?.setAttribute('data-visited', '1');
        // the container persists across template picks — bind once, not once per render
        if (!container.dataset.progressBound) {
            container.dataset.progressBound = '1';
            container.addEventListener('input', rtProgress);
            container.addEventListener('change', rtProgress);
        }
        rtProgress();
    }
    rtButtonsUpdate();
    if (window.customPickerRefresh) customPickerRefresh();
    document.getElementById('requestStep1').style.display = 'none';
    document.getElementById('requestStep2').style.display = 'block';
}
function rtTabSelect(btn, no) {
    const container = document.getElementById('requestFields');
    container.querySelectorAll('.rt-tab').forEach((s, i) => s.classList.toggle('active', i === no));
    container.querySelectorAll('.rt-panel').forEach(p => {
        const active = +p.dataset.rt === no;
        p.classList.toggle('active', active);
        if (active) p.setAttribute('data-visited', '1');
    });
    rtProgress();
    rtButtonsUpdate();
}
// Wizard flow: Send only on the LAST section; the earlier ones show "Sonraki Bölüm"
function rtButtonsUpdate() {
    const container = document.getElementById('requestFields');
    const next = document.getElementById('rtNextBtn');
    const send = document.getElementById('rtSendBtn');
    const panels = container.querySelectorAll('.rt-panel');
    if (!panels.length) { next.style.display = 'none'; send.style.display = ''; return; }
    const activeIndex = +(container.querySelector('.rt-panel.active')?.dataset.rt ?? 0);
    const isLast = activeIndex === panels.length - 1;
    next.style.display = isLast ? 'none' : '';
    send.style.display = isLast ? '' : 'none';
}
function rtNext() {
    const container = document.getElementById('requestFields');
    const activeIndex = +(container.querySelector('.rt-panel.active')?.dataset.rt ?? 0);
    // Are this section's required fields filled? If not, trigger the browser's warning
    const panel = container.querySelector(`.rt-panel[data-rt="${activeIndex}"]`);
    const missing = [...panel.querySelectorAll('input, textarea, select')].find(e => e.required && !e.checkValidity());
    if (missing) { missing.reportValidity(); return; }
    rtTabSelect(null, activeIndex + 1);
}
// Guard against an early submit with the keyboard (Enter): when not on the last section
// move to the next section instead of submitting (this listener is registered before app.js's)
document.addEventListener('submit', e => {
    if (e.target.id !== 'requestForm') return;
    const container = document.getElementById('requestFields');
    const panels = container.querySelectorAll('.rt-panel');
    if (!panels.length) return;
    const activeIndex = +(container.querySelector('.rt-panel.active')?.dataset.rt ?? 0);
    if (activeIndex < panels.length - 1) { e.preventDefault(); e.stopImmediatePropagation(); rtNext(); }
});
// Field filled check: hidden value carriers (custom select / other / multi select) included
function rtIsFull(el) {
    if (el.type === 'file') return el.files.length > 0;
    return (el.value || '').trim() !== '';
}
function rtProgress() {
    const container = document.getElementById('requestFields');
    if (!container.querySelector('.rt-tabs')) return;
    let requiredTotal = 0, requiredFilled = 0;
    container.querySelectorAll('.rt-panel').forEach(p => {
        // The value carrier is the field_* element of the form group: custom pickers (date)
        // turn the real input into type=hidden and move "required" onto the unnamed trigger, so
        // we must count the hidden carrier and read "required" from the asterisk
        const groups = [...p.querySelectorAll('.form-group')];
        const fieldEls = groups.map(g => g.querySelector('[name^="field_"]')).filter(Boolean);
        const requiredFields = groups.filter(g => g.querySelector('.required')).map(g => g.querySelector('[name^="field_"]')).filter(Boolean);
        requiredTotal += requiredFields.length;
        const filledRequired = requiredFields.filter(rtIsFull).length;
        requiredFilled += filledRequired;
        const done = requiredFields.length
            ? filledRequired === requiredFields.length
            : (p.getAttribute('data-visited') === '1' || fieldEls.some(rtIsFull));
        const tab = container.querySelectorAll('.rt-tab')[+p.dataset.rt];
        if (tab) {
            tab.classList.toggle('done', done);
            tab.querySelector('.rt-no').textContent = done ? '✓' : (+p.dataset.rt + 1);
        }
    });
    const panels = [...container.querySelectorAll('.rt-panel')];
    const donePanel = container.querySelectorAll('.rt-tab.done').length;
    const percent = requiredTotal ? Math.round(requiredFilled / requiredTotal * 100) : Math.round(donePanel / panels.length * 100);
    container.querySelector('.rt-progress-full').style.width = percent + '%';
    container.querySelector('.rt-progress-text').textContent = requiredTotal
        ? `${requiredFilled}/${requiredTotal} zorunlu alan tamamlandı (%${percent})`
        : `${donePanel}/${panels.length} bölüm tamamlandı`;
}
// An empty required field on a hidden tab makes the browser block silently;
// we catch the invalid event and switch to that field's tab so the user sees it
document.addEventListener('invalid', e => {
    const panel = e.target.closest('.rt-panel');
    if (panel && !panel.classList.contains('active')) rtTabSelect(null, +panel.dataset.rt);
}, true);
function msUpdate(box) {
    const group = box.closest('.form-group');
    const values = [...group.querySelectorAll('.ms-box:checked')].filter(k => !k.classList.contains('ms-other')).map(k => k.value);
    const other = group.querySelector('.ms-other');
    if (other && other.checked) {
        const text = group.querySelector('.ms-other-text').value.trim();
        values.push(text ? 'Diğer: ' + text : 'Diğer');
    }
    group.querySelector('.ms-value').value = values.join(', ');
}
function selectOther(sel) {
    const group = sel.closest('.form-group');
    const text = group.querySelector('.other-text');
    const hidden = group.querySelector('.other-value');
    if (sel.value === '__other__') {
        text.style.display = '';
        const v = text.value.trim();
        hidden.value = v ? 'Diğer: ' + v : '';
    } else {
        text.style.display = 'none';
        hidden.value = sel.value;
    }
}
function requestBack() { document.getElementById('requestStep2').style.display = 'none'; document.getElementById('requestStep1').style.display = 'block'; }
</script>
<?php page_end(); ?>

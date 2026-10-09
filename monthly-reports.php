<?php
/**
 * SADA One — Monthly Reports
 * The report is written in the client mail itself: click a text and type, click a picture to change it, add or
 * remove number tiles. A new report opens as a draft already filled with the month's data (published and finished
 * work, shoots, follower counts, next month's plan). Saved, it goes to the client from here.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/report-mail.php';
$u = require_staff();
if (is_intern()) { header('Location: index.php'); exit; }

$clients = rows("SELECT c.id, c.name, c.manager_id, u.name manager_name FROM clients c LEFT JOIN users u ON u.id=c.manager_id WHERE c.status='active' ORDER BY c.name");
$currentPeriod = date('Y-m');
// Fill-status of the current period per client (for the tracking grid)
$periodStatus = [];
foreach (rows("SELECT client_id, status FROM monthly_reports WHERE period=?", [$currentPeriod]) as $ps) $periodStatus[$ps['client_id']] = $ps['status'];
$reports = rows("SELECT r.*, d.name client_name, y.name author_name FROM monthly_reports r JOIN clients d ON d.id=r.client_id JOIN users y ON y.id=r.author_id ORDER BY r.period DESC, d.name");

// Report to edit (if client file + period are selected)
$selectClient = (int)($_GET['client'] ?? 0);
$selectPeriod = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['period'] ?? '') ? $_GET['period'] : date('Y-m');
$clientRow = $selectClient ? row("SELECT * FROM clients WHERE id=?", [$selectClient]) : null;
$current = $clientRow ? row("SELECT r.*, y.name author_name FROM monthly_reports r LEFT JOIN users y ON y.id=r.author_id WHERE r.client_id=? AND r.period=?", [$selectClient, $selectPeriod]) : null;
// What the editor opens with: the saved report, or a draft filled from the month's data
$reportState = $clientRow ? ($current ? report_state_clean($current, true) : report_draft($clientRow, $selectPeriod)) : null;

$periodName = fn(string $d) => MONTHS[(int)substr($d, 5, 2)] . ' ' . substr($d, 0, 4);

page_start('Aylık Raporlar', 'mreports');
?>
<div class="page-top">
    <div><div class="page-title">Aylık Raporlar</div><div class="page-bottom">Her dosyaya ayın raporu: doğrudan müşteriye gidecek mailin üzerinde yazılır</div></div>
</div>

<!-- This month at a glance: who has filled in, who has not -->
<div class="card mb-3">
    <div class="row-flex between mb-2">
        <div class="card-title" style="font-size:15px">Bu Ay (<?= e($periodName($currentPeriod)) ?>) Doldurma Durumu</div>
        <span class="small text-muted"><?= count(array_filter($periodStatus, fn($s) => $s === 'completed')) ?>/<?= count($clients) ?> tamamlandı</span>
    </div>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px">
        <?php foreach ($clients as $cl):
            $st = $periodStatus[$cl['id']] ?? null;
            $badge = $st === 'completed' ? '<span class="badge r-completed">Tamamlandı</span>' : ($st === 'draft' ? '<span class="badge r-in_progress">Taslak</span>' : '<span class="badge r-overdue">Boş</span>'); ?>
        <a href="?client=<?= $cl['id'] ?>&period=<?= $currentPeriod ?>" class="row-flex between small" style="padding:9px 12px;background:var(--surface-2);border-radius:10px;gap:8px">
            <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><b><?= e($cl['name']) ?></b><br><span class="text-muted" style="font-size:11px"><?= $cl['manager_name'] ? e($cl['manager_name']) : 'sorumlu atanmadı' ?></span></span>
            <?= $badge ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid rp-layout">
    <div class="card">
        <div class="card-title mb-2">Rapor Seç / Başlat</div>
        <form method="get">
            <div class="form-group"><label class="form-label">Dosya</label>
                <select name="client" class="select" onchange="this.form.submit()">
                    <option value="">Seçin...</option>
                    <?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $selectClient === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-group"><label class="form-label">Dönem</label>
                <input type="month" name="period" class="input native-select" value="<?= e($selectPeriod) ?>" onchange="this.form.submit()"></div>
        </form>

        <div class="card-title mb-2 mt-3" style="font-size:14px">Doldurulan Raporlar</div>
        <div class="vertical" style="gap:5px;max-height:420px;overflow-y:auto">
            <?php if (!$reports): ?><div class="text-muted small">Henüz rapor yok.</div><?php endif; ?>
            <?php foreach ($reports as $r): ?>
            <a href="?client=<?= $r['client_id'] ?>&period=<?= $r['period'] ?>" class="row-flex between small" style="padding:9px 11px;background:var(--surface-2);border-radius:9px">
                <span><b><?= e($r['client_name']) ?></b> · <?= $periodName($r['period']) ?></span>
                <?= $r['status'] === 'completed' ? '<span class="badge r-completed" style="padding:1px 8px">Tamam</span>' : '<span class="badge r-pending" style="padding:1px 8px">Taslak</span>' ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card rp-card">
        <?php if (!$clientRow): ?>
        <div class="empty-state" style="padding:60px 20px">
            <div class="empty-icon">📊</div>
            <div class="empty-title">Rapor seçin</div>
            <div class="empty-text">Soldan dosya ve dönem seçin: rapor, müşteriye gidecek mailin kendisi olarak açılır. Yeni raporlar ayın verileriyle dolu gelir.</div>
        </div>
        <?php else: ?>
        <div class="rp-head">
            <div class="card-title"><?= e($clientRow['name']) ?> — <?= $periodName($selectPeriod) ?> raporu</div>
            <div class="row-flex wrap mt-1" style="gap:8px">
                <span id="rp_status"><?php if (!$current): ?><span class="badge r-pending">Yeni taslak · kaydedilmedi</span><?php elseif ($current['status'] === 'completed'): ?><span class="badge r-completed">Tamamlandı</span><?php else: ?><span class="badge r-in_progress">Taslak</span><?php endif; ?></span>
                <?php if (!empty($current['sent_at'])): ?><span class="badge r-completed" title="<?= e($current['sent_to'] ?? '') ?>">Gönderildi: <?= format_date($current['sent_at'], true) ?></span><?php endif; ?>
                <span class="small text-muted" id="rp_saved"><?= $current ? 'Son kayıt: ' . e($current['author_name'] ?? '') . ' · ' . format_date($current['updated'] ?? $current['created'], true) : '' ?></span>
            </div>
        </div>

        <div class="rp-bar">
            <div class="rp-mode" role="group" aria-label="Görünüm">
                <button type="button" class="rp-mode-btn active" data-mode="edit"><?= icon('item', 14) ?> Düzenle</button>
                <button type="button" class="rp-mode-btn" data-mode="preview"><?= icon('person', 14) ?> Müşterinin göreceği</button>
            </div>
            <span class="rp-dirty" id="rp_dirty" hidden>Kaydedilmemiş değişiklik</span>
            <div class="rp-actions">
                <?php if (permission('ai_use')): ?><button type="button" class="btn btn-sm btn-ghost" id="rp_ai" onclick="rpAi()">🪄 AI ile yaz</button><?php endif; ?>
                <button type="button" class="btn btn-sm" onclick="rpSave()">Kaydet</button>
                <button type="button" class="btn btn-sm" id="rp_complete" onclick="rpComplete()"><?= ($current['status'] ?? '') === 'completed' ? 'Taslağa çevir' : 'Tamamla ✓' ?></button>
                <?php if (permission('report')): ?><button type="button" class="btn btn-sm btn-brand" onclick="reportMailOpen()">📧 Gönder…</button><?php endif; ?>
            </div>
        </div>
        <div class="rp-hint"><?= $current
            ? 'Bir metne tıklayıp yazın, görsele tıklayıp değiştirin. Boş bıraktığınız bölümler maile girmez.'
            : 'Bu taslak ' . e(report_month_in((int)substr($selectPeriod, 5, 2))) . 'ki verilerle dolduruldu: yayınlanan ve biten işler, çekimler, hesapların takipçi sayıları. Okuyun, düzeltin, kaydedin.' ?></div>
        <iframe id="rp_frame" class="rp-frame" title="Rapor maili"></iframe>
        <input type="file" id="rp_file" accept="image/png,image/jpeg,image/webp,image/gif" hidden>

        <?php
        // Internal finance summary for the selected client + period (live, never in the mail)
        $pStart = "$selectPeriod-01"; $pEnd = date('Y-m-t', strtotime($pStart));
        $finBudget = (float)val("SELECT COALESCE(SUM(budget),0) FROM projects WHERE client_id=? AND status='active'", [$selectClient]);
        $finExtra = (float)val("SELECT COALESCE(SUM(t.amount),0) FROM project_extra_requests t JOIN projects p ON p.id=t.project_id WHERE p.client_id=? AND t.status='approved' AND t.created BETWEEN ? AND ?", [$selectClient, "$pStart 00:00:00", "$pEnd 23:59:59"]);
        $finIncome = (float)val("SELECT COALESCE(SUM(o.amount),0) FROM payments o JOIN projects p ON p.id=o.project_id WHERE p.client_id=? AND o.date BETWEEN ? AND ?", [$selectClient, $pStart, $pEnd]);
        $finShoot = (float)val("SELECT COALESCE(SUM(e.cost),0) FROM events e LEFT JOIN projects p ON p.id=e.project_id WHERE (e.client_id=? OR p.client_id=?) AND e.start BETWEEN ? AND ?", [$selectClient, $selectClient, "$pStart 00:00:00", "$pEnd 23:59:59"]);
        ?>
        <details class="rp-finance">
            <summary>İç özet — müşteriye gitmez</summary>
            <div class="grid mt-2" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
                <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="cell-bottom">Aktif Proje Bütçesi</div><div class="bold"><?= number_format($finBudget, 0, ',', '.') ?> ₺</div></div>
                <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="cell-bottom">Bu Ay Onaylı Ek Talep</div><div class="bold">+<?= number_format($finExtra, 0, ',', '.') ?> ₺</div></div>
                <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="cell-bottom">Bu Ay Tahsilat</div><div class="bold" style="color:var(--success)"><?= number_format($finIncome, 0, ',', '.') ?> ₺</div></div>
                <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="cell-bottom">Bu Ay Çekim Maliyeti</div><div class="bold" style="color:var(--danger)"><?= number_format($finShoot, 0, ',', '.') ?> ₺</div></div>
            </div>
        </details>
        <?php endif; ?>
    </div>
</div>

<?php if ($clientRow): ?>
<div class="modal-overlay" id="modalReportMail">
    <div class="modal"><div class="modal-top"><div class="modal-title">📧 Raporu Gönder</div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body">
        <div class="form-group"><label class="form-label">Alıcılar</label>
            <div class="chip-field" id="rm_to_field" onclick="document.getElementById('rm_to_input').focus()">
                <input id="rm_to_input" placeholder="adres yazıp Enter'a basın..." autocomplete="off">
            </div>
            <div class="row-flex wrap mt-1" style="gap:6px" id="rm_people"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Gönderen</label><select class="select native-select" id="rm_from"></select></div>
            <div class="form-group"><label class="form-label">Konu</label><input class="input" id="rm_subject"></div>
        </div>
        <div class="form-hint">Sayfadaki mail, kaydedilen haliyle gider.</div>
    </div>
    <div class="modal-alt">
        <span class="small text-muted" id="rm_sent_info" style="margin-right:auto"></span>
        <button type="button" class="btn btn-ghost" data-modal-close>Vazgeç</button>
        <button type="button" class="btn btn-brand" id="rm_send" onclick="reportMailSend()">Gönder</button>
    </div>
    </div>
</div>
<script>
const RP = { client: <?= $selectClient ?>, period: <?= json_encode($selectPeriod) ?>, saved: <?= $current ? 'true' : 'false' ?>, status: <?= json_encode($current['status'] ?? 'draft') ?> };
let rpState = <?= json_encode(['summary' => $reportState['summary'], 'work_done' => $reportState['work_done'], 'metrics' => $reportState['metrics'], 'plan' => $reportState['plan'],
    'mail_data' => (object)(json_decode($reportState['mail_data'], true) ?: [])], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
let rpMode = 'edit', rpDirty = false, rpImgSlot = '';
const rpFrame = document.getElementById('rp_frame');
const rpDoc = () => rpFrame.contentDocument;

function rpSetDirty(v) { rpDirty = v; document.getElementById('rp_dirty').hidden = !v; }
window.addEventListener('beforeunload', e => { if (rpDirty) { e.preventDefault(); e.returnValue = ''; } });

// What was typed in an editable part: innerText keeps the line breaks of multi-line parts, textContent skips the
// CSS uppercase of labels
const rpText = el => el ? ((el.dataset.multi ? el.innerText : el.textContent) || '').replace(/\u00a0/g, ' ').replace(/\n{3,}/g, '\n\n').trim() : '';

/** The edited mail → rpState (only the editor has editable parts) */
function rpCollect() {
    const d = rpDoc();
    if (rpMode !== 'edit' || !d || !d.querySelector('[contenteditable]')) return;
    const md = rpState.mail_data;
    // an empty part arrives as [] from PHP; values set on an array would not survive JSON
    if (!md.text || Array.isArray(md.text)) md.text = {};
    if (!md.fav || Array.isArray(md.fav)) md.fav = {};
    d.querySelectorAll('[data-edit]').forEach(el => {
        const [a, b] = el.dataset.edit.split('.');
        const v = rpText(el);
        if (b === undefined) rpState[a] = v;
        else if (a === 'text') md.text[b] = v;
        else if (a === 'fav') md.fav[b] = v;
    });
    d.querySelectorAll('table[data-list]').forEach(list => {
        rpState[list.dataset.list] = [...list.querySelectorAll('[data-item]')].map(rpText).filter(Boolean).join('\n');
    });
    md.stats = [...d.querySelectorAll('[data-stat]')].map(t => ({
        label: rpText(t.querySelector('[data-k=label]')), value: rpText(t.querySelector('[data-k=value]')), change: rpText(t.querySelector('[data-k=change]')),
    }));
    // the picture grid: each tile keeps the original upload path (the frame shows a square crop)
    md.gallery = [...d.querySelectorAll('[data-gal]')].map(t => ({ img: t.dataset.path, caption: rpText(t.querySelector('[data-k=caption]')) }));
}

/** Draw the mail again from rpState: the editor, or what the client will see */
async function rpRender() {
    const y = window.scrollY;
    const j = await api('report_mail_render', { client_id: RP.client, period: RP.period, edit: rpMode === 'edit' ? '1' : '0', state: JSON.stringify(rpState) });
    if (!j.ok) return;
    rpFrame.addEventListener('load', () => window.scrollTo(0, y), { once: true });
    rpFrame.srcdoc = j.html;
}

// The frame is as tall as the mail: the page scrolls, not the frame
const rpFit = () => { const d = rpDoc(); if (d && d.body) rpFrame.style.height = Math.ceil(d.body.getBoundingClientRect().height) + 'px'; };

function rpFocusEnd(el) {
    if (!el) return;
    const d = rpDoc();
    el.focus();
    const r = d.createRange(); r.selectNodeContents(el); r.collapse(false);
    const s = d.getSelection(); s.removeAllRanges(); s.addRange(r);
}
function rpItemAdd(afterRow, text) {
    const row = afterRow.cloneNode(true);
    const cell = row.querySelector('[data-item]');
    cell.textContent = text;
    afterRow.after(row);
    rpFocusEnd(cell); rpSetDirty(true); rpFit();
    return row;
}

rpFrame.addEventListener('load', () => {
    const d = rpDoc();
    rpFit();
    new d.defaultView.ResizeObserver(rpFit).observe(d.body);
    d.querySelectorAll('img').forEach(i => i.addEventListener('load', rpFit));
    if (rpMode !== 'edit') return;
    d.addEventListener('input', e => {
        rpSetDirty(true);
        const el = e.target.closest && e.target.closest('[contenteditable]');
        if (el && rpText(el) === '') el.innerHTML = ''; // the hint comes back
    });
    d.addEventListener('keydown', e => {
        const el = e.target.closest && e.target.closest('[contenteditable]');
        if (!el) return;
        // Enter: a new line in a paragraph, the next line of a list, nothing in a title
        if (e.key === 'Enter' && !el.dataset.multi) {
            e.preventDefault();
            if (el.dataset.item) rpItemAdd(el.closest('tr'), '');
            return;
        }
        if (e.key === 'Backspace' && el.dataset.item && rpText(el) === '') {
            const row = el.closest('tr');
            if (row.parentElement.querySelectorAll('[data-item]').length < 2) return;
            e.preventDefault();
            const next = row.previousElementSibling || row.nextElementSibling;
            row.remove(); rpFocusEnd(next.querySelector('[data-item]')); rpSetDirty(true); rpFit();
        }
    });
    // Pasted text arrives plain; several lines pasted into a list become several lines of it
    d.addEventListener('paste', e => {
        const el = e.target.closest && e.target.closest('[contenteditable]');
        if (!el) return;
        e.preventDefault();
        const text = (e.clipboardData.getData('text/plain') || '').replace(/\r/g, '');
        if (el.dataset.item) {
            const [first, ...rest] = text.split('\n').map(s => s.trim().replace(/^[-•*]\s*/, '')).filter(Boolean);
            d.execCommand('insertText', false, first || '');
            let row = el.closest('tr');
            rest.forEach(t => { row = rpItemAdd(row, t); });
            return;
        }
        d.execCommand('insertText', false, el.dataset.multi ? text : text.replace(/\s*\n\s*/g, ' '));
    });
    d.addEventListener('click', e => {
        const op = e.target.closest && e.target.closest('[data-op]');
        if (!op) return;
        e.preventDefault();
        if (op.dataset.op === 'item-add') {
            const rows = d.querySelectorAll(`table[data-list="${op.dataset.for}"] tr[data-row]`);
            rpItemAdd(rows[rows.length - 1], '');
            return;
        }
        if (op.dataset.op === 'img-pick') { rpImgSlot = op.dataset.img; document.getElementById('rp_file').click(); return; }
        rpCollect();
        const md = rpState.mail_data;
        if (op.dataset.op === 'stat-add') md.stats.push({ label: '', value: '', change: '' });
        if (op.dataset.op === 'stat-del') md.stats.splice([...d.querySelectorAll('[data-stat]')].indexOf(op.closest('[data-stat]')), 1);
        if (op.dataset.op === 'img-remove') rpImgSet(op.dataset.img, null);
        if (op.dataset.op === 'gal-del') md.gallery.splice([...d.querySelectorAll('[data-gal]')].indexOf(op.closest('[data-gal]')), 1);
        rpSetDirty(true); rpRender();
    });
});

function rpImgSet(slot, path) {
    const md = rpState.mail_data;
    if (slot === 'hero') { if (path) md.hero = path; else delete md.hero; }
    if (slot === 'fav') { md.fav = md.fav || {}; if (path) md.fav.img = path; else delete md.fav.img; }
    md.gallery = md.gallery || [];
    if (slot === 'gal-new' && path) md.gallery.push({ img: path, caption: '' });
    if (slot.startsWith('gal:') && path && md.gallery[+slot.slice(4)]) md.gallery[+slot.slice(4)].img = path;
}
document.getElementById('rp_file').addEventListener('change', async e => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    toast('Görsel yükleniyor…', 'info', 1500);
    const j = await api('report_image_upload', { image: file });
    if (!j.ok) return;
    rpCollect(); rpImgSet(rpImgSlot, j.path); rpSetDirty(true); rpRender();
});

document.querySelectorAll('.rp-mode-btn').forEach(b => b.addEventListener('click', () => {
    if (b.dataset.mode === rpMode) return;
    rpCollect();
    rpMode = b.dataset.mode;
    document.querySelectorAll('.rp-mode-btn').forEach(x => x.classList.toggle('active', x === b));
    document.querySelector('.rp-card').classList.toggle('rp-previewing', rpMode !== 'edit');
    rpRender();
}));

const RP_BADGES = { draft: '<span class="badge r-in_progress">Taslak</span>', completed: '<span class="badge r-completed">Tamamlandı</span>' };
async function rpSave(status = RP.status) {
    rpCollect();
    const j = await api('monthly_report_save', { client_id: RP.client, period: RP.period, status,
        summary: rpState.summary, work_done: rpState.work_done, metrics: rpState.metrics, plan: rpState.plan, mail_data: JSON.stringify(rpState.mail_data) });
    if (!j.ok) return false;
    toast(j.message, 'success', 2200);
    rpSetDirty(false);
    RP.saved = true; RP.status = j.status;
    document.getElementById('rp_status').innerHTML = RP_BADGES[j.status];
    document.getElementById('rp_saved').textContent = 'Kaydedildi · ' + j.saved_at;
    document.getElementById('rp_complete').textContent = j.status === 'completed' ? 'Taslağa çevir' : 'Tamamla ✓';
    return true;
}
const rpComplete = () => rpSave(RP.status === 'completed' ? 'draft' : 'completed');

async function rpAi() {
    if (!confirm('AI ayın verilerinden özet, yapılanlar, rakam yorumu ve gelecek ay planı yazacak; bu bölümlerdeki metin değişir. Devam edilsin mi?')) return;
    const btn = document.getElementById('rp_ai');
    btn.disabled = true; btn.textContent = 'Yazılıyor… (~20 sn)';
    const j = await api('ai_report_draft', { client_id: RP.client, period: RP.period });
    btn.disabled = false; btn.textContent = '🪄 AI ile yaz';
    if (!j.ok) return;
    rpCollect();
    Object.entries(j.draft).forEach(([k, v]) => { if (v) rpState[k] = v; });
    rpSetDirty(true); rpRender();
    toast('AI taslağı yerleşti — okuyup düzenleyin.', 'success');
}

/* ---- Sending: recipients as chips, the file's contacts one click away ---- */
let rmRecipients = [], rmPersonList = [];
const rmIsEmail = a => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(a);
function rmChipDraw() {
    const field = document.getElementById('rm_to_field');
    field.querySelectorAll('.chip').forEach(cp => cp.remove());
    const input = document.getElementById('rm_to_input');
    rmRecipients.forEach(a => {
        const cp = document.createElement('span');
        cp.className = 'chip' + (rmIsEmail(a) ? '' : ' chip-invalid');
        cp.innerHTML = esc(a) + ' <button type="button" class="chip-delete" aria-label="Kaldır">✕</button>';
        cp.querySelector('.chip-delete').onclick = () => { rmRecipients = rmRecipients.filter(x => x !== a); rmChipDraw(); rmPersonDraw(); };
        field.insertBefore(cp, input);
    });
}
function rmChipAdd(raw) {
    raw.split(/[;,\s]+/).map(a => a.trim().toLowerCase()).filter(Boolean).forEach(a => { if (!rmRecipients.includes(a)) rmRecipients.push(a); });
    rmChipDraw(); rmPersonDraw();
}
function rmPersonDraw() {
    const container = document.getElementById('rm_people');
    const remaining = rmPersonList.filter(k => !rmRecipients.includes(k.email.toLowerCase()));
    container.innerHTML = remaining.map(k =>
        `<button type="button" class="mini-btn" data-email="${esc(k.email)}" title="${esc(k.email)}">+ ${esc(k.name)}${k.title ? ' · ' + esc(k.title) : ''}</button>`
    ).join('') + (remaining.length > 1 ? ' <button type="button" class="mini-btn" data-email-all="1" style="border-color:var(--brand);color:var(--brand)">Hepsini ekle</button>' : '');
}
document.getElementById('rm_people').addEventListener('click', e => {
    const b = e.target.closest('button');
    if (!b) return;
    rmChipAdd(b.dataset.emailAll ? rmPersonList.map(k => k.email).join(',') : b.dataset.email);
});
(() => {
    const input = document.getElementById('rm_to_input');
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); rmChipAdd(input.value); input.value = ''; }
        else if (e.key === 'Backspace' && input.value === '' && rmRecipients.length) { rmRecipients.pop(); rmChipDraw(); rmPersonDraw(); }
    });
    input.addEventListener('blur', () => { if (input.value.trim()) { rmChipAdd(input.value); input.value = ''; } });
})();
async function reportMailOpen() {
    // The mail goes as saved: save what is on the page first
    if ((rpDirty || !RP.saved) && !(await rpSave())) return;
    const j = await api('report_mail_preview', { client_id: RP.client, period: RP.period });
    if (!j.ok) return;
    rmRecipients = (j.to || '').split(/[;,]+/).map(a => a.trim().toLowerCase()).filter(Boolean);
    rmPersonList = j.contacts || [];
    rmChipDraw(); rmPersonDraw();
    document.getElementById('rm_subject').value = j.subject;
    document.getElementById('rm_from').innerHTML = j.senders.map(s => `<option value="${esc(s)}">${esc(s)}</option>`).join('');
    document.getElementById('rm_sent_info').textContent = j.sent_at ? 'Daha önce gönderildi: ' + j.sent_at : '';
    modalOpen('modalReportMail');
}
async function reportMailSend() {
    const btn = document.getElementById('rm_send');
    const typed = document.getElementById('rm_to_input').value.trim();
    if (typed) { rmChipAdd(typed); document.getElementById('rm_to_input').value = ''; }
    if (!rmRecipients.length) { toast('En az bir alıcı ekleyin.', 'error'); return; }
    if (!confirm('Rapor maili şu adreslere gönderilsin mi?\n\n' + rmRecipients.join('\n'))) return;
    btn.disabled = true; btn.textContent = 'Gönderiliyor...';
    const j = await api('report_mail_send', { client_id: RP.client, period: RP.period, to: rmRecipients.join(', '),
        from: document.getElementById('rm_from').value, subject: document.getElementById('rm_subject').value });
    btn.disabled = false; btn.textContent = 'Gönder';
    if (j.ok) { toast(j.message, 'success'); modalClose(document.getElementById('modalReportMail')); setTimeout(() => location.reload(), 700); }
}

rpFrame.srcdoc = <?= json_encode(report_mail_html($reportState, $clientRow, $selectPeriod, true), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php endif; ?>
<?php page_end(); ?>

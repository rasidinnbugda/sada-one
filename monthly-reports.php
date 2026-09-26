<?php
/**
 * SADA One — Monthly Reports
 * Monthly work reports per client file are filled in on the panel.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
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
$selectPeriod = preg_match('/^\d{4}-\d{2}$/', $_GET['period'] ?? '') ? $_GET['period'] : date('Y-m');
$current = $selectClient ? row("SELECT * FROM monthly_reports WHERE client_id=? AND period=?", [$selectClient, $selectPeriod]) : null;

$periodName = function (string $d): string {
    $months = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    [$y, $a] = explode('-', $d);
    return $months[(int)$a] . ' ' . $y;
};

page_start('Aylık Raporlar', 'mreports');
?>
<div class="page-top">
    <div><div class="page-title">Aylık Raporlar</div><div class="page-bottom">Müşteri dosyaları için dönem raporları — özet, yapılanlar, metrikler, gelecek plan</div></div>
</div>

<!-- This month at a glance: who has filled in, who has not -->
<div class="card mb-3">
    <div class="row-flex between mb-2">
        <div class="card-title" style="font-size:15px">Bu Ay (<?= e($currentPeriod) ?>) Doldurma Durumu</div>
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

<div class="grid" style="grid-template-columns:340px 1fr;align-items:start">
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

    <div class="card">
        <?php if (!$selectClient): ?>
        <div class="empty-state" style="padding:60px 20px">
            <div class="empty-icon">📊</div>
            <div class="empty-title">Rapor seçin</div>
            <div class="empty-text">Soldan dosya ve dönem seçerek yeni rapor başlatın ya da mevcut raporu açın.</div>
        </div>
        <?php else:
            $clientName = val("SELECT name FROM clients WHERE id=?", [$selectClient]); ?>
        <div class="row-flex between mb-3">
            <div class="card-title"><?= e($clientName) ?> — <?= $periodName($selectPeriod) ?> Raporu</div>
            <?php if ($current): ?><span class="small text-muted">Son güncelleme: <?= e($current['author_name'] ?? '') ?: '' ?> <?= format_date($current['updated'] ?? $current['created'], true) ?></span><?php endif; ?>
        </div>
        <?php
        // Automatic financial summary for the selected client + period (live, not stored)
        [$pYear, $pMonth] = explode('-', $selectPeriod);
        $pStart = "$selectPeriod-01"; $pEnd = date('Y-m-t', strtotime($pStart));
        $finBudget = (float)val("SELECT COALESCE(SUM(budget),0) FROM projects WHERE client_id=? AND status='active'", [$selectClient]);
        $finExtra = (float)val("SELECT COALESCE(SUM(t.amount),0) FROM project_extra_requests t JOIN projects p ON p.id=t.project_id WHERE p.client_id=? AND t.status='approved' AND t.created BETWEEN ? AND ?", [$selectClient, "$pStart 00:00:00", "$pEnd 23:59:59"]);
        $finIncome = (float)val("SELECT COALESCE(SUM(o.amount),0) FROM payments o JOIN projects p ON p.id=o.project_id WHERE p.client_id=? AND o.date BETWEEN ? AND ?", [$selectClient, $pStart, $pEnd]);
        $finShoot = (float)val("SELECT COALESCE(SUM(e.cost),0) FROM events e LEFT JOIN projects p ON p.id=e.project_id WHERE (e.client_id=? OR p.client_id=?) AND e.start BETWEEN ? AND ?", [$selectClient, $selectClient, "$pStart 00:00:00", "$pEnd 23:59:59"]);
        ?>
        <div class="grid mb-3" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="cell-bottom">Aktif Proje Bütçesi</div><div class="bold"><?= number_format($finBudget, 0, ',', '.') ?> ₺</div></div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="cell-bottom">Bu Ay Onaylı Ek Talep</div><div class="bold">+<?= number_format($finExtra, 0, ',', '.') ?> ₺</div></div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="cell-bottom">Bu Ay Tahsilat</div><div class="bold" style="color:var(--success)"><?= number_format($finIncome, 0, ',', '.') ?> ₺</div></div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="cell-bottom">Bu Ay Çekim Maliyeti</div><div class="bold" style="color:var(--danger)"><?= number_format($finShoot, 0, ',', '.') ?> ₺</div></div>
        </div>
        <div class="form-hint mb-2">Finansal özet panel verilerinden otomatik hesaplanır; rapora elle geçirmenize gerek yok.</div>
        <div class="row-flex mb-2" style="gap:10px">
            <button type="button" class="btn btn-sm" id="aiDraftBtn" onclick="aiDraft(<?= $selectClient ?>, '<?= e($selectPeriod) ?>')">🪄 AI ile Taslak Doldur</button>
            <span class="small text-muted" id="aiDraftStatus"></span>
        </div>
        <form data-ajax="monthly_report_save" data-refresh="no" id="reportForm">
            <input type="hidden" name="client_id" value="<?= $selectClient ?>">
            <input type="hidden" name="period" value="<?= e($selectPeriod) ?>">
            <div class="form-group"><label class="form-label">Genel Özet</label><textarea name="summary" class="text-area" rows="3" placeholder="Bu ay genel olarak..."><?= e($current['summary'] ?? '') ?></textarea></div>
            <div class="form-group"><label class="form-label">Yapılan Çalışmalar</label><textarea name="work_done" class="text-area" rows="5" placeholder="- 12 içerik üretildi ve yayınlandı&#10;- 2 çekim gerçekleştirildi..."><?= e($current['work_done'] ?? '') ?></textarea></div>
            <div class="form-group"><label class="form-label">Metrikler & Sonuçlar</label><textarea name="metrics" class="text-area" rows="4" placeholder="Erişim, etkileşim, takipçi değişimi, öne çıkan içerikler..."><?= e($current['metrics'] ?? '') ?></textarea></div>
            <div class="form-group"><label class="form-label">Gelecek Ay Planı</label><textarea name="plan" class="text-area" rows="3" placeholder="Önümüzdeki dönem hedefleri..."><?= e($current['plan'] ?? '') ?></textarea></div>
            <div class="row-flex" style="gap:10px">
                <button type="submit" class="btn" onclick="this.form.querySelectorAll('input[name=status]').forEach(x => x.remove())">Taslak Kaydet</button>
                <button type="submit" class="btn btn-brand" onclick="this.form.querySelectorAll('input[name=status]').forEach(x => x.remove()); const i = document.createElement('input'); i.type = 'hidden'; i.name = 'status'; i.value = 'completed'; this.form.appendChild(i)">Tamamlandı Olarak Kaydet</button>
                <?php if ($current): ?>
                <button type="button" class="btn" onclick="reportMailOpen(<?= $selectClient ?>, '<?= e($selectPeriod) ?>')">📧 Müşteri Maili</button>
                <?php if (!empty($current['sent_at'])): ?><span class="badge r-completed small" title="<?= e($current['sent_to'] ?? '') ?>">Gönderildi: <?= format_date($current['sent_at'], true) ?></span><?php endif; ?>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<script>
async function aiDraft(clientId, period) {
    const btn = document.getElementById('aiDraftBtn'), st = document.getElementById('aiDraftStatus');
    btn.disabled = true; st.textContent = 'Panel verileri derleniyor, taslak yazılıyor... (~20 sn)';
    const j = await api('ai_report_draft', { client_id: clientId, period });
    btn.disabled = false;
    if (!j.ok) { st.textContent = ''; toast(j.error || 'Taslak üretilemedi', 'error'); return; }
    const f = document.getElementById('reportForm');
    for (const [field, value] of Object.entries(j.draft)) {
        const el = f.querySelector(`[name="${field}"]`);
        if (el && value) el.value = value;
    }
    st.textContent = 'Taslak dolduruldu — kontrol edip kaydedin.';
    toast('AI taslağı hazır. Düzenleyip kaydetmeyi unutmayın.', 'success');
}
</script>
<div class="modal-overlay" id="modalReportMail">
    <div class="modal modal-wide"><div class="modal-top"><div class="modal-title">📧 Müşteri Rapor Maili</div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body">
        <div class="form-row">
            <div class="form-group"><label class="form-label">Alıcılar</label>
                <div class="chip-field" id="rm_to_field" onclick="document.getElementById('rm_to_input').focus()">
                    <input id="rm_to_input" placeholder="adres yazıp Enter'a basın..." autocomplete="off">
                </div>
                <div class="row-flex wrap mt-1" style="gap:6px" id="rm_people"></div>
            </div>
            <div class="form-group"><label class="form-label">Gönderen</label><select class="select native-select" id="rm_from"></select></div>
        </div>
        <div class="form-group"><label class="form-label">Konu</label><input class="input" id="rm_subject"></div>
        <details class="mb-2" id="rm_design">
            <summary class="small bold" style="cursor:pointer;padding:6px 0">🎨 Tasarımı Düzenle — kapak görseli, favori içerik, istatistikler</summary>
            <div class="mt-2" style="padding:14px;background:var(--surface-2);border-radius:12px">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Kapak Görseli <span class="text-muted" style="font-weight:400" id="rm_hero_status"></span></label>
                        <input type="file" class="input" id="rm_hero" accept="image/*">
                        <label class="small row-flex mt-1" style="gap:6px"><input type="checkbox" id="rm_hero_remove"> Mevcut görseli kaldır</label></div>
                    <div class="form-group"><label class="form-label">Favori Görseli <span class="text-muted" style="font-weight:400" id="rm_fav_img_status"></span></label>
                        <input type="file" class="input" id="rm_fav_img" accept="image/*">
                        <label class="small row-flex mt-1" style="gap:6px"><input type="checkbox" id="rm_fav_img_remove"> Mevcut görseli kaldır</label></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Favori Başlığı</label><input class="input" id="rm_fav_title" placeholder="Bu Ayın Favorisi"></div>
                    <div class="form-group"><label class="form-label">Öne Çıkan Sayı</label><input class="input" id="rm_fav_stat" placeholder="113B izlenme"></div>
                </div>
                <div class="form-group"><label class="form-label">Favori Açıklaması</label><textarea class="text-area" id="rm_fav_text" rows="2" placeholder="Ürettiğimiz bu içerik markanızı çok daha ileriye taşıdı!"></textarea></div>
                <label class="form-label">Metinler <span class="text-muted" style="font-weight:400">(boş bırakılan varsayılanı kullanır)</span></label>
                <div class="grid grid-2 mb-2" style="gap:6px">
                    <input class="input rm-text" data-text="title" placeholder="Başlık: Aylık Durum Raporu">
                    <input class="input rm-text" data-text="greeting" placeholder="Selamlama: Selam ... ekibi 👋">
                    <input class="input rm-text" data-text="production_title" placeholder="Bölüm: Markanız İçin Ürettik">
                    <input class="input rm-text" data-text="stat_title" placeholder="Bölüm: Biz Susalım, Sayılar Konuşsun">
                    <input class="input rm-text" data-text="stat_intro" placeholder="İstatistik giriş cümlesi (isteğe bağlı)">
                    <input class="input rm-text" data-text="plan_title" placeholder="Bölüm: Önümüzdeki Ay">
                    <input class="input rm-text" data-text="closing" placeholder="Kapanış: Önümüzdeki ay görüşmek üzere...">
                    <input class="input rm-text" data-text="thanks" placeholder="Alt başlık: Teşekkür Ederiz!">
                </div>
                <label class="form-label">İstatistik Kartları <span class="text-muted" style="font-weight:400">(etiket · değer · değişim — boş bırakılan satır atlanır)</span></label>
                <div class="vertical" style="gap:6px" id="rm_stats">
                    <?php for ($si = 0; $si < 4; $si++): ?>
                    <div class="row-flex" style="gap:6px">
                        <input class="input rm-stat-label" placeholder="<?= ['Erişilen Hesaplar','Görüntüleme','Takipçi Sayısı','Etkileşim'][$si] ?>" style="flex:2">
                        <input class="input rm-stat-value" placeholder="<?= ['340,8K','1.3M','43,3K','86K'][$si] ?>" style="flex:1">
                        <input class="input rm-stat-change" placeholder="+%12" style="flex:1">
                    </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn btn-sm mt-2" onclick="reportMailDesignSave()">Kaydet & Önizlemeyi Yenile</button>
            </div>
        </details>
        <div class="form-group"><label class="form-label">Önizleme</label>
            <iframe id="rm_preview" style="width:100%;height:420px;border:1px solid var(--border);border-radius:12px;background:#eef1f6"></iframe>
        </div>
    </div>
    <div class="modal-alt">
        <span class="small text-muted" id="rm_sent_info" style="margin-right:auto"></span>
        <button type="button" class="btn btn-ghost" data-modal-close>Vazgeç</button>
        <button type="button" class="btn btn-brand" id="rm_send" onclick="reportMailSend()">Gönder</button>
    </div>
    </div>
</div>
<script>
let rmClient = 0, rmPeriod = '';
let rmRecipients = [];
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
    raw.split(/[;,\s]+/).map(a => a.trim().toLowerCase()).filter(Boolean).forEach(a => {
        if (!rmRecipients.includes(a)) rmRecipients.push(a);
    });
    rmChipDraw(); rmPersonDraw();
}
let rmPersonList = [];
function rmPersonDraw() {
    const container = document.getElementById('rm_people');
    const remaining = rmPersonList.filter(k => !rmRecipients.includes(k.email.toLowerCase()));
    container.innerHTML = remaining.map((k, i) =>
        `<button type="button" class="mini-btn" onclick="rmChipAdd(rmPersonList.find(x => x.email === '${esc(k.email)}').email)" title="${esc(k.email)}">+ ${esc(k.name)}${k.title ? ' · ' + esc(k.title) : ''}</button>`
    ).join('') + (remaining.length > 1 ? ` <button type="button" class="mini-btn" style="border-color:var(--brand);color:var(--brand)" onclick="rmChipAdd(rmPersonList.map(k => k.email).join(','))">Hepsini ekle</button>` : '');
}
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('rm_to_input');
    if (!input) return;
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); rmChipAdd(input.value); input.value = ''; }
        else if (e.key === 'Backspace' && input.value === '' && rmRecipients.length) { rmRecipients.pop(); rmChipDraw(); rmPersonDraw(); }
    });
    input.addEventListener('blur', () => { if (input.value.trim()) { rmChipAdd(input.value); input.value = ''; } });
});
async function reportMailOpen(clientId, period) {
    rmClient = clientId; rmPeriod = period;
    const j = await api('report_mail_preview', { client_id: clientId, period });
    if (!j.ok) return;
    rmRecipients = (j.to || '').split(/[;,]+/).map(a => a.trim().toLowerCase()).filter(Boolean);
    rmPersonList = j.contacts || [];
    rmChipDraw(); rmPersonDraw();
    document.getElementById('rm_subject').value = j.subject;
    document.getElementById('rm_from').innerHTML = j.senders.map(s => `<option value="${esc(s)}">${esc(s)}</option>`).join('');
    document.getElementById('rm_preview').srcdoc = j.html;
    document.getElementById('rm_sent_info').textContent = j.sent_at ? 'Daha önce gönderildi: ' + j.sent_at : '';
    // fill the design editor with the current data
    const v = j.mail_data || {};
    document.getElementById('rm_hero_status').textContent = v.hero ? '(yüklü ✓)' : '';
    document.getElementById('rm_fav_img_status').textContent = (v.fav && v.fav.img) ? '(yüklü ✓)' : '';
    document.getElementById('rm_fav_title').value = (v.fav && v.fav.title) || '';
    document.getElementById('rm_fav_stat').value = (v.fav && v.fav.stat) || '';
    document.getElementById('rm_fav_text').value = (v.fav && v.fav.text) || '';
    const rows = document.querySelectorAll('#rm_stats .row-flex');
    rows.forEach((s, i) => {
        const st = (v.stats || [])[i] || {};
        s.querySelector('.rm-stat-label').value = st.label || '';
        s.querySelector('.rm-stat-value').value = st.value || '';
        s.querySelector('.rm-stat-change').value = st.change || '';
    });
    document.querySelectorAll('.rm-text').forEach(i => i.value = (v.text && v.text[i.dataset.text]) || '');
    ['rm_hero', 'rm_fav_img'].forEach(id => document.getElementById(id).value = '');
    ['rm_hero_remove', 'rm_fav_img_remove'].forEach(id => document.getElementById(id).checked = false);
    modalOpen('modalReportMail');
}
async function reportMailDesignSave() {
    const stats = [...document.querySelectorAll('#rm_stats .row-flex')].map(s => ({
        label: s.querySelector('.rm-stat-label').value.trim(),
        value: s.querySelector('.rm-stat-value').value.trim(),
        change: s.querySelector('.rm-stat-change').value.trim()
    })).filter(s => s.label || s.value);
    const data = {
        client_id: rmClient, period: rmPeriod,
        fav_title: document.getElementById('rm_fav_title').value,
        fav_stat: document.getElementById('rm_fav_stat').value,
        fav_text: document.getElementById('rm_fav_text').value,
        stats: stats,
        ...Object.fromEntries([...document.querySelectorAll('.rm-text')].map(i => ['text_' + i.dataset.text, i.value])),
        hero_remove: document.getElementById('rm_hero_remove').checked ? '1' : '0',
        fav_img_remove: document.getElementById('rm_fav_img_remove').checked ? '1' : '0'
    };
    const hero = document.getElementById('rm_hero').files[0];
    const favImg = document.getElementById('rm_fav_img').files[0];
    if (hero) data.hero_img = hero;
    if (favImg) data.fav_img = favImg;
    const j = await api('report_mail_data_save', data);
    if (!j.ok) return;
    toast('Tasarım kaydedildi', 'success', 1600);
    // refresh the preview (refills the fields)
    reportMailOpen(rmClient, rmPeriod);
    document.getElementById('rm_design').open = true;
}
async function reportMailSend() {
    const btn = document.getElementById('rm_send');
    const inputRemaining = document.getElementById('rm_to_input').value.trim();
    if (inputRemaining) { rmChipAdd(inputRemaining); document.getElementById('rm_to_input').value = ''; }
    if (!rmRecipients.length) { toast('En az bir alıcı ekleyin.', 'error'); return; }
    if (!confirm('Rapor maili şu adreslere gönderilsin mi?\n\n' + rmRecipients.join('\n'))) return;
    btn.disabled = true; btn.textContent = 'Gönderiliyor...';
    const j = await api('report_mail_send', {
        client_id: rmClient, period: rmPeriod,
        to: rmRecipients.join(', '),
        from: document.getElementById('rm_from').value,
        subject: document.getElementById('rm_subject').value
    });
    btn.disabled = false; btn.textContent = 'Gönder';
    if (j.ok) { toast(j.message, 'success'); modalClose(document.getElementById('modalReportMail')); setTimeout(() => location.reload(), 700); }
}
</script>
<?php page_end(); ?>

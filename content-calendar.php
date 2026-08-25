<?php
/**
 * SADA One — Content Calendar
 * Contents are tied to a client file (brand); the project is optional. Multiple platforms are supported.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; } if ($month > 12) { $month = 1; $year++; }
$clientFiltre = (int)($_GET['client'] ?? 0);

// Accessible client files
if (is_staff()) {
    $clients = rows("SELECT id, name FROM clients WHERE status='aktif' ORDER BY name");
} else {
    [$in, $p] = in_clause(customer_client_ids());
    $clients = rows("SELECT id, name FROM clients WHERE id IN $in ORDER BY name", $p);
}
$clientIds = array_map('intval', array_column($clients, 'id'));
$projects = is_staff() ? rows("SELECT id, name, client_id FROM projects WHERE status='aktif' ORDER BY name") : [];

$firstDay = mktime(0, 0, 0, $month, 1, $year);
$dayCount = (int)date('t', $firstDay);
$startWeek = (int)date('N', $firstDay);

$monthInitial = sprintf('%04d-%02d-01', $year, $month);
$monthLast = sprintf('%04d-%02d-%02d', $year, $month, $dayCount);

$contentGunleri = [];
$tumContents = [];
if ($clientIds) {
    [$inD, $pD] = in_clause($clientIds);
    $params = array_merge($pD, [$monthInitial, $monthLast]);
    $ekKosul = '';
    if ($clientFiltre) { $ekKosul = ' AND COALESCE(i.client_id, pr.client_id)=?'; $params[] = $clientFiltre; }
    $tumContents = rows("SELECT i.*, d.name client_name, pr.name project_name, (SELECT g.id FROM tasks g WHERE g.content_id=i.id LIMIT 1) task_id
        FROM contents i
        LEFT JOIN projects pr ON pr.id=i.project_id
        LEFT JOIN clients d ON d.id=COALESCE(i.client_id, pr.client_id)
        WHERE COALESCE(i.client_id, pr.client_id) IN $inD AND i.date BETWEEN ? AND ?$ekKosul
        ORDER BY i.date, i.time", $params);
    foreach ($tumContents as $internal) { $g = (int)date('j', strtotime($internal['date'])); $contentGunleri[$g][] = $internal; }
}

page_start('İçerik Takvimi', 'content');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">İçerik Takvimi</div><div class="sayfa-alt">Dosya (marka) bazlı sosyal medya içerik planı</div></div>
    <?php if (permission('icerik_yonet')): ?><div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalContent"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> İçerik Planla</button></div><?php endif; ?>
</div>

<div class="filtre-bar">
    <select class="secim" style="max-width:280px" onchange="location.href='?client='+this.value+'&month=<?= $month ?>&year=<?= $year ?>'">
        <option value="0">Tüm Dosyalar</option>
        <?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $clientFiltre == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
</div>

<div class="kart">
    <div class="takvim-baslik-bar">
        <div class="satir-esnek" style="gap:8px">
            <a href="?month=<?= $month - 1 ?>&year=<?= $year ?>&client=<?= $clientFiltre ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
            <div class="takvim-ay-ad"><?= MONTHS[$month] ?> <?= $year ?></div>
            <a href="?month=<?= $month + 1 ?>&year=<?= $year ?>&client=<?= $clientFiltre ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>
        </div>
        <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>&client=<?= $clientFiltre ?>" class="btn btn-sm">Bugün</a>
    </div>
    <div class="takvim-izgara">
        <?php foreach (['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $g): ?><div class="takvim-gun-baslik"><?= $g ?></div><?php endforeach; ?>
        <?php for ($i = 1; $i < $startWeek; $i++): ?><div class="takvim-hucre bos"></div><?php endfor; ?>
        <?php for ($day = 1; $day <= $dayCount; $day++):
            $today = ($day == date('j') && $month == date('n') && $year == date('Y'));
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day); ?>
        <div class="takvim-hucre <?= $today ? 'bugun' : '' ?>" data-date="<?= $dateStr ?>" <?= permission('icerik_yonet') ? "onclick=\"contentAdd('$dateStr')\" style=\"cursor:pointer\"" : '' ?>>
            <div class="takvim-gun-no"><?= $day ?></div>
            <?php foreach ($contentGunleri[$day] ?? [] as $internal):
                $statusColor = ['taslak' => 'var(--muted)', 'internal_approval' => 'var(--info)', 'customer_approval' => 'var(--warning)', 'revize' => 'var(--info)', 'onaylandi' => 'var(--basari)', 'yayinlandi' => 'var(--marka)'][$internal['status']]; ?>
            <div class="takvim-etkinlik" draggable="<?= permission('icerik_yonet') ? 'true' : 'false' ?>" data-content="<?= $internal['id'] ?>" onclick="event.stopPropagation();contentShow(<?= $internal['id'] ?>)" style="border-color:<?= $statusColor ?>;background:color-mix(in srgb, <?= $statusColor ?> 14%, transparent);color:<?= $statusColor ?>" title="<?= e($internal['title']) ?> · <?= e($internal['client_name'] ?? '') ?>"><?= platform_badges($internal['platform'], true) ?> <?= e($internal['title']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="satir-esnek sarma mt-3" style="gap:16px;justify-content:center">
    <?php foreach (CONTENT_STATUSES as $k => $v):
        $color = ['taslak' => 'var(--muted)', 'internal_approval' => 'var(--info)', 'customer_approval' => 'var(--warning)', 'revize' => 'var(--info)', 'onaylandi' => 'var(--basari)', 'yayinlandi' => 'var(--marka)'][$k]; ?>
    <span class="satir-esnek kucuk" style="gap:6px"><span class="etiket-nokta" style="background:<?= $color ?>"></span><?= $v ?></span>
    <?php endforeach; ?>
</div>

<?php if (permission('icerik_yonet')): ?>
<div class="modal-katman" id="modalContent">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">İçerik Planla</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="content_save" data-refresh="evet" id="contentForm">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="title" class="girdi" required></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Dosya (marka) <span class="zorunlu">*</span></label><select name="client_id" id="internal_client" class="secim" required><option value="">Seçin...</option><?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $clientFiltre == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Proje (opsiyonel)</label><select name="project_id" id="internal_project" class="secim"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" data-client="<?= $p['client_id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Platformlar <span class="metin-muted" style="font-weight:400">(birden fazla seçilebilir)</span></label>
                <input type="hidden" name="platforms" id="internal_platforms">
                <div class="satir-esnek sarma" style="gap:6px">
                    <?php foreach (PLATFORMS as $k => $v): ?>
                    <label class="satir-esnek kucuk" style="gap:7px;padding:7px 12px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="platform-kutu" value="<?= $k ?>" <?= $k === 'instagram' ? 'checked' : '' ?>> <?= icon(isset(ICONS[$k]) ? $k : 'diger', 14) ?> <?= $v ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tarih <span class="zorunlu">*</span></label><input type="date" name="date" class="girdi" required id="internal_date" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-grup"><label class="form-etiket">Saat</label><input type="time" name="time" class="girdi"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Durum</label><select name="status" class="secim"><?php foreach (CONTENT_STATUSES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="form-grup"><label class="form-etiket">Açıklama / Metin</label><textarea name="description" class="metin-alani" placeholder="Gönderi metni, hashtag'ler..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Planla</button></div>
    </form></div>
</div>
<?php endif; ?>

<!-- Content detail -->
<div class="modal-katman" id="modalContentDetay">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="idTitle"></div><button class="modal-kapat" data-modal-close>✕</button></div>
    <div class="modal-govde" id="idBody"></div>
    </div>
</div>

<script>
const contents = <?= json_encode(array_column($tumContents, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
const internalStatus = <?= json_encode(CONTENT_STATUSES, JSON_UNESCAPED_UNICODE) ?>;
const platforms = <?= json_encode(PLATFORMS, JSON_UNESCAPED_UNICODE) ?>;
const platformIcon = {}; // the detail view uses text tags only
const contentManager = <?= permission('icerik_yonet') ? 'true' : 'false' ?>;

function contentAdd(date) { const el = document.getElementById('internal_date'); if (el) { el.value = date; modalOpen('modalContent'); } }
const internalForm = document.getElementById('contentForm');
if (internalForm) {
    internalForm.addEventListener('submit', () => {
        document.getElementById('internal_platforms').value = JSON.stringify(Array.from(document.querySelectorAll('.platform-box:checked')).map(c => c.value));
    });
    document.getElementById('internal_project').addEventListener('change', function () {
        const client = this.selectedOptions[0]?.dataset.client;
        if (client) document.getElementById('internal_client').value = client;
    });
}
function contentShow(id) {
    const internal = contents[id]; if (!internal) return;
    document.getElementById('idTitle').textContent = internal.title;
    const platformList = (internal.platform || '').split(',').map(pl => `${platformIcon[pl] || ''} ${platforms[pl] || pl}`).join(' · ');
    let statusSelect = `<select class="secim mt-2" onchange="internalStatusChange(${id},this.value)">`;
    for (const k in internalStatus) statusSelect += `<option value="${k}" ${internal.status === k ? 'selected' : ''}>${internalStatus[k]}</option>`;
    statusSelect += `</select>`;
    let h = `<div class="dikey" style="gap:12px">
        <div class="satir-esnek arasi"><span class="hucre-alt">Dosya</span><span class="kucuk kalin">${esc(internal.client_name || '—')}</span></div>
        ${ic.proje_ad ? `<div class="satir-esnek arasi"><span class="hucre-alt">Proje</span><span class="kucuk">${esc(internal.project_name)}</span></div>` : ''}
        <div class="satir-esnek arasi"><span class="hucre-alt">Platformlar</span><span class="kucuk">${platformList}</span></div>
        <div class="satir-esnek arasi"><span class="hucre-alt">Tarih</span><span class="kucuk">${new Date(internal.date).toLocaleDateString('tr-TR', { dateStyle: 'long' })}${internal.time ? ' ' + internal.time.slice(0, 5) : ''}</span></div>
        <div><div class="hucre-alt mb-2">Durum</div>${statusSelect}</div>`;
    if (internal.description) h += `<div><div class="hucre-alt mb-2">İçerik</div><div class="kucuk metin-2" style="white-space:pre-wrap">${internal.description.replace(/</g, '&lt;')}</div></div>`;
    if (internal.task_id) h += `<a href="task.php?id=${internal.task_id}" class="btn btn-sm mt-2" style="margin-right:6px">Bağlı göreve git →</a>`;
    if (contentManager) h += `<div class="satir-esnek mt-2" style="gap:8px"><input type="date" class="girdi" id="internalMoveDate" value="${internal.date}" style="max-width:150px"><input type="time" class="girdi" id="internalMoveTime" value="${(internal.time||'').slice(0,5)}" style="max-width:110px"><button class="btn btn-sm" onclick="internalMove(${id})">Tarihi Güncelle</button></div>`;
    if (contentManager) h += `<button class="btn btn-tehlike btn-sm mt-2" onclick="internalDelete(${id})">İçeriği Sil</button>`;
    h += `</div>`;
    document.getElementById('idBody').innerHTML = h;
    if (window.ozelPickerRefresh) ozelPickerRefresh();
    modalOpen('modalContentDetay');
}
async function internalStatusChange(id, status) { const j = await api('content_status', { id, status }); if (j.ok) toast('Durum güncellendi', 'basari'); }
async function internalDelete(id) { if (confirm('İçerik silinsin mi?')) { const j = await api('content_delete', { id }); if (j.ok) location.reload(); } }
async function internalMove(id) {
    const tEl = document.getElementById('internalMoveDate'), sEl = document.getElementById('internalMoveTime');
    const j = await api('content_move', { id, date: tEl.dataset.setting_value ?? tEl.value, time: sEl.dataset.setting_value ?? sEl.value });
    if (j.ok) { toast(j.message, 'basari'); setTimeout(() => location.reload(), 600); }
}
// Drag and drop: move content to another day
let surContent = null;
document.querySelectorAll('.calendar-event[data-content]').forEach(chip => {
    chip.addEventListener('dragstart', e => { surContent = chip.dataset.content; e.stopPropagation(); });
});
document.querySelectorAll('.calendar-hucre[data-date]').forEach(hucre => {
    hucre.addEventListener('dragover', e => { if (surContent) { e.preventDefault(); hucre.style.borderColor = 'var(--marka)'; } });
    hucre.addEventListener('dragleave', () => hucre.style.borderColor = '');
    hucre.addEventListener('drop', async e => {
        e.preventDefault(); hucre.style.borderColor = '';
        if (!surContent) return;
        const j = await api('content_move', { id: surContent, date: hucre.dataset.date, time: '' });
        surContent = null;
        if (j.ok) { toast(j.message, 'basari'); setTimeout(() => location.reload(), 500); }
    });
});
</script>
<?php page_end(); ?>

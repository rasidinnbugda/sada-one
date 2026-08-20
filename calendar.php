<?php
/**
 * SADA One — Shoot & Production Calendar
 * Multi-day events are shown as continuous strips (bands) across the week.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; } if ($month > 12) { $month = 1; $year++; }

$firstDay = mktime(0, 0, 0, $month, 1, $year);
$dayCount = (int)date('t', $firstDay);
$startWeek = (int)date('N', $firstDay); // 1=Mon

$monthInitial = sprintf('%04d-%02d-01', $year, $month);
$monthLast = sprintf('%04d-%02d-%02d', $year, $month, $dayCount);

// All events intersecting the month
$events = rows("SELECT e.*, p.name project_name, d.name client_name FROM events e LEFT JOIN projects p ON p.id=e.project_id LEFT JOIN clients d ON d.id=COALESCE(e.client_id, p.client_id)
    WHERE DATE(e.start) <= ? AND DATE(COALESCE(e.end, e.start)) >= ? ORDER BY e.start", [$monthLast, $monthInitial]);

// Equipment linked to each event (for the detail modal)
$eventEquipment = [];
if ($events) {
    $eIdler = implode(',', array_map(fn($e) => (int)$e['id'], $events));
    foreach (rows("SELECT ee.event_id, ek.id, ek.code, ek.name, ek.status FROM event_equipment ee JOIN equipment ek ON ek.id=ee.equipment_id WHERE ee.event_id IN ($eIdler)") as $r) {
        $eventEquipment[$r['event_id']][] = $r;
    }
}
foreach ($events as &$e) $e['equipment'] = $eventEquipment[$e['id']] ?? [];
unset($e);

/* ---- Split into weeks: 7 cells per week (days outside the month are null) ---- */
$haftalar = [];
$week = array_fill(0, $startWeek - 1, null);
for ($day = 1; $day <= $dayCount; $day++) {
    $week[] = $day;
    if (count($week) === 7) { $haftalar[] = $week; $week = []; }
}
if ($week) $haftalar[] = array_pad($week, 7, null);

/* ---- Separate events: single-day (chip) / multi-day (band) ---- */
$tekGunluk = [];   // day → event list
$cokGunluk = [];   // band list
foreach ($events as $e) {
    $initialTs = strtotime(date('Y-m-d', strtotime($e['start'])));
    $lastTs = strtotime(date('Y-m-d', strtotime($e['end'] ?: $e['start'])));
    if ($lastTs < $initialTs) $lastTs = $initialTs;
    if ($initialTs === $lastTs) {
        if ((int)date('n', $initialTs) === $month && (int)date('Y', $initialTs) === $year) $tekGunluk[(int)date('j', $initialTs)][] = $e;
    } else {
        $cokGunluk[] = ['e' => $e, 'initial' => $initialTs, 'last' => $lastTs];
    }
}

/* ---- Compute the bands for each week (lane stacking) ---- */
function week_bantlari(array $week, array $cokGunluk, int $month, int $year): array {
    // Actual date range within the week
    $first = null; $last = null; $columnDate = [];
    foreach ($week as $kol => $day) {
        $columnDate[$kol] = $day ? mktime(0, 0, 0, $month, $day, $year) : null;
        if ($day) { if ($first === null) $first = $columnDate[$kol]; $last = $columnDate[$kol]; }
    }
    if ($first === null) return [];
    $bantlar = [];
    foreach ($cokGunluk as $c) {
        if ($c['last'] < $first || $c['initial'] > $last) continue;
        // Start/end columns within the week
        $initialKol = 0; $lastKol = 6;
        foreach ($columnDate as $kol => $t) {
            if ($t !== null && $t <= $c['initial']) $initialKol = $kol;
            if ($t !== null && $t <= $c['last']) $lastKol = $kol;
        }
        if ($columnDate[$initialKol] === null) { foreach ($columnDate as $kol => $t) { if ($t !== null) { $initialKol = $kol; break; } } }
        $bantlar[] = [
            'e' => $c['e'], 'initial_kol' => $initialKol, 'last_kol' => $lastKol,
            'soldan_ongoing' => $c['initial'] < ($columnDate[$initialKol] ?? $first),
            'sagdan_ongoing' => $c['last'] > ($columnDate[$lastKol] ?? $last),
        ];
    }
    // Assign lanes (overlapping bands stack vertically)
    $laneler = [];
    foreach ($bantlar as $i => $b) {
        $lane = 0;
        while (true) {
            $cakisti = false;
            foreach ($bantlar as $j => $b2) {
                if ($j >= $i || ($b2['lane'] ?? -1) !== $lane) continue;
                if ($b['initial_kol'] <= $b2['last_kol'] && $b['last_kol'] >= $b2['initial_kol']) { $cakisti = true; break; }
            }
            if (!$cakisti) break;
            $lane++;
        }
        $bantlar[$i]['lane'] = $lane;
    }
    return $bantlar;
}

$projects = rows("SELECT id, name, client_id FROM projects WHERE status='aktif' ORDER BY name");
$clients = rows("SELECT id, name FROM clients WHERE status='aktif' ORDER BY name");
$musaitEquipment = rows("SELECT id, code, name, category FROM equipment WHERE status='studyoda' ORDER BY FIELD(category,'kamera','lens','sd_kart','tripod','isik','ses','drone','aksesuar','diger'), code");

$turRenkleri = ['cekim' => '#e86b82', 'toplanti' => 'var(--info)', 'is_delivered' => 'var(--warning)', 'diger' => 'var(--marka)'];

page_start('Çekim & Prodüksiyon Takvimi', 'calendar');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Prodüksiyon Takvimi</div><div class="sayfa-alt">Çekimler, toplantılar ve teslim tarihleri — çok günlü işler şerit olarak yayılır</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalEtkinlik"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Etkinlik Ekle</button></div>
</div>

<div class="kart">
    <div class="takvim-baslik-bar">
        <div class="satir-esnek" style="gap:8px">
            <a href="?month=<?= $month - 1 ?>&year=<?= $year ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
            <div class="takvim-ay-ad"><?= MONTHS[$month] ?> <?= $year ?></div>
            <a href="?month=<?= $month + 1 ?>&year=<?= $year ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>
        </div>
        <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-sm">Bugün</a>
    </div>

    <div class="takvim-izgara" style="margin-bottom:4px">
        <?php foreach (['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $gAd): ?><div class="takvim-gun-baslik"><?= $gAd ?></div><?php endforeach; ?>
    </div>

    <?php foreach ($haftalar as $week):
        $bantlar = week_bantlari($week, $cokGunluk, $month, $year);
        $laneCount = $bantlar ? max(array_column($bantlar, 'lane')) + 1 : 0;
        $bantField = $laneCount * 26; ?>
    <div class="takvim-hafta">
        <?php // Bands (over the cells, below the day number)
        foreach ($bantlar as $b):
            $e = $b['e'];
            $color = $turRenkleri[$e['type']] ?? 'var(--marka)';
            $sol = $b['initial_kol'] / 7 * 100;
            $genislik = ($b['last_kol'] - $b['initial_kol'] + 1) / 7 * 100; ?>
        <div class="takvim-bant <?= $b['soldan_ongoing'] ? 'devam-sol' : '' ?> <?= $b['sagdan_ongoing'] ? 'devam-sag' : '' ?>"
             style="left:calc(<?= $sol ?>% + 3px);width:calc(<?= $genislik ?>% - 6px);top:<?= 30 + $b['lane'] * 26 ?>px;--bant-renk:<?= $color ?>"
             onclick="etkinlikGoster(<?= $e['id'] ?>)" title="<?= e($e['title']) ?> · <?= format_date(substr($e['start'], 0, 10)) ?> → <?= format_date(substr($e['end'], 0, 10)) ?>">
            <?= $b['soldan_ongoing'] ? '◂ ' : '' ?><?= e($e['title']) ?><?= $b['sagdan_ongoing'] ? ' ▸' : '' ?>
        </div>
        <?php endforeach; ?>

        <?php foreach ($week as $day):
            if ($day === null): ?><div class="takvim-hucre bos"></div><?php continue; endif;
            $today = ($day == date('j') && $month == date('n') && $year == date('Y'));
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day); ?>
        <div class="takvim-hucre <?= $today ? 'bugun' : '' ?>" data-date="<?= $dateStr ?>" onclick="etkinlikEkle('<?= $dateStr ?>')" style="cursor:pointer;padding-top:<?= 30 + $bantField ?>px">
            <div class="takvim-gun-no" style="position:absolute;top:8px;right:10px"><?= $day ?></div>
            <?php foreach ($tekGunluk[$day] ?? [] as $e): ?>
            <div class="takvim-etkinlik <?= $e['type'] ?>" draggable="true" data-event="<?= $e['id'] ?>" onclick="event.stopPropagation();etkinlikGoster(<?= $e['id'] ?>)" title="<?= e($e['title']) ?>"><?= date('H:i', strtotime($e['start'])) ?> <?= e($e['title']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<div class="izgara izgara-3 mt-3">
    <?php foreach (EVENT_TYPES as $k => $v):
        $color = $turRenkleri[$k];
        $say = count(array_filter($events, fn($e) => $e['type'] === $k)); ?>
    <div class="kart satir-esnek" style="gap:12px;padding:14px"><span class="etiket-nokta" style="width:14px;height:14px;background:<?= $color ?>"></span><div><div class="kalin"><?= $say ?> <?= $v ?></div><div class="hucre-alt">bu ay</div></div></div>
    <?php endforeach; ?>
</div>

<!-- Add event -->
<div class="modal-katman" id="modalEvent">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik">Yeni Etkinlik</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="event_save" data-refresh="evet" id="eventForm">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="title" class="girdi" required id="et_title"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tür</label><select name="type" class="secim"><?php foreach (EVENT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Yer</label><input name="place" class="girdi"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Başlangıç <span class="zorunlu">*</span></label><input type="datetime-local" name="start" class="girdi" required id="et_start"></div>
                <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="datetime-local" name="end" class="girdi"><div class="form-ipucu">Farklı güne uzarsa takvimde şerit olarak yayılır.</div></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">İlgili Dosya</label><select name="client_id" id="ev_client" class="secim"><option value="">— Ajans içi</option><?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Etkinliğin hangi marka/müşteriyle ilgili olduğu.</div></div>
                <div class="form-grup"><label class="form-etiket">Proje (opsiyonel)</label><select name="project_id" id="ev_project" class="secim"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" data-client="<?= $p['client_id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Katılımcılar</label><input name="participants" class="girdi" placeholder="İsimler, virgülle ayırın"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Alınacaklar</label><textarea name="shopping_list" class="metin-alani" rows="2" placeholder="- Yedek pil&#10;- Gaffer bandı"></textarea></div>
                <div class="form-grup"><label class="form-etiket">İhtiyaç Listesi</label><textarea name="needs_list" class="metin-alani" rows="2" placeholder="- Mekan izni&#10;- Prompter metni"></textarea></div>
            </div>
            <?php if ($musaitEquipment): ?>
            <div class="form-grup">
                <label class="form-etiket">Ekipman Seç <span class="metin-muted" style="font-weight:400">(stüdyodaki müsait ekipmanlar — seçilenler çekime zimmetlenir)</span></label>
                <input type="hidden" name="equipment" id="et_equipment">
                <div class="izgara izgara-2" style="gap:6px;max-height:180px;overflow-y:auto;padding:2px">
                    <?php foreach ($musaitEquipment as $me): ?>
                    <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="ekipman-kutu" value="<?= $me['id'] ?>">
                        <?= icon($me['category'], 14) ?><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $me['code'] ? e($me['code']) . ' — ' : '' ?><?= e($me['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="form-grup"><label class="form-etiket">Not</label><textarea name="description" class="metin-alani"></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- Event detail -->
<div class="modal-katman" id="modalEventDetay">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="edTitle"></div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <div class="modal-govde" id="edBody"></div>
    <div class="modal-alt"><button type="button" class="btn btn-tehlike" id="edDelete">Sil</button><button type="button" class="btn btn-hayalet" data-modal-kapat>Kapat</button></div>
    </div>
</div>

<script>
const events = <?= json_encode(array_column($events, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
const typeName = <?= json_encode(EVENT_TYPES, JSON_UNESCAPED_UNICODE) ?>;
function eventAdd(date) { document.getElementById('et_start').value = date + 'T10:00'; modalOpen('modalEvent'); }
// Auto-fill the client file when a project is selected
document.getElementById('ev_project').addEventListener('change', function () {
    const client = this.selectedOptions[0]?.dataset.client;
    if (client) document.getElementById('ev_client').value = client;
});
document.getElementById('eventForm').addEventListener('submit', () => {
    const field = document.getElementById('et_equipment');
    if (field) field.value = JSON.stringify(Array.from(document.querySelectorAll('.equipment-box:checked')).map(c => c.value));
});
function eventShow(id) {
    const e = events[id]; if (!e) return;
    document.getElementById('edTitle').textContent = e.title;
    let h = `<div class="dikey" style="gap:12px">
        <div class="satir-esnek arasi"><span class="hucre-alt">Tür</span><span class="rozet rozet-tur">${typeName[e.type]}</span></div>
        <div class="satir-esnek arasi"><span class="hucre-alt">Başlangıç</span><span class="kucuk kalin">${new Date(e.start.replace(' ', 'T')).toLocaleString('tr-TR', { dateStyle: 'medium', timeStyle: 'short' })}</span></div>`;
    if (e.end) h += `<div class="satir-esnek arasi"><span class="hucre-alt">Bitiş</span><span class="kucuk kalin">${new Date(e.end.replace(' ', 'T')).toLocaleString('tr-TR', { dateStyle: 'medium', timeStyle: 'short' })}</span></div>`;
    if (e.place) h += `<div class="satir-esnek arasi"><span class="hucre-alt">Yer</span><span class="kucuk">${esc(e.place)}</span></div>`;
    if (e.client_name) h += `<div class="satir-esnek arasi"><span class="hucre-alt">Dosya</span><span class="kucuk kalin">${esc(e.client_name)}</span></div>`;
    if (e.project_name) h += `<div class="satir-esnek arasi"><span class="hucre-alt">Proje</span><span class="kucuk">${esc(e.project_name)}</span></div>`;
    if (e.participants) h += `<div><div class="hucre-alt mb-2">Katılımcılar</div><div class="kucuk">${esc(e.participants)}</div></div>`;
    if (e.equipment && e.equipment.length) {
        h += `<div><div class="satir-esnek arasi mb-2"><span class="hucre-alt">Ekipmanlar (${e.equipment.length})</span>`;
        const disarida = e.equipment.some(k => k.status === 'cekimde');
        if (disarida) h += `<button class="mini-btn" onclick="ekipmanIade(${id})">Tümünü iade al</button>`;
        h += `</div>`;
        e.equipment.forEach(k => {
            const badge = k.status === 'cekimde' ? '<span class="rozet r-bekliyor">Çekimde</span>' : '<span class="rozet r-onaylandi">Stüdyoda</span>';
            h += `<div class="satir-esnek arasi kucuk" style="padding:6px 10px;background:var(--surface-2);border-radius:8px;margin-bottom:4px"><span>${esc(k.code ? k.code + ' — ' : '')}${esc(k.name)}</span>${badge}</div>`;
        });
        h += `</div>`;
    }
    if (e.description) h += `<div><div class="hucre-alt mb-2">Not</div><div class="kucuk metin-2">${e.description.replace(/</g, '&lt;')}</div></div>`;
    if (e.shopping_list) h += `<div><div class="hucre-alt mb-2">🛒 Alınacaklar</div><div class="kucuk metin-2" style="white-space:pre-wrap">${e.shopping_list.replace(/</g, '&lt;')}</div></div>`;
    if (e.needs_list) h += `<div><div class="hucre-alt mb-2">📋 İhtiyaç Listesi</div><div class="kucuk metin-2" style="white-space:pre-wrap">${e.needs_list.replace(/</g, '&lt;')}</div></div>`;
    h += `<div><div class="hucre-alt mb-2">Tarihi Değiştir</div><div class="satir-esnek sarma" style="gap:8px"><input type="datetime-local" class="girdi" id="etTasiBas" value="${e.start.replace(' ', 'T').slice(0,16)}" style="max-width:200px"><input type="datetime-local" class="girdi" id="etTasiBit" value="${e.end ? e.end.replace(' ', 'T').slice(0,16) : ''}" style="max-width:200px"><button class="btn btn-sm" onclick="etTasi(${id})">Güncelle</button></div></div>`;
    h += `</div>`;
    document.getElementById('edBody').innerHTML = h;
    if (window.ozelPickerRefresh) ozelPickerRefresh();
    document.getElementById('edDelete').onclick = async () => {
        if (confirm('Etkinlik silinsin mi? (Çekimdeki ekipmanlar otomatik iade alınır)')) {
            await api('event_equipment_return', { event_id: id });
            const j = await api('event_delete', { id });
            if (j.ok) location.reload();
        }
    };
    modalOpen('modalEventDetay');
}
async function etMove(id) {
    const bEl = document.getElementById('etMoveInitial'), tEl = document.getElementById('etMoveBit');
    const bV = bEl.dataset.setting_value ?? bEl.value, tV = tEl.dataset.setting_value ?? tEl.value;
    const j = await api('event_move', { id, start: bV.replace('T', ' '), end: tV.replace('T', ' ') });
    if (j.ok) { toast(j.message, 'basari'); setTimeout(() => location.reload(), 600); }
}
// Move single-day events by drag and drop
let surEt = null;
document.querySelectorAll('.calendar-event[data-event]').forEach(chip => {
    chip.addEventListener('dragstart', e => { surEt = chip.dataset.event; e.stopPropagation(); });
});
document.querySelectorAll('.calendar-hucre[data-date]').forEach(hucre => {
    hucre.addEventListener('dragover', e => { if (surEt) { e.preventDefault(); hucre.style.borderColor = 'var(--marka)'; } });
    hucre.addEventListener('dragleave', () => hucre.style.borderColor = '');
    hucre.addEventListener('drop', async e => {
        e.preventDefault(); hucre.style.borderColor = '';
        if (!surEt) return;
        const j = await api('event_move', { id: surEt, date: hucre.dataset.date });
        surEt = null;
        if (j.ok) { toast(j.message, 'basari'); setTimeout(() => location.reload(), 500); }
    });
});
async function equipmentReturn(eventId) {
    const j = await api('event_equipment_return', { event_id: eventId });
    if (j.ok) { toast(j.message, 'basari'); setTimeout(() => location.reload(), 600); }
}
</script>
<?php page_end(); ?>

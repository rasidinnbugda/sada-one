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
    $eIds = implode(',', array_map(fn($e) => (int)$e['id'], $events));
    foreach (rows("SELECT ee.event_id, ek.id, ek.code, ek.name, ek.status FROM event_equipment ee JOIN equipment ek ON ek.id=ee.equipment_id WHERE ee.event_id IN ($eIds)") as $r) {
        $eventEquipment[$r['event_id']][] = $r;
    }
}
foreach ($events as &$e) $e['equipment'] = $eventEquipment[$e['id']] ?? [];
unset($e);

/* ---- Split into weeks: 7 cells per week (days outside the month are null) ---- */
$weeks = [];
$week = array_fill(0, $startWeek - 1, null);
for ($day = 1; $day <= $dayCount; $day++) {
    $week[] = $day;
    if (count($week) === 7) { $weeks[] = $week; $week = []; }
}
if ($week) $weeks[] = array_pad($week, 7, null);

/* ---- Separate events: single-day (chip) / multi-day (band) ---- */
$singleDay = [];   // day → event list
$multiDay = [];   // band list
foreach ($events as $e) {
    $initialTs = strtotime(date('Y-m-d', strtotime($e['start'])));
    $lastTs = strtotime(date('Y-m-d', strtotime($e['end'] ?: $e['start'])));
    if ($lastTs < $initialTs) $lastTs = $initialTs;
    if ($initialTs === $lastTs) {
        if ((int)date('n', $initialTs) === $month && (int)date('Y', $initialTs) === $year) $singleDay[(int)date('j', $initialTs)][] = $e;
    } else {
        $multiDay[] = ['e' => $e, 'initial' => $initialTs, 'last' => $lastTs];
    }
}

/* ---- Compute the bands for each week (lane stacking) ---- */
function week_bands(array $week, array $multiDay, int $month, int $year): array {
    // Actual date range within the week
    $first = null; $last = null; $columnDate = [];
    foreach ($week as $col => $day) {
        $columnDate[$col] = $day ? mktime(0, 0, 0, $month, $day, $year) : null;
        if ($day) { if ($first === null) $first = $columnDate[$col]; $last = $columnDate[$col]; }
    }
    if ($first === null) return [];
    $bands = [];
    foreach ($multiDay as $c) {
        if ($c['last'] < $first || $c['initial'] > $last) continue;
        // Start/end columns within the week
        $initialCol = 0; $lastCol = 6;
        foreach ($columnDate as $col => $t) {
            if ($t !== null && $t <= $c['initial']) $initialCol = $col;
            if ($t !== null && $t <= $c['last']) $lastCol = $col;
        }
        if ($columnDate[$initialCol] === null) { foreach ($columnDate as $col => $t) { if ($t !== null) { $initialCol = $col; break; } } }
        $bands[] = [
            'e' => $c['e'], 'initial_col' => $initialCol, 'last_col' => $lastCol,
            'ongoing_from_left' => $c['initial'] < ($columnDate[$initialCol] ?? $first),
            'ongoing_from_right' => $c['last'] > ($columnDate[$lastCol] ?? $last),
        ];
    }
    // Assign lanes (overlapping bands stack vertically)
    $lanes = [];
    foreach ($bands as $i => $b) {
        $lane = 0;
        while (true) {
            $conflicted = false;
            foreach ($bands as $j => $b2) {
                if ($j >= $i || ($b2['lane'] ?? -1) !== $lane) continue;
                if ($b['initial_col'] <= $b2['last_col'] && $b['last_col'] >= $b2['initial_col']) { $conflicted = true; break; }
            }
            if (!$conflicted) break;
            $lane++;
        }
        $bands[$i]['lane'] = $lane;
    }
    return $bands;
}

$projects = rows("SELECT id, name, client_id FROM projects WHERE status='active' ORDER BY name");
$clients = rows("SELECT id, name FROM clients WHERE status='active' ORDER BY name");
$availableEquipment = rows("SELECT id, code, name, category FROM equipment WHERE status='in_studio' ORDER BY FIELD(category,'camera','lens','sd_card','tripod','light','audio','drone','accessory','other'), code");

$typeColors = ['shoot' => '#e86b82', 'meeting' => 'var(--info)', 'delivery' => 'var(--warning)', 'other' => 'var(--brand)'];

page_start('Çekim & Prodüksiyon Takvimi', 'calendar');
?>
<div class="page-top">
    <div><div class="page-title">Prodüksiyon Takvimi</div><div class="page-bottom">Çekimler, toplantılar ve teslim tarihleri — çok günlü işler şerit olarak yayılır</div></div>
    <div class="page-top-action"><button class="btn btn-brand" data-modal="modalEvent"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Etkinlik Ekle</button></div>
</div>

<div class="card">
    <div class="calendar-title-bar">
        <div class="row-flex" style="gap:8px">
            <a href="?month=<?= $month - 1 ?>&year=<?= $year ?>" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
            <div class="calendar-month-name"><?= MONTHS[$month] ?> <?= $year ?></div>
            <a href="?month=<?= $month + 1 ?>&year=<?= $year ?>" class="icon-action"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>
        </div>
        <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-sm">Bugün</a>
    </div>

    <div class="calendar-grid" style="margin-bottom:4px">
        <?php foreach (['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $gAd): ?><div class="calendar-day-title"><?= $gAd ?></div><?php endforeach; ?>
    </div>

    <?php foreach ($weeks as $week):
        $bands = week_bands($week, $multiDay, $month, $year);
        $laneCount = $bands ? max(array_column($bands, 'lane')) + 1 : 0;
        $bandField = $laneCount * 26; ?>
    <div class="calendar-week">
        <?php // Bands (over the cells, below the day number)
        foreach ($bands as $b):
            $e = $b['e'];
            $color = $typeColors[$e['type']] ?? 'var(--brand)';
            $left = $b['initial_col'] / 7 * 100;
            $width = ($b['last_col'] - $b['initial_col'] + 1) / 7 * 100; ?>
        <div class="calendar-band <?= $b['ongoing_from_left'] ? 'continues-left' : '' ?> <?= $b['ongoing_from_right'] ? 'continues-right' : '' ?>"
             style="left:calc(<?= $left ?>% + 3px);width:calc(<?= $width ?>% - 6px);top:<?= 30 + $b['lane'] * 26 ?>px;--band-color:<?= $color ?>"
             onclick="eventShow(<?= $e['id'] ?>)" title="<?= e($e['title']) ?> · <?= format_date(substr($e['start'], 0, 10)) ?> → <?= format_date(substr($e['end'], 0, 10)) ?>">
            <?= $b['ongoing_from_left'] ? '◂ ' : '' ?><?= e($e['title']) ?><?= $b['ongoing_from_right'] ? ' ▸' : '' ?>
        </div>
        <?php endforeach; ?>

        <?php foreach ($week as $day):
            if ($day === null): ?><div class="calendar-cell empty"></div><?php continue; endif;
            $today = ($day == date('j') && $month == date('n') && $year == date('Y'));
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day); ?>
        <div class="calendar-cell <?= $today ? 'today' : '' ?>" data-date="<?= $dateStr ?>" onclick="eventAdd('<?= $dateStr ?>')" style="cursor:pointer;padding-top:<?= 30 + $bandField ?>px">
            <div class="calendar-day-number" style="position:absolute;top:8px;right:10px"><?= $day ?></div>
            <?php foreach ($singleDay[$day] ?? [] as $e): ?>
            <div class="calendar-event <?= $e['type'] ?>" draggable="true" data-event="<?= $e['id'] ?>" onclick="event.stopPropagation();eventShow(<?= $e['id'] ?>)" title="<?= e($e['title']) ?>"><?= date('H:i', strtotime($e['start'])) ?> <?= e($e['title']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-3 mt-3">
    <?php foreach (EVENT_TYPES as $k => $v):
        $color = $typeColors[$k];
        $say = count(array_filter($events, fn($e) => $e['type'] === $k)); ?>
    <div class="card row-flex" style="gap:12px;padding:14px"><span class="label-dot" style="width:14px;height:14px;background:<?= $color ?>"></span><div><div class="bold"><?= $say ?> <?= $v ?></div><div class="cell-bottom">bu ay</div></div></div>
    <?php endforeach; ?>
</div>

<!-- Add event -->
<div class="modal-overlay" id="modalEvent">
    <div class="modal modal-wide"><div class="modal-top"><div class="modal-title">Yeni Etkinlik</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="event_save" data-refresh="yes" id="eventForm">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık <span class="required">*</span></label><input name="title" class="input" required id="ev_title"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tür</label><select name="type" class="select"><?php foreach (EVENT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Yer</label><input name="place" class="input"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Başlangıç <span class="required">*</span></label><input type="datetime-local" name="start" class="input" required id="ev_start"></div>
                <div class="form-group"><label class="form-label">Bitiş</label><input type="datetime-local" name="end" class="input"><div class="form-hint">Farklı güne uzarsa takvimde şerit olarak yayılır.</div></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">İlgili Dosya</label><select name="client_id" id="ev_client" class="select"><option value="">— Ajans içi</option><?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select><div class="form-hint">Etkinliğin hangi marka/müşteriyle ilgili olduğu.</div></div>
                <div class="form-group"><label class="form-label">Proje (opsiyonel)</label><select name="project_id" id="ev_project" class="select"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" data-client="<?= $p['client_id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group"><label class="form-label">Katılımcılar</label><input name="participants" class="input" placeholder="İsimler, virgülle ayırın"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Alınacaklar</label><textarea name="shopping_list" class="text-area" rows="2" placeholder="- Yedek pil&#10;- Gaffer bandı"></textarea></div>
                <div class="form-group"><label class="form-label">İhtiyaç Listesi</label><textarea name="needs_list" class="text-area" rows="2" placeholder="- Mekan izni&#10;- Prompter metni"></textarea></div>
            </div>
            <?php if (permission('budget_view')): ?>
            <div class="form-group"><label class="form-label">Çekim Maliyeti (₺)</label><input name="cost" class="input" placeholder="0.00"><div class="form-hint">Kiralama, ulaşım vb. Girildiğinde Finans'a otomatik gider kaydı düşer.</div></div>
            <?php endif; ?>
            <div class="form-group"><label class="form-label">Drive Klasörü <span class="text-muted" style="font-weight:400">(çekim için, opsiyonel)</span></label>
                <input name="drive_folder" class="input" placeholder="Klasör linki veya ID — boşsa dosyanın klasörü kullanılır">
                <div class="form-hint">Çekim görüntülerinin yükleneceği klasör; aktarım buradan otomatik denetlenir.</div>
            </div>
            <?php if ($availableEquipment): ?>
            <div class="form-group">
                <label class="form-label">Ekipman Seç <span class="text-muted" style="font-weight:400">(stüdyodaki müsait ekipmanlar — seçilenler çekime zimmetlenir)</span></label>
                <input type="hidden" name="equipment" id="ev_equipment">
                <div class="grid grid-2" style="gap:6px;max-height:180px;overflow-y:auto;padding:2px">
                    <?php foreach ($availableEquipment as $me): ?>
                    <label class="row-flex small" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="equipment-box" value="<?= $me['id'] ?>">
                        <?= icon($me['category'], 14) ?><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $me['code'] ? e($me['code']) . ' — ' : '' ?><?= e($me['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="form-group"><label class="form-label">Not</label><textarea name="description" class="text-area"></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<!-- Event detail -->
<div class="modal-overlay" id="modalEventDetail">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="edTitle"></div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body" id="edBody"></div>
    <div class="modal-alt"><button type="button" class="btn btn-danger" id="edDelete">Sil</button><button type="button" class="btn btn-ghost" data-modal-close>Kapat</button></div>
    </div>
</div>

<script>
const events = <?= json_encode(array_column($events, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
const typeName = <?= json_encode(EVENT_TYPES, JSON_UNESCAPED_UNICODE) ?>;
function eventAdd(date) { document.getElementById('ev_start').value = date + 'T10:00'; modalOpen('modalEvent'); }
// Auto-fill the client file when a project is selected
document.getElementById('ev_project').addEventListener('change', function () {
    const client = this.selectedOptions[0]?.dataset.client;
    if (client) document.getElementById('ev_client').value = client;
});
document.getElementById('eventForm').addEventListener('submit', () => {
    const field = document.getElementById('ev_equipment');
    if (field) field.value = JSON.stringify(Array.from(document.querySelectorAll('.equipment-box:checked')).map(c => c.value));
});
function eventShow(id) {
    const e = events[id]; if (!e) return;
    document.getElementById('edTitle').textContent = e.title;
    let h = `<div class="vertical" style="gap:12px">
        <div class="row-flex between"><span class="cell-bottom">Tür</span><span class="badge badge-type">${typeName[e.type]}</span></div>
        <div class="row-flex between"><span class="cell-bottom">Başlangıç</span><span class="small bold">${new Date(e.start.replace(' ', 'T')).toLocaleString('tr-TR', { dateStyle: 'medium', timeStyle: 'short' })}</span></div>`;
    if (e.end) h += `<div class="row-flex between"><span class="cell-bottom">Bitiş</span><span class="small bold">${new Date(e.end.replace(' ', 'T')).toLocaleString('tr-TR', { dateStyle: 'medium', timeStyle: 'short' })}</span></div>`;
    if (e.place) h += `<div class="row-flex between"><span class="cell-bottom">Yer</span><span class="small">${esc(e.place)}</span></div>`;
    if (e.client_name) h += `<div class="row-flex between"><span class="cell-bottom">Dosya</span><span class="small bold">${esc(e.client_name)}</span></div>`;
    if (e.project_name) h += `<div class="row-flex between"><span class="cell-bottom">Proje</span><span class="small">${esc(e.project_name)}</span></div>`;
    if (e.participants) h += `<div><div class="cell-bottom mb-2">Katılımcılar</div><div class="small">${esc(e.participants)}</div></div>`;
    if (e.equipment && e.equipment.length) {
        h += `<div><div class="row-flex between mb-2"><span class="cell-bottom">Ekipmanlar (${e.equipment.length})</span>`;
        const onShoot = e.equipment.some(k => k.status === 'on_shoot');
        if (onShoot) h += `<button class="mini-btn" onclick="equipmentReturn(${id})">Tümünü iade al</button>`;
        h += `</div>`;
        e.equipment.forEach(k => {
            const badge = k.status === 'on_shoot' ? '<span class="badge r-pending">Çekimde</span>' : '<span class="badge r-approved">Stüdyoda</span>';
            h += `<div class="row-flex between small" style="padding:6px 10px;background:var(--surface-2);border-radius:8px;margin-bottom:4px"><span>${esc(k.code ? k.code + ' — ' : '')}${esc(k.name)}</span>${badge}</div>`;
        });
        h += `</div>`;
    }
    if (e.description) h += `<div><div class="cell-bottom mb-2">Not</div><div class="small text-2">${e.description.replace(/</g, '&lt;')}</div></div>`;
    if (e.shopping_list) h += `<div><div class="cell-bottom mb-2">🛒 Alınacaklar</div><div class="small text-2" style="white-space:pre-wrap">${e.shopping_list.replace(/</g, '&lt;')}</div></div>`;
    if (e.needs_list) h += `<div><div class="cell-bottom mb-2">📋 İhtiyaç Listesi</div><div class="small text-2" style="white-space:pre-wrap">${e.needs_list.replace(/</g, '&lt;')}</div></div>`;
    h += `<div><div class="cell-bottom mb-2">Tarihi Değiştir</div><div class="row-flex wrap" style="gap:8px"><input type="datetime-local" class="input" id="eventMoveStart" value="${e.start.replace(' ', 'T').slice(0,16)}" style="max-width:200px"><input type="datetime-local" class="input" id="eventMoveEnd" value="${e.end ? e.end.replace(' ', 'T').slice(0,16) : ''}" style="max-width:200px"><button class="btn btn-sm" onclick="eventMove(${id})">Güncelle</button></div></div>`;
    h += `</div>`;
    document.getElementById('edBody').innerHTML = h;
    if (window.customPickerRefresh) customPickerRefresh();
    document.getElementById('edDelete').onclick = async () => {
        if (confirm('Etkinlik silinsin mi? (Çekimdeki ekipmanlar otomatik iade alınır)')) {
            await api('event_equipment_return', { event_id: id });
            const j = await api('event_delete', { id });
            if (j.ok) location.reload();
        }
    };
    modalOpen('modalEventDetail');
}
async function eventMove(id) {
    const bEl = document.getElementById("eventMoveStart"), tEl = document.getElementById("eventMoveEnd");
    const bV = bEl.dataset.value ?? bEl.value, tV = tEl.dataset.value ?? tEl.value;
    const j = await api('event_move', { id, start: bV.replace('T', ' '), end: tV.replace('T', ' ') });
    if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 600); }
}
// Move single-day events by drag and drop
let draggedEventId = null;
document.querySelectorAll('.calendar-event[data-event]').forEach(chip => {
    chip.addEventListener('dragstart', e => { draggedEventId = chip.dataset.event; e.stopPropagation(); });
});
document.querySelectorAll('.calendar-cell[data-date]').forEach(cell => {
    cell.addEventListener('dragover', e => { if (draggedEventId) { e.preventDefault(); cell.style.borderColor = 'var(--brand)'; } });
    cell.addEventListener('dragleave', () => cell.style.borderColor = '');
    cell.addEventListener('drop', async e => {
        e.preventDefault(); cell.style.borderColor = '';
        if (!draggedEventId) return;
        const j = await api('event_move', { id: draggedEventId, date: cell.dataset.date });
        draggedEventId = null;
        if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 500); }
    });
});
async function equipmentReturn(eventId) {
    const j = await api('event_equipment_return', { event_id: eventId });
    if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 600); }
}
</script>
<?php page_end(); ?>

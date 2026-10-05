<?php
/**
 * SADA One — Work log ("Çalışma Defteri")
 * Everyone writes the days they worked, the hours and what they did; the month's total adds itself up.
 * Everyone sees their own log; managers see anyone's and an overview of the whole team.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/worklog.php';
require_once __DIR__ . '/includes/office.php';
$u = require_staff();

$month = preg_match('~^\d{4}-\d{2}$~', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$people = office_people();
$overview = is_pm() && ($_GET['user'] ?? '') === 'all';
$who = (int)($_GET['user'] ?? 0) ?: (int)$u['id'];
if (!is_pm() || !array_filter($people, fn($p) => (int)$p['id'] === $who)) $who = (int)$u['id'];
$person = current(array_filter($people, fn($p) => (int)$p['id'] === $who)) ?: ['id' => $u['id'], 'name' => $u['name']];
$mine = $who === (int)$u['id'];
$link = fn(array $q) => 'worklog.php?' . http_build_query(array_filter($q + ['month' => $month, 'user' => $overview ? 'all' : ($mine ? null : $who)], fn($v) => $v !== null && $v !== ''));

$entries = $overview ? [] : rows("SELECT w.*, p.name project_name, c.name client_name FROM work_logs w
    LEFT JOIN projects p ON p.id=w.project_id LEFT JOIN clients c ON c.id=w.client_id
    WHERE w.user_id=? AND w.date BETWEEN ? AND ? ORDER BY w.date, w.start_time, w.id", [$who, $monthStart, $monthEnd]);
$totals = worklog_month_totals($month, $overview ? null : $who);
$mineTotals = $totals[$who] ?? ['minutes' => 0, 'days' => 0, 'by' => []];

// What the work was for: a client file, or one of its projects
$targets = [];
foreach (rows("SELECT c.id client_id, c.name client_name, p.id project_id, p.name project_name FROM clients c
    LEFT JOIN projects p ON p.client_id=c.id AND p.status='active' WHERE c.status='active' ORDER BY c.name, p.name") as $r) {
    $targets[$r['client_id']]['name'] = $r['client_name'];
    if ($r['project_id']) $targets[$r['client_id']]['projects'][$r['project_id']] = $r['project_name'];
}
// A new entry starts from today's office hours, if there are any
$todayOffice = office_range(date('Y-m-d'), date('Y-m-d'), [$who])[$who][date('Y-m-d')] ?? null;
$defaults = $todayOffice && $todayOffice['kind'] !== 'out' ? ['office', $todayOffice['start'], $todayOffice['end']] : ['office', '10:00', '18:00'];

// Month tabs, like the sheet: the five months before this one and this one (plus the one shown, if elsewhere)
$tabs = [];
for ($i = 5; $i >= 0; $i--) $tabs[] = date('Y-m', strtotime("first day of -$i month"));
if (!in_array($month, $tabs, true)) $tabs[] = $month;
sort($tabs);

page_start('Çalışma Defteri', 'worklog');
?>
<div class="page-top">
    <div>
        <div class="page-title">Çalışma Defteri</div>
        <div class="page-bottom">Geldiğin günleri, çalıştığın saatleri ve o sürede ne yaptığını yaz; toplam süre kendiliğinden hesaplanır.</div>
    </div>
    <div class="page-top-action">
        <?php if (is_pm()): ?>
        <form method="get" class="row-flex" style="gap:8px">
            <input type="hidden" name="month" value="<?= e($month) ?>">
            <select name="user" class="select native-select" style="width:auto;min-width:190px" onchange="this.form.submit()" aria-label="Kimin defteri">
                <option value="all" <?= $overview ? 'selected' : '' ?>>Herkes — ay özeti</option>
                <?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>" <?= !$overview && (int)$p['id'] === $who ? 'selected' : '' ?>><?= (int)$p['id'] === (int)$u['id'] ? 'Ben (' . e($p['name']) . ')' : e($p['name']) ?></option><?php endforeach; ?>
            </select>
        </form>
        <a href="export.php?type=worklog&amp;month=<?= e($month) ?><?= $overview ? '' : '&amp;user=' . $who ?>" class="btn">CSV</a>
        <?php endif; ?>
    </div>
</div>

<nav class="view-bar wl-months" aria-label="Aylar">
    <?php foreach ($tabs as $tab): ?><a href="<?= e($link(['month' => $tab])) ?>" class="view-tab<?= $tab === $month ? ' active' : '' ?>"><?= MONTHS[(int)substr($tab, 5, 2)] ?><?= substr($tab, 0, 4) !== date('Y') ? ' ' . substr($tab, 0, 4) : '' ?></a><?php endforeach; ?>
</nav>

<?php if ($overview):
    $teamTotal = array_sum(array_column($totals, 'minutes')); ?>
<div class="card">
    <div class="row-flex between mb-2"><div class="card-title" style="font-size:15px"><?= MONTHS[(int)substr($month, 5, 2)] ?> <?= substr($month, 0, 4) ?> — ekip</div><span class="wl-total-sm"><?= worklog_hm($teamTotal) ?></span></div>
    <div class="table-wrap" style="box-shadow:none"><table class="table">
        <thead><tr><th>Kişi</th><th>Gün</th><th>Toplam</th><?php foreach (WORK_LOG_CATEGORIES as $label): ?><th><?= $label ?></th><?php endforeach; ?><th></th></tr></thead>
        <tbody>
        <?php foreach ($people as $p): $t = $totals[$p['id']] ?? ['minutes' => 0, 'days' => 0, 'by' => []]; ?>
        <tr>
            <td><div class="row-flex" style="gap:8px"><?= avatar($p, 26) ?><span class="small bold"><?= e($p['name']) ?></span></div></td>
            <td class="small"><?= $t['days'] ?: '—' ?></td>
            <td><span class="wl-num bold"><?= $t['minutes'] ? worklog_hm($t['minutes']) : '—' ?></span></td>
            <?php foreach (array_keys(WORK_LOG_CATEGORIES) as $cat): ?><td><span class="wl-num small"><?= !empty($t['by'][$cat]) ? worklog_hm($t['by'][$cat]) : '—' ?></span></td><?php endforeach; ?>
            <td><a class="mini-btn" href="<?= e('worklog.php?month=' . $month . '&user=' . $p['id']) ?>">Deftere bak →</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php else: ?>
<div class="wl-layout">
    <div class="card wl-summary">
        <div class="cell-bottom"><?= $mine ? 'Aylık toplamın' : 'Aylık toplam — ' . e($person['name']) ?> · <?= MONTHS[(int)substr($month, 5, 2)] ?> <?= substr($month, 0, 4) ?></div>
        <div class="wl-total"><?= worklog_hm($mineTotals['minutes']) ?></div>
        <div class="cell-bottom"><?= $mineTotals['days'] ?> gün kayıt</div>
        <div class="wl-chips mt-2">
            <?php foreach (WORK_LOG_CATEGORIES as $cat => $label): if (empty($mineTotals['by'][$cat])) continue; ?>
            <span class="wl-cat is-<?= $cat ?>"><?= $label ?> <b><?= worklog_hm($mineTotals['by'][$cat]) ?></b></span>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-title mb-2" style="font-size:15px"><?= $mine ? 'Yeni kayıt' : 'Yeni kayıt — ' . e($person['name']) ?></div>
        <form data-ajax="worklog_save" id="wlForm">
            <input type="hidden" name="user_id" value="<?= $who ?>">
            <div class="wl-fields">
                <div class="form-group"><label class="form-label">Tarih</label><input type="date" name="date" class="input" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label class="form-label">Kategori</label><select name="category" class="select native-select"><?php foreach (WORK_LOG_CATEGORIES as $k => $v): ?><option value="<?= $k ?>" <?= $k === $defaults[0] ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Başlangıç</label><input type="time" name="start" class="input" required value="<?= $defaults[1] ?>"></div>
                <div class="form-group"><label class="form-label">Bitiş</label><input type="time" name="end" class="input" required value="<?= $defaults[2] ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Dosya / Proje <span class="text-muted" style="font-weight:400">(isteğe bağlı)</span></label>
                <select name="target" class="select native-select"><option value="">—</option>
                    <?php foreach ($targets as $cid => $c): ?><optgroup label="<?= e($c['name']) ?>"><option value="c:<?= $cid ?>"><?= e($c['name']) ?> (genel)</option><?php foreach ($c['projects'] ?? [] as $pid => $pname): ?><option value="p:<?= $pid ?>"><?= e($pname) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Notlar ve çıktılar</label><textarea name="note" class="text-area" rows="2" placeholder="Bu sürede ne yaptın? Örn. stajyerlerle toplantı yapıldı, eğitim sunumu hazırlandı"></textarea></div>
            <div class="row-flex between wrap" style="gap:10px"><span class="small text-2 wl-live">Toplam: <b class="wl-num">—</b></span><button type="submit" class="btn btn-brand">Kaydet</button></div>
        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card-title mb-2" style="font-size:15px"><?= MONTHS[(int)substr($month, 5, 2)] ?> kayıtları <span class="badge" style="padding:1px 8px"><?= count($entries) ?></span></div>
    <?php if (!$entries): ?><div class="text-muted small" style="padding:12px 0">Bu ay için kayıt yok.<?= $mine ? ' Yukarıdan ilk kaydını ekleyebilirsin.' : '' ?></div>
    <?php else: ?>
    <div class="table-wrap" style="box-shadow:none"><table class="table wl-table">
        <thead><tr><th>Tarih</th><th>Kategori</th><th>Başlangıç</th><th>Bitiş</th><th>Toplam</th><th>Durum</th><th>Dosya / Proje</th><th>Notlar ve çıktılar</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($entries as $w): [$statusKey, $statusLabel] = worklog_status($w['category'], (int)$w['minutes']); ?>
        <tr>
            <td class="small wl-num"><?= format_date($w['date']) ?><div class="cell-bottom"><?= DAYS_SHORT[(int)date('N', strtotime($w['date'])) - 1] ?></div></td>
            <td><span class="wl-cat is-<?= e($w['category']) ?>"><?= WORK_LOG_CATEGORIES[$w['category']] ?? e($w['category']) ?></span></td>
            <td class="wl-num small"><?= substr($w['start_time'], 0, 5) ?></td>
            <td class="wl-num small"><?= substr($w['end_time'], 0, 5) ?></td>
            <td class="wl-num small bold"><?= worklog_hm((int)$w['minutes']) ?></td>
            <td><span class="wl-status is-<?= $statusKey ?>"><?= $statusLabel ?></span></td>
            <td class="small"><?= $w['project_name'] ? e($w['project_name']) : ($w['client_name'] ? e($w['client_name']) : '<span class="text-muted">—</span>') ?></td>
            <td class="small wl-note"><?= nl2br(e((string)$w['note'])) ?></td>
            <td style="white-space:nowrap">
                <button class="icon-action" title="Düzenle" onclick='wlEdit(<?= json_encode(['id' => (int)$w['id'], 'date' => $w['date'], 'category' => $w['category'], 'start' => substr($w['start_time'], 0, 5), 'end' => substr($w['end_time'], 0, 5), 'target' => $w['project_id'] ? 'p:' . $w['project_id'] : ($w['client_id'] ? 'c:' . $w['client_id'] : ''), 'note' => (string)$w['note']], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'>✎</button>
                <button class="icon-action danger" title="Sil" data-action="worklog_delete" data-id="<?= $w['id'] ?>" data-confirm="Bu kayıt silinsin mi?">✕</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="4" class="small bold" style="text-align:right">Aylık toplam</td><td class="wl-num bold"><?= worklog_hm($mineTotals['minutes']) ?></td><td colspan="4"></td></tr></tfoot>
    </table></div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="modalWorklog">
    <div class="modal"><div class="modal-top"><div class="modal-title">Kaydı Düzenle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="worklog_save" id="wlEditForm">
        <input type="hidden" name="id">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tarih</label><input type="date" name="date" class="input" required max="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label class="form-label">Kategori</label><select name="category" class="select native-select"><?php foreach (WORK_LOG_CATEGORIES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Başlangıç</label><input type="time" name="start" class="input" required></div>
                <div class="form-group"><label class="form-label">Bitiş</label><input type="time" name="end" class="input" required></div>
            </div>
            <div class="form-group"><label class="form-label">Dosya / Proje</label>
                <select name="target" class="select native-select"><option value="">—</option>
                    <?php foreach ($targets as $cid => $c): ?><optgroup label="<?= e($c['name']) ?>"><option value="c:<?= $cid ?>"><?= e($c['name']) ?> (genel)</option><?php foreach ($c['projects'] ?? [] as $pid => $pname): ?><option value="p:<?= $pid ?>"><?= e($pname) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Notlar ve çıktılar</label><textarea name="note" class="text-area" rows="3"></textarea></div>
            <div class="small text-2 wl-live">Toplam: <b class="wl-num">—</b></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<script>
(() => {
    // Live total under the form: end before start runs into the next day; a meeting is "Katıldım", 6+ hours "Tam gün"
    const live = (form) => {
        const v = (n) => form.querySelector(`[name=${n}]`)?.value || '';
        const toMin = (t) => /^\d{2}:\d{2}/.test(t) ? +t.slice(0, 2) * 60 + +t.slice(3, 5) : null;
        const s = toMin(v('start')), e = toMin(v('end'));
        const out = form.querySelector('.wl-live b');
        if (s === null || e === null) { out.textContent = '—'; return; }
        let m = e - s; if (m < 0) m += 1440;
        if (m <= 0 || m > 1080) { out.textContent = 'geçersiz aralık'; return; }
        const status = v('category') === 'meeting' ? 'Katıldım' : (m >= 360 ? 'Tam gün' : 'Yarım gün');
        out.textContent = `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')} · ${status}`;
    };
    document.querySelectorAll('#wlForm, #wlEditForm').forEach(f => { f.addEventListener('change', () => live(f)); f.addEventListener('input', () => live(f)); });
    window.addEventListener('load', () => { const f = document.getElementById('wlForm'); if (f) live(f); });
    window.wlEdit = (w) => {
        const f = document.getElementById('wlEditForm');
        ['id', 'date', 'category', 'start', 'end', 'target', 'note'].forEach(k => formFieldSet(f, k, w[k] ?? ''));
        live(f);
        modalOpen('modalWorklog');
    };
})();
</script>
<?php endif; ?>
<?php page_end(); ?>

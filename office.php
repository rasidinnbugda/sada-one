<?php
/**
 * SADA One — Office days
 * Everyone keeps their weekly office pattern and changes single days on top of it; the whole team sees the week.
 * No approval: changes take effect at once and the managers are told. Managers may keep someone else's days.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/office.php';
$u = require_staff();

$people = office_people();
$who = (int)($_GET['user'] ?? 0) ?: (int)$u['id'];
if ($who !== (int)$u['id'] && !is_pm()) $who = (int)$u['id'];
$person = current(array_filter($people, fn($p) => (int)$p['id'] === $who)) ?: null;
if (!$person) { $who = (int)$u['id']; $person = ['id' => $u['id'], 'name' => $u['name']]; }
$mine = $who === (int)$u['id'];

$pattern = office_pattern($who);
$changes = rows("SELECT * FROM office_days WHERE user_id=? AND date >= CURDATE() ORDER BY date LIMIT 40", [$who]);
$week = preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['week'] ?? '') ? $_GET['week'] : date('Y-m-d');
$monday = date('Y-m-d', strtotime('monday this week', strtotime($week)));

page_start('Ofis Günleri', 'office');
?>
<div class="page-top">
    <div>
        <div class="page-title">Ofis Günleri</div>
        <div class="page-bottom">Haftalık düzenini bir kez gir; gelemeyeceğin ya da fazladan geleceğin günleri tek tek değiştir. Değişiklikler yöneticilere bildirilir.</div>
    </div>
    <?php if (is_pm()): ?>
    <div class="page-top-action">
        <form method="get" class="row-flex" style="gap:8px">
            <label class="cell-bottom" for="officeWho">Kimin günleri</label>
            <select id="officeWho" name="user" class="select" style="width:auto;min-width:180px" onchange="this.form.submit()">
                <?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>" <?= (int)$p['id'] === $who ? 'selected' : '' ?>><?= (int)$p['id'] === (int)$u['id'] ? 'Ben (' . e($p['name']) . ')' : e($p['name']) ?></option><?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="office-layout">
    <div class="card">
        <div class="card-title mb-1" style="font-size:15px"><?= $mine ? 'Haftalık düzenim' : 'Haftalık düzen — ' . e($person['name']) ?></div>
        <div class="cell-bottom mb-3">Her hafta geldiğin günler ve saatler. Saatler 15 dakikalık adımlarla.</div>
        <form data-ajax="office_pattern_save" id="officePattern">
            <input type="hidden" name="user_id" value="<?= $who ?>">
            <div class="office-days">
                <?php for ($d = 1; $d <= 7; $d++): $day = $pattern[$d] ?? null; ?>
                <div class="office-day<?= $day ? '' : ' is-off' ?>">
                    <label class="row-flex" style="gap:9px;cursor:pointer"><input type="checkbox" name="day_<?= $d ?>_on" value="1" <?= $day ? 'checked' : '' ?>> <span class="small bold"><?= DAYS[$d - 1] ?></span></label>
                    <input type="time" name="day_<?= $d ?>_start" class="input" step="900" value="<?= e($day['start'] ?? '10:00') ?>" aria-label="<?= DAYS[$d - 1] ?> başlangıç">
                    <span class="cell-bottom">–</span>
                    <input type="time" name="day_<?= $d ?>_end" class="input" step="900" value="<?= e($day['end'] ?? '18:00') ?>" aria-label="<?= DAYS[$d - 1] ?> bitiş">
                </div>
                <?php endfor; ?>
            </div>
            <button type="submit" class="btn btn-brand mt-3">Düzeni Kaydet</button>
        </form>
    </div>

    <div class="card">
        <div class="card-title mb-1" style="font-size:15px">Gün değişikliği</div>
        <div class="cell-bottom mb-3">Düzenin dışında kalan tek bir gün: gelemeyeceksin, farklı saatte geleceksin ya da fazladan geleceksin.</div>
        <form data-ajax="office_day_save" id="officeDay">
            <input type="hidden" name="user_id" value="<?= $who ?>">
            <div class="form-group"><label class="form-label">Tarih</label><input type="date" name="date" class="input" required min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+1 year')) ?>"></div>
            <div class="form-group">
                <div class="row-flex wrap" style="gap:8px">
                    <label class="row-flex small office-choice"><input type="radio" name="kind" value="out" checked> Gelemeyeceğim</label>
                    <label class="row-flex small office-choice"><input type="radio" name="kind" value="in"> Bu saatlerde geleceğim</label>
                </div>
            </div>
            <div class="form-row office-day-hours" hidden>
                <div class="form-group"><label class="form-label">Geliş</label><input type="time" name="start" class="input" step="900" value="10:00"></div>
                <div class="form-group"><label class="form-label">Çıkış</label><input type="time" name="end" class="input" step="900" value="18:00"></div>
            </div>
            <div class="form-group"><label class="form-label">Not <span class="text-muted" style="font-weight:400">(isteğe bağlı)</span></label><input name="note" class="input" maxlength="255" placeholder="Örn. çekimdeyim, evden çalışıyorum"></div>
            <button type="submit" class="btn btn-brand">Ekle</button>
        </form>

        <div class="cell-bottom mt-3 mb-1">Yaklaşan değişiklikler</div>
        <?php if (!$changes): ?><div class="text-muted small">Yaklaşan bir değişiklik yok; her zamanki düzen geçerli.</div>
        <?php else: foreach ($changes as $c): $weekdayName = DAYS[(int)date('N', strtotime($c['date'])) - 1]; ?>
        <div class="row-flex between office-change">
            <span class="small"><b><?= format_date($c['date']) ?></b> <span class="cell-bottom"><?= $weekdayName ?></span> · <?= $c['kind'] === 'out' ? '<span class="office-slot is-out">gelmiyor</span>' : '<span class="office-slot is-changed">' . office_hours(substr($c['start_time'], 0, 5), substr($c['end_time'], 0, 5)) . '</span>' ?><?= $c['note'] ? ' <span class="cell-bottom">— ' . e($c['note']) . '</span>' : '' ?></span>
            <button class="icon-action danger" style="width:26px;height:26px" data-action="office_day_delete" data-id="<?= $c['id'] ?>" data-confirm="Bu değişiklik kaldırılsın mı? O gün her zamanki düzen geçerli olur." title="Kaldır">✕</button>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="mt-3"><?php office_week_board($monday, true, 'office.php' . ($mine ? '' : '?user=' . $who)); ?></div>

<script>
(() => {
    // A day without the tick is only shown faded — its hours stay editable (the panel swaps time inputs for its own
    // picker after this script, so disabling them here could never be undone). Picking an hour ticks the day.
    document.querySelectorAll('.office-day').forEach(row => {
        const box = row.querySelector('input[type=checkbox]');
        const sync = () => row.classList.toggle('is-off', !box.checked);
        row.addEventListener('change', e => { if (e.target !== box && !box.checked) box.checked = true; sync(); });
        sync();
    });
    // the change form asks for hours only when coming
    const form = document.getElementById('officeDay');
    const hours = form.querySelector('.office-day-hours');
    const syncKind = () => { hours.hidden = form.querySelector('input[name=kind]:checked').value !== 'in'; };
    form.querySelectorAll('input[name=kind]').forEach(r => r.addEventListener('change', syncKind)); syncKind();
})();
</script>
<?php page_end(); ?>

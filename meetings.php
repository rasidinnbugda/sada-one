<?php
/**
 * SADA One — Meeting Calendar
 * Meeting management with participant selection, online meeting link, and reminder notifications.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; } if ($month > 12) { $month = 1; $year++; }
$monthInitial = sprintf('%04d-%02d-01', $year, $month);
$monthLast = date('Y-m-t', strtotime($monthInitial));

$meetings = rows("SELECT e.*, p.name project_name, us.name creator_name FROM events e
    LEFT JOIN projects p ON p.id=e.project_id LEFT JOIN users us ON us.id=e.created_by
    WHERE e.type='toplanti' AND DATE(e.start) BETWEEN ? AND ? ORDER BY e.start", [$monthInitial, $monthLast]);

// Load participants
foreach ($meetings as &$t) {
    $t['participant_list'] = rows("SELECT us.id, us.name, us.color, us.avatar FROM event_participants ek JOIN users us ON us.id=ek.user_id WHERE ek.event_id=? ORDER BY us.name", [$t['id']]);
}
unset($t);

// Group by day
$days = [];
foreach ($meetings as $t) $days[substr($t['start'], 0, 10)][] = $t;

$team = rows("SELECT id, name, color, avatar FROM users WHERE role IN ('yonetici','pm','ekip','finans','stajyer') AND is_active=1 ORDER BY name");
$projects = rows("SELECT id, name, client_id FROM projects WHERE status='aktif' ORDER BY name");
$clientsList = rows("SELECT id, name FROM clients WHERE status='aktif' ORDER BY name");

page_start('Toplantı Takvimi', 'meetings');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Toplantı Takvimi</div><div class="sayfa-alt">Katılımcılı ve linkli toplantılar — başlamadan ~1 saat önce hatırlatılır</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalToplanti"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Toplantı Planla</button></div>
</div>

<div class="takvim-baslik-bar">
    <div class="satir-esnek" style="gap:8px">
        <a href="?month=<?= $month - 1 ?>&year=<?= $year ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
        <div class="takvim-ay-ad"><?= MONTHS[$month] ?> <?= $year ?></div>
        <a href="?month=<?= $month + 1 ?>&year=<?= $year ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>
    </div>
    <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-sm">Bu Ay</a>
</div>

<?php if (!$meetings): ?>
<div class="bos-durum">
    <div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg></div>
    <div class="bos-baslik">Bu ay toplantı yok</div>
    <div class="bos-metin">Toplantı planlayın, katılımcılar otomatik bilgilendirilsin.</div>
    <button class="btn btn-marka" data-modal="modalToplanti">Toplantı Planla</button>
</div>
<?php else: foreach ($days as $dayDate => $gunToplantilari):
    $todayMu = $dayDate === date('Y-m-d');
    $history = $dayDate < date('Y-m-d'); ?>
<div class="nav-bolum" style="padding:16px 0 8px;<?= $todayMu ? 'color:var(--marka)' : '' ?>">
    <?= $todayMu ? 'BUGÜN — ' : '' ?><?= DAYS[(int)date('N', strtotime($dayDate)) - 1] ?>, <?= format_date($dayDate) ?>
</div>
<div class="izgara izgara-2">
    <?php foreach ($gunToplantilari as $t):
        $basladi = strtotime($t['start']) <= time() && (!$t['end'] || strtotime($t['end']) >= time()); ?>
    <div class="kart" style="padding:16px;<?= $history ? 'opacity:.6' : '' ?><?= $basladi ? 'border-color:var(--marka)' : '' ?>">
        <div class="satir-esnek arasi" style="align-items:flex-start">
            <div style="min-width:0">
                <div class="satir-esnek sarma" style="gap:8px">
                    <span class="kalin"><?= e($t['title']) ?></span>
                    <?php if ($basladi): ?><span class="rozet r-devam">● Şu an</span><?php endif; ?>
                    <?php if ($t['online_link']): ?><span class="rozet rozet-tur"><?= icon('video', 12) ?> Online</span><?php endif; ?>
                </div>
                <div class="hucre-alt mt-1">
                    <?= date('H:i', strtotime($t['start'])) ?><?= $t['end'] ? ' – ' . date('H:i', strtotime($t['end'])) : '' ?>
                    <?= $t['place'] ? ' · ' . e($t['place']) : '' ?>
                    <?= $t['project_name'] ? ' · ' . e($t['project_name']) : '' ?>
                </div>
            </div>
            <button class="ikon-eylem tehlike" data-action="event_delete" data-id="<?= $t['id'] ?>" data-approval="Toplantı silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button>
        </div>
        <?php if ($t['description']): ?><div class="kucuk metin-2 mt-2"><?= nl2br(e(mb_substr($t['description'], 0, 200))) ?></div><?php endif; ?>
        <div class="satir-esnek arasi mt-2 sarma" style="gap:10px">
            <div class="satir-esnek" style="gap:8px">
                <?php if ($t['participant_list']): ?>
                <span class="avatar-dizi"><?php foreach (array_slice($t['participant_list'], 0, 6) as $ktl) echo avatar($ktl, 26); ?></span>
                <span class="hucre-alt"><?= count($t['participant_list']) ?> katılımcı</span>
                <?php else: ?><span class="hucre-alt">Katılımcı eklenmemiş</span><?php endif; ?>
            </div>
            <?php if ($t['online_link']): ?>
            <a href="<?= e($t['online_link']) ?>" target="_blank" class="btn btn-marka btn-sm"><?= icon('video', 14) ?> Toplantıya Katıl</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; endif; ?>

<!-- Schedule meeting -->
<div class="modal-katman" id="modalMeeting">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik">Toplantı Planla</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="event_save" data-refresh="evet" id="meetingForm">
        <input type="hidden" name="type" value="toplanti">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Konu <span class="zorunlu">*</span></label><input name="title" class="girdi" required placeholder="Örn. Haftalık planlama toplantısı"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Başlangıç <span class="zorunlu">*</span></label><input type="datetime-local" name="start" class="girdi" required></div>
                <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="datetime-local" name="end" class="girdi"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Online Toplantı Linki</label><input name="online_link" class="girdi" placeholder="Meet/Zoom linki — girilirse 'Katıl' butonu görünür"></div>
                <div class="form-grup"><label class="form-etiket">Yer (fiziksel ise)</label><input name="place" class="girdi" placeholder="Örn. Ofis toplantı odası"></div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Katılımcılar <span class="metin-muted" style="font-weight:400">(seçilenlere davet bildirimi gider)</span></label>
                <input type="hidden" name="participant_ids" id="t_participants">
                <div class="izgara izgara-2" style="gap:6px;max-height:170px;overflow-y:auto;padding:2px">
                    <?php foreach ($team as $k): if ($k['id'] == $u['id']) continue; ?>
                    <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="katilimci-kutu" value="<?= $k['id'] ?>">
                        <?= avatar($k, 22) ?> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($k['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">İlgili Dosya</label><select name="client_id" id="tp_client" class="secim"><option value="">— Ajans içi</option><?php foreach ($clientsList as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Toplantının hangi marka/müşteriyle ilgili olduğu.</div></div>
                <div class="form-grup"><label class="form-etiket">Proje (opsiyonel)</label><select name="project_id" id="tp_project" class="secim"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" data-client="<?= $p['client_id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Gündem / Not</label><textarea name="description" class="metin-alani"></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Planla</button></div>
    </form></div>
</div>
<script>
document.getElementById('meetingForm').addEventListener('submit', () => {
    document.getElementById('t_participants').value = JSON.stringify(Array.from(document.querySelectorAll('.participant-box:checked')).map(c => c.value));
});
// Auto-fill the client when a project is selected
document.getElementById('tp_project').addEventListener('change', function () {
    const client = this.selectedOptions[0]?.dataset.client;
    if (client) document.getElementById('tp_client').value = client;
});
</script>
<?php page_end(); ?>

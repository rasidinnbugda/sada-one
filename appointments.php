<?php
/**
 * SADA One — Appointment Requests
 * Customer: creates a request and tracks its status. Team: approves / suggests an alternative time / rejects.
 * An approved appointment is automatically added to the meeting calendar.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();
if (is_intern()) { header('Location: index.php'); exit; }

if (is_customer()) {
    $appointments = rows("SELECT r.*, d.name client_name FROM appointments r LEFT JOIN clients d ON d.id=r.client_id WHERE r.customer_id=? ORDER BY r.id DESC", [$u['id']]);
    [$in, $p] = in_clause(customer_client_ids());
    $my_clients = rows("SELECT id, name FROM clients WHERE id IN $in ORDER BY name", $p);
} else {
    $appointments = rows("SELECT r.*, d.name client_name, us.name customer_name, us.color customer_color, us.avatar customer_avatar
        FROM appointments r LEFT JOIN clients d ON d.id=r.client_id JOIN users us ON us.id=r.customer_id
        ORDER BY FIELD(r.status,'bekliyor','alternatif','onaylandi','reddedildi'), r.date DESC");
}
$pendingCount = count(array_filter($appointments, fn($r) => $r['status'] === 'bekliyor'));

page_start('Randevular', 'appointments');
?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik"><?= is_customer() ? 'Randevu Taleplerim' : 'Randevu Talepleri' ?></div>
        <div class="sayfa-alt"><?= is_customer() ? 'Ajansla görüşme talebi oluşturun; onaylanınca takviminize düşer' : $pendingCount . ' bekleyen talep — onaylananlar toplantı takvimine eklenir' ?></div>
    </div>
    <?php if (is_customer()): ?>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalAppointment"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Randevu Talep Et</button></div>
    <?php endif; ?>
</div>

<?php if (!$appointments): ?>
<div class="bos-durum">
    <div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm7-6l2 2 4-4"/></svg></div>
    <div class="bos-baslik">Randevu talebi yok</div>
    <div class="bos-metin"><?= is_customer() ? 'Görüşmek istediğiniz konu ve size uygun saati iletin, en kısa sürede dönüş yapalım.' : 'Müşterilerden gelen randevu talepleri burada görünür.' ?></div>
    <?php if (is_customer()): ?><button class="btn btn-marka" data-modal="modalAppointment">Randevu Talep Et</button><?php endif; ?>
</div>
<?php else: foreach ($appointments as $r): ?>
<div class="kart mb-2" style="<?= $r['status'] === 'bekliyor' ? 'border-color:var(--warning)' : '' ?>">
    <div class="satir-esnek arasi sarma" style="gap:14px;align-items:flex-start">
        <div style="min-width:0;flex:1">
            <div class="satir-esnek sarma" style="gap:8px">
                <span class="kalin"><?= e($r['topic']) ?></span>
                <?= badge($r['status'], RANDEVU_DURUMLARI) ?>
                <?php if ($r['online_request']): ?><span class="rozet rozet-tur"><?= icon('video', 12) ?> Online</span><?php endif; ?>
            </div>
            <div class="hucre-alt mt-1">
                <?php if (!is_customer()): ?><?= e($r['customer_name']) ?> · <?php endif; ?>
                <?= $r['client_name'] ? e($r['client_name']) . ' · ' : '' ?>
                <b style="color:var(--text)"><?= format_date($r['date'], true) ?></b>
                <?php if ($r['status'] === 'alternatif' && $r['alternative_date']): ?> → önerilen: <b style="color:var(--uyari)"><?= format_date($r['alternative_date'], true) ?></b><?php endif; ?>
            </div>
            <?php if ($r['notes']): ?><div class="kucuk metin-2 mt-1"><?= nl2br(e($r['notes'])) ?></div><?php endif; ?>
            <?php if ($r['reply_note']): ?><div class="mt-2 kucuk" style="padding:8px 12px;background:var(--surface-2);border-radius:9px"><b>Ajans notu:</b> <?= e($r['reply_note']) ?></div><?php endif; ?>
            <?php if ($r['status'] === 'onaylandi' && $r['online_link']): ?>
            <a href="<?= e($r['online_link']) ?>" target="_blank" class="btn btn-marka btn-sm mt-2"><?= icon('video', 14) ?> Toplantıya Katıl</a>
            <?php endif; ?>
        </div>

        <?php if (is_pm() && $r['status'] === 'bekliyor'): ?>
        <div class="dikey" style="gap:6px;flex-shrink:0;min-width:150px">
            <button class="btn btn-marka btn-sm btn-blok" onclick="appointmentApprove(<?= $r['id'] ?>)">✓ Onayla</button>
            <button class="btn btn-sm btn-blok" onclick="appointmentAlternative(<?= $r['id'] ?>)"><?= icon('repeat', 13) ?> Farklı Saat Öner</button>
            <button class="btn btn-tehlike btn-sm btn-blok" onclick="appointmentReject(<?= $r['id'] ?>)">✕ Reddet</button>
        </div>
        <?php elseif (is_customer() && $r['status'] === 'alternatif'): ?>
        <div class="dikey" style="gap:6px;flex-shrink:0">
            <button class="btn btn-marka btn-sm" data-action="appointment_accept" data-id="<?= $r['id'] ?>">✓ Önerilen Saati Kabul Et</button>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; endif; ?>

<?php if (is_customer()): ?>
<!-- Appointment request modal -->
<div class="modal-katman" id="modalAppointment">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Randevu Talep Et</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="appointment_create">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Konu <span class="zorunlu">*</span></label><input name="topic" class="girdi" required placeholder="Örn. Ekim kampanyası planlaması"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tercih Ettiğiniz Tarih/Saat <span class="zorunlu">*</span></label><input type="datetime-local" name="date" class="girdi" required min="<?= date('Y-m-d\TH:i') ?>"></div>
                <?php if (count($my_clients) > 1): ?>
                <div class="form-grup"><label class="form-etiket">İlgili Dosya</label><select name="client_id" class="secim"><?php foreach ($my_clients as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <?php else: ?><input type="hidden" name="client_id" value="<?= $my_clients[0]['id'] ?? '' ?>"><?php endif; ?>
            </div>
            <div class="form-grup"><label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="online_request" value="1" checked> <span class="kucuk"><b>Online görüşme tercih ederim</b> (Meet/Zoom linki tarafımıza iletilir)</span></label></div>
            <div class="form-grup"><label class="form-etiket">Not</label><textarea name="notes" class="metin-alani" placeholder="Görüşmek istediğiniz detaylar..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Talebi Gönder</button></div>
    </form></div>
</div>
<?php endif; ?>

<?php if (is_pm()): ?>
<!-- Approve modal -->
<div class="modal-katman" id="modalROnay">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Randevuyu Onayla</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="appointment_respond">
        <input type="hidden" name="id" id="ro_id"><input type="hidden" name="operation" value="onayla">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Online Toplantı Linki</label><input name="online_link" class="girdi" placeholder="Meet/Zoom linki (online istekse)"><div class="form-ipucu">Girilirse müşteri "Katıl" butonunu görür.</div></div>
            <div class="form-grup"><label class="form-etiket">Not</label><input name="not" class="girdi" placeholder="Opsiyonel"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Onayla & Takvime Ekle</button></div>
    </form></div>
</div>
<!-- Alternative time modal -->
<div class="modal-katman" id="modalRAlt">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Farklı Saat Öner</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="appointment_respond">
        <input type="hidden" name="id" id="ra_id"><input type="hidden" name="operation" value="alternatif">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Önerilen Tarih/Saat <span class="zorunlu">*</span></label><input type="datetime-local" name="alternative_date" class="girdi" required min="<?= date('Y-m-d\TH:i') ?>"></div>
            <div class="form-grup"><label class="form-etiket">Not</label><input name="not" class="girdi" placeholder="Örn. o saatte çekimdeyiz, bu saat uygun mu?"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Öner</button></div>
    </form></div>
</div>
<!-- Reject modal -->
<div class="modal-katman" id="modalRRed">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Talebi Reddet</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="appointment_respond">
        <input type="hidden" name="id" id="rr_id"><input type="hidden" name="operation" value="reddet">
        <div class="modal-govde"><div class="form-grup"><label class="form-etiket">Neden <span class="zorunlu">*</span></label><input name="not" class="girdi" required placeholder="Müşteriye iletilecek açıklama"></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-tehlike">Reddet</button></div>
    </form></div>
</div>
<script>
function appointmentApprove(id) { document.getElementById('ro_id').value = id; modalOpen('modalROnay'); }
function appointmentAlternative(id) { document.getElementById('ra_id').value = id; modalOpen('modalRAlt'); }
function appointmentReject(id) { document.getElementById('rr_id').value = id; modalOpen('modalRRed'); }
</script>
<?php endif; ?>
<?php page_end(); ?>

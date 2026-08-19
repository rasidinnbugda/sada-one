<?php
/**
 * SADA One — Randevu Talepleri
 * Müşteri: talep oluşturur ve durumunu izler. Ekip: onaylar / alternatif saat önerir / reddeder.
 * Onaylanan randevu otomatik olarak toplantı takvimine düşer.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();
if (is_stajyer()) { header('Location: index.php'); exit; }

if (is_musteri()) {
    $randevular = rows("SELECT r.*, d.ad dosya_ad FROM randevular r LEFT JOIN dosyalar d ON d.id=r.dosya_id WHERE r.musteri_id=? ORDER BY r.id DESC", [$u['id']]);
    [$in, $p] = in_sorgu(musteri_dosya_idler());
    $dosyalarim = rows("SELECT id, ad FROM dosyalar WHERE id IN $in ORDER BY ad", $p);
} else {
    $randevular = rows("SELECT r.*, d.ad dosya_ad, us.ad musteri_ad, us.renk musteri_renk, us.avatar musteri_avatar
        FROM randevular r LEFT JOIN dosyalar d ON d.id=r.dosya_id JOIN users us ON us.id=r.musteri_id
        ORDER BY FIELD(r.durum,'bekliyor','alternatif','onaylandi','reddedildi'), r.tarih DESC");
}
$bekleyenSayi = count(array_filter($randevular, fn($r) => $r['durum'] === 'bekliyor'));

sayfa_basi('Randevular', 'randevular');
?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik"><?= is_musteri() ? 'Randevu Taleplerim' : 'Randevu Talepleri' ?></div>
        <div class="sayfa-alt"><?= is_musteri() ? 'Ajansla görüşme talebi oluşturun; onaylanınca takviminize düşer' : $bekleyenSayi . ' bekleyen talep — onaylananlar toplantı takvimine eklenir' ?></div>
    </div>
    <?php if (is_musteri()): ?>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalRandevu"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Randevu Talep Et</button></div>
    <?php endif; ?>
</div>

<?php if (!$randevular): ?>
<div class="bos-durum">
    <div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm7-6l2 2 4-4"/></svg></div>
    <div class="bos-baslik">Randevu talebi yok</div>
    <div class="bos-metin"><?= is_musteri() ? 'Görüşmek istediğiniz konu ve size uygun saati iletin, en kısa sürede dönüş yapalım.' : 'Müşterilerden gelen randevu talepleri burada görünür.' ?></div>
    <?php if (is_musteri()): ?><button class="btn btn-marka" data-modal="modalRandevu">Randevu Talep Et</button><?php endif; ?>
</div>
<?php else: foreach ($randevular as $r): ?>
<div class="kart mb-2" style="<?= $r['durum'] === 'bekliyor' ? 'border-color:var(--uyari)' : '' ?>">
    <div class="satir-esnek arasi sarma" style="gap:14px;align-items:flex-start">
        <div style="min-width:0;flex:1">
            <div class="satir-esnek sarma" style="gap:8px">
                <span class="kalin"><?= e($r['konu']) ?></span>
                <?= rozet($r['durum'], RANDEVU_DURUMLARI) ?>
                <?php if ($r['online_istek']): ?><span class="rozet rozet-tur"><?= ikon('video', 12) ?> Online</span><?php endif; ?>
            </div>
            <div class="hucre-alt mt-1">
                <?php if (!is_musteri()): ?><?= e($r['musteri_ad']) ?> · <?php endif; ?>
                <?= $r['dosya_ad'] ? e($r['dosya_ad']) . ' · ' : '' ?>
                <b style="color:var(--text)"><?= tarih($r['tarih'], true) ?></b>
                <?php if ($r['durum'] === 'alternatif' && $r['alternatif_tarih']): ?> → önerilen: <b style="color:var(--uyari)"><?= tarih($r['alternatif_tarih'], true) ?></b><?php endif; ?>
            </div>
            <?php if ($r['notlar']): ?><div class="kucuk metin-2 mt-1"><?= nl2br(e($r['notlar'])) ?></div><?php endif; ?>
            <?php if ($r['cevap_notu']): ?><div class="mt-2 kucuk" style="padding:8px 12px;background:var(--surface-2);border-radius:9px"><b>Ajans notu:</b> <?= e($r['cevap_notu']) ?></div><?php endif; ?>
            <?php if ($r['durum'] === 'onaylandi' && $r['online_link']): ?>
            <a href="<?= e($r['online_link']) ?>" target="_blank" class="btn btn-marka btn-sm mt-2"><?= ikon('video', 14) ?> Toplantıya Katıl</a>
            <?php endif; ?>
        </div>

        <?php if (is_pm() && $r['durum'] === 'bekliyor'): ?>
        <div class="dikey" style="gap:6px;flex-shrink:0;min-width:150px">
            <button class="btn btn-marka btn-sm btn-blok" onclick="randevuOnayla(<?= $r['id'] ?>)">✓ Onayla</button>
            <button class="btn btn-sm btn-blok" onclick="randevuAlternatif(<?= $r['id'] ?>)"><?= ikon('tekrar', 13) ?> Farklı Saat Öner</button>
            <button class="btn btn-tehlike btn-sm btn-blok" onclick="randevuReddet(<?= $r['id'] ?>)">✕ Reddet</button>
        </div>
        <?php elseif (is_musteri() && $r['durum'] === 'alternatif'): ?>
        <div class="dikey" style="gap:6px;flex-shrink:0">
            <button class="btn btn-marka btn-sm" data-eylem="randevu_kabul" data-id="<?= $r['id'] ?>">✓ Önerilen Saati Kabul Et</button>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; endif; ?>

<?php if (is_musteri()): ?>
<!-- Randevu talep modalı -->
<div class="modal-katman" id="modalRandevu">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Randevu Talep Et</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="randevu_olustur">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Konu <span class="zorunlu">*</span></label><input name="konu" class="girdi" required placeholder="Örn. Ekim kampanyası planlaması"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tercih Ettiğiniz Tarih/Saat <span class="zorunlu">*</span></label><input type="datetime-local" name="tarih" class="girdi" required min="<?= date('Y-m-d\TH:i') ?>"></div>
                <?php if (count($dosyalarim) > 1): ?>
                <div class="form-grup"><label class="form-etiket">İlgili Dosya</label><select name="dosya_id" class="secim"><?php foreach ($dosyalarim as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['ad']) ?></option><?php endforeach; ?></select></div>
                <?php else: ?><input type="hidden" name="dosya_id" value="<?= $dosyalarim[0]['id'] ?? '' ?>"><?php endif; ?>
            </div>
            <div class="form-grup"><label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="online_istek" value="1" checked> <span class="kucuk"><b>Online görüşme tercih ederim</b> (Meet/Zoom linki tarafımıza iletilir)</span></label></div>
            <div class="form-grup"><label class="form-etiket">Not</label><textarea name="notlar" class="metin-alani" placeholder="Görüşmek istediğiniz detaylar..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Talebi Gönder</button></div>
    </form></div>
</div>
<?php endif; ?>

<?php if (is_pm()): ?>
<!-- Onayla modalı -->
<div class="modal-katman" id="modalROnay">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Randevuyu Onayla</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="randevu_cevapla">
        <input type="hidden" name="id" id="ro_id"><input type="hidden" name="islem" value="onayla">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Online Toplantı Linki</label><input name="online_link" class="girdi" placeholder="Meet/Zoom linki (online istekse)"><div class="form-ipucu">Girilirse müşteri "Katıl" butonunu görür.</div></div>
            <div class="form-grup"><label class="form-etiket">Not</label><input name="not" class="girdi" placeholder="Opsiyonel"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Onayla & Takvime Ekle</button></div>
    </form></div>
</div>
<!-- Alternatif saat modalı -->
<div class="modal-katman" id="modalRAlt">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Farklı Saat Öner</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="randevu_cevapla">
        <input type="hidden" name="id" id="ra_id"><input type="hidden" name="islem" value="alternatif">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Önerilen Tarih/Saat <span class="zorunlu">*</span></label><input type="datetime-local" name="alternatif_tarih" class="girdi" required min="<?= date('Y-m-d\TH:i') ?>"></div>
            <div class="form-grup"><label class="form-etiket">Not</label><input name="not" class="girdi" placeholder="Örn. o saatte çekimdeyiz, bu saat uygun mu?"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Öner</button></div>
    </form></div>
</div>
<!-- Reddet modalı -->
<div class="modal-katman" id="modalRRed">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Talebi Reddet</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="randevu_cevapla">
        <input type="hidden" name="id" id="rr_id"><input type="hidden" name="islem" value="reddet">
        <div class="modal-govde"><div class="form-grup"><label class="form-etiket">Neden <span class="zorunlu">*</span></label><input name="not" class="girdi" required placeholder="Müşteriye iletilecek açıklama"></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-tehlike">Reddet</button></div>
    </form></div>
</div>
<script>
function randevuOnayla(id) { document.getElementById('ro_id').value = id; modalAc('modalROnay'); }
function randevuAlternatif(id) { document.getElementById('ra_id').value = id; modalAc('modalRAlt'); }
function randevuReddet(id) { document.getElementById('rr_id').value = id; modalAc('modalRRed'); }
</script>
<?php endif; ?>
<?php sayfa_sonu(); ?>

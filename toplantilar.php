<?php
/**
 * SADA One — Toplantı Takvimi
 * Katılımcı seçimi, online toplantı linki ve hatırlatma bildirimli toplantı yönetimi.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$yil = (int)($_GET['yil'] ?? date('Y'));
$ay = (int)($_GET['ay'] ?? date('n'));
if ($ay < 1) { $ay = 12; $yil--; } if ($ay > 12) { $ay = 1; $yil++; }
$ayBas = sprintf('%04d-%02d-01', $yil, $ay);
$aySon = date('Y-m-t', strtotime($ayBas));

$toplantilar = rows("SELECT e.*, p.ad proje_ad, us.ad olusturan_ad FROM etkinlikler e
    LEFT JOIN projeler p ON p.id=e.proje_id LEFT JOIN users us ON us.id=e.olusturan_id
    WHERE e.tur='toplanti' AND DATE(e.baslangic) BETWEEN ? AND ? ORDER BY e.baslangic", [$ayBas, $aySon]);

// Katılımcıları yükle
foreach ($toplantilar as &$t) {
    $t['katilimci_listesi'] = rows("SELECT us.id, us.ad, us.renk, us.avatar FROM etkinlik_katilimcilari ek JOIN users us ON us.id=ek.user_id WHERE ek.etkinlik_id=? ORDER BY us.ad", [$t['id']]);
}
unset($t);

// Güne grupla
$gunler = [];
foreach ($toplantilar as $t) $gunler[substr($t['baslangic'], 0, 10)][] = $t;

$ekip = rows("SELECT id, ad, renk, avatar FROM users WHERE rol IN ('yonetici','pm','ekip','finans','stajyer') AND aktif=1 ORDER BY ad");
$projeler = rows("SELECT id, ad, dosya_id FROM projeler WHERE durum='aktif' ORDER BY ad");
$dosyalarListe = rows("SELECT id, ad FROM dosyalar WHERE durum='aktif' ORDER BY ad");

sayfa_basi('Toplantı Takvimi', 'toplantilar');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Toplantı Takvimi</div><div class="sayfa-alt">Katılımcılı ve linkli toplantılar — başlamadan ~1 saat önce hatırlatılır</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalToplanti"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Toplantı Planla</button></div>
</div>

<div class="takvim-baslik-bar">
    <div class="satir-esnek" style="gap:8px">
        <a href="?ay=<?= $ay - 1 ?>&yil=<?= $yil ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
        <div class="takvim-ay-ad"><?= AYLAR[$ay] ?> <?= $yil ?></div>
        <a href="?ay=<?= $ay + 1 ?>&yil=<?= $yil ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>
    </div>
    <a href="?ay=<?= date('n') ?>&yil=<?= date('Y') ?>" class="btn btn-sm">Bu Ay</a>
</div>

<?php if (!$toplantilar): ?>
<div class="bos-durum">
    <div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg></div>
    <div class="bos-baslik">Bu ay toplantı yok</div>
    <div class="bos-metin">Toplantı planlayın, katılımcılar otomatik bilgilendirilsin.</div>
    <button class="btn btn-marka" data-modal="modalToplanti">Toplantı Planla</button>
</div>
<?php else: foreach ($gunler as $gunTarih => $gunToplantilari):
    $bugunMu = $gunTarih === date('Y-m-d');
    $gecmis = $gunTarih < date('Y-m-d'); ?>
<div class="nav-bolum" style="padding:16px 0 8px;<?= $bugunMu ? 'color:var(--marka)' : '' ?>">
    <?= $bugunMu ? 'BUGÜN — ' : '' ?><?= GUNLER[(int)date('N', strtotime($gunTarih)) - 1] ?>, <?= tarih($gunTarih) ?>
</div>
<div class="izgara izgara-2">
    <?php foreach ($gunToplantilari as $t):
        $basladi = strtotime($t['baslangic']) <= time() && (!$t['bitis'] || strtotime($t['bitis']) >= time()); ?>
    <div class="kart" style="padding:16px;<?= $gecmis ? 'opacity:.6' : '' ?><?= $basladi ? 'border-color:var(--marka)' : '' ?>">
        <div class="satir-esnek arasi" style="align-items:flex-start">
            <div style="min-width:0">
                <div class="satir-esnek sarma" style="gap:8px">
                    <span class="kalin"><?= e($t['baslik']) ?></span>
                    <?php if ($basladi): ?><span class="rozet r-devam">● Şu an</span><?php endif; ?>
                    <?php if ($t['online_link']): ?><span class="rozet rozet-tur"><?= ikon('video', 12) ?> Online</span><?php endif; ?>
                </div>
                <div class="hucre-alt mt-1">
                    <?= date('H:i', strtotime($t['baslangic'])) ?><?= $t['bitis'] ? ' – ' . date('H:i', strtotime($t['bitis'])) : '' ?>
                    <?= $t['yer'] ? ' · ' . e($t['yer']) : '' ?>
                    <?= $t['proje_ad'] ? ' · ' . e($t['proje_ad']) : '' ?>
                </div>
            </div>
            <button class="ikon-eylem tehlike" data-eylem="etkinlik_sil" data-id="<?= $t['id'] ?>" data-onay="Toplantı silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button>
        </div>
        <?php if ($t['aciklama']): ?><div class="kucuk metin-2 mt-2"><?= nl2br(e(mb_substr($t['aciklama'], 0, 200))) ?></div><?php endif; ?>
        <div class="satir-esnek arasi mt-2 sarma" style="gap:10px">
            <div class="satir-esnek" style="gap:8px">
                <?php if ($t['katilimci_listesi']): ?>
                <span class="avatar-dizi"><?php foreach (array_slice($t['katilimci_listesi'], 0, 6) as $ktl) echo avatar($ktl, 26); ?></span>
                <span class="hucre-alt"><?= count($t['katilimci_listesi']) ?> katılımcı</span>
                <?php else: ?><span class="hucre-alt">Katılımcı eklenmemiş</span><?php endif; ?>
            </div>
            <?php if ($t['online_link']): ?>
            <a href="<?= e($t['online_link']) ?>" target="_blank" class="btn btn-marka btn-sm"><?= ikon('video', 14) ?> Toplantıya Katıl</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; endif; ?>

<!-- Toplantı planla -->
<div class="modal-katman" id="modalToplanti">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik">Toplantı Planla</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="etkinlik_kaydet" data-yenile="evet" id="toplantiForm">
        <input type="hidden" name="tur" value="toplanti">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Konu <span class="zorunlu">*</span></label><input name="baslik" class="girdi" required placeholder="Örn. Haftalık planlama toplantısı"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Başlangıç <span class="zorunlu">*</span></label><input type="datetime-local" name="baslangic" class="girdi" required></div>
                <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="datetime-local" name="bitis" class="girdi"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Online Toplantı Linki</label><input name="online_link" class="girdi" placeholder="Meet/Zoom linki — girilirse 'Katıl' butonu görünür"></div>
                <div class="form-grup"><label class="form-etiket">Yer (fiziksel ise)</label><input name="yer" class="girdi" placeholder="Örn. Ofis toplantı odası"></div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Katılımcılar <span class="metin-muted" style="font-weight:400">(seçilenlere davet bildirimi gider)</span></label>
                <input type="hidden" name="katilimci_idler" id="t_katilimcilar">
                <div class="izgara izgara-2" style="gap:6px;max-height:170px;overflow-y:auto;padding:2px">
                    <?php foreach ($ekip as $k): if ($k['id'] == $u['id']) continue; ?>
                    <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="katilimci-kutu" value="<?= $k['id'] ?>">
                        <?= avatar($k, 22) ?> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($k['ad']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">İlgili Dosya</label><select name="dosya_id" id="tp_dosya" class="secim"><option value="">— Ajans içi</option><?php foreach ($dosyalarListe as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['ad']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Toplantının hangi marka/müşteriyle ilgili olduğu.</div></div>
                <div class="form-grup"><label class="form-etiket">Proje (opsiyonel)</label><select name="proje_id" id="tp_proje" class="secim"><option value="">—</option><?php foreach ($projeler as $p): ?><option value="<?= $p['id'] ?>" data-dosya="<?= $p['dosya_id'] ?>"><?= e($p['ad']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Gündem / Not</label><textarea name="aciklama" class="metin-alani"></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Planla</button></div>
    </form></div>
</div>
<script>
document.getElementById('toplantiForm').addEventListener('submit', () => {
    document.getElementById('t_katilimcilar').value = JSON.stringify(Array.from(document.querySelectorAll('.katilimci-kutu:checked')).map(c => c.value));
});
// Proje seçilince dosyası otomatik dolsun
document.getElementById('tp_proje').addEventListener('change', function () {
    const dosya = this.selectedOptions[0]?.dataset.dosya;
    if (dosya) document.getElementById('tp_dosya').value = dosya;
});
</script>
<?php sayfa_sonu(); ?>

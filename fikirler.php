<?php
/**
 * SADA One — Fikir Panosu
 * Ekip üyelerinin birbirine içerik fikri önerdiği pano: fikir · uyarlanabilecek kurum · açıklama
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$fikirler = rows("SELECT f.*, uu.ad oneren_ad, uu.renk oneren_renk, uu.avatar oneren_avatar
    FROM fikirler f JOIN users uu ON uu.id=f.oneren_id ORDER BY FIELD(f.durum,'yeni','begenildi','uygulandi'), f.id DESC");
$yonetir = is_admin() || $u['rol'] === 'pm';
$FDURUM = ['yeni' => ['Yeni', 'r-bekliyor'], 'begenildi' => ['Beğenildi', 'r-devam'], 'uygulandi' => ['Uygulandı', 'r-tamamlandi']];

sayfa_basi('Fikir Panosu', 'fikirler');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Fikir Panosu</div><div class="sayfa-alt">İçerik fikirleri — hangi kuruma uyarlanabilir, nasıl uygulanır</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalFikir"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Fikir Öner</button></div>
</div>

<?php if (!$fikirler): ?>
<div class="bos-durum">
    <div class="bos-ikon">💡</div>
    <div class="bos-baslik">Pano boş</div>
    <div class="bos-metin">Aklınıza gelen içerik fikrini paylaşın — hangi kuruma uyarlanabileceğini de yazın, ekip değerlendirsin.</div>
    <button class="btn btn-marka" data-modal="modalFikir">İlk Fikri Öner</button>
</div>
<?php else: ?>
<div class="izgara izgara-3">
    <?php foreach ($fikirler as $f): [$fEtiket, $fSinif] = $FDURUM[$f['durum']]; ?>
    <div class="kart" style="display:flex;flex-direction:column">
        <div class="satir-esnek arasi mb-2">
            <span class="rozet <?= $fSinif ?>"><?= $fEtiket ?></span>
            <div class="satir-esnek" style="gap:4px">
                <?php if ($yonetir && $f['durum'] !== 'uygulandi'): ?>
                <button class="mini-btn" title="Durum ilerlet" onclick="fikirIlerlet(<?= $f['id'] ?>, '<?= $f['durum'] === 'yeni' ? 'begenildi' : 'uygulandi' ?>')"><?= $f['durum'] === 'yeni' ? '👍 Beğen' : '✅ Uygulandı' ?></button>
                <?php endif; ?>
                <?php if (is_admin() || $f['oneren_id'] == $u['id']): ?>
                <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-eylem="fikir_sil" data-id="<?= $f['id'] ?>" data-onay="Fikir silinsin mi?"><?= ikon('cop', 13) ?></button>
                <?php endif; ?>
            </div>
        </div>
        <div class="kalin mb-1" style="font-size:15px;line-height:1.45"><?= e($f['fikir']) ?></div>
        <?php if ($f['kurum']): ?><div class="kucuk mb-1"><span class="metin-muted">Uyarlanabilecek kurum:</span> <b><?= e($f['kurum']) ?></b></div><?php endif; ?>
        <?php if ($f['aciklama']): ?><div class="kucuk metin-2 mb-2" style="white-space:pre-wrap"><?= e($f['aciklama']) ?></div><?php endif; ?>
        <div class="satir-esnek mt-auto" style="gap:8px;padding-top:10px;border-top:1px solid var(--border)">
            <?= avatar(['ad' => $f['oneren_ad'], 'renk' => $f['oneren_renk'], 'avatar' => $f['oneren_avatar']], 24) ?>
            <span class="kucuk metin-muted"><?= e($f['oneren_ad']) ?> · <?= zaman_once($f['created']) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalFikir">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Fikir Öner</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="fikir_kaydet">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Fikir <span class="zorunlu">*</span></label><input name="fikir" class="girdi" required placeholder="Örn. Kurum çalışanlarıyla 'bir günüm' reels serisi"></div>
            <div class="form-grup"><label class="form-etiket">Uyarlanabilecek Kurum</label><input name="kurum" class="girdi" placeholder="Hangi müşteri/dosya için uygun olur?"></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="aciklama" class="metin-alani" placeholder="Nasıl uygulanır, neden işe yarar..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Panoya Ekle</button></div>
    </form></div>
</div>

<script>
async function fikirIlerlet(id, durum) {
    const j = await api('fikir_durum', { id, durum });
    if (j.ok) location.reload();
}
</script>
<?php sayfa_sonu(); ?>

<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bilesenler.php';
$u = require_login();

// Müşterinin verdiği puanlar (onay bazında)
$verilenPuanlar = is_musteri()
    ? array_column(rows("SELECT ref_id, puan FROM puanlar WHERE ref_tur='onay' AND user_id=?", [$u['id']]), 'puan', 'ref_id')
    : [];

if (is_staff()) {
    $onaylar = rows("SELECT o.*, p.ad proje_ad, d.ad dosya_ad, ug.ad gonderen_ad FROM onaylar o JOIN projeler p ON p.id=o.proje_id JOIN dosyalar d ON d.id=p.dosya_id LEFT JOIN users ug ON ug.id=o.gonderen_id ORDER BY FIELD(o.durum,'bekliyor','revize','onaylandi','reddedildi'), o.id DESC");
} else {
    [$in, $p] = in_sorgu(musteri_dosya_idler());
    $onaylar = rows("SELECT o.*, p.ad proje_ad, d.ad dosya_ad, ug.ad gonderen_ad FROM onaylar o JOIN projeler p ON p.id=o.proje_id JOIN dosyalar d ON d.id=p.dosya_id LEFT JOIN users ug ON ug.id=o.gonderen_id WHERE p.dosya_id IN $in ORDER BY FIELD(o.durum,'bekliyor','revize','onaylandi','reddedildi'), o.id DESC", $p);
}

sayfa_basi('Onaylar', 'onaylar');
?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik">Onay Süreçleri</div>
        <div class="sayfa-alt"><?= is_musteri() ? 'Onayınızı bekleyen içerikler' : 'Tüm projelerdeki onay süreçleri' ?></div>
    </div>
</div>

<div class="filtre-bar">
    <div class="pill-filtre" data-pill-grup="#onayListe .onay-kart">
        <button class="pill aktif" data-deger="">Tümü</button>
        <?php foreach (ONAY_DURUMLARI as $k => $v): ?><button class="pill" data-deger="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$onaylar): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="bos-baslik">Onay süreci yok</div><div class="bos-metin"><?= is_musteri() ? 'Şu an onayınızı bekleyen bir içerik bulunmuyor.' : 'Henüz onaya gönderilmiş bir içerik yok.' ?></div></div>
<?php else: ?>
<div id="onayListe">
<?php foreach ($onaylar as $o):
    $ar = $o['arsiv_id'] ? row("SELECT * FROM arsiv WHERE id=?", [$o['arsiv_id']]) : null;
    $gorsel = $ar && in_array($ar['uzanti'], ['jpg', 'jpeg', 'png', 'gif', 'webp']); ?>
<div class="kart mb-2 onay-kart" data-filtre="<?= $o['durum'] ?>">
    <div class="satir-esnek arasi sarma" style="gap:16px;align-items:flex-start">
        <div style="flex:1;min-width:0">
            <div class="satir-esnek sarma" style="gap:9px"><span class="kalin"><?= e($o['baslik']) ?></span><?= rozet($o['durum'], ONAY_DURUMLARI) ?></div>
            <div class="hucre-alt mt-1"><?= e($o['dosya_ad']) ?> · <?= e($o['proje_ad']) ?> · <?= e($o['gonderen_ad']) ?> tarafından <?= zaman_once($o['created']) ?></div>
            <?php if ($o['aciklama']): ?><div class="metin-2 kucuk mt-2"><?= nl2br(e($o['aciklama'])) ?></div><?php endif; ?>
            <?php if ($o['drive_link']): ?><a href="<?= e($o['drive_link']) ?>" target="_blank" class="btn btn-sm mt-2" style="margin-right:6px"><?= ikon('web', 13) ?> Drive'da Görüntüle</a><?php endif; ?>
            <?php if ($ar): ?>
            <div class="mt-2">
                <?php if ($gorsel): ?><a href="uploads/<?= e($ar['dosya_yolu']) ?>" target="_blank"><img src="uploads/<?= e($ar['dosya_yolu']) ?>" style="max-width:280px;max-height:200px;border-radius:12px;border:1px solid var(--border)"></a>
                <?php else: ?><a href="uploads/<?= e($ar['dosya_yolu']) ?>" target="_blank" class="btn btn-sm"><?= ikon('atac', 13) ?> <?= e($ar['ad']) ?></a><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($o['cevap_notu']): ?><div class="mt-2" style="padding:10px 14px;background:var(--surface-2);border-radius:10px;font-size:13px"><b>Not:</b> <?= nl2br(e($o['cevap_notu'])) ?> <span class="hucre-alt">— <?= tarih($o['cevap_tarih']) ?></span></div><?php endif; ?>
        </div>
        <?php if ($o['durum'] === 'bekliyor' && (is_musteri() || is_admin())): ?>
        <div class="dikey" style="gap:8px;flex-shrink:0;min-width:130px">
            <button class="btn btn-marka btn-sm btn-blok" style="background:var(--basari);color:#fff" data-eylem="onay_cevap" data-id="<?= $o['id'] ?>" data-durum="onaylandi">✓ Onayla</button>
            <button class="btn btn-sm btn-blok" onclick="onayNot(<?= $o['id'] ?>,'revize')">↻ Revize İste</button>
            <button class="btn btn-tehlike btn-sm btn-blok" onclick="onayNot(<?= $o['id'] ?>,'reddedildi')">✕ Reddet</button>
        </div>
        <?php elseif ($o['durum'] === 'bekliyor' && is_staff()): ?>
        <span class="rozet r-bekliyor" style="flex-shrink:0">Müşteri onayı bekleniyor</span>
        <?php elseif ($o['durum'] === 'onaylandi' && is_musteri()): ?>
        <div style="flex-shrink:0">
            <?php if (isset($verilenPuanlar[$o['id']])): ?>
            <button class="btn btn-sm" onclick="puanVer('onay', <?= $o['id'] ?>, '<?= e($o['baslik']) ?>')" title="Puanı güncelle"><?= yildizlar((float)$verilenPuanlar[$o['id']], 13) ?></button>
            <?php else: ?>
            <button class="btn btn-marka btn-sm" onclick="puanVer('onay', <?= $o['id'] ?>, '<?= e($o['baslik']) ?>')"><?= ikon('yildiz', 13) ?> Değerlendir</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalOnayNot">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="onayNotBaslik">Not Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="onay_cevap">
        <input type="hidden" name="id" id="onayNotId"><input type="hidden" name="durum" id="onayNotDurum">
        <div class="modal-govde"><div class="form-grup"><label class="form-etiket">Notunuz <span class="zorunlu">*</span></label><textarea name="not" class="metin-alani" required placeholder="Değişiklik taleplerinizi veya nedeninizi yazın..."></textarea></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Gönder</button></div>
    </form></div>
</div>
<?php puan_modali(); ?>
<script>
function onayNot(id, durum) {
    document.getElementById('onayNotId').value = id; document.getElementById('onayNotDurum').value = durum;
    document.getElementById('onayNotBaslik').textContent = durum === 'revize' ? 'Revize Talebi' : 'Reddetme Nedeni';
    modalAc('modalOnayNot');
}
</script>
<?php sayfa_sonu(); ?>

<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$dosyaFiltre = (int)($_GET['dosya'] ?? 0);

if (is_staff()) {
    $kosul = $dosyaFiltre ? "a.dosya_id=$dosyaFiltre OR p.dosya_id=$dosyaFiltre" : "1=1";
    $arsivler = rows("SELECT a.*, us.ad yukleyen_ad, p.ad proje_ad, d.ad dosya_ad FROM arsiv a LEFT JOIN users us ON us.id=a.yukleyen_id LEFT JOIN projeler p ON p.id=a.proje_id LEFT JOIN dosyalar d ON d.id=COALESCE(a.dosya_id, p.dosya_id) WHERE $kosul ORDER BY a.id DESC");
    $dosyalar = rows("SELECT id, ad FROM dosyalar ORDER BY ad");
} else {
    [$in, $p] = in_sorgu(musteri_dosya_idler());
    $arsivler = rows("SELECT a.*, us.ad yukleyen_ad, p.ad proje_ad FROM arsiv a LEFT JOIN users us ON us.id=a.yukleyen_id LEFT JOIN projeler p ON p.id=a.proje_id WHERE p.dosya_id IN $in ORDER BY a.id DESC", $p);
    $dosyalar = [];
}
if (is_staff()) { $projeler = rows("SELECT id, ad FROM projeler WHERE durum='aktif' ORDER BY ad"); }
else { [$in2, $p2] = in_sorgu(musteri_dosya_idler()); $projeler = rows("SELECT id, ad FROM projeler WHERE dosya_id IN $in2 ORDER BY ad", $p2); }

sayfa_basi('Dosya Arşivi', 'arsiv');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Dosya Arşivi</div><div class="sayfa-alt">Logo, brief, tasarım ve medya dosyaları</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalYukle"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 15V3m0 0L8 7m4-4l4 4M3 15v4a2 2 0 002 2h14a2 2 0 002-2v-4"/></svg> Dosya Yükle</button></div>
</div>

<div class="filtre-bar">
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="Dosya ara..." data-arama="#arsivIzgara .arsiv-kart"></div>
    <?php if ($dosyalar): ?>
    <select class="secim" style="max-width:240px" onchange="location.href='?dosya='+this.value">
        <option value="0">Tüm Dosyalar</option>
        <?php foreach ($dosyalar as $d): ?><option value="<?= $d['id'] ?>" <?= $dosyaFiltre == $d['id'] ? 'selected' : '' ?>><?= e($d['ad']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
</div>

<?php if (!$arsivler): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg></div><div class="bos-baslik">Arşiv boş</div><div class="bos-metin">İlk dosyanızı yükleyerek arşivi oluşturmaya başlayın.</div></div>
<?php else: ?>
<div class="izgara izgara-auto" id="arsivIzgara">
    <?php foreach ($arsivler as $a):
        $gorsel = in_array($a['uzanti'], ['jpg', 'jpeg', 'png', 'gif', 'webp']); ?>
    <div class="kart arsiv-kart" data-ara="<?= e($a['ad']) ?>" style="padding:0;overflow:hidden">
        <a href="uploads/<?= e($a['dosya_yolu']) ?>" target="_blank">
            <?php if ($gorsel): ?>
            <div style="height:140px;background:var(--surface-2) url('uploads/<?= e($a['dosya_yolu']) ?>') center/cover"></div>
            <?php else: ?>
            <div style="height:140px;background:var(--surface-2);display:flex;align-items:center;justify-content:center"><div class="dosya-avatar" style="width:56px;height:56px;font-size:16px;background:var(--parlak);color:var(--marka)"><?= e(mb_strtoupper($a['uzanti'] ?: '?')) ?></div></div>
            <?php endif; ?>
        </a>
        <div style="padding:12px">
            <div class="satir-esnek arasi">
                <div style="min-width:0"><div class="hucre-ana kucuk" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($a['ad']) ?></div><div class="hucre-alt"><?= boyut_format($a['boyut']) ?> · <?= e($a['proje_ad'] ?? $a['dosya_ad'] ?? 'Genel') ?></div></div>
                <?php if (is_staff()): ?><button class="ikon-eylem tehlike" data-eylem="arsiv_sil" data-id="<?= $a['id'] ?>" data-onay="Silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalYukle">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Dosya Yükle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="arsiv_yukle" data-yenile="evet">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Dosya <span class="zorunlu">*</span></label><input type="file" name="dosya" class="girdi" required><div class="form-ipucu">Maksimum 50MB. PHP/HTML/script dosyaları kabul edilmez.</div></div>
            <?php if ($dosyalar): ?>
            <div class="form-grup"><label class="form-etiket">İlgili Dosya</label><select name="dosya_id" class="secim"><option value="">— Genel</option><?php foreach ($dosyalar as $d): ?><option value="<?= $d['id'] ?>" <?= $dosyaFiltre == $d['id'] ? 'selected' : '' ?>><?= e($d['ad']) ?></option><?php endforeach; ?></select></div>
            <?php elseif ($dosyaFiltre): ?><input type="hidden" name="dosya_id" value="<?= $dosyaFiltre ?>"><?php endif; ?>
            <div class="form-grup"><label class="form-etiket">Proje (opsiyonel)</label><select name="proje_id" class="secim"><option value="">—</option><?php foreach ($projeler as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['ad']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Yükle</button></div>
    </form></div>
</div>
<?php sayfa_sonu(); ?>

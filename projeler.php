<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bilesenler.php';
$u = require_login();

if (is_staff()) {
    $projeler = rows("SELECT p.*, d.ad dosya_ad, d.renk dosya_renk, uu.ad pm_ad,
        (SELECT COUNT(*) FROM gorevler g WHERE g.proje_id=p.id) gorev_sayi,
        (SELECT COUNT(*) FROM gorevler g WHERE g.proje_id=p.id AND g.durum='tamamlandi') tamam_sayi
        FROM projeler p JOIN dosyalar d ON d.id=p.dosya_id LEFT JOIN users uu ON uu.id=p.pm_id
        ORDER BY p.durum='aktif' DESC, p.created DESC");
} else {
    $projeler = rows("SELECT p.*, d.ad dosya_ad, d.renk dosya_renk, uu.ad pm_ad,
        (SELECT COUNT(*) FROM gorevler g WHERE g.proje_id=p.id) gorev_sayi,
        (SELECT COUNT(*) FROM gorevler g WHERE g.proje_id=p.id AND g.durum='tamamlandi') tamam_sayi
        FROM projeler p JOIN dosyalar d ON d.id=p.dosya_id LEFT JOIN users uu ON uu.id=p.pm_id
        WHERE p.dosya_id IN " . in_sorgu(musteri_dosya_idler())[0] . " ORDER BY d.ad, p.created DESC", in_sorgu(musteri_dosya_idler())[1]);
}

sayfa_basi(is_staff() ? 'Projeler' : 'Projelerim', 'projeler');
?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik"><?= is_staff() ? 'Projeler' : 'Projelerim' ?></div>
        <div class="sayfa-alt"><?= count($projeler) ?> proje</div>
    </div>
    <?php if (yetki('dosya_yonet')): ?>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalProje"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Proje</button></div>
    <?php endif; ?>
</div>

<div class="filtre-bar">
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="Proje ara..." data-arama="#projeIzgara .proje-kart"></div>
    <div class="pill-filtre" data-pill-grup="#projeIzgara .proje-kart">
        <button class="pill aktif" data-deger="">Tümü</button>
        <?php foreach (PROJE_TURLERI as $k => $v): ?><button class="pill" data-deger="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$projeler): ?>
<div class="bos-durum">
    <div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg></div>
    <div class="bos-baslik">Henüz proje yok</div>
    <div class="bos-metin"><?= yetki('dosya_yonet') ? 'Bir dosya seçip ilk projeyi oluşturun.' : 'Size atanmış bir proje bulunmuyor.' ?></div>
</div>
<?php else: ?>
<div class="izgara izgara-auto" id="projeIzgara">
    <?php foreach ($projeler as $p):
        $oran = $p['gorev_sayi'] ? round($p['tamam_sayi'] / $p['gorev_sayi'] * 100) : 0; ?>
    <a href="proje.php?id=<?= $p['id'] ?>" class="kart kart-tik proje-kart" data-filtre="<?= $p['tur'] ?>" data-ara="<?= e($p['ad'] . ' ' . $p['dosya_ad']) ?>">
        <div class="satir-esnek arasi mb-2">
            <span class="rozet rozet-tur"><?= PROJE_TURLERI[$p['tur']] ?></span>
            <?= rozet($p['durum'], PROJE_DURUMLARI) ?>
        </div>
        <div class="kart-baslik" style="font-size:16px"><?= e($p['ad']) ?></div>
        <div class="satir-esnek mt-1" style="gap:7px">
            <span class="etiket-nokta" style="background:<?= e($p['dosya_renk']) ?>"></span>
            <span class="hucre-alt"><?= e($p['dosya_ad']) ?></span>
        </div>
        <div class="ilerleme mt-2"><div class="ilerleme-dolu" data-oran="<?= $oran ?>" style="width:0"></div></div>
        <div class="satir-esnek arasi mt-1"><span class="hucre-alt"><?= $p['tamam_sayi'] ?>/<?= $p['gorev_sayi'] ?> görev</span><span class="hucre-alt kalin">%<?= $oran ?></span></div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
if (yetki('dosya_yonet')) {
    $dosyalar = rows("SELECT id, ad FROM dosyalar WHERE durum='aktif' ORDER BY ad");
    $pmler = rows("SELECT id, ad FROM users WHERE rol IN ('yonetici','pm') AND aktif=1 ORDER BY ad");
?>
<div class="modal-katman" id="modalProje">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Proje</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
        <form data-ajax="proje_kaydet">
            <div class="modal-govde">
                <div class="form-grup"><label class="form-etiket">Proje Adı <span class="zorunlu">*</span></label><input name="ad" class="girdi" required></div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Dosya <span class="zorunlu">*</span></label><select name="dosya_id" class="secim" required><option value="">Seçin...</option><?php foreach ($dosyalar as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['ad']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-grup"><label class="form-etiket">Hizmet Türü</label><select name="tur" class="secim"><?php foreach (PROJE_TURLERI as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Başlangıç</label><input type="date" name="baslangic" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="date" name="bitis" class="girdi"></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Proje Yöneticisi</label><select name="pm_id" class="secim"><option value="">—</option><?php foreach ($pmler as $pm): ?><option value="<?= $pm['id'] ?>"><?= e($pm['ad']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-grup"><label class="form-etiket">Sözleşme Tutarı (₺)</label><input name="sozlesme_tutari" class="girdi" placeholder="0,00"></div>
                </div>
                <div class="form-grup"><label class="form-etiket">Proje Şablonu (opsiyonel)</label><select name="psablon_id" class="secim"><option value="">— Boş proje</option><?php foreach (rows("SELECT id, ad FROM proje_sablonlari ORDER BY ad") as $psx): ?><option value="<?= $psx['id'] ?>"><?= e($psx['ad']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilirse şablondaki görevler akışlarıyla birlikte kurulur.</div></div>
                <?php uye_secici(); ?>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="aciklama" class="metin-alani"></textarea></div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
        </form>
    </div>
</div>
<?php } ?>
<?php sayfa_sonu(); ?>

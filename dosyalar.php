<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bilesenler.php';
$u = require_login();

if (is_musteri()) {
    // Müşteri: yalnızca erişebildiği dosyalar
    [$in, $p] = in_sorgu(musteri_dosya_idler());
    $dosyalar = rows("SELECT d.*,
        (SELECT COUNT(*) FROM projeler pr WHERE pr.dosya_id=d.id) proje_sayi,
        (SELECT COUNT(*) FROM projeler pr WHERE pr.dosya_id=d.id AND pr.durum='aktif') aktif_proje
        FROM dosyalar d WHERE d.id IN $in ORDER BY d.ad", $p);
} else {
    $dosyalar = rows("SELECT d.*,
        (SELECT COUNT(*) FROM projeler p WHERE p.dosya_id=d.id) proje_sayi,
        (SELECT COUNT(*) FROM projeler p WHERE p.dosya_id=d.id AND p.durum='aktif') aktif_proje
        FROM dosyalar d ORDER BY d.durum='aktif' DESC, d.ad");
}

sayfa_basi(is_musteri() ? 'Dosyalarım' : 'Dosyalar', 'dosyalar');
?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik"><?= is_musteri() ? 'Dosyalarım' : 'Dosyalar' ?></div>
        <div class="sayfa-alt"><?= is_musteri() ? 'Ajansımızla yürüttüğünüz dosyalar' : "Markalar, şirketler ve STK'lar" ?> — <?= count($dosyalar) ?> dosya</div>
    </div>
    <?php if (yetki('dosya_yonet')): ?>
    <div class="sayfa-ust-aksiyon">
        <button class="btn btn-marka" data-modal="modalDosya" onclick="dosyaFormSifirla()">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Dosya
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="filtre-bar">
    <div class="arama-kutu">
        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
        <input class="girdi" placeholder="Dosya ara..." data-arama="#dosyaIzgara .dosya-kart">
    </div>
    <div class="pill-filtre" data-pill-grup="#dosyaIzgara .dosya-kart">
        <button class="pill aktif" data-deger="">Tümü</button>
        <?php foreach (DOSYA_TURLERI as $k => $v): ?><button class="pill" data-deger="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$dosyalar): ?>
<div class="bos-durum">
    <div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg></div>
    <div class="bos-baslik">Henüz dosya yok</div>
    <div class="bos-metin">İlk markanızı, şirketinizi veya STK'nızı ekleyerek başlayın.</div>
    <?php if (yetki('dosya_yonet')): ?><button class="btn btn-marka" data-modal="modalDosya">Yeni Dosya Oluştur</button><?php endif; ?>
</div>
<?php else: ?>
<div class="izgara izgara-auto" id="dosyaIzgara">
    <?php foreach ($dosyalar as $d): ?>
    <a href="dosya.php?id=<?= $d['id'] ?>" class="kart kart-tik dosya-kart" data-filtre="<?= $d['tur'] ?>" data-ara="<?= e($d['ad']) ?>">
        <div class="satir-esnek arasi mb-2">
            <?= dosya_logo($d, 40, 15) ?>
            <?php if ($d['durum'] === 'pasif'): ?><span class="rozet r-iptal">Pasif</span><?php else: ?><span class="rozet rozet-tur"><?= DOSYA_TURLERI[$d['tur']] ?></span><?php endif; ?>
        </div>
        <div class="kart-baslik" style="font-size:16px"><?= e($d['ad']) ?></div>
        <?php if ($d['iletisim_ad']): ?><div class="hucre-alt mt-1"><?= e($d['iletisim_ad']) ?></div><?php endif; ?>
        <div class="satir-esnek mt-2" style="gap:16px;color:var(--muted);font-size:12.5px">
            <span><b style="color:var(--text)"><?= $d['aktif_proje'] ?></b> aktif proje</span>
            <span><b style="color:var(--text)"><?= $d['proje_sayi'] ?></b> toplam</span>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (yetki('dosya_yonet')): ?>
<div class="modal-katman" id="modalDosya">
    <div class="modal">
        <div class="modal-ust"><div class="modal-baslik" id="dosyaModalBaslik">Yeni Dosya</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
        <form data-ajax="dosya_kaydet" id="dosyaForm">
            <input type="hidden" name="id" id="dosya_id">
            <div class="modal-govde">
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Dosya Adı <span class="zorunlu">*</span></label><input name="ad" id="d_ad" class="girdi" required></div>
                    <div class="form-grup"><label class="form-etiket">Tür</label><select name="tur" id="d_tur" class="secim"><?php foreach (DOSYA_TURLERI as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-grup">
                    <label class="form-etiket">Renk</label>
                    <div class="satir-esnek sarma" id="renkSecim">
                        <?php foreach (['#b1fb01', '#182f5d', '#610714', '#f8f2cb', '#3b9df0', '#35c66b', '#f5a524', '#a58bf0'] as $r): ?>
                        <label style="cursor:pointer"><input type="radio" name="renk" value="<?= $r ?>" <?= $r === '#182f5d' ? 'checked' : '' ?> style="display:none" class="renk-radio"><span class="etiket-nokta" style="width:28px;height:28px;background:<?= $r ?>;border:2px solid transparent"></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-grup"><label class="form-etiket">Logo</label><input type="file" name="logo" class="girdi" accept="image/*"><div class="form-ipucu">JPG, PNG veya WebP.</div></div>
                <?php uye_secici([], 'Sorumlu Ekip Üyeleri'); ?>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="aciklama" id="d_aciklama" class="metin-alani"></textarea></div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">İletişim Kişisi</label><input name="iletisim_ad" id="d_iletisim_ad" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Telefon</label><input name="iletisim_tel" id="d_iletisim_tel" class="girdi"></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">E-posta</label><input type="email" name="iletisim_eposta" id="d_iletisim_eposta" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Durum</label><select name="durum" id="d_durum" class="secim"><option value="aktif">Aktif</option><option value="pasif">Pasif</option></select></div>
                </div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
        </form>
    </div>
</div>
<script>
function dosyaFormSifirla() {
    const f = document.getElementById('dosyaForm'); f.reset();
    document.getElementById('dosya_id').value = '';
    document.getElementById('dosyaModalBaslik').textContent = 'Yeni Dosya';
    renkVurgula();
}
// Renk seçim vurgusu
function renkVurgula() {
    document.querySelectorAll('.renk-radio').forEach(r => {
        r.nextElementSibling.style.borderColor = r.checked ? 'var(--text)' : 'transparent';
    });
}
document.getElementById('renkSecim').addEventListener('change', renkVurgula);
renkVurgula();
</script>
<?php endif; ?>
<?php sayfa_sonu(); ?>

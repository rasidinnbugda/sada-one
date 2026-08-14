<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$sablonlar = rows("SELECT s.*, (SELECT COUNT(*) FROM sablon_adimlari sa WHERE sa.sablon_id=s.id) adim_sayi, (SELECT COUNT(*) FROM gorevler g JOIN gorev_adimlari ga ON ga.gorev_id=g.id WHERE 1=0) kullanim FROM akis_sablonlari s ORDER BY s.ad");
foreach ($sablonlar as &$s) $s['adimlar'] = rows("SELECT ad FROM sablon_adimlari WHERE sablon_id=? ORDER BY sira", [$s['id']]);
unset($s);

sayfa_basi('Akış Şablonları', 'akislar');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Akış Şablonları</div><div class="sayfa-alt">Görevlerin izleyeceği iş akışı adımlarını tanımlayın</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalAkis" onclick="akisSifirla()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Şablon</button></div>
</div>

<?php if (!$sablonlar): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div><div class="bos-baslik">Şablon yok</div><div class="bos-metin">İçerik üretimi, prodüksiyon veya web projeleri için akış şablonları oluşturun.</div></div>
<?php else: ?>
<div class="izgara izgara-2">
    <?php foreach ($sablonlar as $s): ?>
    <div class="kart">
        <div class="satir-esnek arasi mb-2">
            <div><div class="kart-baslik" style="font-size:16px"><?= e($s['ad']) ?></div><?php if ($s['aciklama']): ?><div class="hucre-alt mt-1"><?= e($s['aciklama']) ?></div><?php endif; ?></div>
            <div class="satir-esnek" style="gap:4px">
                <button class="ikon-eylem" onclick='akisDuzenle(<?= json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                <button class="ikon-eylem tehlike" data-eylem="akis_sil" data-id="<?= $s['id'] ?>" data-onay="Şablon silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button>
            </div>
        </div>
        <div class="akis-ray" style="margin-top:14px">
            <?php foreach ($s['adimlar'] as $i => $a): ?>
            <div class="akis-adim">
                <div class="akis-cizgi"></div>
                <div class="akis-adim-ic"><div class="akis-yuvarlak"><?= $i + 1 ?></div><div class="akis-ad"><?= e($a['ad']) ?></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalAkis">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="akisBaslik">Yeni Akış Şablonu</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="akis_kaydet" id="akisForm">
        <input type="hidden" name="id" id="a_id"><input type="hidden" name="adimlar" id="a_adimlar">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Şablon Adı <span class="zorunlu">*</span></label><input name="ad" id="a_ad" class="girdi" required placeholder="Örn. Sosyal Medya İçerik Üretimi"></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="aciklama" id="a_aciklama" class="girdi"></div>
            <div class="form-grup">
                <label class="form-etiket">Akış Adımları</label>
                <div class="dikey" id="adimListe" style="gap:8px"></div>
                <button type="button" class="btn btn-sm btn-hayalet mt-2" onclick="adimEkle()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Adım Ekle</button>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
function adimSatiri(deger = '') {
    const div = document.createElement('div');
    div.className = 'satir-esnek adim-satir';
    div.style.gap = '8px';
    div.setAttribute('data-siralanabilir', '');
    div.innerHTML = `<span class="sira-oklar">
        <button type="button" class="sira-ok" data-sira-yon="yukari" title="Yukarı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 15l7-7 7 7"/></svg></button>
        <button type="button" class="sira-ok" data-sira-yon="asagi" title="Aşağı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg></button>
    </span><input class="girdi adim-input" value="${deger.replace(/"/g,'&quot;')}" placeholder="Adım adı"><button type="button" class="ikon-eylem tehlike" onclick="this.parentElement.remove()"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
    document.getElementById('adimListe').appendChild(div);
}
function adimEkle() { adimSatiri(); }
function akisSifirla() {
    document.getElementById('akisForm').reset();
    document.getElementById('a_id').value = '';
    document.getElementById('akisBaslik').textContent = 'Yeni Akış Şablonu';
    document.getElementById('adimListe').innerHTML = '';
    adimSatiri('Brief'); adimSatiri('Tasarım'); adimSatiri('İç Onay'); adimSatiri('Müşteri Onayı');
}
function akisDuzenle(s) {
    document.getElementById('akisBaslik').textContent = 'Şablonu Düzenle';
    document.getElementById('a_id').value = s.id;
    document.getElementById('a_ad').value = s.ad;
    document.getElementById('a_aciklama').value = s.aciklama || '';
    document.getElementById('adimListe').innerHTML = '';
    s.adimlar.forEach(a => adimSatiri(a.ad));
    modalAc('modalAkis');
}
document.getElementById('akisForm').addEventListener('submit', () => {
    const adimlar = Array.from(document.querySelectorAll('.adim-input')).map(i => i.value.trim()).filter(Boolean);
    document.getElementById('a_adimlar').value = JSON.stringify(adimlar);
});
</script>
<?php sayfa_sonu(); ?>

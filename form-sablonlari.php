<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$formlar = rows("SELECT * FROM form_sablonlari ORDER BY ad");
foreach ($formlar as &$f) $f['alanlar'] = rows("SELECT * FROM form_alanlari WHERE sablon_id=? ORDER BY sira", [$f['id']]);
unset($f);
$alanTipleri = ['metin' => 'Kısa Metin', 'uzun_metin' => 'Uzun Metin', 'secim' => 'Seçim Listesi', 'tarih' => 'Tarih', 'sayi' => 'Sayı', 'dosya' => 'Dosya Yükleme'];

sayfa_basi('Form Şablonları', 'formlar');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Talep Form Şablonları</div><div class="sayfa-alt">Müşterilerin dolduracağı talep formlarını tasarlayın</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalForm" onclick="formSifirla()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Form</button></div>
</div>

<div class="izgara izgara-2">
    <?php foreach ($formlar as $f): ?>
    <div class="kart" style="<?= $f['aktif'] ? '' : 'opacity:.6' ?>">
        <div class="satir-esnek arasi mb-2">
            <div><div class="kart-baslik" style="font-size:16px"><?= e($f['ad']) ?> <?php if (!$f['aktif']): ?><span class="rozet r-iptal">Pasif</span><?php endif; ?></div><?php if ($f['aciklama']): ?><div class="hucre-alt mt-1"><?= e($f['aciklama']) ?></div><?php endif; ?></div>
            <div class="satir-esnek" style="gap:4px">
                <button class="ikon-eylem" onclick='formDuzenle(<?= json_encode($f, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                <button class="ikon-eylem tehlike" data-eylem="form_sil" data-id="<?= $f['id'] ?>" data-onay="Form silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button>
            </div>
        </div>
        <div class="dikey mt-2" style="gap:6px">
            <?php foreach ($f['alanlar'] as $a): ?>
            <div class="satir-esnek arasi kucuk" style="padding:8px 12px;background:var(--surface-2);border-radius:9px"><span><?= e($a['etiket']) ?><?php if ($a['zorunlu']): ?> <span class="zorunlu">*</span><?php endif; ?></span><span class="metin-muted"><?= $alanTipleri[$a['tip']] ?? $a['tip'] ?></span></div>
            <?php endforeach; ?>
            <?php if (!$f['alanlar']): ?><div class="metin-muted kucuk">Alan yok</div><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="modal-katman" id="modalForm">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik" id="formBaslik">Yeni Form Şablonu</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="form_kaydet" id="formForm">
        <input type="hidden" name="id" id="f_id"><input type="hidden" name="alanlar" id="f_alanlar">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Form Adı <span class="zorunlu">*</span></label><input name="ad" id="f_ad" class="girdi" required></div>
                <div class="form-grup"><label class="form-etiket">Durum</label><select name="aktif" id="f_aktif" class="secim"><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="aciklama" id="f_aciklama" class="girdi"></div>
            <div class="form-grup">
                <label class="form-etiket">Form Alanları</label>
                <div class="dikey" id="alanListe" style="gap:10px"></div>
                <button type="button" class="btn btn-sm btn-hayalet mt-2" onclick="alanEkle()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Alan Ekle</button>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
const alanTipleri = <?= json_encode($alanTipleri, JSON_UNESCAPED_UNICODE) ?>;
function alanSatiri(alan = {}) {
    const div = document.createElement('div');
    div.className = 'kart alan-satir';
    div.style.padding = '12px';
    let tipOps = ''; for (const t in alanTipleri) tipOps += `<option value="${t}" ${alan.tip===t?'selected':''}>${alanTipleri[t]}</option>`;
    div.setAttribute('data-siralanabilir', '');
    div.innerHTML = `
        <div class="satir-esnek" style="gap:8px;align-items:flex-start">
            <span class="sira-oklar" style="padding-top:8px">
                <button type="button" class="sira-ok" data-sira-yon="yukari" title="Yukarı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 15l7-7 7 7"/></svg></button>
                <button type="button" class="sira-ok" data-sira-yon="asagi" title="Aşağı taşı"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg></button>
            </span>
            <div style="flex:1">
                <div class="form-satir" style="margin-bottom:8px">
                    <input class="girdi alan-etiket" placeholder="Alan etiketi" value="${(alan.etiket||'').replace(/"/g,'&quot;')}">
                    <select class="secim alan-tip" onchange="secenekGoster(this)">${tipOps}</select>
                </div>
                <textarea class="metin-alani alan-secenekler" placeholder="Seçenekler (her satıra bir tane)" style="min-height:60px;display:${alan.tip==='secim'?'block':'none'}">${alan.secenekler||''}</textarea>
                <label class="satir-esnek kucuk mt-2" style="gap:7px;cursor:pointer"><input type="checkbox" class="alan-zorunlu" ${alan.zorunlu!=0?'checked':''}> Zorunlu alan</label>
            </div>
            <button type="button" class="ikon-eylem tehlike" onclick="this.closest('.alan-satir').remove()"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>`;
    document.getElementById('alanListe').appendChild(div);
    if (window.ozelSeciciYenile) ozelSeciciYenile();
}
function secenekGoster(sel) { sel.closest('.alan-satir').querySelector('.alan-secenekler').style.display = sel.value === 'secim' ? 'block' : 'none'; }
function alanEkle() { alanSatiri(); }
function formSifirla() {
    document.getElementById('formForm').reset();
    document.getElementById('f_id').value = '';
    document.getElementById('formBaslik').textContent = 'Yeni Form Şablonu';
    document.getElementById('alanListe').innerHTML = '';
    alanSatiri({etiket:'Konu', tip:'metin'}); alanSatiri({etiket:'Açıklama', tip:'uzun_metin'});
}
function formDuzenle(f) {
    document.getElementById('formBaslik').textContent = 'Formu Düzenle';
    document.getElementById('f_id').value = f.id;
    document.getElementById('f_ad').value = f.ad;
    document.getElementById('f_aciklama').value = f.aciklama || '';
    document.getElementById('f_aktif').value = f.aktif;
    document.getElementById('alanListe').innerHTML = '';
    f.alanlar.forEach(a => alanSatiri(a));
    modalAc('modalForm');
}
document.getElementById('formForm').addEventListener('submit', () => {
    const alanlar = Array.from(document.querySelectorAll('.alan-satir')).map(s => ({
        etiket: s.querySelector('.alan-etiket').value.trim(),
        tip: s.querySelector('.alan-tip').value,
        secenekler: s.querySelector('.alan-secenekler').value.trim(),
        zorunlu: s.querySelector('.alan-zorunlu').checked ? 1 : 0
    })).filter(a => a.etiket);
    document.getElementById('f_alanlar').value = JSON.stringify(alanlar);
});
</script>
<?php sayfa_sonu(); ?>

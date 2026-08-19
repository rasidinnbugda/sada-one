<?php
/**
 * SADA One — Proje Şablonları
 * Hazır görev setleriyle tek tıkla proje kurulumu.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$sablonlar = rows("SELECT * FROM proje_sablonlari ORDER BY ad");
$akislar = rows("SELECT id, ad FROM akis_sablonlari ORDER BY ad");

sayfa_basi('Proje Şablonları', 'psablonlar');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Proje Şablonları</div><div class="sayfa-alt">Yeni proje açarken tek tıkla kurulan hazır görev setleri</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalPS" onclick="psSifirla()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Şablon</button></div>
</div>

<?php if (!$sablonlar): ?>
<div class="bos-durum">
    <div class="bos-ikon"><?= ikon('belge', 36) ?></div>
    <div class="bos-baslik">Şablon yok</div>
    <div class="bos-metin">Örn. "Aylık Sosyal Medya Paketi" şablonu: içerik üretimi, çekim, raporlama görevleri akışlarıyla hazır kurulsun.</div>
    <button class="btn btn-marka" data-modal="modalPS" onclick="psSifirla()">İlk Şablonu Oluştur</button>
</div>
<?php else: ?>
<div class="izgara izgara-2">
    <?php foreach ($sablonlar as $ps):
        $gorevListe = json_decode($ps['gorevler'], true) ?: []; ?>
    <div class="kart">
        <div class="satir-esnek arasi mb-2">
            <div><div class="kart-baslik" style="font-size:16px"><?= e($ps['ad']) ?></div><?php if ($ps['aciklama']): ?><div class="hucre-alt mt-1"><?= e($ps['aciklama']) ?></div><?php endif; ?></div>
            <div class="satir-esnek" style="gap:4px">
                <button class="ikon-eylem" onclick='psDuzenle(<?= json_encode($ps, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= ikon('kalem', 16) ?></button>
                <button class="ikon-eylem tehlike" data-eylem="psablon_sil" data-id="<?= $ps['id'] ?>" data-onay="Şablon silinsin mi? (Mevcut projeler etkilenmez)"><?= ikon('cop', 16) ?></button>
            </div>
        </div>
        <div class="dikey" style="gap:5px">
            <?php foreach ($gorevListe as $sg): ?>
            <div class="satir-esnek arasi kucuk" style="padding:7px 11px;background:var(--surface-2);border-radius:9px">
                <span><?= e($sg['baslik']) ?></span>
                <span class="satir-esnek" style="gap:6px">
                    <?php if (($sg['oncelik'] ?? 'normal') !== 'normal'): ?><?= rozet($sg['oncelik'], ONCELIKLER) ?><?php endif; ?>
                    <?php if (!empty($sg['akis_id'])): $akisAd = ''; foreach ($akislar as $ak) if ($ak['id'] == $sg['akis_id']) $akisAd = $ak['ad']; ?>
                    <span class="rozet rozet-tur"><?= ikon('roket', 10) ?> <?= e($akisAd ?: 'Akış') ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalPS">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik" id="psBaslik">Yeni Proje Şablonu</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="psablon_kaydet" id="psForm">
        <input type="hidden" name="id" id="ps_id"><input type="hidden" name="gorevler" id="ps_gorevler">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Şablon Adı <span class="zorunlu">*</span></label><input name="ad" id="ps_ad" class="girdi" required placeholder="Örn. Aylık Sosyal Medya Paketi"></div>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="aciklama" id="ps_aciklama" class="girdi"></div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Görevler</label>
                <div class="dikey" id="psGorevListe" style="gap:8px"></div>
                <button type="button" class="btn btn-sm btn-hayalet mt-2" onclick="psGorevEkle()">+ Görev Ekle</button>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
const psAkislar = <?= json_encode($akislar, JSON_UNESCAPED_UNICODE) ?>;
const psOncelikler = <?= json_encode(ONCELIKLER, JSON_UNESCAPED_UNICODE) ?>;
function psGorevEkle(g = {}) {
    const div = document.createElement('div');
    div.className = 'satir-esnek ps-satir';
    div.style.gap = '8px';
    let akisOps = '<option value="0">Akışsız</option>';
    psAkislar.forEach(a => akisOps += `<option value="${a.id}" ${g.akis_id == a.id ? 'selected' : ''}>${a.ad}</option>`);
    let oncOps = '';
    for (const k in psOncelikler) oncOps += `<option value="${k}" ${(g.oncelik || 'normal') === k ? 'selected' : ''}>${psOncelikler[k]}</option>`;
    div.innerHTML = `<input class="girdi ps-baslik" placeholder="Görev başlığı" style="flex:2" value="${(g.baslik || '').replace(/"/g, '&quot;')}">
        <select class="secim ps-akis" style="flex:1">${akisOps}</select>
        <select class="secim ps-onc" style="width:110px">${oncOps}</select>
        <button type="button" class="ikon-eylem tehlike" onclick="this.parentElement.remove()">✕</button>`;
    document.getElementById('psGorevListe').appendChild(div);
    if (window.ozelSeciciYenile) ozelSeciciYenile();
}
function psSifirla() {
    document.getElementById('psForm').reset();
    document.getElementById('ps_id').value = '';
    document.getElementById('psBaslik').textContent = 'Yeni Proje Şablonu';
    document.getElementById('psGorevListe').innerHTML = '';
    psGorevEkle(); psGorevEkle();
}
function psDuzenle(ps) {
    document.getElementById('psBaslik').textContent = 'Şablonu Düzenle';
    document.getElementById('ps_id').value = ps.id;
    document.getElementById('ps_ad').value = ps.ad;
    document.getElementById('ps_aciklama').value = ps.aciklama || '';
    document.getElementById('psGorevListe').innerHTML = '';
    (JSON.parse(ps.gorevler || '[]')).forEach(g => psGorevEkle(g));
    modalAc('modalPS');
}
document.getElementById('psForm').addEventListener('submit', () => {
    const gorevler = Array.from(document.querySelectorAll('.ps-satir')).map(s => ({
        baslik: s.querySelector('.ps-baslik').value.trim(),
        akis_id: parseInt(s.querySelector('.ps-akis').value) || 0,
        oncelik: s.querySelector('.ps-onc').value,
    })).filter(g => g.baslik);
    document.getElementById('ps_gorevler').value = JSON.stringify(gorevler);
});
</script>
<?php sayfa_sonu(); ?>

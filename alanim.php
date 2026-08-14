<?php
/**
 * SADA Dijital — Kişisel Çalışma Alanı
 * Notlar, kişisel yapılacaklar, yer imleri ve hızlı karalama — yalnızca sahibi görür.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();
if (is_musteri()) { header('Location: index.php'); exit; }

$notlar = rows("SELECT * FROM kisisel_notlar WHERE user_id=? ORDER BY COALESCE(guncelleme, created) DESC", [$u['id']]);
$isler = rows("SELECT * FROM kisisel_isler WHERE user_id=? ORDER BY tamam, sira", [$u['id']]);
$linkler = rows("SELECT * FROM kisisel_linkler WHERE user_id=? ORDER BY ad", [$u['id']]);

$notRenkleri = [
    'varsayilan' => 'var(--surface)',
    'sari' => 'color-mix(in srgb, #f5a524 12%, var(--surface))',
    'yesil' => 'color-mix(in srgb, #35c66b 12%, var(--surface))',
    'mavi' => 'color-mix(in srgb, #3b9df0 12%, var(--surface))',
    'pembe' => 'color-mix(in srgb, #e86b82 12%, var(--surface))',
];

sayfa_basi('Alanım', 'alanim');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Kişisel Alanım</div><div class="sayfa-alt">Notların, yapılacakların ve yer imlerin — yalnızca sen görürsün</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" onclick="notSifirla();modalAc('modalNot')"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Not</button></div>
</div>

<div class="izgara" style="grid-template-columns:1fr 320px">
    <div>
        <!-- Notlar -->
        <?php if (!$notlar): ?>
        <div class="kart orta" style="padding:36px">
            <div class="bos-ikon" style="margin-bottom:14px"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></div>
            <div class="bos-baslik">Henüz not yok</div>
            <div class="bos-metin">Fikirlerini, toplantı notlarını, aklında kalmasını istediklerini buraya yaz.</div>
        </div>
        <?php else: ?>
        <div class="izgara izgara-2">
            <?php foreach ($notlar as $n): ?>
            <div class="kart" style="background:<?= $notRenkleri[$n['renk']] ?? $notRenkleri['varsayilan'] ?>;padding:16px">
                <div class="satir-esnek arasi" style="align-items:flex-start">
                    <?php if ($n['baslik']): ?><div class="kalin" style="font-size:14.5px"><?= e($n['baslik']) ?></div><?php else: ?><span></span><?php endif; ?>
                    <div class="satir-esnek" style="gap:2px;flex-shrink:0">
                        <button class="ikon-eylem" style="width:26px;height:26px" onclick='notDuzenle(<?= json_encode($n, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="14"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
                        <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-eylem="not_sil" data-id="<?= $n['id'] ?>" data-onay="Not silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="14"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                    </div>
                </div>
                <div class="kucuk metin-2 mt-1" style="white-space:pre-wrap;word-break:break-word"><?= e(mb_substr($n['metin'], 0, 600)) ?><?= mb_strlen($n['metin']) > 600 ? '…' : '' ?></div>
                <div class="hucre-alt mt-2"><?= zaman_once($n['guncelleme'] ?: $n['created']) ?><?= $n['guncelleme'] ? ' (düzenlendi)' : '' ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Hızlı karalama -->
        <div class="kart mt-3">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik"><?= ikon('kalem', 16) ?> Hızlı Karalama</div>
                <span class="hucre-alt" id="karalamaDurum">otomatik kaydedilir</span>
            </div>
            <textarea class="metin-alani" id="karalamaAlani" style="min-height:180px;font-family:inherit" placeholder="Buraya istediğini karala — yazdıkça kaydedilir, döndüğünde kaldığın yerde bulursun..."><?= e($u['karalama'] ?? '') ?></textarea>
        </div>
    </div>

    <div>
        <!-- Kişisel yapılacaklar -->
        <div class="kart mb-2">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik" style="font-size:14px"><?= ikon('onay', 15) ?> Yapılacaklarım</div>
                <span class="hucre-alt" id="isSayac"><?= count(array_filter($isler, fn($i) => !$i['tamam'])) ?> açık</span>
            </div>
            <div class="dikey" style="gap:2px" id="isListe">
                <?php foreach ($isler as $is): ?>
                <div class="kontrol-oge <?= $is['tamam'] ? 'tamam' : '' ?>">
                    <input type="checkbox" <?= $is['tamam'] ? 'checked' : '' ?> onchange="isToggle(<?= $is['id'] ?>, this)">
                    <span class="kontrol-metin"><?= e($is['ad']) ?></span>
                    <button class="ikon-eylem tehlike" style="width:24px;height:24px" data-eylem="kisisel_is_sil" data-id="<?= $is['id'] ?>" data-yenile="hayir" onclick="setTimeout(()=>{this.closest('.kontrol-oge').remove();isSayacGuncelle()},300)"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <?php endforeach; ?>
                <?php if (!$isler): ?><div class="metin-muted kucuk" style="padding:6px 0" id="isBos">Henüz madde yok.</div><?php endif; ?>
            </div>
            <form class="satir-esnek mt-2" style="gap:8px" onsubmit="return isEkle(event)">
                <input class="girdi" id="isYeni" placeholder="Yeni madde...">
                <button type="submit" class="btn btn-sm">Ekle</button>
            </form>
        </div>

        <!-- Yer imleri -->
        <div class="kart">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik" style="font-size:14px"><?= ikon('atac', 15) ?> Yer İmlerim</div>
                <button class="mini-btn" data-modal="modalLink">+ Ekle</button>
            </div>
            <?php if (!$linkler): ?><div class="metin-muted kucuk">Sık kullandığın linkleri buraya ekle (Drive klasörleri, araçlar...).</div>
            <?php else: foreach ($linkler as $l): ?>
            <div class="satir-esnek arasi mt-1" style="padding:7px 10px;background:var(--surface-2);border-radius:9px">
                <a href="<?= e($l['url']) ?>" target="_blank" class="satir-esnek kucuk kalin" style="gap:8px;min-width:0;color:var(--marka)">
                    <span style="display:inline-flex;color:var(--marka)"><?= ikon('web', 14) ?></span><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($l['ad']) ?></span>
                </a>
                <button class="ikon-eylem tehlike" style="width:24px;height:24px" data-eylem="link_sil" data-id="<?= $l['id'] ?>" data-onay="Yer imi silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Not ekle/düzenle -->
<div class="modal-katman" id="modalNot">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="notBaslikUst">Yeni Not</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="not_kaydet">
        <input type="hidden" name="id" id="n_id">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık</label><input name="baslik" id="n_baslik" class="girdi" placeholder="Opsiyonel"></div>
            <div class="form-grup"><label class="form-etiket">Not <span class="zorunlu">*</span></label><textarea name="metin" id="n_metin" class="metin-alani" style="min-height:140px" required></textarea></div>
            <div class="form-grup">
                <label class="form-etiket">Renk</label>
                <div class="satir-esnek" style="gap:10px">
                    <?php foreach (['varsayilan' => 'var(--surface-3)', 'sari' => '#f5a524', 'yesil' => '#35c66b', 'mavi' => '#3b9df0', 'pembe' => '#e86b82'] as $rk => $rv): ?>
                    <label style="cursor:pointer"><input type="radio" name="renk" value="<?= $rk ?>" <?= $rk === 'varsayilan' ? 'checked' : '' ?> class="renk-radio" style="display:none"><span class="etiket-nokta not-renk" data-renk="<?= $rk ?>" style="width:26px;height:26px;background:<?= $rv ?>;border:2px solid transparent"></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- Yer imi ekle -->
<div class="modal-katman" id="modalLink">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yer İmi Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="link_ekle">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Ad <span class="zorunlu">*</span></label><input name="ad" class="girdi" required placeholder="Örn. Marka X Drive klasörü"></div>
            <div class="form-grup"><label class="form-etiket">Adres <span class="zorunlu">*</span></label><input name="url" class="girdi" required placeholder="https://..."></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Ekle</button></div>
    </form></div>
</div>

<script>
/* Not renk seçimi vurgusu */
document.querySelectorAll('.not-renk').forEach(n => n.addEventListener('click', () => {
    document.querySelectorAll('.not-renk').forEach(x => x.style.borderColor = 'transparent');
    n.style.borderColor = 'var(--text)';
}));
function notSifirla() {
    document.getElementById('n_id').value = '';
    document.getElementById('n_baslik').value = '';
    document.getElementById('n_metin').value = '';
    document.getElementById('notBaslikUst').textContent = 'Yeni Not';
    document.querySelector('input[name=renk][value=varsayilan]').checked = true;
}
function notDuzenle(n) {
    document.getElementById('n_id').value = n.id;
    document.getElementById('n_baslik').value = n.baslik || '';
    document.getElementById('n_metin').value = n.metin || '';
    document.getElementById('notBaslikUst').textContent = 'Notu Düzenle';
    const radio = document.querySelector(`input[name=renk][value=${n.renk}]`);
    if (radio) radio.checked = true;
    modalAc('modalNot');
}

/* Kişisel yapılacaklar: yenilemesiz */
function isSayacGuncelle() {
    const acik = document.querySelectorAll('#isListe .kontrol-oge:not(.tamam)').length;
    document.getElementById('isSayac').textContent = acik + ' açık';
}
async function isEkle(e) {
    e.preventDefault();
    const girdi = document.getElementById('isYeni');
    const ad = girdi.value.trim(); if (!ad) return false;
    const j = await api('kisisel_is_ekle', { ad });
    if (j.ok) {
        girdi.value = '';
        const bos = document.getElementById('isBos'); if (bos) bos.remove();
        const div = document.createElement('div');
        div.className = 'kontrol-oge';
        div.innerHTML = `<input type="checkbox" onchange="isToggle(${j.id}, this)"><span class="kontrol-metin"></span><button class="ikon-eylem tehlike" style="width:24px;height:24px" data-eylem="kisisel_is_sil" data-id="${j.id}" data-yenile="hayir" onclick="setTimeout(()=>{this.closest('.kontrol-oge').remove();isSayacGuncelle()},300)"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
        div.querySelector('.kontrol-metin').textContent = j.ad;
        document.getElementById('isListe').appendChild(div);
        isSayacGuncelle();
    }
    return false;
}
async function isToggle(id, kutu) {
    const j = await api('kisisel_is_toggle', { id });
    if (j.ok) { kutu.closest('.kontrol-oge').classList.toggle('tamam', kutu.checked); isSayacGuncelle(); }
    else kutu.checked = !kutu.checked;
}

/* Karalama: 1,2 sn hareketsizlikte otomatik kaydet */
const karalama = document.getElementById('karalamaAlani');
const karalamaDurum = document.getElementById('karalamaDurum');
let karalamaZaman = null;
karalama.addEventListener('input', () => {
    karalamaDurum.textContent = 'yazılıyor...';
    clearTimeout(karalamaZaman);
    karalamaZaman = setTimeout(async () => {
        const j = await api('karalama_kaydet', { metin: karalama.value });
        karalamaDurum.textContent = j.ok ? '✓ kaydedildi' : 'kaydedilemedi!';
    }, 1200);
});
</script>
<?php sayfa_sonu(); ?>

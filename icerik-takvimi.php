<?php
/**
 * SADA Dijital — İçerik Takvimi
 * İçerikler dosyaya (markaya) bağlıdır; proje opsiyoneldir. Çoklu platform desteklenir.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$yil = (int)($_GET['yil'] ?? date('Y'));
$ay = (int)($_GET['ay'] ?? date('n'));
if ($ay < 1) { $ay = 12; $yil--; } if ($ay > 12) { $ay = 1; $yil++; }
$dosyaFiltre = (int)($_GET['dosya'] ?? 0);

// Erişilebilir dosyalar
if (is_staff()) {
    $dosyalar = rows("SELECT id, ad FROM dosyalar WHERE durum='aktif' ORDER BY ad");
} else {
    [$in, $p] = in_sorgu(musteri_dosya_idler());
    $dosyalar = rows("SELECT id, ad FROM dosyalar WHERE id IN $in ORDER BY ad", $p);
}
$dosyaIdler = array_map('intval', array_column($dosyalar, 'id'));
$projeler = is_staff() ? rows("SELECT id, ad, dosya_id FROM projeler WHERE durum='aktif' ORDER BY ad") : [];

$ilkGun = mktime(0, 0, 0, $ay, 1, $yil);
$gunSayisi = (int)date('t', $ilkGun);
$baslangicHafta = (int)date('N', $ilkGun);

$ayBas = sprintf('%04d-%02d-01', $yil, $ay);
$aySon = sprintf('%04d-%02d-%02d', $yil, $ay, $gunSayisi);

$icerikGunleri = [];
$tumIcerikler = [];
if ($dosyaIdler) {
    [$inD, $pD] = in_sorgu($dosyaIdler);
    $params = array_merge($pD, [$ayBas, $aySon]);
    $ekKosul = '';
    if ($dosyaFiltre) { $ekKosul = ' AND COALESCE(i.dosya_id, pr.dosya_id)=?'; $params[] = $dosyaFiltre; }
    $tumIcerikler = rows("SELECT i.*, d.ad dosya_ad, pr.ad proje_ad, (SELECT g.id FROM gorevler g WHERE g.icerik_id=i.id LIMIT 1) gorev_id
        FROM icerikler i
        LEFT JOIN projeler pr ON pr.id=i.proje_id
        LEFT JOIN dosyalar d ON d.id=COALESCE(i.dosya_id, pr.dosya_id)
        WHERE COALESCE(i.dosya_id, pr.dosya_id) IN $inD AND i.tarih BETWEEN ? AND ?$ekKosul
        ORDER BY i.tarih, i.saat", $params);
    foreach ($tumIcerikler as $ic) { $g = (int)date('j', strtotime($ic['tarih'])); $icerikGunleri[$g][] = $ic; }
}

sayfa_basi('İçerik Takvimi', 'icerik');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">İçerik Takvimi</div><div class="sayfa-alt">Dosya (marka) bazlı sosyal medya içerik planı</div></div>
    <?php if (yetki('icerik_yonet')): ?><div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalIcerik"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> İçerik Planla</button></div><?php endif; ?>
</div>

<div class="filtre-bar">
    <select class="secim" style="max-width:280px" onchange="location.href='?dosya='+this.value+'&ay=<?= $ay ?>&yil=<?= $yil ?>'">
        <option value="0">Tüm Dosyalar</option>
        <?php foreach ($dosyalar as $d): ?><option value="<?= $d['id'] ?>" <?= $dosyaFiltre == $d['id'] ? 'selected' : '' ?>><?= e($d['ad']) ?></option><?php endforeach; ?>
    </select>
</div>

<div class="kart">
    <div class="takvim-baslik-bar">
        <div class="satir-esnek" style="gap:8px">
            <a href="?ay=<?= $ay - 1 ?>&yil=<?= $yil ?>&dosya=<?= $dosyaFiltre ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
            <div class="takvim-ay-ad"><?= AYLAR[$ay] ?> <?= $yil ?></div>
            <a href="?ay=<?= $ay + 1 ?>&yil=<?= $yil ?>&dosya=<?= $dosyaFiltre ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>
        </div>
        <a href="?ay=<?= date('n') ?>&yil=<?= date('Y') ?>&dosya=<?= $dosyaFiltre ?>" class="btn btn-sm">Bugün</a>
    </div>
    <div class="takvim-izgara">
        <?php foreach (['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $g): ?><div class="takvim-gun-baslik"><?= $g ?></div><?php endforeach; ?>
        <?php for ($i = 1; $i < $baslangicHafta; $i++): ?><div class="takvim-hucre bos"></div><?php endfor; ?>
        <?php for ($gun = 1; $gun <= $gunSayisi; $gun++):
            $bugun = ($gun == date('j') && $ay == date('n') && $yil == date('Y'));
            $tarihStr = sprintf('%04d-%02d-%02d', $yil, $ay, $gun); ?>
        <div class="takvim-hucre <?= $bugun ? 'bugun' : '' ?>" data-tarih="<?= $tarihStr ?>" <?= yetki('icerik_yonet') ? "onclick=\"icerikEkle('$tarihStr')\" style=\"cursor:pointer\"" : '' ?>>
            <div class="takvim-gun-no"><?= $gun ?></div>
            <?php foreach ($icerikGunleri[$gun] ?? [] as $ic):
                $durumRenk = ['taslak' => 'var(--muted)', 'ic_onay' => 'var(--bilgi)', 'musteri_onay' => 'var(--uyari)', 'revize' => 'var(--bilgi)', 'onaylandi' => 'var(--basari)', 'yayinlandi' => 'var(--marka)'][$ic['durum']]; ?>
            <div class="takvim-etkinlik" draggable="<?= yetki('icerik_yonet') ? 'true' : 'false' ?>" data-icerik="<?= $ic['id'] ?>" onclick="event.stopPropagation();icerikGoster(<?= $ic['id'] ?>)" style="border-color:<?= $durumRenk ?>;background:color-mix(in srgb, <?= $durumRenk ?> 14%, transparent);color:<?= $durumRenk ?>" title="<?= e($ic['baslik']) ?> · <?= e($ic['dosya_ad'] ?? '') ?>"><?= platform_rozetleri($ic['platform'], true) ?> <?= e($ic['baslik']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="satir-esnek sarma mt-3" style="gap:16px;justify-content:center">
    <?php foreach (ICERIK_DURUMLARI as $k => $v):
        $renk = ['taslak' => 'var(--muted)', 'ic_onay' => 'var(--bilgi)', 'musteri_onay' => 'var(--uyari)', 'revize' => 'var(--bilgi)', 'onaylandi' => 'var(--basari)', 'yayinlandi' => 'var(--marka)'][$k]; ?>
    <span class="satir-esnek kucuk" style="gap:6px"><span class="etiket-nokta" style="background:<?= $renk ?>"></span><?= $v ?></span>
    <?php endforeach; ?>
</div>

<?php if (yetki('icerik_yonet')): ?>
<div class="modal-katman" id="modalIcerik">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">İçerik Planla</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="icerik_kaydet" data-yenile="evet" id="icerikForm">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="baslik" class="girdi" required></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Dosya (marka) <span class="zorunlu">*</span></label><select name="dosya_id" id="ic_dosya" class="secim" required><option value="">Seçin...</option><?php foreach ($dosyalar as $d): ?><option value="<?= $d['id'] ?>" <?= $dosyaFiltre == $d['id'] ? 'selected' : '' ?>><?= e($d['ad']) ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Proje (opsiyonel)</label><select name="proje_id" id="ic_proje" class="secim"><option value="">—</option><?php foreach ($projeler as $p): ?><option value="<?= $p['id'] ?>" data-dosya="<?= $p['dosya_id'] ?>"><?= e($p['ad']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Platformlar <span class="metin-muted" style="font-weight:400">(birden fazla seçilebilir)</span></label>
                <input type="hidden" name="platformlar" id="ic_platformlar">
                <div class="satir-esnek sarma" style="gap:6px">
                    <?php foreach (PLATFORMLAR as $k => $v): ?>
                    <label class="satir-esnek kucuk" style="gap:7px;padding:7px 12px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="platform-kutu" value="<?= $k ?>" <?= $k === 'instagram' ? 'checked' : '' ?>> <?= ikon(isset(IKONLAR[$k]) ? $k : 'diger', 14) ?> <?= $v ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tarih <span class="zorunlu">*</span></label><input type="date" name="tarih" class="girdi" required id="ic_tarih" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-grup"><label class="form-etiket">Saat</label><input type="time" name="saat" class="girdi"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Durum</label><select name="durum" class="secim"><?php foreach (ICERIK_DURUMLARI as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="form-grup"><label class="form-etiket">Açıklama / Metin</label><textarea name="aciklama" class="metin-alani" placeholder="Gönderi metni, hashtag'ler..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Planla</button></div>
    </form></div>
</div>
<?php endif; ?>

<!-- İçerik detay -->
<div class="modal-katman" id="modalIcerikDetay">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="idBaslik"></div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <div class="modal-govde" id="idGovde"></div>
    </div>
</div>

<script>
const icerikler = <?= json_encode(array_column($tumIcerikler, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
const icDurum = <?= json_encode(ICERIK_DURUMLARI, JSON_UNESCAPED_UNICODE) ?>;
const platformlar = <?= json_encode(PLATFORMLAR, JSON_UNESCAPED_UNICODE) ?>;
const platformIkon = {}; // detay görünümünde yalnızca metin etiket kullanılır
const icerikYonetici = <?= yetki('icerik_yonet') ? 'true' : 'false' ?>;

function icerikEkle(tarih) { const el = document.getElementById('ic_tarih'); if (el) { el.value = tarih; modalAc('modalIcerik'); } }
const icForm = document.getElementById('icerikForm');
if (icForm) {
    icForm.addEventListener('submit', () => {
        document.getElementById('ic_platformlar').value = JSON.stringify(Array.from(document.querySelectorAll('.platform-kutu:checked')).map(c => c.value));
    });
    document.getElementById('ic_proje').addEventListener('change', function () {
        const dosya = this.selectedOptions[0]?.dataset.dosya;
        if (dosya) document.getElementById('ic_dosya').value = dosya;
    });
}
function icerikGoster(id) {
    const ic = icerikler[id]; if (!ic) return;
    document.getElementById('idBaslik').textContent = ic.baslik;
    const platformListe = (ic.platform || '').split(',').map(pl => `${platformIkon[pl] || ''} ${platformlar[pl] || pl}`).join(' · ');
    let durumSecim = `<select class="secim mt-2" onchange="icDurumDegistir(${id},this.value)">`;
    for (const k in icDurum) durumSecim += `<option value="${k}" ${ic.durum === k ? 'selected' : ''}>${icDurum[k]}</option>`;
    durumSecim += `</select>`;
    let h = `<div class="dikey" style="gap:12px">
        <div class="satir-esnek arasi"><span class="hucre-alt">Dosya</span><span class="kucuk kalin">${ic.dosya_ad || '—'}</span></div>
        ${ic.proje_ad ? `<div class="satir-esnek arasi"><span class="hucre-alt">Proje</span><span class="kucuk">${ic.proje_ad}</span></div>` : ''}
        <div class="satir-esnek arasi"><span class="hucre-alt">Platformlar</span><span class="kucuk">${platformListe}</span></div>
        <div class="satir-esnek arasi"><span class="hucre-alt">Tarih</span><span class="kucuk">${new Date(ic.tarih).toLocaleDateString('tr-TR', { dateStyle: 'long' })}${ic.saat ? ' ' + ic.saat.slice(0, 5) : ''}</span></div>
        <div><div class="hucre-alt mb-2">Durum</div>${durumSecim}</div>`;
    if (ic.aciklama) h += `<div><div class="hucre-alt mb-2">İçerik</div><div class="kucuk metin-2" style="white-space:pre-wrap">${ic.aciklama.replace(/</g, '&lt;')}</div></div>`;
    if (ic.gorev_id) h += `<a href="gorev.php?id=${ic.gorev_id}" class="btn btn-sm mt-2" style="margin-right:6px">Bağlı göreve git →</a>`;
    if (icerikYonetici) h += `<div class="satir-esnek mt-2" style="gap:8px"><input type="date" class="girdi" id="icTasiTarih" value="${ic.tarih}" style="max-width:150px"><input type="time" class="girdi" id="icTasiSaat" value="${(ic.saat||'').slice(0,5)}" style="max-width:110px"><button class="btn btn-sm" onclick="icTasi(${id})">Tarihi Güncelle</button></div>`;
    if (icerikYonetici) h += `<button class="btn btn-tehlike btn-sm mt-2" onclick="icSil(${id})">İçeriği Sil</button>`;
    h += `</div>`;
    document.getElementById('idGovde').innerHTML = h;
    if (window.ozelSeciciYenile) ozelSeciciYenile();
    modalAc('modalIcerikDetay');
}
async function icDurumDegistir(id, durum) { const j = await api('icerik_durum', { id, durum }); if (j.ok) toast('Durum güncellendi', 'basari'); }
async function icSil(id) { if (confirm('İçerik silinsin mi?')) { const j = await api('icerik_sil', { id }); if (j.ok) location.reload(); } }
async function icTasi(id) {
    const tEl = document.getElementById('icTasiTarih'), sEl = document.getElementById('icTasiSaat');
    const j = await api('icerik_tasi', { id, tarih: tEl.dataset.deger ?? tEl.value, saat: sEl.dataset.deger ?? sEl.value });
    if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(() => location.reload(), 600); }
}
// Sürükle-bırak: içeriği başka güne taşı
let surIcerik = null;
document.querySelectorAll('.takvim-etkinlik[data-icerik]').forEach(chip => {
    chip.addEventListener('dragstart', e => { surIcerik = chip.dataset.icerik; e.stopPropagation(); });
});
document.querySelectorAll('.takvim-hucre[data-tarih]').forEach(hucre => {
    hucre.addEventListener('dragover', e => { if (surIcerik) { e.preventDefault(); hucre.style.borderColor = 'var(--marka)'; } });
    hucre.addEventListener('dragleave', () => hucre.style.borderColor = '');
    hucre.addEventListener('drop', async e => {
        e.preventDefault(); hucre.style.borderColor = '';
        if (!surIcerik) return;
        const j = await api('icerik_tasi', { id: surIcerik, tarih: hucre.dataset.tarih, saat: '' });
        surIcerik = null;
        if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(() => location.reload(), 500); }
    });
});
</script>
<?php sayfa_sonu(); ?>

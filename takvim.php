<?php
/**
 * SADA One — Çekim & Prodüksiyon Takvimi
 * Çok günlü etkinlikler hafta üzerinde kesintisiz şerit (bant) olarak gösterilir.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$yil = (int)($_GET['yil'] ?? date('Y'));
$ay = (int)($_GET['ay'] ?? date('n'));
if ($ay < 1) { $ay = 12; $yil--; } if ($ay > 12) { $ay = 1; $yil++; }

$ilkGun = mktime(0, 0, 0, $ay, 1, $yil);
$gunSayisi = (int)date('t', $ilkGun);
$baslangicHafta = (int)date('N', $ilkGun); // 1=Pzt

$ayBas = sprintf('%04d-%02d-01', $yil, $ay);
$aySon = sprintf('%04d-%02d-%02d', $yil, $ay, $gunSayisi);

// Ay ile kesişen tüm etkinlikler
$etkinlikler = rows("SELECT e.*, p.ad proje_ad, d.ad dosya_ad FROM etkinlikler e LEFT JOIN projeler p ON p.id=e.proje_id LEFT JOIN dosyalar d ON d.id=COALESCE(e.dosya_id, p.dosya_id)
    WHERE DATE(e.baslangic) <= ? AND DATE(COALESCE(e.bitis, e.baslangic)) >= ? ORDER BY e.baslangic", [$aySon, $ayBas]);

// Etkinlik başına bağlı ekipmanlar (detay modalı için)
$etkinlikEkipman = [];
if ($etkinlikler) {
    $eIdler = implode(',', array_map(fn($e) => (int)$e['id'], $etkinlikler));
    foreach (rows("SELECT ee.etkinlik_id, ek.id, ek.kod, ek.ad, ek.durum FROM etkinlik_ekipmanlari ee JOIN ekipmanlar ek ON ek.id=ee.ekipman_id WHERE ee.etkinlik_id IN ($eIdler)") as $r) {
        $etkinlikEkipman[$r['etkinlik_id']][] = $r;
    }
}
foreach ($etkinlikler as &$e) $e['ekipmanlar'] = $etkinlikEkipman[$e['id']] ?? [];
unset($e);

/* ---- Haftalara böl: her hafta 7 hücre (ay dışı günler null) ---- */
$haftalar = [];
$hafta = array_fill(0, $baslangicHafta - 1, null);
for ($gun = 1; $gun <= $gunSayisi; $gun++) {
    $hafta[] = $gun;
    if (count($hafta) === 7) { $haftalar[] = $hafta; $hafta = []; }
}
if ($hafta) $haftalar[] = array_pad($hafta, 7, null);

/* ---- Etkinlikleri ayır: tek günlük (chip) / çok günlük (bant) ---- */
$tekGunluk = [];   // gün → etkinlik listesi
$cokGunluk = [];   // bant listesi
foreach ($etkinlikler as $e) {
    $basTs = strtotime(date('Y-m-d', strtotime($e['baslangic'])));
    $sonTs = strtotime(date('Y-m-d', strtotime($e['bitis'] ?: $e['baslangic'])));
    if ($sonTs < $basTs) $sonTs = $basTs;
    if ($basTs === $sonTs) {
        if ((int)date('n', $basTs) === $ay && (int)date('Y', $basTs) === $yil) $tekGunluk[(int)date('j', $basTs)][] = $e;
    } else {
        $cokGunluk[] = ['e' => $e, 'bas' => $basTs, 'son' => $sonTs];
    }
}

/* ---- Her hafta için bantları hesapla (lane istifleme) ---- */
function hafta_bantlari(array $hafta, array $cokGunluk, int $ay, int $yil): array {
    // Haftadaki gerçek tarih aralığı
    $ilk = null; $son = null; $kolonTarih = [];
    foreach ($hafta as $kol => $gun) {
        $kolonTarih[$kol] = $gun ? mktime(0, 0, 0, $ay, $gun, $yil) : null;
        if ($gun) { if ($ilk === null) $ilk = $kolonTarih[$kol]; $son = $kolonTarih[$kol]; }
    }
    if ($ilk === null) return [];
    $bantlar = [];
    foreach ($cokGunluk as $c) {
        if ($c['son'] < $ilk || $c['bas'] > $son) continue;
        // Haftadaki başlangıç/bitiş kolonları
        $basKol = 0; $sonKol = 6;
        foreach ($kolonTarih as $kol => $t) {
            if ($t !== null && $t <= $c['bas']) $basKol = $kol;
            if ($t !== null && $t <= $c['son']) $sonKol = $kol;
        }
        if ($kolonTarih[$basKol] === null) { foreach ($kolonTarih as $kol => $t) { if ($t !== null) { $basKol = $kol; break; } } }
        $bantlar[] = [
            'e' => $c['e'], 'bas_kol' => $basKol, 'son_kol' => $sonKol,
            'soldan_devam' => $c['bas'] < ($kolonTarih[$basKol] ?? $ilk),
            'sagdan_devam' => $c['son'] > ($kolonTarih[$sonKol] ?? $son),
        ];
    }
    // Lane ata (çakışanlar alt alta)
    $laneler = [];
    foreach ($bantlar as $i => $b) {
        $lane = 0;
        while (true) {
            $cakisti = false;
            foreach ($bantlar as $j => $b2) {
                if ($j >= $i || ($b2['lane'] ?? -1) !== $lane) continue;
                if ($b['bas_kol'] <= $b2['son_kol'] && $b['son_kol'] >= $b2['bas_kol']) { $cakisti = true; break; }
            }
            if (!$cakisti) break;
            $lane++;
        }
        $bantlar[$i]['lane'] = $lane;
    }
    return $bantlar;
}

$projeler = rows("SELECT id, ad, dosya_id FROM projeler WHERE durum='aktif' ORDER BY ad");
$dosyalar = rows("SELECT id, ad FROM dosyalar WHERE durum='aktif' ORDER BY ad");
$musaitEkipman = rows("SELECT id, kod, ad, kategori FROM ekipmanlar WHERE durum='studyoda' ORDER BY FIELD(kategori,'kamera','lens','sd_kart','tripod','isik','ses','drone','aksesuar','diger'), kod");

$turRenkleri = ['cekim' => '#e86b82', 'toplanti' => 'var(--bilgi)', 'teslim' => 'var(--uyari)', 'diger' => 'var(--marka)'];

sayfa_basi('Çekim & Prodüksiyon Takvimi', 'takvim');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Prodüksiyon Takvimi</div><div class="sayfa-alt">Çekimler, toplantılar ve teslim tarihleri — çok günlü işler şerit olarak yayılır</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalEtkinlik"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Etkinlik Ekle</button></div>
</div>

<div class="kart">
    <div class="takvim-baslik-bar">
        <div class="satir-esnek" style="gap:8px">
            <a href="?ay=<?= $ay - 1 ?>&yil=<?= $yil ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
            <div class="takvim-ay-ad"><?= AYLAR[$ay] ?> <?= $yil ?></div>
            <a href="?ay=<?= $ay + 1 ?>&yil=<?= $yil ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>
        </div>
        <a href="?ay=<?= date('n') ?>&yil=<?= date('Y') ?>" class="btn btn-sm">Bugün</a>
    </div>

    <div class="takvim-izgara" style="margin-bottom:4px">
        <?php foreach (['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $gAd): ?><div class="takvim-gun-baslik"><?= $gAd ?></div><?php endforeach; ?>
    </div>

    <?php foreach ($haftalar as $hafta):
        $bantlar = hafta_bantlari($hafta, $cokGunluk, $ay, $yil);
        $laneSayisi = $bantlar ? max(array_column($bantlar, 'lane')) + 1 : 0;
        $bantAlani = $laneSayisi * 26; ?>
    <div class="takvim-hafta">
        <?php // Bantlar (hücrelerin üzerine, gün numarasının altına)
        foreach ($bantlar as $b):
            $e = $b['e'];
            $renk = $turRenkleri[$e['tur']] ?? 'var(--marka)';
            $sol = $b['bas_kol'] / 7 * 100;
            $genislik = ($b['son_kol'] - $b['bas_kol'] + 1) / 7 * 100; ?>
        <div class="takvim-bant <?= $b['soldan_devam'] ? 'devam-sol' : '' ?> <?= $b['sagdan_devam'] ? 'devam-sag' : '' ?>"
             style="left:calc(<?= $sol ?>% + 3px);width:calc(<?= $genislik ?>% - 6px);top:<?= 30 + $b['lane'] * 26 ?>px;--bant-renk:<?= $renk ?>"
             onclick="etkinlikGoster(<?= $e['id'] ?>)" title="<?= e($e['baslik']) ?> · <?= tarih(substr($e['baslangic'], 0, 10)) ?> → <?= tarih(substr($e['bitis'], 0, 10)) ?>">
            <?= $b['soldan_devam'] ? '◂ ' : '' ?><?= e($e['baslik']) ?><?= $b['sagdan_devam'] ? ' ▸' : '' ?>
        </div>
        <?php endforeach; ?>

        <?php foreach ($hafta as $gun):
            if ($gun === null): ?><div class="takvim-hucre bos"></div><?php continue; endif;
            $bugun = ($gun == date('j') && $ay == date('n') && $yil == date('Y'));
            $tarihStr = sprintf('%04d-%02d-%02d', $yil, $ay, $gun); ?>
        <div class="takvim-hucre <?= $bugun ? 'bugun' : '' ?>" data-tarih="<?= $tarihStr ?>" onclick="etkinlikEkle('<?= $tarihStr ?>')" style="cursor:pointer;padding-top:<?= 30 + $bantAlani ?>px">
            <div class="takvim-gun-no" style="position:absolute;top:8px;right:10px"><?= $gun ?></div>
            <?php foreach ($tekGunluk[$gun] ?? [] as $e): ?>
            <div class="takvim-etkinlik <?= $e['tur'] ?>" draggable="true" data-etkinlik="<?= $e['id'] ?>" onclick="event.stopPropagation();etkinlikGoster(<?= $e['id'] ?>)" title="<?= e($e['baslik']) ?>"><?= date('H:i', strtotime($e['baslangic'])) ?> <?= e($e['baslik']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<div class="izgara izgara-3 mt-3">
    <?php foreach (ETKINLIK_TURLERI as $k => $v):
        $renk = $turRenkleri[$k];
        $say = count(array_filter($etkinlikler, fn($e) => $e['tur'] === $k)); ?>
    <div class="kart satir-esnek" style="gap:12px;padding:14px"><span class="etiket-nokta" style="width:14px;height:14px;background:<?= $renk ?>"></span><div><div class="kalin"><?= $say ?> <?= $v ?></div><div class="hucre-alt">bu ay</div></div></div>
    <?php endforeach; ?>
</div>

<!-- Etkinlik ekle -->
<div class="modal-katman" id="modalEtkinlik">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik">Yeni Etkinlik</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="etkinlik_kaydet" data-yenile="evet" id="etkinlikForm">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="baslik" class="girdi" required id="et_baslik"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tür</label><select name="tur" class="secim"><?php foreach (ETKINLIK_TURLERI as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Yer</label><input name="yer" class="girdi"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Başlangıç <span class="zorunlu">*</span></label><input type="datetime-local" name="baslangic" class="girdi" required id="et_baslangic"></div>
                <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="datetime-local" name="bitis" class="girdi"><div class="form-ipucu">Farklı güne uzarsa takvimde şerit olarak yayılır.</div></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">İlgili Dosya</label><select name="dosya_id" id="ev_dosya" class="secim"><option value="">— Ajans içi</option><?php foreach ($dosyalar as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['ad']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Etkinliğin hangi marka/müşteriyle ilgili olduğu.</div></div>
                <div class="form-grup"><label class="form-etiket">Proje (opsiyonel)</label><select name="proje_id" id="ev_proje" class="secim"><option value="">—</option><?php foreach ($projeler as $p): ?><option value="<?= $p['id'] ?>" data-dosya="<?= $p['dosya_id'] ?>"><?= e($p['ad']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Katılımcılar</label><input name="katilimcilar" class="girdi" placeholder="İsimler, virgülle ayırın"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Alınacaklar</label><textarea name="alinacaklar" class="metin-alani" rows="2" placeholder="- Yedek pil&#10;- Gaffer bandı"></textarea></div>
                <div class="form-grup"><label class="form-etiket">İhtiyaç Listesi</label><textarea name="ihtiyac_listesi" class="metin-alani" rows="2" placeholder="- Mekan izni&#10;- Prompter metni"></textarea></div>
            </div>
            <?php if ($musaitEkipman): ?>
            <div class="form-grup">
                <label class="form-etiket">Ekipman Seç <span class="metin-muted" style="font-weight:400">(stüdyodaki müsait ekipmanlar — seçilenler çekime zimmetlenir)</span></label>
                <input type="hidden" name="ekipmanlar" id="et_ekipmanlar">
                <div class="izgara izgara-2" style="gap:6px;max-height:180px;overflow-y:auto;padding:2px">
                    <?php foreach ($musaitEkipman as $me): ?>
                    <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="ekipman-kutu" value="<?= $me['id'] ?>">
                        <?= ikon($me['kategori'], 14) ?><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $me['kod'] ? e($me['kod']) . ' — ' : '' ?><?= e($me['ad']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="form-grup"><label class="form-etiket">Not</label><textarea name="aciklama" class="metin-alani"></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- Etkinlik detay -->
<div class="modal-katman" id="modalEtkinlikDetay">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="edBaslik"></div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <div class="modal-govde" id="edGovde"></div>
    <div class="modal-alt"><button type="button" class="btn btn-tehlike" id="edSil">Sil</button><button type="button" class="btn btn-hayalet" data-modal-kapat>Kapat</button></div>
    </div>
</div>

<script>
const etkinlikler = <?= json_encode(array_column($etkinlikler, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
const turAd = <?= json_encode(ETKINLIK_TURLERI, JSON_UNESCAPED_UNICODE) ?>;
function etkinlikEkle(tarih) { document.getElementById('et_baslangic').value = tarih + 'T10:00'; modalAc('modalEtkinlik'); }
// Proje seçilince dosyası otomatik dolsun
document.getElementById('ev_proje').addEventListener('change', function () {
    const dosya = this.selectedOptions[0]?.dataset.dosya;
    if (dosya) document.getElementById('ev_dosya').value = dosya;
});
document.getElementById('etkinlikForm').addEventListener('submit', () => {
    const alan = document.getElementById('et_ekipmanlar');
    if (alan) alan.value = JSON.stringify(Array.from(document.querySelectorAll('.ekipman-kutu:checked')).map(c => c.value));
});
function etkinlikGoster(id) {
    const e = etkinlikler[id]; if (!e) return;
    document.getElementById('edBaslik').textContent = e.baslik;
    let h = `<div class="dikey" style="gap:12px">
        <div class="satir-esnek arasi"><span class="hucre-alt">Tür</span><span class="rozet rozet-tur">${turAd[e.tur]}</span></div>
        <div class="satir-esnek arasi"><span class="hucre-alt">Başlangıç</span><span class="kucuk kalin">${new Date(e.baslangic.replace(' ', 'T')).toLocaleString('tr-TR', { dateStyle: 'medium', timeStyle: 'short' })}</span></div>`;
    if (e.bitis) h += `<div class="satir-esnek arasi"><span class="hucre-alt">Bitiş</span><span class="kucuk kalin">${new Date(e.bitis.replace(' ', 'T')).toLocaleString('tr-TR', { dateStyle: 'medium', timeStyle: 'short' })}</span></div>`;
    if (e.yer) h += `<div class="satir-esnek arasi"><span class="hucre-alt">Yer</span><span class="kucuk">${e.yer}</span></div>`;
    if (e.dosya_ad) h += `<div class="satir-esnek arasi"><span class="hucre-alt">Dosya</span><span class="kucuk kalin">${e.dosya_ad}</span></div>`;
    if (e.proje_ad) h += `<div class="satir-esnek arasi"><span class="hucre-alt">Proje</span><span class="kucuk">${e.proje_ad}</span></div>`;
    if (e.katilimcilar) h += `<div><div class="hucre-alt mb-2">Katılımcılar</div><div class="kucuk">${e.katilimcilar}</div></div>`;
    if (e.ekipmanlar && e.ekipmanlar.length) {
        h += `<div><div class="satir-esnek arasi mb-2"><span class="hucre-alt">Ekipmanlar (${e.ekipmanlar.length})</span>`;
        const disarida = e.ekipmanlar.some(k => k.durum === 'cekimde');
        if (disarida) h += `<button class="mini-btn" onclick="ekipmanIade(${id})">Tümünü iade al</button>`;
        h += `</div>`;
        e.ekipmanlar.forEach(k => {
            const rozet = k.durum === 'cekimde' ? '<span class="rozet r-bekliyor">Çekimde</span>' : '<span class="rozet r-onaylandi">Stüdyoda</span>';
            h += `<div class="satir-esnek arasi kucuk" style="padding:6px 10px;background:var(--surface-2);border-radius:8px;margin-bottom:4px"><span>${k.kod ? k.kod + ' — ' : ''}${k.ad}</span>${rozet}</div>`;
        });
        h += `</div>`;
    }
    if (e.aciklama) h += `<div><div class="hucre-alt mb-2">Not</div><div class="kucuk metin-2">${e.aciklama.replace(/</g, '&lt;')}</div></div>`;
    if (e.alinacaklar) h += `<div><div class="hucre-alt mb-2">🛒 Alınacaklar</div><div class="kucuk metin-2" style="white-space:pre-wrap">${e.alinacaklar.replace(/</g, '&lt;')}</div></div>`;
    if (e.ihtiyac_listesi) h += `<div><div class="hucre-alt mb-2">📋 İhtiyaç Listesi</div><div class="kucuk metin-2" style="white-space:pre-wrap">${e.ihtiyac_listesi.replace(/</g, '&lt;')}</div></div>`;
    h += `<div><div class="hucre-alt mb-2">Tarihi Değiştir</div><div class="satir-esnek sarma" style="gap:8px"><input type="datetime-local" class="girdi" id="etTasiBas" value="${e.baslangic.replace(' ', 'T').slice(0,16)}" style="max-width:200px"><input type="datetime-local" class="girdi" id="etTasiBit" value="${e.bitis ? e.bitis.replace(' ', 'T').slice(0,16) : ''}" style="max-width:200px"><button class="btn btn-sm" onclick="etTasi(${id})">Güncelle</button></div></div>`;
    h += `</div>`;
    document.getElementById('edGovde').innerHTML = h;
    if (window.ozelSeciciYenile) ozelSeciciYenile();
    document.getElementById('edSil').onclick = async () => {
        if (confirm('Etkinlik silinsin mi? (Çekimdeki ekipmanlar otomatik iade alınır)')) {
            await api('etkinlik_ekipman_iade', { etkinlik_id: id });
            const j = await api('etkinlik_sil', { id });
            if (j.ok) location.reload();
        }
    };
    modalAc('modalEtkinlikDetay');
}
async function etTasi(id) {
    const bEl = document.getElementById('etTasiBas'), tEl = document.getElementById('etTasiBit');
    const bV = bEl.dataset.deger ?? bEl.value, tV = tEl.dataset.deger ?? tEl.value;
    const j = await api('etkinlik_tasi', { id, baslangic: bV.replace('T', ' '), bitis: tV.replace('T', ' ') });
    if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(() => location.reload(), 600); }
}
// Tek günlük etkinlikleri sürükleyerek taşı
let surEt = null;
document.querySelectorAll('.takvim-etkinlik[data-etkinlik]').forEach(chip => {
    chip.addEventListener('dragstart', e => { surEt = chip.dataset.etkinlik; e.stopPropagation(); });
});
document.querySelectorAll('.takvim-hucre[data-tarih]').forEach(hucre => {
    hucre.addEventListener('dragover', e => { if (surEt) { e.preventDefault(); hucre.style.borderColor = 'var(--marka)'; } });
    hucre.addEventListener('dragleave', () => hucre.style.borderColor = '');
    hucre.addEventListener('drop', async e => {
        e.preventDefault(); hucre.style.borderColor = '';
        if (!surEt) return;
        const j = await api('etkinlik_tasi', { id: surEt, tarih: hucre.dataset.tarih });
        surEt = null;
        if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(() => location.reload(), 500); }
    });
});
async function ekipmanIade(etkinlikId) {
    const j = await api('etkinlik_ekipman_iade', { etkinlik_id: etkinlikId });
    if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(() => location.reload(), 600); }
}
</script>
<?php sayfa_sonu(); ?>

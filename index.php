<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bilesenler.php';
$u = require_login();

// Widget kişiselleştirme: kullanıcının seçtiği bölümler (varsayılan hepsi açık)
const PANEL_WIDGETLERI = ['duyurular' => 'Duyurular', 'yaklasanlar' => 'Yaklaşanlar (7 gün)', 'istatistik' => 'İstatistik kartları', 'ekip' => 'Ekip durumu', 'gorevlerim' => 'Görevlerim', 'hareketler' => 'Son hareketler', 'uyarilar' => 'Uyarılar (geciken/talep)'];
$acikWidgetler = json_decode($u['widgetler'] ?? '', true);
if (!is_array($acikWidgetler)) $acikWidgetler = array_keys(PANEL_WIDGETLERI);
$wAcik = fn($k) => in_array($k, $acikWidgetler);

sayfa_basi('Panel', 'panel');

/* ---------- Sürüm notları (kapatılabilir) ---------- */
if (($u['gorulen_surum'] ?? '') !== SURUM && isset(SURUM_NOTLARI[SURUM])): ?>
<div class="kart mb-3" id="surumKarti" style="border-color:var(--marka);background:linear-gradient(135deg,var(--surface),var(--parlak))">
    <div class="satir-esnek arasi" style="align-items:flex-start;gap:12px">
        <div>
            <div class="kart-baslik"><?= ikon('roket', 17) ?> Yenilikler — sürüm <?= SURUM ?></div>
            <ul class="kucuk metin-2 mt-2" style="list-style:none;display:flex;flex-direction:column;gap:6px">
                <?php foreach (SURUM_NOTLARI[SURUM] as $notSatiri): ?><li><?= $notSatiri ?></li><?php endforeach; ?>
            </ul>
        </div>
        <button class="btn btn-sm" onclick="surumKapat()" style="flex-shrink:0">Kapat ✕</button>
    </div>
</div>
<script>
async function surumKapat() {
    const j = await api('surum_kapat');
    if (j.ok) document.getElementById('surumKarti').remove();
}
</script>
<?php endif;

if (is_staff()) {
    /* ---------- EKİP PANELİ ---------- */
    $dosyaSayi = (int)val("SELECT COUNT(*) FROM dosyalar WHERE durum='aktif'");
    $projeSayi = (int)val("SELECT COUNT(*) FROM projeler WHERE durum='aktif'");
    $benimGorev = (int)val("SELECT COUNT(*) FROM gorevler WHERE atanan_id=? AND durum!='tamamlandi'", [$u['id']]);
    $bekleyenOnay = (int)val("SELECT COUNT(*) FROM onaylar WHERE durum='bekliyor'");
    $geciken = (int)val("SELECT COUNT(*) FROM gorevler WHERE son_tarih<CURDATE() AND durum!='tamamlandi'");
    $yeniTalep = (int)val("SELECT COUNT(*) FROM talepler WHERE durum='yeni'");
?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik">Merhaba, <?= e(explode(' ', $u['ad'])[0]) ?> 👋</div>
        <div class="sayfa-alt"><?= GUNLER[(int)date('N') - 1] ?>, <?= tarih(date('Y-m-d')) ?> — bugünün özeti</div>
    </div>
    <div class="sayfa-ust-aksiyon">
        <button class="btn btn-hayalet" data-modal="modalWidget" title="Panel görünümünü kişiselleştir">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h7"/></svg> Paneli Düzenle
        </button>
        <?php if (yetki('dosya_yonet')): ?>
        <button class="btn btn-marka" data-modal="modalProje">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Proje
        </button>
        <?php endif; ?>
    </div>
</div>

<?php
// Okunmamış duyurular
$duyurular = rows("SELECT d.*, us.ad olusturan_ad FROM duyurular d LEFT JOIN users us ON us.id=d.olusturan_id
    WHERE NOT EXISTS(SELECT 1 FROM duyuru_okuyanlar o WHERE o.duyuru_id=d.id AND o.user_id=?)
    ORDER BY d.onemli DESC, d.id DESC LIMIT 3", [$u['id']]);
if ($duyurular && $wAcik('duyurular')): ?>
<div class="dikey mb-3" style="gap:10px">
    <?php foreach ($duyurular as $dy): ?>
    <div class="kart satir-esnek arasi sarma" style="gap:12px;border-color:<?= $dy['onemli'] ? 'var(--uyari)' : 'var(--border-2)' ?>;<?= $dy['onemli'] ? 'background:linear-gradient(135deg,var(--surface),rgba(245,165,36,.06))' : '' ?>">
        <div class="satir-esnek" style="gap:12px;min-width:0">
            <span class="dosya-avatar" style="width:38px;height:38px;background:var(--parlak);color:<?= $dy['onemli'] ? 'var(--uyari)' : 'var(--marka)' ?>;flex-shrink:0"><?= ikon($dy['onemli'] ? 'megafon' : 'pin', 18) ?></span>
            <div style="min-width:0">
                <div class="kalin"><?= e($dy['baslik']) ?></div>
                <?php if ($dy['metin']): ?><div class="kucuk metin-2 mt-1"><?= nl2br(e(mb_substr($dy['metin'], 0, 220))) ?></div><?php endif; ?>
                <div class="hucre-alt mt-1"><?= e($dy['olusturan_ad']) ?> · <?= zaman_once($dy['created']) ?></div>
            </div>
        </div>
        <button class="btn btn-sm" data-eylem="duyuru_oku" data-id="<?= $dy['id'] ?>">Okudum ✓</button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="stat-izgara <?= $wAcik('istatistik') ? '' : 'widget-kapali' ?>">
    <?php
    $statlar = [
        ['deger' => $dosyaSayi, 'etiket' => 'Aktif Dosya', 'ikon' => 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z', 'link' => 'dosyalar.php'],
        ['deger' => $projeSayi, 'etiket' => 'Aktif Proje', 'ikon' => 'M9 12h6m-6 4h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z', 'link' => 'projeler.php'],
        ['deger' => $benimGorev, 'etiket' => 'Bekleyen Görevim', 'ikon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2', 'link' => 'gorevler.php'],
        ['deger' => $bekleyenOnay, 'etiket' => 'Bekleyen Onay', 'ikon' => 'M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'link' => 'onaylar.php'],
    ];
    foreach ($statlar as $s): ?>
    <a href="<?= $s['link'] ?>" class="stat-kart">
        <div class="stat-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="<?= $s['ikon'] ?>"/></svg></div>
        <div class="stat-deger" data-sayac="<?= $s['deger'] ?>">0</div>
        <div class="stat-etiket"><?= $s['etiket'] ?></div>
    </a>
    <?php endforeach; ?>
</div>

<div class="izgara izgara-2">
    <!-- Bana atanan görevler -->
    <div class="kart <?= $wAcik('gorevlerim') ? '' : 'widget-kapali' ?>">
        <div class="kart-ust">
            <div class="kart-baslik">
                <svg width="18" fill="none" stroke="var(--marka)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                Görevlerim
            </div>
            <a href="gorevler.php" class="mini-btn">Tümü →</a>
        </div>
        <?php
        $gorevlerim = rows("SELECT g.*, p.ad proje_ad FROM gorevler g JOIN projeler p ON p.id=g.proje_id WHERE g.atanan_id=? AND g.durum!='tamamlandi' AND g.arsivlendi=0 ORDER BY g.son_tarih IS NULL, g.son_tarih ASC LIMIT 6", [$u['id']]);
        $pAdimKosul = sadece_kendi_adimlarim() ? "ga.sorumlu_id=?" : "(ga.sorumlu_id=? OR (ga.sorumlu_id IS NULL AND (g.atanan_id=? OR EXISTS(SELECT 1 FROM gorev_atananlar gat WHERE gat.gorev_id=g.id AND gat.user_id=?))))";
        $pAdimParam = sadece_kendi_adimlarim() ? [$u['id']] : [$u['id'], $u['id'], $u['id']];
        $panelAdimlar = rows("SELECT ga.ad adim_ad, g.id gid, g.baslik FROM gorev_adimlari ga JOIN gorevler g ON g.id=ga.gorev_id WHERE ga.durum='aktif' AND g.arsivlendi=0 AND $pAdimKosul LIMIT 4", $pAdimParam);
        if ($panelAdimlar): ?>
        <div class="katla kapali" data-katla="panelAdimlar" style="border-bottom:1px solid var(--border)">
            <button data-katla-btn type="button" class="satir-esnek" style="gap:8px;padding:10px 0;width:100%">
                <span class="katla-ok"><svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" width="12"><path d="M19 9l-7 7-7-7"/></svg></span>
                <span class="kucuk kalin"><?= ikon('roket', 13) ?> Adımlarım</span>
                <span class="rozet r-devam" style="padding:1px 8px"><?= count($panelAdimlar) ?> sıra sende</span>
            </button>
            <div class="katla-icerik">
        <?php foreach ($panelAdimlar as $pa): ?>
        <a href="gorev.php?id=<?= $pa['gid'] ?>" class="satir-esnek arasi" style="padding:11px 0;border-bottom:1px solid var(--border)">
            <div style="min-width:0"><div class="hucre-ana" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= ikon('roket', 12) ?> <?= e($pa['adim_ad']) ?></div><div class="hucre-alt"><?= e($pa['baslik']) ?> · akış adımı</div></div>
            <span class="rozet r-devam">Sıra sende</span>
        </a>
        <?php endforeach; ?>
            </div>
        </div>
        <?php endif;
        if (!$gorevlerim && !$panelAdimlar): ?>
            <div class="metin-muted kucuk" style="padding:20px 0;text-align:center">Bekleyen görevin yok 🎉</div>
        <?php else: foreach ($gorevlerim as $gr):
            $gecikti = $gr['son_tarih'] && $gr['son_tarih'] < date('Y-m-d'); ?>
        <a href="gorev.php?id=<?= $gr['id'] ?>" class="satir-esnek arasi" style="padding:11px 0;border-bottom:1px solid var(--border)">
            <div style="min-width:0">
                <div class="hucre-ana" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($gr['baslik']) ?></div>
                <div class="hucre-alt"><?= e($gr['proje_ad']) ?><?php if ($gr['son_tarih']): ?> · <span style="color:<?= $gecikti ? 'var(--tehlike)' : 'inherit' ?>"><?= tarih($gr['son_tarih']) ?></span><?php endif; ?></div>
            </div>
            <?= rozet($gr['durum'], GOREV_DURUMLARI) ?>
        </a>
        <?php endforeach; endif; ?>
    </div>

    <!-- Son aktiviteler -->
    <div class="kart <?= $wAcik('hareketler') ? '' : 'widget-kapali' ?>">
        <div class="kart-ust">
            <div class="kart-baslik">
                <svg width="18" fill="none" stroke="var(--marka)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Son Hareketler
            </div>
        </div>
        <?php
        $aktiviteler = rows("SELECT a.*, u.ad, u.renk FROM aktiviteler a JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 8");
        if (!$aktiviteler): ?>
            <div class="metin-muted kucuk" style="padding:20px 0;text-align:center">Henüz hareket yok</div>
        <?php else: ?>
        <div class="zaman-tunel" style="margin-top:4px">
            <?php foreach ($aktiviteler as $a): ?>
            <div class="tunel-oge">
                <div class="tunel-metin"><b><?= e($a['ad']) ?></b> <?= e($a['aciklama']) ?></div>
                <div class="tunel-zaman"><?= zaman_once($a['created']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($wAcik('yaklasanlar')):
    // Önümüzdeki 7 günün öğeleri: görevlerim + toplantılarım + etkinlikler + içerikler
    $bugun = date('Y-m-d'); $yediGun = date('Y-m-d', strtotime('+7 days'));
    $yaklasanlar = [];
    foreach (rows("SELECT g.id, g.baslik, g.son_tarih tarih FROM gorevler g WHERE g.arsivlendi=0 AND g.durum!='tamamlandi' AND g.son_tarih BETWEEN ? AND ?
        AND (g.atanan_id=? OR EXISTS(SELECT 1 FROM gorev_atananlar ga WHERE ga.gorev_id=g.id AND ga.user_id=?)) ORDER BY g.son_tarih LIMIT 8", [$bugun, $yediGun, $u['id'], $u['id']]) as $r)
        $yaklasanlar[] = ['tarih' => $r['tarih'], 'saat' => null, 'ikon' => ikon('onay', 15), 'metin' => $r['baslik'], 'alt' => 'Görev teslimi', 'link' => 'gorev.php?id=' . $r['id']];
    foreach (rows("SELECT e.id, e.baslik, DATE(e.baslangic) tarih, TIME(e.baslangic) saat, e.online_link FROM etkinlikler e WHERE e.tur='toplanti' AND DATE(e.baslangic) BETWEEN ? AND ?
        AND (e.olusturan_id=? OR EXISTS(SELECT 1 FROM etkinlik_katilimcilari ek WHERE ek.etkinlik_id=e.id AND ek.user_id=?)) ORDER BY e.baslangic LIMIT 8", [$bugun, $yediGun, $u['id'], $u['id']]) as $r)
        $yaklasanlar[] = ['tarih' => $r['tarih'], 'saat' => substr($r['saat'], 0, 5), 'ikon' => ikon('kisiler', 15), 'metin' => $r['baslik'], 'alt' => 'Toplantı' . ($r['online_link'] ? ' (online)' : ''), 'link' => 'toplantilar.php'];
    foreach (rows("SELECT id, baslik, DATE(baslangic) tarih, TIME(baslangic) saat, tur FROM etkinlikler WHERE tur!='toplanti' AND DATE(baslangic) BETWEEN ? AND ? ORDER BY baslangic LIMIT 6", [$bugun, $yediGun]) as $r)
        $yaklasanlar[] = ['tarih' => $r['tarih'], 'saat' => substr($r['saat'], 0, 5), 'ikon' => ikon('video', 15), 'metin' => $r['baslik'], 'alt' => ETKINLIK_TURLERI[$r['tur']], 'link' => 'takvim.php'];
    foreach (rows("SELECT id, baslik, tarih, saat, platform FROM icerikler WHERE tarih BETWEEN ? AND ? AND durum!='yayinlandi' ORDER BY tarih LIMIT 6", [$bugun, $yediGun]) as $r)
        $yaklasanlar[] = ['tarih' => $r['tarih'], 'saat' => $r['saat'] ? substr($r['saat'], 0, 5) : null, 'ikon' => ikon('takvim', 15), 'metin' => $r['baslik'], 'alt' => (PLATFORMLAR[$r['platform']] ?? '') . ' içeriği', 'link' => 'icerik-takvimi.php'];
    usort($yaklasanlar, fn($a, $b) => strcmp($a['tarih'] . ($a['saat'] ?? '99'), $b['tarih'] . ($b['saat'] ?? '99')));
    $yaklasanlar = array_slice($yaklasanlar, 0, 10);
    if ($yaklasanlar): ?>
<div class="kart mt-2">
    <div class="kart-ust">
        <div class="kart-baslik"><svg width="18" fill="none" stroke="var(--marka)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Yaklaşanlar — önümüzdeki 7 gün</div>
    </div>
    <div class="dikey" style="gap:4px">
        <?php $sonTarih = '';
        foreach ($yaklasanlar as $y):
            $gunEtiket = $y['tarih'] === date('Y-m-d') ? 'Bugün' : ($y['tarih'] === date('Y-m-d', strtotime('+1 day')) ? 'Yarın' : GUNLER[(int)date('N', strtotime($y['tarih'])) - 1] . ' ' . date('j', strtotime($y['tarih']))); ?>
        <a href="<?= $y['link'] ?>" class="satir-esnek" style="gap:11px;padding:8px 10px;border-radius:10px;transition:background .2s" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
            <span class="rozet <?= $y['tarih'] === date('Y-m-d') ? 'rozet-tur' : '' ?>" style="min-width:76px;justify-content:center"><?= $gunEtiket ?><?= $y['saat'] ? ' ' . $y['saat'] : '' ?></span>
            <span style="color:var(--marka);display:inline-flex"><?= $y['ikon'] ?></span>
            <span class="kucuk kalin" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($y['metin']) ?></span>
            <span class="hucre-alt" style="margin-left:auto;flex-shrink:0"><?= $y['alt'] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; endif; ?>

<?php if ($wAcik('ekip')):
    $ekipDurum = rows("SELECT us.id, us.ad, us.renk, us.avatar,
        (SELECT g.baslik FROM gorevler g WHERE g.arsivlendi=0 AND g.durum='devam' AND (g.atanan_id=us.id OR EXISTS(SELECT 1 FROM gorev_atananlar ga WHERE ga.gorev_id=g.id AND ga.user_id=us.id)) ORDER BY g.id DESC LIMIT 1) aktif_is
        FROM users us WHERE us.rol IN ('yonetici','pm','ekip','finans') AND us.aktif=1 ORDER BY us.ad LIMIT 10"); ?>
<div class="kart mt-2">
    <div class="kart-ust">
        <div class="kart-baslik"><svg width="18" fill="none" stroke="var(--marka)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg> Ekip Şu An</div>
        <a href="ekip.php" class="mini-btn">Ekip Panosu →</a>
    </div>
    <div class="izgara" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px">
        <?php foreach ($ekipDurum as $ed): ?>
        <div class="satir-esnek" style="gap:9px;padding:8px 10px;background:var(--surface-2);border-radius:10px;min-width:0">
            <?= avatar($ed, 30) ?>
            <div style="min-width:0">
                <div class="kucuk kalin"><?= e(explode(' ', $ed['ad'])[0]) ?></div>
                <div class="hucre-alt" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $ed['aktif_is'] ? '● ' . e($ed['aktif_is']) : '○ Boşta' ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (($geciken || $yeniTalep) && $wAcik('uyarilar')): ?>
<div class="izgara izgara-2 mt-2">
    <?php if ($geciken): ?>
    <div class="kart" style="border-color:rgba(240,79,79,.3)">
        <div class="satir-esnek arasi">
            <div class="satir-esnek">
                <div class="stat-ikon" style="margin:0;background:rgba(240,79,79,.14);color:var(--tehlike);width:38px;height:38px"><svg width="20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/></svg></div>
                <div><div class="kalin"><?= $geciken ?> geciken görev</div><div class="hucre-alt">Son tarihi geçmiş, tamamlanmamış</div></div>
            </div>
            <a href="gorevler.php?filtre=geciken" class="btn btn-sm">Görüntüle</a>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($yeniTalep && is_pm()): ?>
    <div class="kart" style="border-color:var(--border-2)">
        <div class="satir-esnek arasi">
            <div class="satir-esnek">
                <div class="stat-ikon" style="margin:0;width:38px;height:38px"><svg width="20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M8 10h8m-8 4h4m9-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                <div><div class="kalin"><?= $yeniTalep ?> yeni talep</div><div class="hucre-alt">Müşterilerden gelen istekler</div></div>
            </div>
            <a href="talepler.php" class="btn btn-sm">İncele</a>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Widget kişiselleştirme modalı -->
<div class="modal-katman" id="modalWidget">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Paneli Düzenle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <div class="modal-govde">
        <div class="hucre-alt mb-3">Panelde görmek istediğiniz bölümleri seçin.</div>
        <div class="dikey" style="gap:10px">
            <?php foreach (PANEL_WIDGETLERI as $wk => $wEtiket): ?>
            <label class="satir-esnek arasi" style="padding:11px 14px;background:var(--surface-2);border-radius:11px;cursor:pointer">
                <span class="kucuk"><?= $wEtiket ?></span>
                <span class="anahtar"><input type="checkbox" class="widget-kutu" value="<?= $wk ?>" <?= $wAcik($wk) ? 'checked' : '' ?>></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="button" class="btn btn-marka" onclick="widgetKaydet()">Kaydet</button></div>
    </div>
</div>
<script>
async function widgetKaydet() {
    const secili = Array.from(document.querySelectorAll('.widget-kutu:checked')).map(c => c.value);
    const j = await api('widget_kaydet', { widgetler: secili });
    if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(() => location.reload(), 550); }
}
</script>

<?php
    // PM için proje ekleme modalı (gerekli veriler)
    if (yetki('dosya_yonet')) proje_modali();

} else {
    /* ---------- MÜŞTERİ PANELİ ---------- */
    [$in, $p] = in_sorgu(musteri_dosya_idler());
    $projeler = rows("SELECT p2.*, d.ad dosya_ad FROM projeler p2 JOIN dosyalar d ON d.id=p2.dosya_id WHERE p2.dosya_id IN $in ORDER BY d.ad, p2.created DESC", $p);
    $bekleyenOnay = (int)val("SELECT COUNT(*) FROM onaylar o JOIN projeler pr ON pr.id=o.proje_id WHERE pr.dosya_id IN $in AND o.durum='bekliyor'", $p);
    $dosyaSayisi = count(musteri_dosya_idler());
    $dosya = row("SELECT * FROM dosyalar WHERE id=?", [$u['dosya_id']]);
?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik">Merhaba, <?= e(explode(' ', $u['ad'])[0]) ?> 👋</div>
        <div class="sayfa-alt"><?= $dosyaSayisi > 1 ? $dosyaSayisi . ' dosyanız — proje durumunuz' : e($dosya['ad'] ?? '') . ' — proje durumunuz' ?></div>
    </div>
    <div class="sayfa-ust-aksiyon">
        <a href="talepler.php" class="btn btn-marka">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Talep Oluştur
        </a>
    </div>
</div>

<div class="stat-izgara">
    <div class="stat-kart"><div class="stat-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg></div><div class="stat-deger" data-sayac="<?= count($projeler) ?>">0</div><div class="stat-etiket">Projeleriniz</div></div>
    <a href="onaylar.php" class="stat-kart"><div class="stat-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="stat-deger" data-sayac="<?= $bekleyenOnay ?>">0</div><div class="stat-etiket">Onayınızı bekleyen</div></a>
    <a href="mesajlar.php" class="stat-kart"><div class="stat-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M8 12h8m-8-4h8m-9 8l-4 4V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H7z"/></svg></div><div class="stat-deger" style="color:var(--marka)"><?= ikon('sohbet', 28) ?></div><div class="stat-etiket">Ekiple mesajlaşın</div></a>
</div>

<div class="kart">
    <div class="kart-ust"><div class="kart-baslik">Projeleriniz</div></div>
    <?php if (!$projeler): ?>
        <div class="metin-muted kucuk orta" style="padding:24px">Henüz projeniz bulunmuyor.</div>
    <?php else: ?>
    <div class="izgara izgara-auto">
        <?php foreach ($projeler as $p):
            $ilerleme = (int)val("SELECT COUNT(*) FROM gorevler WHERE proje_id=?", [$p['id']]);
            $tamam = (int)val("SELECT COUNT(*) FROM gorevler WHERE proje_id=? AND durum='tamamlandi'", [$p['id']]);
            $oran = $ilerleme ? round($tamam / $ilerleme * 100) : 0; ?>
        <a href="proje.php?id=<?= $p['id'] ?>" class="kart kart-tik" style="padding:16px">
            <div class="satir-esnek arasi mb-2">
                <span class="rozet rozet-tur"><?= PROJE_TURLERI[$p['tur']] ?></span>
                <?= rozet($p['durum'], PROJE_DURUMLARI) ?>
            </div>
            <div class="kart-baslik" style="font-size:15px"><?= e($p['ad']) ?></div>
            <div class="ilerleme mt-2"><div class="ilerleme-dolu" data-oran="<?= $oran ?>" style="width:0"></div></div>
            <div class="hucre-alt mt-1"><?= $tamam ?>/<?= $ilerleme ?> görev tamamlandı</div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php
}

sayfa_sonu();

/* ---------- Yeniden kullanılabilir proje modalı ---------- */
function proje_modali(?int $dosyaId = null) {
    $dosyalar = rows("SELECT id, ad FROM dosyalar WHERE durum='aktif' ORDER BY ad");
    $pmler = rows("SELECT id, ad FROM users WHERE rol IN ('yonetici','pm') AND aktif=1 ORDER BY ad");
?>
<div class="modal-katman" id="modalProje">
    <div class="modal">
        <div class="modal-ust"><div class="modal-baslik">Yeni Proje</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
        <form data-ajax="proje_kaydet">
            <div class="modal-govde">
                <div class="form-grup">
                    <label class="form-etiket">Proje Adı <span class="zorunlu">*</span></label>
                    <input name="ad" class="girdi" required placeholder="Örn. Instagram İçerik Yönetimi">
                </div>
                <div class="form-satir">
                    <div class="form-grup">
                        <label class="form-etiket">Dosya <span class="zorunlu">*</span></label>
                        <select name="dosya_id" class="secim" required>
                            <option value="">Seçin...</option>
                            <?php foreach ($dosyalar as $d): ?><option value="<?= $d['id'] ?>" <?= $dosyaId == $d['id'] ? 'selected' : '' ?>><?= e($d['ad']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-grup">
                        <label class="form-etiket">Hizmet Türü</label>
                        <select name="tur" class="secim">
                            <?php foreach (PROJE_TURLERI as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Başlangıç</label><input type="date" name="baslangic" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="date" name="bitis" class="girdi"></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup">
                        <label class="form-etiket">Proje Yöneticisi</label>
                        <select name="pm_id" class="secim"><option value="">—</option><?php foreach ($pmler as $pm): ?><option value="<?= $pm['id'] ?>"><?= e($pm['ad']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="form-grup"><label class="form-etiket">Sözleşme Tutarı (₺)</label><input name="sozlesme_tutari" class="girdi" placeholder="0,00"></div>
                </div>
                <div class="form-grup"><label class="form-etiket">Proje Şablonu (opsiyonel)</label><select name="psablon_id" class="secim"><option value="">— Boş proje</option><?php foreach (rows("SELECT id, ad FROM proje_sablonlari ORDER BY ad") as $psx): ?><option value="<?= $psx['id'] ?>"><?= e($psx['ad']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilirse şablondaki görevler akışlarıyla birlikte kurulur.</div></div>
                <?php uye_secici(); ?>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="aciklama" class="metin-alani" placeholder="Proje kapsamı..."></textarea></div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
        </form>
    </div>
</div>
<?php }

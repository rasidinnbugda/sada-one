<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_yetki('finans');

// Kapasite verileri (bu hafta)
$haftaBasi = date('Y-m-d', strtotime('monday this week'));
$haftaSonu = date('Y-m-d', strtotime('sunday this week'));
$kapasiteler = yetki('kapasite') ? rows("SELECT us.id, us.ad, us.renk, us.avatar, us.unvan, us.haftalik_kapasite,
    (SELECT COALESCE(SUM(z.dakika),0) FROM zaman_kayitlari z WHERE z.user_id=us.id AND z.tarih BETWEEN ? AND ?) hafta_dakika,
    (SELECT COUNT(*) FROM gorevler g WHERE g.arsivlendi=0 AND g.durum!='tamamlandi' AND (g.atanan_id=us.id OR EXISTS(SELECT 1 FROM gorev_atananlar ga WHERE ga.gorev_id=g.id AND ga.user_id=us.id))) acik_gorev
    FROM users us WHERE us.rol IN ('yonetici','pm','ekip','finans') AND us.aktif=1 ORDER BY us.ad", [$haftaBasi, $haftaSonu]) : [];

// Giderler
$giderler = rows("SELECT gd.*, us.ad kisi_ad FROM giderler gd LEFT JOIN users us ON us.id=gd.user_id ORDER BY gd.tarih DESC LIMIT 200");
$giderToplam = (float)val("SELECT COALESCE(SUM(tutar),0) FROM giderler WHERE durum='odendi'");
$giderBekleyen = (float)val("SELECT COALESCE(SUM(tutar),0) FROM giderler WHERE durum='bekliyor'");

// Kâr/Zarar: son 6 ay
$aylikVeri = [];
for ($i = 5; $i >= 0; $i--) {
    $ayAnahtar = date('Y-m', strtotime("-$i months"));
    $aylikVeri[] = [
        'etiket' => AYLAR[(int)date('n', strtotime("-$i months"))] . ' ' . date('y', strtotime("-$i months")),
        'gelir' => (float)val("SELECT COALESCE(SUM(tutar),0) FROM odemeler WHERE durum='odendi' AND DATE_FORMAT(tarih,'%Y-%m')=?", [$ayAnahtar]),
        'gider' => (float)val("SELECT COALESCE(SUM(tutar),0) FROM giderler WHERE durum='odendi' AND DATE_FORMAT(tarih,'%Y-%m')=?", [$ayAnahtar]),
    ];
}
$maxTutar = max(1, max(array_merge(array_column($aylikVeri, 'gelir'), array_column($aylikVeri, 'gider'))));

// Teklif & Fatura belgeleri
$belgeler = rows("SELECT b.*, d.ad dosya_ad FROM belgeler b LEFT JOIN dosyalar d ON d.id=b.dosya_id ORDER BY b.id DESC LIMIT 100");
foreach ($belgeler as &$bg) {
    $kls = json_decode($bg['kalemler'], true) ?: [];
    $bg['ara'] = array_sum(array_map(fn($k) => $k['adet'] * $k['fiyat'], $kls));
    $bg['toplam'] = $bg['ara'] * (1 + $bg['kdv_oran'] / 100);
}
unset($bg);
$tumDosyalar = rows("SELECT id, ad FROM dosyalar WHERE durum='aktif' ORDER BY ad");

// Bütçe hedefi + bu ay gerçekleşme
$butceHedef = (float)ayar('butce_hedef', '0');
$buAyGelir = (float)val("SELECT COALESCE(SUM(tutar),0) FROM odemeler WHERE durum='odendi' AND DATE_FORMAT(tarih,'%Y-%m')=?", [date('Y-m')]);

// Nakit akış projeksiyonu: önümüzdeki 3 ay
$projeksiyon = [];
$aylikSozlesme = (float)val("SELECT COALESCE(SUM(sozlesme_tutari),0) FROM projeler WHERE durum='aktif' AND tur='aylik'");
$aylikMaas = (float)val("SELECT COALESCE(SUM(maas),0) FROM users WHERE aktif=1 AND maas>0");
$aylikTekrarGider = (float)val("SELECT COALESCE(SUM(tutar),0) FROM giderler WHERE tekrar='aylik'");
for ($i = 1; $i <= 3; $i++) {
    $ayAnahtar = date('Y-m', strtotime("+$i months"));
    $bekleyenTahsilat = (float)val("SELECT COALESCE(SUM(tutar),0) FROM odemeler WHERE durum='bekliyor' AND DATE_FORMAT(tarih,'%Y-%m')=?", [$ayAnahtar]);
    $planliGider = (float)val("SELECT COALESCE(SUM(tutar),0) FROM giderler WHERE durum='bekliyor' AND tekrar='yok' AND DATE_FORMAT(tarih,'%Y-%m')=?", [$ayAnahtar]);
    $gelir = $aylikSozlesme + $bekleyenTahsilat;
    $gider = $aylikMaas + $aylikTekrarGider + $planliGider;
    $projeksiyon[] = ['etiket' => AYLAR[(int)date('n', strtotime("+$i months"))] . ' ' . date('y', strtotime("+$i months")), 'gelir' => $gelir, 'gider' => $gider];
}
$projMax = max(1, max(array_merge(array_column($projeksiyon, 'gelir'), array_column($projeksiyon, 'gider'))));

// Cari hesap: dosya bazlı borç/alacak
$cariler = rows("SELECT d.id, d.ad, d.renk,
    COALESCE((SELECT SUM(o.tutar) FROM odemeler o JOIN projeler p ON p.id=o.proje_id WHERE p.dosya_id=d.id AND o.tur='fatura'),0) borc,
    COALESCE((SELECT SUM(o.tutar) FROM odemeler o JOIN projeler p ON p.id=o.proje_id WHERE p.dosya_id=d.id AND o.tur='tahsilat' AND o.durum='odendi'),0) tahsil
    FROM dosyalar d HAVING borc>0 OR tahsil>0 ORDER BY (borc-tahsil) DESC");

// Proje kârlılığı: sözleşme tutarı − işçilik maliyeti (kayıtlı süre × kişi saat maliyeti; saat maliyeti = maaş/172)
$karlilik = rows("SELECT p.id, p.ad, p.sozlesme_tutari, d.ad dosya_ad, d.renk dosya_renk,
    COALESCE((SELECT SUM(z.dakika/60 * (us.maas/172)) FROM zaman_kayitlari z JOIN gorevler g ON g.id=z.gorev_id JOIN users us ON us.id=z.user_id WHERE g.proje_id=p.id AND us.maas>0), 0) iscilik
    FROM projeler p JOIN dosyalar d ON d.id=p.dosya_id WHERE p.durum IN ('aktif','tamamlandi') AND p.sozlesme_tutari>0 ORDER BY p.sozlesme_tutari DESC LIMIT 20");

$projeFiltre = (int)($_GET['proje'] ?? 0);
$kosul = $projeFiltre ? "o.proje_id=$projeFiltre" : "1=1";

$odemeler = rows("SELECT o.*, p.ad proje_ad, d.ad dosya_ad FROM odemeler o JOIN projeler p ON p.id=o.proje_id JOIN dosyalar d ON d.id=p.dosya_id WHERE $kosul ORDER BY o.tarih DESC");
$projeler = rows("SELECT id, ad FROM projeler ORDER BY ad");

$toplamFatura = (float)val("SELECT COALESCE(SUM(tutar),0) FROM odemeler WHERE tur='fatura'");
$tahsilEdilen = (float)val("SELECT COALESCE(SUM(tutar),0) FROM odemeler WHERE durum='odendi'");
$bekleyen = (float)val("SELECT COALESCE(SUM(tutar),0) FROM odemeler WHERE durum='bekliyor'");
$geciken = (float)val("SELECT COALESCE(SUM(tutar),0) FROM odemeler WHERE durum='gecikti'");
$sozlesmeToplam = (float)val("SELECT COALESCE(SUM(sozlesme_tutari),0) FROM projeler WHERE durum='aktif'");

sayfa_basi('Finans', 'finans');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Finans & Kapasite</div><div class="sayfa-alt">Fatura, tahsilat ve ekip çalışma kapasitesi</div></div>
    <div class="sayfa-ust-aksiyon">
        <a href="export.php?tip=finans" class="btn"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 15V3m0 12l-4-4m4 4l4-4M3 17v2a2 2 0 002 2h14a2 2 0 002-2v-2"/></svg> CSV İndir</a>
        <button class="btn btn-marka" data-modal="modalOdeme"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Kayıt Ekle</button>
    </div>
</div>

<div class="sekme-kap">
<div class="sekmeler">
    <button class="sekme aktif" data-sekme="kayitlar">Gelirler</button>
    <button class="sekme" data-sekme="giderler">Giderler</button>
    <button class="sekme" data-sekme="belgeler">Teklif & Fatura</button>
    <button class="sekme" data-sekme="cari">Cari Hesap</button>
    <button class="sekme" data-sekme="karzarar">Kâr / Zarar</button>
    <?php if (yetki('kapasite')): ?><button class="sekme" data-sekme="kapasite">Ekip Kapasitesi</button><?php endif; ?>
</div>
<div class="sekme-icerik aktif" id="sekme-kayitlar">

<div class="stat-izgara">
    <div class="stat-kart"><div class="stat-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 7h6m-6 4h6m-6 4h4M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"/></svg></div><div class="stat-deger" style="font-size:22px"><?= para($toplamFatura) ?></div><div class="stat-etiket">Toplam Fatura</div></div>
    <div class="stat-kart"><div class="stat-ikon" style="background:rgba(53,198,107,.14);color:var(--basari)"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="stat-deger" style="font-size:22px;color:var(--basari)"><?= para($tahsilEdilen) ?></div><div class="stat-etiket">Tahsil Edilen</div></div>
    <div class="stat-kart"><div class="stat-ikon" style="background:rgba(245,165,36,.14);color:var(--uyari)"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="stat-deger" style="font-size:22px;color:var(--uyari)"><?= para($bekleyen) ?></div><div class="stat-etiket">Bekleyen</div></div>
    <div class="stat-kart"><div class="stat-ikon" style="background:rgba(240,79,79,.14);color:var(--tehlike)"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/></svg></div><div class="stat-deger" style="font-size:22px;color:var(--tehlike)"><?= para($geciken) ?></div><div class="stat-etiket">Geciken</div></div>
</div>

<div class="filtre-bar">
    <select class="secim" style="max-width:280px" onchange="location.href='?proje='+this.value">
        <option value="0">Tüm Projeler</option>
        <?php foreach ($projeler as $p): ?><option value="<?= $p['id'] ?>" <?= $projeFiltre == $p['id'] ? 'selected' : '' ?>><?= e($p['ad']) ?></option><?php endforeach; ?>
    </select>
</div>

<?php if (!$odemeler): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 8c-2.21 0-4 .9-4 2s1.79 2 4 2 4 .9 4 2-1.79 2-4 2m0-8V6m0 12v-2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="bos-baslik">Finans kaydı yok</div><div class="bos-metin">Fatura veya tahsilat kaydı ekleyerek başlayın.</div></div>
<?php else: ?>
<div class="tablo-sar"><table class="tablo"><thead><tr><th>Kayıt</th><th>Proje</th><th>Tür</th><th>Tutar</th><th>Tarih</th><th>Durum</th><th></th></tr></thead><tbody>
    <?php foreach ($odemeler as $o): ?>
    <tr>
        <td class="hucre-ana"><?= e($o['baslik']) ?><?php if ($o['aciklama']): ?><div class="hucre-alt"><?= e($o['aciklama']) ?></div><?php endif; ?></td>
        <td class="kucuk"><?= e($o['proje_ad']) ?></td>
        <td><span class="rozet"><?= $o['tur'] === 'fatura' ? 'Fatura' : 'Tahsilat' ?></span></td>
        <td class="kalin"><?= para($o['tutar']) ?></td>
        <td class="kucuk"><?= tarih($o['tarih']) ?></td>
        <td>
            <select class="secim" style="padding:5px 28px 5px 10px;font-size:12px;width:auto" onchange="odemeDurum(<?= $o['id'] ?>,this.value)">
                <?php foreach (['bekliyor' => 'Bekliyor', 'odendi' => 'Ödendi', 'gecikti' => 'Gecikti'] as $k => $v): ?><option value="<?= $k ?>" <?= $o['durum'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </td>
        <td><button class="ikon-eylem tehlike" data-eylem="odeme_sil" data-id="<?= $o['id'] ?>" data-onay="Silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/></svg></button></td>
    </tr>
    <?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div><!-- /sekme-kayitlar -->

<!-- GİDERLER -->
<div class="sekme-icerik" id="sekme-giderler">
    <div class="satir-esnek arasi mb-3 sarma" style="gap:10px">
        <div class="satir-esnek sarma" style="gap:14px">
            <span class="kucuk">Ödenen: <b style="color:var(--tehlike)"><?= para($giderToplam) ?></b></span>
            <span class="kucuk">Bekleyen: <b style="color:var(--uyari)"><?= para($giderBekleyen) ?></b></span>
        </div>
        <button class="btn btn-marka btn-sm" data-modal="modalGider"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Gider Ekle</button>
    </div>
    <?php if (!$giderler): ?>
    <div class="metin-muted kucuk orta kart" style="padding:30px">Henüz gider kaydı yok. Maaş tanımlı kullanıcılar için her ay başında otomatik oluşur.</div>
    <?php else: ?>
    <div class="tablo-sar"><table class="tablo"><thead><tr><th>Gider</th><th>Tür</th><th>Tutar</th><th>Tarih</th><th>Durum</th><th></th></tr></thead><tbody>
        <?php foreach ($giderler as $gd): ?>
        <tr>
            <td class="hucre-ana"><?= e($gd['baslik']) ?><?php if ($gd['tekrar'] === 'aylik'): ?> <span class="rozet rozet-tur" title="Her ay otomatik yinelenir"><?= ikon('tekrar', 11) ?> Aylık</span><?php endif; ?><?php if ($gd['aciklama']): ?><div class="hucre-alt"><?= e($gd['aciklama']) ?></div><?php endif; ?></td>
            <td><span class="rozet"><?= GIDER_TURLERI[$gd['tur']] ?></span></td>
            <td class="kalin" style="color:var(--tehlike)">−<?= para($gd['tutar']) ?></td>
            <td class="kucuk"><?= tarih($gd['tarih']) ?></td>
            <td><select class="secim" style="padding:5px 28px 5px 10px;font-size:12px;width:auto" onchange="giderDurum(<?= $gd['id'] ?>,this.value)"><option value="bekliyor" <?= $gd['durum'] === 'bekliyor' ? 'selected' : '' ?>>Bekliyor</option><option value="odendi" <?= $gd['durum'] === 'odendi' ? 'selected' : '' ?>>Ödendi</option></select></td>
            <td><button class="ikon-eylem tehlike" data-eylem="gider_sil" data-id="<?= $gd['id'] ?>" data-onay="Gider silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<!-- TEKLİF & FATURA -->
<div class="sekme-icerik" id="sekme-belgeler">
    <div class="satir-esnek arasi mb-3">
        <div class="hucre-alt">Numaralı teklif/fatura belgeleri — yazdırıp PDF olarak müşteriye gönderin</div>
        <button class="btn btn-marka btn-sm" data-modal="modalBelge"><?= ikon('belge', 14) ?> Yeni Belge</button>
    </div>
    <?php if (!$belgeler): ?>
    <div class="metin-muted kucuk orta kart" style="padding:30px">Henüz belge yok. İlk teklifinizi oluşturun.</div>
    <?php else: ?>
    <div class="tablo-sar"><table class="tablo"><thead><tr><th>No</th><th>Başlık</th><th>Dosya</th><th>Toplam (KDV dahil)</th><th>Durum</th><th></th></tr></thead><tbody>
        <?php foreach ($belgeler as $bg): ?>
        <tr>
            <td class="kalin" style="color:var(--marka)"><?= e($bg['no']) ?></td>
            <td><a href="belge.php?id=<?= $bg['id'] ?>" class="hucre-ana"><?= e($bg['baslik']) ?></a><div class="hucre-alt"><?= $bg['tur'] === 'teklif' ? 'Teklif' : 'Fatura' ?> · <?= tarih($bg['created']) ?></div></td>
            <td class="kucuk"><?= e($bg['dosya_ad'] ?? '—') ?></td>
            <td class="kalin"><?= para($bg['toplam']) ?></td>
            <td>
                <select class="secim native-kal" style="padding:5px 28px 5px 10px;font-size:12px;width:auto" onchange="belgeDurum(<?= $bg['id'] ?>,this.value)">
                    <?php foreach (['taslak' => 'Taslak', 'gonderildi' => 'Gönderildi', 'onaylandi' => 'Onaylandı', 'reddedildi' => 'Reddedildi'] as $k => $v): ?><option value="<?= $k ?>" <?= $bg['durum'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                </select>
            </td>
            <td class="satir-esnek" style="gap:4px">
                <a href="belge.php?id=<?= $bg['id'] ?>" target="_blank" class="ikon-eylem" title="Yazdır/PDF"><?= ikon('belge', 16) ?></a>
                <button class="ikon-eylem tehlike" data-eylem="belge_sil" data-id="<?= $bg['id'] ?>" data-onay="Belge silinsin mi?"><?= ikon('cop', 16) ?></button>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<!-- CARİ HESAP -->
<div class="sekme-icerik" id="sekme-cari">
    <div class="hucre-alt mb-3">Dosya bazında borç (kesilen faturalar) − tahsilat = güncel bakiye. Satıra tıklayarak yazdırılabilir ekstre alın.</div>
    <?php if (!$cariler): ?>
    <div class="metin-muted kucuk orta kart" style="padding:30px">Henüz finansal hareket yok.</div>
    <?php else: ?>
    <div class="tablo-sar"><table class="tablo"><thead><tr><th>Dosya</th><th>Toplam Fatura</th><th>Tahsil Edilen</th><th>Bakiye</th><th></th></tr></thead><tbody>
        <?php foreach ($cariler as $cr): $bakiye = $cr['borc'] - $cr['tahsil']; ?>
        <tr class="tik" onclick="location.href='ekstre.php?dosya=<?= $cr['id'] ?>'">
            <td><span class="etiket-nokta" style="width:9px;height:9px;background:<?= e($cr['renk']) ?>;margin-right:6px"></span><span class="hucre-ana"><?= e($cr['ad']) ?></span></td>
            <td><?= para($cr['borc']) ?></td>
            <td style="color:var(--basari)"><?= para($cr['tahsil']) ?></td>
            <td class="kalin" style="color:<?= $bakiye > 0 ? 'var(--tehlike)' : 'var(--basari)' ?>"><?= para($bakiye) ?></td>
            <td><a href="ekstre.php?dosya=<?= $cr['id'] ?>" class="mini-btn" onclick="event.stopPropagation()">Ekstre →</a></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<!-- KÂR / ZARAR -->
<div class="sekme-icerik" id="sekme-karzarar">
    <?php
    $sonAy = end($aylikVeri);
    $netBuAy = $sonAy['gelir'] - $sonAy['gider'];
    $toplam6Gelir = array_sum(array_column($aylikVeri, 'gelir'));
    $toplam6Gider = array_sum(array_column($aylikVeri, 'gider')); ?>
    <div class="stat-izgara">
        <div class="stat-kart"><div class="stat-deger" style="font-size:22px;color:var(--basari)"><?= para($sonAy['gelir']) ?></div><div class="stat-etiket">Bu Ay Gelir (tahsil edilen)</div></div>
        <div class="stat-kart"><div class="stat-deger" style="font-size:22px;color:var(--tehlike)"><?= para($sonAy['gider']) ?></div><div class="stat-etiket">Bu Ay Gider (ödenen)</div></div>
        <div class="stat-kart"><div class="stat-deger" style="font-size:22px;color:<?= $netBuAy >= 0 ? 'var(--basari)' : 'var(--tehlike)' ?>"><?= ($netBuAy >= 0 ? '+' : '') . para($netBuAy) ?></div><div class="stat-etiket">Bu Ay Net</div></div>
        <div class="stat-kart"><div class="stat-deger" style="font-size:22px"><?= para($toplam6Gelir - $toplam6Gider) ?></div><div class="stat-etiket">6 Aylık Net</div></div>
    </div>
    <div class="kart mb-3">
        <div class="kart-baslik mb-3">Son 6 Ay — Gelir / Gider</div>
        <div style="display:flex;gap:14px;align-items:flex-end;height:200px;padding:0 6px">
            <?php foreach ($aylikVeri as $av): ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%">
                <div style="flex:1;display:flex;gap:5px;align-items:flex-end;width:100%;justify-content:center">
                    <div title="Gelir: <?= para($av['gelir']) ?>" style="width:26px;border-radius:6px 6px 0 0;background:var(--basari);height:<?= max(2, round($av['gelir'] / $maxTutar * 100)) ?>%;transition:height .6s"></div>
                    <div title="Gider: <?= para($av['gider']) ?>" style="width:26px;border-radius:6px 6px 0 0;background:var(--tehlike);opacity:.75;height:<?= max(2, round($av['gider'] / $maxTutar * 100)) ?>%;transition:height .6s"></div>
                </div>
                <span class="hucre-alt"><?= $av['etiket'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="satir-esnek mt-2" style="gap:16px;justify-content:center">
            <span class="satir-esnek kucuk" style="gap:6px"><span class="etiket-nokta" style="background:var(--basari)"></span>Gelir</span>
            <span class="satir-esnek kucuk" style="gap:6px"><span class="etiket-nokta" style="background:var(--tehlike)"></span>Gider</span>
        </div>
    </div>
    <!-- Bütçe hedefi -->
    <div class="kart mb-3">
        <div class="satir-esnek arasi sarma mb-2" style="gap:10px">
            <div class="kart-baslik">Aylık Gelir Hedefi</div>
            <form data-ajax="butce_kaydet" data-yenile="evet" class="satir-esnek" style="gap:8px">
                <input name="hedef" class="girdi" style="width:150px" value="<?= $butceHedef ? number_format($butceHedef, 0, ',', '.') : '' ?>" placeholder="Örn. 250.000">
                <button type="submit" class="btn btn-sm">Kaydet</button>
            </form>
        </div>
        <?php if ($butceHedef > 0): $hedefOran = min(100, round($buAyGelir / $butceHedef * 100)); ?>
        <div class="satir-esnek arasi mb-2"><span class="kucuk"><?= AYLAR[(int)date('n')] ?> gerçekleşme: <b><?= para($buAyGelir) ?></b> / <?= para($butceHedef) ?></span><span class="kalin" style="color:<?= $hedefOran >= 100 ? 'var(--basari)' : 'var(--text)' ?>">%<?= round($buAyGelir / $butceHedef * 100) ?></span></div>
        <div class="ilerleme" style="height:10px"><div class="ilerleme-dolu <?= $hedefOran >= 100 ? '' : ($hedefOran >= 70 ? '' : 'yogun') ?>" data-oran="<?= $hedefOran ?>" style="width:0;<?= $hedefOran >= 100 ? 'background:var(--basari)' : '' ?>"></div></div>
        <?php else: ?><div class="hucre-alt">Hedef girin — bu ayın tahsilatları hedefe oranla izlenir.</div><?php endif; ?>
    </div>

    <!-- Nakit akış projeksiyonu -->
    <div class="kart mb-3">
        <div class="kart-baslik mb-2">Nakit Akış Projeksiyonu — önümüzdeki 3 ay</div>
        <div class="hucre-alt mb-3">Beklenen gelir = aktif aylık sözleşmeler + planlanan tahsilatlar · Gider = maaşlar + tekrarlayan ve planlı giderler</div>
        <div style="display:flex;gap:20px;align-items:flex-end;height:150px;padding:0 6px">
            <?php foreach ($projeksiyon as $pj): $net = $pj['gelir'] - $pj['gider']; ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%">
                <span class="kucuk kalin" style="color:<?= $net >= 0 ? 'var(--basari)' : 'var(--tehlike)' ?>"><?= ($net >= 0 ? '+' : '') . para($net) ?></span>
                <div style="flex:1;display:flex;gap:6px;align-items:flex-end;width:100%;justify-content:center">
                    <div title="Beklenen gelir: <?= para($pj['gelir']) ?>" style="width:30px;border-radius:6px 6px 0 0;background:var(--basari);height:<?= max(2, round($pj['gelir'] / $projMax * 100)) ?>%"></div>
                    <div title="Planlı gider: <?= para($pj['gider']) ?>" style="width:30px;border-radius:6px 6px 0 0;background:var(--tehlike);opacity:.75;height:<?= max(2, round($pj['gider'] / $projMax * 100)) ?>%"></div>
                </div>
                <span class="hucre-alt"><?= $pj['etiket'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kart">
        <div class="kart-baslik mb-2">Proje Kârlılığı</div>
        <div class="hucre-alt mb-3">İşçilik maliyeti = kayıtlı süre × kişinin saat maliyeti (maaş ÷ 172 saat). Maaş girilmeyen kişilerin süresi maliyete katılmaz.</div>
        <?php if (!$karlilik): ?><div class="metin-muted kucuk">Sözleşme tutarı girilmiş proje yok.</div>
        <?php else: ?>
        <div class="tablo-sar"><table class="tablo"><thead><tr><th>Proje</th><th>Sözleşme</th><th>İşçilik Maliyeti</th><th>Tahmini Kâr</th><th>Marj</th></tr></thead><tbody>
            <?php foreach ($karlilik as $kr):
                $kar = $kr['sozlesme_tutari'] - $kr['iscilik'];
                $marj = $kr['sozlesme_tutari'] > 0 ? round($kar / $kr['sozlesme_tutari'] * 100) : 0; ?>
            <tr>
                <td><span class="etiket-nokta" style="width:8px;height:8px;background:<?= e($kr['dosya_renk']) ?>;margin-right:6px"></span><a href="proje.php?id=<?= $kr['id'] ?>" class="hucre-ana"><?= e($kr['ad']) ?></a><div class="hucre-alt"><?= e($kr['dosya_ad']) ?></div></td>
                <td class="kalin"><?= para($kr['sozlesme_tutari']) ?></td>
                <td style="color:var(--tehlike)">−<?= para($kr['iscilik']) ?></td>
                <td class="kalin" style="color:<?= $kar >= 0 ? 'var(--basari)' : 'var(--tehlike)' ?>"><?= para($kar) ?></td>
                <td><span class="rozet <?= $marj >= 40 ? 'r-onaylandi' : ($marj >= 15 ? 'r-bekliyor' : 'r-reddedildi') ?>">%<?= $marj ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<?php if (yetki('kapasite')): ?>
<div class="sekme-icerik" id="sekme-kapasite">
    <div class="kart">
        <div class="satir-esnek arasi mb-3">
            <div>
                <div class="kart-baslik">Haftalık Doluluk — <?= tarih($haftaBasi) ?> / <?= tarih($haftaSonu) ?></div>
                <div class="hucre-alt mt-1">Kayıtlı çalışma süresi, kişinin haftalık kapasite hedefine oranlanır.</div>
            </div>
            <a href="export.php?tip=zaman" class="btn btn-sm">Zaman Raporu CSV</a>
        </div>
        <?php if (!$kapasiteler): ?><div class="metin-muted kucuk">Ekip üyesi yok.</div>
        <?php else: foreach ($kapasiteler as $kp):
            $hedefDk = (int)$kp['haftalik_kapasite'] * 60;
            $oran = $hedefDk > 0 ? round($kp['hafta_dakika'] / $hedefDk * 100) : 0;
            $sinif = $oran > 100 ? 'asiri' : ($oran > 80 ? 'yogun' : ''); ?>
        <div class="kapasite-satir">
            <?= avatar($kp, 38) ?>
            <div style="min-width:150px">
                <div class="hucre-ana kucuk"><?= e($kp['ad']) ?></div>
                <div class="hucre-alt"><?= $kp['acik_gorev'] ?> açık görev · hedef <?= $kp['haftalik_kapasite'] ?> sa/hafta</div>
            </div>
            <div class="kapasite-bar">
                <div class="ilerleme"><div class="ilerleme-dolu <?= $sinif ?>" data-oran="<?= min(100, $oran) ?>" style="width:0"></div></div>
                <div class="hucre-alt mt-1"><?= dakika_format((int)$kp['hafta_dakika']) ?> kayıtlı</div>
            </div>
            <div class="kapasite-yuzde" style="<?= $oran > 100 ? 'color:var(--tehlike)' : ($oran > 80 ? 'color:var(--uyari)' : '') ?>">%<?= $oran ?></div>
        </div>
        <?php endforeach; endif; ?>
        <div class="form-ipucu mt-2">Kapasite hedefleri <b>Yönetim → Kullanıcılar</b>'dan kişi bazında ayarlanır. %80 üzeri sarı, %100 üzeri kırmızı gösterilir.</div>
    </div>
</div>
<?php endif; ?>
</div><!-- /sekme-kap -->

<div class="modal-katman" id="modalOdeme">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Finans Kaydı</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="odeme_kaydet">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="baslik" class="girdi" required placeholder="Örn. Ekim ayı hizmet bedeli"></div>
            <div class="form-grup"><label class="form-etiket">Proje <span class="zorunlu">*</span></label><select name="proje_id" class="secim" required><option value="">Seçin...</option><?php foreach ($projeler as $p): ?><option value="<?= $p['id'] ?>" <?= $projeFiltre == $p['id'] ? 'selected' : '' ?>><?= e($p['ad']) ?></option><?php endforeach; ?></select></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tür</label><select name="tur" class="secim"><option value="fatura">Fatura</option><option value="tahsilat">Tahsilat</option></select></div>
                <div class="form-grup"><label class="form-etiket">Tutar (₺) <span class="zorunlu">*</span></label><input name="tutar" class="girdi" required placeholder="0,00"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tarih</label><input type="date" name="tarih" class="girdi" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-grup"><label class="form-etiket">Durum</label><select name="durum" class="secim"><option value="bekliyor">Bekliyor</option><option value="odendi">Ödendi</option><option value="gecikti">Gecikti</option></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="aciklama" class="girdi"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>
<!-- Gider ekleme modalı -->
<div class="modal-katman" id="modalGider">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Gider Kaydı</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="gider_kaydet">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="baslik" class="girdi" required placeholder="Örn. Ofis kirası"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tür</label><select name="tur" class="secim"><?php foreach (GIDER_TURLERI as $k => $v): if ($k === 'maas') continue; ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Tutar (₺) <span class="zorunlu">*</span></label><input name="tutar" class="girdi" required placeholder="0,00"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tarih</label><input type="date" name="tarih" class="girdi" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-grup"><label class="form-etiket">Durum</label><select name="durum" class="secim"><option value="bekliyor">Bekliyor</option><option value="odendi">Ödendi</option></select></div>
            </div>
            <div class="form-grup"><label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="tekrar" value="aylik"> <span class="kucuk"><b>Her ay tekrarla</b> — kira/abonelik gibi giderler her ay başında otomatik oluşur</span></label></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="aciklama" class="girdi"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- Teklif/Fatura oluşturma modalı -->
<div class="modal-katman" id="modalBelge">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik">Yeni Teklif / Fatura</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="belge_kaydet" id="belgeForm">
        <input type="hidden" name="kalemler" id="b_kalemler">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Belge Türü</label><select name="tur" class="secim"><option value="teklif">Teklif</option><option value="fatura">Fatura</option></select></div>
                <div class="form-grup"><label class="form-etiket">Dosya (müşteri)</label><select name="dosya_id" class="secim"><option value="">—</option><?php foreach ($tumDosyalar as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['ad']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="baslik" class="girdi" required placeholder="Örn. 2026 Sosyal Medya Yönetimi Teklifi"></div>
            <div class="form-grup">
                <label class="form-etiket">Kalemler</label>
                <div class="dikey" id="kalemListe" style="gap:8px"></div>
                <button type="button" class="btn btn-sm btn-hayalet mt-2" onclick="kalemEkle()">+ Kalem Ekle</button>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">KDV Oranı (%)</label><input type="number" name="kdv_oran" class="girdi" value="20" min="0" max="50"></div>
                <div class="form-grup"><label class="form-etiket">Geçerlilik Tarihi</label><input type="date" name="gecerlilik" class="girdi"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Notlar</label><textarea name="notlar" class="metin-alani" placeholder="Ödeme koşulları, teslim süresi vb."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
    </form></div>
</div>

<script>
async function odemeDurum(id, durum) { const j = await api('odeme_durum', {id, durum}); if (j.ok) { toast('Güncellendi', 'basari'); setTimeout(()=>location.reload(),500); } }
async function giderDurum(id, durum) { const j = await api('gider_durum', {id, durum}); if (j.ok) toast('Güncellendi', 'basari'); }
async function belgeDurum(id, durum) { const j = await api('belge_durum', {id, durum}); if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(()=>location.reload(),700); } }
function kalemEkle(k = {}) {
    const div = document.createElement('div');
    div.className = 'satir-esnek kalem-satir';
    div.style.gap = '8px';
    div.innerHTML = `<input class="girdi k-ad" placeholder="Hizmet/ürün adı" style="flex:2" value="${(k.ad||'').replace(/"/g,'&quot;')}">
        <input class="girdi k-adet" placeholder="Adet" style="width:70px" value="${k.adet||1}">
        <input class="girdi k-fiyat" placeholder="Birim ₺" style="width:110px" value="${k.fiyat||''}">
        <button type="button" class="ikon-eylem tehlike" onclick="this.parentElement.remove()">✕</button>`;
    document.getElementById('kalemListe').appendChild(div);
}
kalemEkle();
document.getElementById('belgeForm').addEventListener('submit', () => {
    const kalemler = Array.from(document.querySelectorAll('.kalem-satir')).map(s => ({
        ad: s.querySelector('.k-ad').value.trim(),
        adet: s.querySelector('.k-adet').value,
        fiyat: s.querySelector('.k-fiyat').value,
    })).filter(k => k.ad);
    document.getElementById('b_kalemler').value = JSON.stringify(kalemler);
});
</script>
<?php sayfa_sonu(); ?>

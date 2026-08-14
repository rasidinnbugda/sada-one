<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_yetki('rapor');

// Genel metrikler
$toplamGorev = (int)val("SELECT COUNT(*) FROM gorevler");
$tamamGorev = (int)val("SELECT COUNT(*) FROM gorevler WHERE durum='tamamlandi'");
$gecikenGorev = (int)val("SELECT COUNT(*) FROM gorevler WHERE son_tarih<CURDATE() AND durum!='tamamlandi'");
$tamamOran = $toplamGorev ? round($tamamGorev / $toplamGorev * 100) : 0;

// Durum dağılımı
$durumDagilim = [];
foreach (GOREV_DURUMLARI as $k => $v) $durumDagilim[$k] = (int)val("SELECT COUNT(*) FROM gorevler WHERE durum=?", [$k]);
$maxDurum = max(1, max($durumDagilim));

// Kişi bazlı performans
$kisiler = rows("SELECT u.id, u.ad, u.renk,
    (SELECT COUNT(*) FROM gorevler g WHERE g.atanan_id=u.id) toplam,
    (SELECT COUNT(*) FROM gorevler g WHERE g.atanan_id=u.id AND g.durum='tamamlandi') tamam,
    (SELECT COALESCE(SUM(z.dakika),0) FROM zaman_kayitlari z WHERE z.user_id=u.id) dakika
    FROM users u WHERE u.rol IN ('yonetici','pm','ekip') AND u.aktif=1 ORDER BY toplam DESC");

// Dosya bazlı proje sayısı
$dosyaDagilim = rows("SELECT d.ad, d.renk, COUNT(p.id) proje FROM dosyalar d LEFT JOIN projeler p ON p.dosya_id=d.id GROUP BY d.id ORDER BY proje DESC LIMIT 8");
$maxDosya = max(1, max(array_column($dosyaDagilim, 'proje') ?: [1]));

// Onay istatistikleri
$onayToplam = (int)val("SELECT COUNT(*) FROM onaylar");
$onayOnaylanan = (int)val("SELECT COUNT(*) FROM onaylar WHERE durum='onaylandi'");
$onayBekleyen = (int)val("SELECT COUNT(*) FROM onaylar WHERE durum='bekliyor'");

sayfa_basi('Raporlar', 'raporlar');
$durumRenk = ['yapilacak' => 'var(--muted)', 'devam' => 'var(--bilgi)', 'incelemede' => 'var(--uyari)', 'onayda' => '#a58bf0', 'tamamlandi' => 'var(--basari)'];
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Raporlar & Analiz</div><div class="sayfa-alt">Performans ve iş yükü özeti</div></div>
    <div class="sayfa-ust-aksiyon">
        <a href="export.php?tip=gorevler" class="btn btn-sm"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M12 15V3m0 12l-4-4m4 4l4-4M3 17v2a2 2 0 002 2h14a2 2 0 002-2v-2"/></svg> Görevler CSV</a>
        <a href="export.php?tip=zaman" class="btn btn-sm"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M12 15V3m0 12l-4-4m4 4l4-4M3 17v2a2 2 0 002 2h14a2 2 0 002-2v-2"/></svg> Zaman CSV</a>
    </div>
</div>

<div class="stat-izgara">
    <div class="stat-kart"><div class="stat-deger"><?= $tamamOran ?>%</div><div class="stat-etiket">Genel Tamamlanma</div><div class="ilerleme mt-2"><div class="ilerleme-dolu" data-oran="<?= $tamamOran ?>" style="width:0"></div></div></div>
    <div class="stat-kart"><div class="stat-deger" data-sayac="<?= $toplamGorev ?>">0</div><div class="stat-etiket">Toplam Görev</div></div>
    <div class="stat-kart"><div class="stat-deger" data-sayac="<?= $tamamGorev ?>">0</div><div class="stat-etiket">Tamamlanan</div></div>
    <div class="stat-kart"><div class="stat-deger" style="color:var(--tehlike)" data-sayac="<?= $gecikenGorev ?>">0</div><div class="stat-etiket">Geciken</div></div>
</div>

<div class="izgara izgara-2">
    <!-- Durum dağılımı -->
    <div class="kart">
        <div class="kart-baslik mb-3">Görev Durum Dağılımı</div>
        <div class="dikey" style="gap:14px">
            <?php foreach ($durumDagilim as $k => $sayi): ?>
            <div>
                <div class="satir-esnek arasi mb-2"><span class="satir-esnek kucuk" style="gap:7px"><span class="etiket-nokta" style="background:<?= $durumRenk[$k] ?>"></span><?= GOREV_DURUMLARI[$k] ?></span><span class="kucuk kalin"><?= $sayi ?></span></div>
                <div class="ilerleme"><div class="ilerleme-dolu" data-oran="<?= round($sayi / $maxDurum * 100) ?>" style="width:0;background:<?= $durumRenk[$k] ?>"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Dosya başına proje -->
    <div class="kart">
        <div class="kart-baslik mb-3">Dosya Başına Proje</div>
        <div class="dikey" style="gap:14px">
            <?php foreach ($dosyaDagilim as $d): ?>
            <div>
                <div class="satir-esnek arasi mb-2"><span class="satir-esnek kucuk" style="gap:7px"><span class="etiket-nokta" style="background:<?= e($d['renk']) ?>"></span><?= e($d['ad']) ?></span><span class="kucuk kalin"><?= $d['proje'] ?></span></div>
                <div class="ilerleme"><div class="ilerleme-dolu" data-oran="<?= round($d['proje'] / $maxDosya * 100) ?>" style="width:0;background:<?= e($d['renk']) ?>"></div></div>
            </div>
            <?php endforeach; ?>
            <?php if (!$dosyaDagilim): ?><div class="metin-muted kucuk">Veri yok</div><?php endif; ?>
        </div>
    </div>
</div>

<div class="kart mt-3">
    <div class="kart-baslik mb-3">Ekip Performansı</div>
    <div class="tablo-sar"><table class="tablo"><thead><tr><th>Kişi</th><th>Toplam Görev</th><th>Tamamlanan</th><th>Tamamlanma</th><th>Kayıtlı Süre</th></tr></thead><tbody>
        <?php foreach ($kisiler as $k):
            $oran = $k['toplam'] ? round($k['tamam'] / $k['toplam'] * 100) : 0; ?>
        <tr>
            <td><div class="satir-esnek" style="gap:9px"><?= avatar(['ad' => $k['ad'], 'renk' => $k['renk']], 30) ?><span class="hucre-ana"><?= e($k['ad']) ?></span></div></td>
            <td><?= $k['toplam'] ?></td>
            <td><?= $k['tamam'] ?></td>
            <td><div class="satir-esnek" style="gap:10px"><div class="ilerleme" style="flex:1;max-width:120px"><div class="ilerleme-dolu" data-oran="<?= $oran ?>" style="width:0"></div></div><span class="kucuk kalin">%<?= $oran ?></span></div></td>
            <td class="kucuk"><?= dakika_format((int)$k['dakika']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="izgara izgara-3 mt-3">
    <div class="kart orta"><div class="stat-deger" data-sayac="<?= $onayToplam ?>">0</div><div class="stat-etiket">Toplam Onay Süreci</div></div>
    <div class="kart orta"><div class="stat-deger" style="color:var(--basari)" data-sayac="<?= $onayOnaylanan ?>">0</div><div class="stat-etiket">Onaylanan</div></div>
    <div class="kart orta"><div class="stat-deger" style="color:var(--uyari)" data-sayac="<?= $onayBekleyen ?>">0</div><div class="stat-etiket">Bekleyen Onay</div></div>
</div>

<!-- MÜŞTERİ MEMNUNİYETİ -->
<?php
$genelPuan = row("SELECT AVG(puan) ort, COUNT(*) adet FROM puanlar");
if ((int)$genelPuan['adet'] > 0):
    $dosyaPuanlari = rows("SELECT d.ad, d.renk, AVG(pu.puan) ort, COUNT(*) adet
        FROM puanlar pu JOIN projeler p ON p.id=pu.proje_id JOIN dosyalar d ON d.id=p.dosya_id
        GROUP BY d.id ORDER BY ort DESC");
    $sonYorumlar = rows("SELECT pu.*, us.ad musteri_ad, p.ad proje_ad FROM puanlar pu JOIN users us ON us.id=pu.user_id JOIN projeler p ON p.id=pu.proje_id WHERE pu.yorum IS NOT NULL AND pu.yorum!='' ORDER BY pu.id DESC LIMIT 6"); ?>
<div class="kart mt-3">
    <div class="satir-esnek arasi mb-3 sarma" style="gap:10px">
        <div class="kart-baslik">😊 Müşteri Memnuniyeti</div>
        <div class="satir-esnek" style="gap:10px">
            <?= yildizlar((float)$genelPuan['ort'], 18) ?>
            <span class="kalin" style="font-family:'Space Grotesk',sans-serif;font-size:20px"><?= number_format((float)$genelPuan['ort'], 1, ',', '') ?></span>
            <span class="hucre-alt"><?= $genelPuan['adet'] ?> değerlendirme</span>
        </div>
    </div>
    <div class="izgara izgara-2">
        <div>
            <div class="hucre-alt mb-2">Dosya bazında ortalama</div>
            <?php foreach ($dosyaPuanlari as $dp): ?>
            <div class="satir-esnek arasi" style="padding:9px 0;border-bottom:1px solid var(--border)">
                <span class="satir-esnek kucuk" style="gap:7px"><span class="etiket-nokta" style="background:<?= e($dp['renk']) ?>"></span><?= e($dp['ad']) ?></span>
                <span class="satir-esnek" style="gap:8px"><?= yildizlar((float)$dp['ort']) ?><span class="kucuk kalin" style="<?= $dp['ort'] < 3 ? 'color:var(--tehlike)' : '' ?>"><?= number_format((float)$dp['ort'], 1, ',', '') ?></span><span class="hucre-alt">(<?= $dp['adet'] ?>)</span></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div>
            <div class="hucre-alt mb-2">Son yorumlar</div>
            <?php if (!$sonYorumlar): ?><div class="metin-muted kucuk">Henüz yorumlu değerlendirme yok.</div>
            <?php else: foreach ($sonYorumlar as $sy): ?>
            <div style="padding:9px 12px;background:var(--surface-2);border-radius:10px;margin-bottom:6px">
                <div class="satir-esnek arasi"><span class="kucuk kalin"><?= e($sy['musteri_ad']) ?></span><?= yildizlar((float)$sy['puan'], 12) ?></div>
                <div class="kucuk metin-2 mt-1">"<?= e(mb_substr($sy['yorum'], 0, 140)) ?>"</div>
                <div class="hucre-alt mt-1"><?= e($sy['proje_ad']) ?> · <?= zaman_once($sy['created']) ?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php sayfa_sonu(); ?>

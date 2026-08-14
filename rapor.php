<?php
/**
 * SADA Dijital — Müşteri Raporu (yazdırılabilir / PDF)
 * Tarayıcının "PDF olarak kaydet" özelliğiyle çıktı alınır.
 */
require __DIR__ . '/includes/init.php';
require_staff();

$projeId = (int)($_GET['proje'] ?? 0);
$ay = (int)($_GET['ay'] ?? date('n'));
$yil = (int)($_GET['yil'] ?? date('Y'));
$proje = row("SELECT p.*, d.ad dosya_ad, d.renk dosya_renk, d.logo dosya_logo FROM projeler p JOIN dosyalar d ON d.id=p.dosya_id WHERE p.id=?", [$projeId]);
if (!$proje) { header('Location: projeler.php'); exit; }

$ayBas = sprintf('%04d-%02d-01', $yil, $ay);
$aySon = date('Y-m-t', strtotime($ayBas));
$siteAdi = ayar('site_adi', 'SADA Dijital');

$tamamlanan = rows("SELECT g.*, u.ad atanan_ad FROM gorevler g LEFT JOIN users u ON u.id=g.atanan_id WHERE g.proje_id=? AND g.durum='tamamlandi' AND g.tamamlanma BETWEEN ? AND ? ORDER BY g.tamamlanma", [$projeId, $ayBas . ' 00:00:00', $aySon . ' 23:59:59']);
$devamEden = rows("SELECT g.* FROM gorevler g WHERE g.proje_id=? AND g.arsivlendi=0 AND g.durum!='tamamlandi' ORDER BY g.son_tarih IS NULL, g.son_tarih", [$projeId]);
$onaylar = rows("SELECT * FROM onaylar WHERE proje_id=? AND created BETWEEN ? AND ? ORDER BY id", [$projeId, $ayBas . ' 00:00:00', $aySon . ' 23:59:59']);
$icerikler = rows("SELECT * FROM icerikler WHERE proje_id=? AND tarih BETWEEN ? AND ? ORDER BY tarih", [$projeId, $ayBas, $aySon]);
$toplamDk = (int)val("SELECT COALESCE(SUM(z.dakika),0) FROM zaman_kayitlari z JOIN gorevler g ON g.id=z.gorev_id WHERE g.proje_id=? AND z.tarih BETWEEN ? AND ?", [$projeId, $ayBas, $aySon]);
$memnuniyet = row("SELECT AVG(puan) ort, COUNT(*) adet FROM puanlar WHERE proje_id=?", [$projeId]);
?>
<!DOCTYPE html>
<html lang="tr" data-theme="navy-light">
<head>
<meta charset="UTF-8">
<title><?= e($proje['dosya_ad']) ?> — <?= AYLAR[$ay] ?> <?= $yil ?> Raporu</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&family=Unbounded:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
<style>
body { background: #fff !important; color: #1a2233; }
.rapor { max-width: 800px; margin: 0 auto; padding: 40px 32px; }
.rapor-baslik { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid <?= e($proje['dosya_renk']) ?>; padding-bottom: 20px; margin-bottom: 28px; }
.rapor h2 { font-family: 'Space Grotesk', sans-serif; font-size: 17px; margin: 26px 0 12px; padding-bottom: 6px; border-bottom: 1px solid #e2e6ee; }
.rapor table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rapor th { text-align: left; padding: 8px 10px; background: #f0f3f9; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #5a6780; }
.rapor td { padding: 8px 10px; border-bottom: 1px solid #eef0f5; }
.ozet-kutu { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 20px; }
.ozet-kutu > div { background: #f5f7fb; border-radius: 12px; padding: 14px; text-align: center; }
.ozet-deger { font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 700; color: #182f5d; }
.ozet-etiket { font-size: 11.5px; color: #6a7590; margin-top: 3px; }
.yazdir-bar { position: sticky; top: 0; background: #182f5d; color: #fff; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; z-index: 10; }
@media print { .yazdir-bar { display: none !important; } .rapor { padding: 0; } body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
.durum-cip { display: inline-block; padding: 2px 9px; border-radius: 99px; font-size: 11px; font-weight: 600; }
</style>
</head>
<body>
<div class="yazdir-bar">
    <span style="font-weight:600">📄 Rapor önizleme — yazdır penceresinden "PDF olarak kaydet" seçin</span>
    <span style="display:flex;gap:10px">
        <select onchange="location.href='rapor.php?proje=<?= $projeId ?>&'+this.value" style="padding:6px 10px;border-radius:8px;border:none">
            <?php for ($i = 0; $i < 6; $i++): $t = strtotime("-$i months"); $sec = ((int)date('n', $t) === $ay && (int)date('Y', $t) === $yil); ?>
            <option value="ay=<?= date('n', $t) ?>&yil=<?= date('Y', $t) ?>" <?= $sec ? 'selected' : '' ?>><?= AYLAR[(int)date('n', $t)] ?> <?= date('Y', $t) ?></option>
            <?php endfor; ?>
        </select>
        <button onclick="window.print()" style="background:#b1fb01;color:#14210a;border:none;padding:8px 18px;border-radius:9px;font-weight:700;cursor:pointer">🖨 Yazdır / PDF</button>
    </span>
</div>

<div class="rapor">
    <div class="rapor-baslik">
        <div>
            <div style="font-family:'Unbounded',sans-serif;font-size:20px;font-weight:700"><?= e($siteAdi) ?><span style="color:<?= e($proje['dosya_renk']) ?>">.</span></div>
            <div style="font-size:12px;color:#6a7590;margin-top:4px">Aylık Çalışma Raporu</div>
        </div>
        <div style="text-align:right">
            <div style="font-family:'Space Grotesk',sans-serif;font-size:19px;font-weight:700"><?= e($proje['dosya_ad']) ?></div>
            <div style="font-size:13px;color:#3a466c"><?= e($proje['ad']) ?></div>
            <div style="font-size:12px;color:#6a7590;margin-top:3px"><?= AYLAR[$ay] ?> <?= $yil ?> · Rapor tarihi: <?= tarih(date('Y-m-d')) ?></div>
        </div>
    </div>

    <div class="ozet-kutu">
        <div><div class="ozet-deger"><?= count($tamamlanan) ?></div><div class="ozet-etiket">Tamamlanan Görev</div></div>
        <div><div class="ozet-deger"><?= count($icerikler) ?></div><div class="ozet-etiket">Planlanan İçerik</div></div>
        <div><div class="ozet-deger"><?= count(array_filter($onaylar, fn($o) => $o['durum'] === 'onaylandi')) ?>/<?= count($onaylar) ?></div><div class="ozet-etiket">Onaylanan İş</div></div>
        <div><div class="ozet-deger"><?= $toplamDk ? round($toplamDk / 60) : 0 ?> sa</div><div class="ozet-etiket">Harcanan Emek</div></div>
    </div>
    <?php if ((int)$memnuniyet['adet'] > 0): ?>
    <div style="margin-top:14px;background:#f5f7fb;border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:12px;justify-content:center">
        <span style="color:#f0a020;font-size:16px;letter-spacing:2px"><?php $ort = round((float)$memnuniyet['ort']); for ($i = 1; $i <= 5; $i++) echo '<span style="opacity:' . ($i <= $ort ? '1' : '.25') . '">★</span>'; ?></span>
        <span style="font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:18px;color:#182f5d"><?= number_format((float)$memnuniyet['ort'], 1, ',', '') ?>/5</span>
        <span style="font-size:12px;color:#6a7590">memnuniyet puanınız (<?= $memnuniyet['adet'] ?> değerlendirme)</span>
    </div>
    <?php endif; ?>

    <?php if ($tamamlanan): ?>
    <h2>✅ Bu Ay Tamamlanan İşler</h2>
    <table><thead><tr><th>İş</th><th>Tamamlanma</th></tr></thead><tbody>
        <?php foreach ($tamamlanan as $t): ?>
        <tr><td><?= e($t['baslik']) ?></td><td><?= tarih(substr($t['tamamlanma'], 0, 10)) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <?php if ($icerikler): ?>
    <h2>📅 İçerik Planı</h2>
    <table><thead><tr><th>İçerik</th><th>Platform</th><th>Tarih</th><th>Durum</th></tr></thead><tbody>
        <?php foreach ($icerikler as $ic):
            $renkler = ['yayinlandi' => '#dcf5e4;color:#1d7a41', 'onaylandi' => '#dcf5e4;color:#1d7a41', 'taslak' => '#eef0f5;color:#5a6780'];
            $stil = $renkler[$ic['durum']] ?? '#fdf0d9;color:#9a6b10'; ?>
        <tr><td><?= e($ic['baslik']) ?></td><td><?= implode(', ', array_map(fn($pl) => PLATFORMLAR[trim($pl)] ?? trim($pl), explode(',', $ic['platform']))) ?></td><td><?= tarih($ic['tarih']) ?></td><td><span class="durum-cip" style="background:<?= $stil ?>"><?= ICERIK_DURUMLARI[$ic['durum']] ?></span></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <?php if ($onaylar): ?>
    <h2>🔏 Onay Süreçleri</h2>
    <table><thead><tr><th>İş</th><th>Gönderim</th><th>Sonuç</th></tr></thead><tbody>
        <?php foreach ($onaylar as $o): ?>
        <tr><td><?= e($o['baslik']) ?></td><td><?= tarih(substr($o['created'], 0, 10)) ?></td><td><?= ONAY_DURUMLARI[$o['durum']] ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <?php if ($devamEden): ?>
    <h2>🔄 Devam Eden İşler</h2>
    <table><thead><tr><th>İş</th><th>Durum</th><th>Hedef Tarih</th></tr></thead><tbody>
        <?php foreach ($devamEden as $d): ?>
        <tr><td><?= e($d['baslik']) ?></td><td><?= GOREV_DURUMLARI[$d['durum']] ?></td><td><?= tarih($d['son_tarih']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <div style="margin-top:40px;padding-top:16px;border-top:1px solid #e2e6ee;font-size:11.5px;color:#8a93a8;text-align:center">
        Bu rapor <?= e($siteAdi) ?> yönetim sistemi tarafından <?= tarih(date('Y-m-d'), true) ?> tarihinde otomatik oluşturulmuştur.
    </div>
</div>
</body>
</html>

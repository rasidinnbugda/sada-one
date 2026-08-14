<?php
/**
 * SADA Dijital — Teklif / Fatura Belgesi (yazdırılabilir)
 */
require __DIR__ . '/includes/init.php';
require_yetki('finans');

$id = (int)($_GET['id'] ?? 0);
$b = row("SELECT b.*, d.ad dosya_ad, d.iletisim_ad, d.iletisim_eposta, us.ad olusturan_ad FROM belgeler b LEFT JOIN dosyalar d ON d.id=b.dosya_id LEFT JOIN users us ON us.id=b.olusturan_id WHERE b.id=?", [$id]);
if (!$b) { header('Location: finans.php'); exit; }
$kalemler = json_decode($b['kalemler'], true) ?: [];
$araToplam = array_sum(array_map(fn($k) => $k['adet'] * $k['fiyat'], $kalemler));
$kdv = $araToplam * $b['kdv_oran'] / 100;
$siteAdi = ayar('site_adi', 'SADA Dijital');
$turAd = $b['tur'] === 'fatura' ? 'FATURA' : 'TEKLİF';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8"><title><?= e($b['no']) ?> — <?= $turAd ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;600&family=Unbounded:wght@700&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; color:#1a2233; background:#f0f2f7; font-size:13.5px; line-height:1.55; }
.yazdir-bar { position:sticky; top:0; background:#182f5d; color:#fff; padding:12px 24px; display:flex; justify-content:space-between; align-items:center; }
.belge { max-width:800px; margin:24px auto; background:#fff; padding:48px; border-radius:4px; box-shadow:0 4px 20px rgba(0,0,0,.08); }
@media print { .yazdir-bar { display:none; } .belge { margin:0; box-shadow:none; padding:20px; } body { background:#fff; } }
table { width:100%; border-collapse:collapse; margin:24px 0; }
th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#5a6780; padding:9px 12px; background:#f0f3f9; }
td { padding:10px 12px; border-bottom:1px solid #eef0f5; }
.sag { text-align:right; }
</style>
</head>
<body>
<div class="yazdir-bar">
    <span style="font-weight:600"><?= e($b['no']) ?> önizleme</span>
    <button onclick="window.print()" style="background:#b1fb01;color:#14210a;border:none;padding:8px 18px;border-radius:9px;font-weight:700;cursor:pointer">🖨 Yazdır / PDF</button>
</div>
<div class="belge">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #182f5d;padding-bottom:20px">
        <div>
            <?php if (ayar('site_logo')): ?><img src="uploads/<?= e(ayar('site_logo')) ?>" style="max-height:48px;max-width:200px;object-fit:contain">
            <?php else: ?><div style="font-family:'Unbounded',sans-serif;font-size:22px;font-weight:700"><?= e($siteAdi) ?><span style="color:#b1fb01">.</span></div><?php endif; ?>
        </div>
        <div style="text-align:right">
            <div style="font-family:'Space Grotesk',sans-serif;font-size:24px;font-weight:700;letter-spacing:.05em"><?= $turAd ?></div>
            <div style="font-size:14px;color:#182f5d;font-weight:600"><?= e($b['no']) ?></div>
            <div style="font-size:12px;color:#6a7590">Tarih: <?= tarih(substr($b['created'], 0, 10)) ?></div>
            <?php if ($b['gecerlilik']): ?><div style="font-size:12px;color:#6a7590">Geçerlilik: <?= tarih($b['gecerlilik']) ?></div><?php endif; ?>
        </div>
    </div>

    <?php if ($b['dosya_ad']): ?>
    <div style="margin-top:22px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#8a93a8">Sayın</div>
        <div style="font-weight:600;font-size:15px"><?= e($b['dosya_ad']) ?></div>
        <?php if ($b['iletisim_ad']): ?><div style="font-size:12.5px;color:#5a6780"><?= e($b['iletisim_ad']) ?><?= $b['iletisim_eposta'] ? ' · ' . e($b['iletisim_eposta']) : '' ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:18px;font-family:'Space Grotesk',sans-serif;font-size:16px;font-weight:600"><?= e($b['baslik']) ?></div>

    <table>
        <thead><tr><th>Hizmet / Ürün</th><th class="sag">Adet</th><th class="sag">Birim Fiyat</th><th class="sag">Tutar</th></tr></thead>
        <tbody>
        <?php foreach ($kalemler as $k): ?>
        <tr><td><?= e($k['ad']) ?></td><td class="sag"><?= rtrim(rtrim(number_format($k['adet'], 2, ',', '.'), '0'), ',') ?></td><td class="sag"><?= para($k['fiyat']) ?></td><td class="sag" style="font-weight:600"><?= para($k['adet'] * $k['fiyat']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div style="display:flex;justify-content:flex-end">
        <div style="min-width:260px">
            <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:13px"><span style="color:#5a6780">Ara Toplam</span><span><?= para($araToplam) ?></span></div>
            <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:13px"><span style="color:#5a6780">KDV (%<?= $b['kdv_oran'] ?>)</span><span><?= para($kdv) ?></span></div>
            <div style="display:flex;justify-content:space-between;padding:10px 0;border-top:2px solid #182f5d;font-weight:700;font-size:16px;font-family:'Space Grotesk',sans-serif"><span>GENEL TOPLAM</span><span><?= para($araToplam + $kdv) ?></span></div>
        </div>
    </div>

    <?php if ($b['notlar']): ?>
    <div style="margin-top:26px;padding:14px 18px;background:#f5f7fb;border-radius:10px;font-size:12.5px;color:#3a466c">
        <b>Notlar:</b><br><?= nl2br(e($b['notlar'])) ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:36px;padding-top:14px;border-top:1px solid #e2e6ee;font-size:11px;color:#8a93a8;text-align:center">
        <?= e($siteAdi) ?> · Bu belge <?= tarih(date('Y-m-d'), true) ?> tarihinde oluşturulmuştur · <?= e($b['olusturan_ad']) ?>
    </div>
</div>
</body>
</html>

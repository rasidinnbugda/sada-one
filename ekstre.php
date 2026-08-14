<?php
/**
 * SADA Dijital — Cari Hesap Ekstresi (yazdırılabilir)
 * Dosya bazlı fatura/tahsilat dökümü + yürüyen bakiye.
 */
require __DIR__ . '/includes/init.php';
require_yetki('finans');

$dosyaId = (int)($_GET['dosya'] ?? 0);
$dosya = row("SELECT * FROM dosyalar WHERE id=?", [$dosyaId]);
if (!$dosya) { header('Location: finans.php'); exit; }

$hareketler = rows("SELECT o.*, p.ad proje_ad FROM odemeler o JOIN projeler p ON p.id=o.proje_id WHERE p.dosya_id=? ORDER BY o.tarih, o.id", [$dosyaId]);
$siteAdi = ayar('site_adi', 'SADA Dijital');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8"><title><?= e($dosya['ad']) ?> — Cari Ekstre</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;600&family=Unbounded:wght@700&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; color:#1a2233; background:#f0f2f7; font-size:13px; line-height:1.5; }
.yazdir-bar { position:sticky; top:0; background:#182f5d; color:#fff; padding:12px 24px; display:flex; justify-content:space-between; align-items:center; }
.belge { max-width:820px; margin:24px auto; background:#fff; padding:44px; border-radius:4px; box-shadow:0 4px 20px rgba(0,0,0,.08); }
@media print { .yazdir-bar { display:none; } .belge { margin:0; box-shadow:none; padding:16px; } body { background:#fff; } }
table { width:100%; border-collapse:collapse; margin-top:20px; }
th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:#5a6780; padding:8px 10px; background:#f0f3f9; }
td { padding:8px 10px; border-bottom:1px solid #eef0f5; }
.sag { text-align:right; }
</style>
</head>
<body>
<div class="yazdir-bar">
    <span style="font-weight:600"><?= e($dosya['ad']) ?> — cari ekstre</span>
    <button onclick="window.print()" style="background:#b1fb01;color:#14210a;border:none;padding:8px 18px;border-radius:9px;font-weight:700;cursor:pointer">🖨 Yazdır / PDF</button>
</div>
<div class="belge">
    <div style="display:flex;justify-content:space-between;border-bottom:3px solid #182f5d;padding-bottom:16px">
        <div>
            <?php if (ayar('site_logo')): ?><img src="uploads/<?= e(ayar('site_logo')) ?>" style="max-height:44px;object-fit:contain">
            <?php else: ?><div style="font-family:'Unbounded',sans-serif;font-size:20px;font-weight:700"><?= e($siteAdi) ?><span style="color:#b1fb01">.</span></div><?php endif; ?>
        </div>
        <div style="text-align:right">
            <div style="font-family:'Space Grotesk',sans-serif;font-size:19px;font-weight:700">CARİ HESAP EKSTRESİ</div>
            <div style="font-size:14px;font-weight:600;color:#182f5d"><?= e($dosya['ad']) ?></div>
            <div style="font-size:11.5px;color:#6a7590"><?= tarih(date('Y-m-d')) ?> itibarıyla</div>
        </div>
    </div>

    <table>
        <thead><tr><th>Tarih</th><th>Açıklama</th><th>Proje</th><th class="sag">Borç (Fatura)</th><th class="sag">Alacak (Tahsilat)</th><th class="sag">Bakiye</th></tr></thead>
        <tbody>
        <?php $bakiye = 0;
        foreach ($hareketler as $h):
            $borc = $h['tur'] === 'fatura' ? (float)$h['tutar'] : 0;
            $alacak = ($h['tur'] === 'tahsilat' && $h['durum'] === 'odendi') ? (float)$h['tutar'] : 0;
            $bakiye += $borc - $alacak; ?>
        <tr>
            <td><?= tarih($h['tarih']) ?></td>
            <td><?= e($h['baslik']) ?><?= $h['tur'] === 'tahsilat' && $h['durum'] !== 'odendi' ? ' <span style="color:#c98a00;font-size:11px">(bekliyor — bakiyeye işlenmedi)</span>' : '' ?></td>
            <td style="color:#5a6780"><?= e($h['proje_ad']) ?></td>
            <td class="sag" style="color:#b03030"><?= $borc ? para($borc) : '—' ?></td>
            <td class="sag" style="color:#1d7a41"><?= $alacak ? para($alacak) : '—' ?></td>
            <td class="sag" style="font-weight:600"><?= para($bakiye) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$hareketler): ?><tr><td colspan="6" style="text-align:center;color:#8a93a8;padding:20px">Hareket bulunmuyor.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <div style="display:flex;justify-content:flex-end;margin-top:16px">
        <div style="padding:12px 20px;background:<?= $bakiye > 0 ? '#fdf0f0' : '#eefaf1' ?>;border-radius:10px;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:16px;color:<?= $bakiye > 0 ? '#b03030' : '#1d7a41' ?>">
            GÜNCEL BAKİYE: <?= para($bakiye) ?><?= $bakiye > 0 ? ' (tahsil edilecek)' : '' ?>
        </div>
    </div>

    <div style="margin-top:32px;padding-top:12px;border-top:1px solid #e2e6ee;font-size:11px;color:#8a93a8;text-align:center">
        <?= e($siteAdi) ?> yönetim sistemi tarafından otomatik oluşturulmuştur.
    </div>
</div>
</body>
</html>

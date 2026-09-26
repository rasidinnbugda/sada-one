<?php
/**
 * SADA One — Account Statement (printable)
 * Per-client invoice/collection breakdown + running balance.
 */
require __DIR__ . '/includes/init.php';
require_permission('finance');

$clientId = (int)($_GET['client'] ?? 0);
$client = row("SELECT * FROM clients WHERE id=?", [$clientId]);
if (!$client) { header('Location: finance.php'); exit; }

$logs = rows("SELECT o.*, p.name project_name FROM payments o JOIN projects p ON p.id=o.project_id WHERE p.client_id=? ORDER BY o.date, o.id", [$clientId]);
$siteName = setting('site_name', 'SADA One');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8"><title><?= e($client['name']) ?> — Cari Ekstre</title>
<link rel="stylesheet" href="assets/css/fonts.css?v=<?= APP_VERSION ?>">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; color:#1a2233; background:#f0f2f7; font-size:13px; line-height:1.5; }
.print-bar { position:sticky; top:0; background:#182f5d; color:#fff; padding:12px 24px; display:flex; justify-content:space-between; align-items:center; }
.document { max-width:820px; margin:24px auto; background:#fff; padding:44px; border-radius:4px; box-shadow:0 4px 20px rgba(0,0,0,.08); }
@media print { .print-bar { display:none; } .document { margin:0; box-shadow:none; padding:16px; } body { background:#fff; } }
table { width:100%; border-collapse:collapse; margin-top:20px; }
th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:#5a6780; padding:8px 10px; background:#f0f3f9; }
td { padding:8px 10px; border-bottom:1px solid #eef0f5; }
.right { text-align:right; }
</style>
</head>
<body>
<div class="print-bar">
    <span style="font-weight:600"><?= e($client['name']) ?> — cari ekstre</span>
    <button onclick="window.print()" style="background:#b1fb01;color:#14210a;border:none;padding:8px 18px;border-radius:9px;font-weight:700;cursor:pointer">🖨 Yazdır / PDF</button>
</div>
<div class="document">
    <div style="display:flex;justify-content:space-between;border-bottom:3px solid #182f5d;padding-bottom:16px">
        <div>
            <?php if (setting('site_logo')): ?><img src="uploads/<?= e(setting('site_logo')) ?>" style="max-height:44px;object-fit:contain">
            <?php else: ?><div style="font-family:'Unbounded',sans-serif;font-size:20px;font-weight:700"><?= e($siteName) ?><span style="color:#b1fb01">.</span></div><?php endif; ?>
        </div>
        <div style="text-align:right">
            <div style="font-family:'Space Grotesk',sans-serif;font-size:19px;font-weight:700">CARİ HESAP EKSTRESİ</div>
            <div style="font-size:14px;font-weight:600;color:#182f5d"><?= e($client['name']) ?></div>
            <div style="font-size:11.5px;color:#6a7590"><?= format_date(date('Y-m-d')) ?> itibarıyla</div>
        </div>
    </div>

    <table>
        <thead><tr><th>Tarih</th><th>Açıklama</th><th>Proje</th><th class="right">Borç (Fatura)</th><th class="right">Alacak (Tahsilat)</th><th class="right">Bakiye</th></tr></thead>
        <tbody>
        <?php $balance = 0;
        foreach ($logs as $h):
            $debt = $h['type'] === 'invoice' ? (float)$h['amount'] : 0;
            $receivable = ($h['type'] === 'collection' && $h['status'] === 'paid') ? (float)$h['amount'] : 0;
            $balance += $debt - $receivable; ?>
        <tr>
            <td><?= format_date($h['date']) ?></td>
            <td><?= e($h['title']) ?><?= $h['type'] === 'collection' && $h['status'] !== 'paid' ? ' <span style="color:#c98a00;font-size:11px">(bekliyor — bakiyeye işlenmedi)</span>' : '' ?></td>
            <td style="color:#5a6780"><?= e($h['project_name']) ?></td>
            <td class="right" style="color:#b03030"><?= $debt ? money($debt) : '—' ?></td>
            <td class="right" style="color:#1d7a41"><?= $receivable ? money($receivable) : '—' ?></td>
            <td class="right" style="font-weight:600"><?= money($balance) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="6" style="text-align:center;color:#8a93a8;padding:20px">Hareket bulunmuyor.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <div style="display:flex;justify-content:flex-end;margin-top:16px">
        <div style="padding:12px 20px;background:<?= $balance > 0 ? '#fdf0f0' : '#eefaf1' ?>;border-radius:10px;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:16px;color:<?= $balance > 0 ? '#b03030' : '#1d7a41' ?>">
            GÜNCEL BAKİYE: <?= money($balance) ?><?= $balance > 0 ? ' (tahsil edilecek)' : '' ?>
        </div>
    </div>

    <div style="margin-top:32px;padding-top:12px;border-top:1px solid #e2e6ee;font-size:11px;color:#8a93a8;text-align:center">
        <?= e($siteName) ?> yönetim sistemi tarafından otomatik oluşturulmuştur.
    </div>
</div>
</body>
</html>

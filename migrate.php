<?php
/**
 * SADA One — Version Upgrade Script (v1 → v2)
 * Safely adds new columns/tables to existing installations.
 * Can be run multiple times (idempotent).
 * Usage: open /guncelle.php in the browser (admin login required),
 * then delete this file from the server when finished.
 */
require __DIR__ . '/includes/init.php';
$u = require_admin();

require_once __DIR__ . '/includes/migration.php';
$results = run_migrations(db());
?>
<!DOCTYPE html>
<html lang="tr" data-theme="lime">
<head><meta charset="UTF-8"><title>Güncelleme</title><link rel="stylesheet" href="assets/css/app.css"></head>
<body style="padding:40px;max-width:820px;margin:0 auto">
<h1 style="font-family:'Space Grotesk',sans-serif;margin-bottom:8px">Veritabanı Güncellemesi</h1>
<p class="metin-2" style="margin-bottom:24px">v2 şema değişiklikleri uygulandı. "Atlandı" satırları zaten güncel olan kısımlardır.</p>
<?php foreach ($results as [$status, $sql]): ?>
<div style="padding:9px 14px;margin-bottom:6px;border-radius:10px;font-size:12.5px;font-family:monospace;background:var(--surface);border:1px solid var(--border)">
    <?= ['ok' => '✅', 'skip' => '⏭️', 'error' => '❌'][$status] ?> <?= e(mb_substr($sql, 0, 110)) ?>
</div>
<?php endforeach; ?>
<div style="margin-top:24px;padding:14px 18px;border-radius:12px;background:var(--parlak);border:1px solid var(--border-2)">
    <b>Bitti!</b> Güvenlik için bu dosyayı (<code>migrate.php</code>) sunucudan silin. <a href="index.php" style="color:var(--marka);font-weight:600">Panele dön →</a>
</div>
</body>
</html>

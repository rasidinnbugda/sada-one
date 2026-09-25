<?php
/**
 * SADA One — In-Panel Update System
 * Updates by uploading a ZIP or downloading the latest release from GitHub.
 * The same ZIP package is also used for fresh installs (contains install/).
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/migration.php';
require_once __DIR__ . '/includes/updater-core.php';
$u = require_admin();
/* ---------------- POST actions ---------------- */
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    set_time_limit(300);
    $operation = $_POST['operation'] ?? '';

    if ($operation === 'zip' && !empty($_FILES['package']['tmp_name'])) {
        if (!preg_match('/\.zip$/i', $_FILES['package']['name'])) {
            $result = [false, 'Lütfen .zip uzantılı bir paket yükleyin.', []];
        } else {
            $result = install_package($_FILES['package']['tmp_name']);
        }
    } elseif ($operation === 'github') {
        $rel = github_json('https://api.github.com/repos/' . GITHUB_REPO . '/releases/latest');
        $url = null;
        foreach (($rel['assets'] ?? []) as $a) {
            if (str_ends_with($a['name'] ?? '', '.zip')) { $url = $a['browser_download_url']; break; }
        }
        $url = $url ?: ($rel['zipball_url'] ?? null);
        if (!$url) {
            $result = [false, 'GitHub\'da indirilebilir bir sürüm paketi bulunamadı.', []];
        } else {
            $tmp = tempnam(sys_get_temp_dir(), 'sadaone') . '.zip';
            if (!download_url($url, $tmp)) {
                $result = [false, 'Paket indirilemedi — sunucunun dışarı bağlantısına izin verilmiyor olabilir. ZIP yükleme yöntemini deneyin.', []];
            } else {
                $result = install_package($tmp);
                @unlink($tmp);
            }
        }
    }
    // After the update, read the new version number from the fresh file
    if ($result && $result[0]) {
        $newVersion = null;
        if (preg_match("/const APP_VERSION = '([^']+)'/", (string)@file_get_contents(ROOT . '/includes/init.php'), $m)) $newVersion = $m[1];
        if ($newVersion) $result[1] = 'Güncelleme tamamlandı: v' . APP_VERSION . ' → v' . $newVersion . '. Sayfayı yenilediğinizde yeni sürüm etkin olur.';
    }
}

$backups = is_dir(ROOT . '/backups') ? array_reverse(glob(ROOT . '/backups/backup-*.zip') ?: []) : [];

page_start('Güncelleme', 'update');
?>
<div class="page-top"><div><div class="page-title">Sistem Güncelleme</div><div class="page-bottom">Mevcut sürüm: <b>v<?= APP_VERSION ?></b> — paketle veya GitHub üzerinden güncelleyin
    · PHP <?= PHP_VERSION ?> (<?= e(php_sapi_name()) ?>) · arka plan işleri: <b><?= function_exists('litespeed_finish_request') || function_exists('fastcgi_finish_request') ? 'sayfa teslim edildikten sonra ✓' : 'sayfa sonunda (erken teslim desteği yok)' ?></b></div></div></div>

<?php if ($result): [$ok, $message, $d] = $result; ?>
<div class="card mb-3" style="border-color:<?= $ok ? 'var(--success)' : 'var(--danger)' ?>">
    <div class="card-title mb-2"><?= $ok ? '✅' : '⚠️' ?> <?= e($message) ?></div>
    <?php if ($d): ?>
    <div class="text-2 small" style="line-height:1.9">
        Yazılan dosya: <b><?= (int)($d['written'] ?? 0) ?></b> · Korunan/atlanan: <b><?= (int)($d['skipped'] ?? 0) ?></b> ·
        Şema: <b><?= (int)($d['mig_ok'] ?? 0) ?> yeni</b>, <?= (int)($d['mig_skip'] ?? 0) ?> zaten güncel ·
        Yedek: <code><?= e($d['backup'] ?? '-') ?></code>
        <?php foreach (($d['client_error'] ?? []) as $h): ?><br>❌ Yazılamadı: <code><?= e($h) ?></code><?php endforeach; ?>
        <?php foreach (($d['mig_error'] ?? []) as $h): ?><br>❌ Şema: <code><?= e(mb_substr($h, 0, 160)) ?></code><?php endforeach; ?>
    </div>
    <?php if ($ok): ?><a href="updater.php" class="btn btn-brand mt-2">Sayfayı Yenile</a><?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-title mb-2"><?= icon('web', 16) ?> GitHub'dan Güncelle</div>
        <div class="cell-bottom mb-3">Depo: <code><?= GITHUB_REPO ?></code> — son yayınlanan sürüm denetlenir, tek tıkla indirilip kurulur.</div>
        <div id="ghStatus" class="small text-2 mb-3">Denetlemek için butona basın.</div>
        <div class="row-flex" style="gap:10px">
            <button class="btn" id="ghCheckBtn" onclick="versionCheck()">Sürüm Denetle</button>
            <form method="post" id="ghSetupForm" style="display:none" onsubmit="return confirm('Son sürüm indirilip kurulacak. Otomatik yedek alınır. Devam edilsin mi?')">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="operation" value="github">
                <button class="btn btn-brand" type="submit">İndir ve Kur</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-title mb-2"><?= icon('paperclip', 16) ?> ZIP Paketi Yükle</div>
        <div class="cell-bottom mb-3">Size iletilen <code>sada-one.zip</code> paketini seçin. <code>config.php</code>, <code>uploads/</code> ve yedekler korunur; veritabanı şeması otomatik güncellenir.</div>
        <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Paket mevcut kurulumun üzerine uygulanacak. Otomatik yedek alınır. Devam edilsin mi?')">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
            <input type="hidden" name="operation" value="zip">
            <div class="form-group"><input type="file" name="package" class="input" accept=".zip" required></div>
            <button class="btn btn-brand" type="submit">Yükle ve Güncelle</button>
        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card-title mb-2">Yedekler</div>
    <?php if (!$backups): ?><div class="text-muted small">Henüz yedek yok. Her güncelleme öncesi otomatik alınır.</div>
    <?php else: ?>
    <div class="vertical" style="gap:6px">
        <?php foreach (array_slice($backups, 0, 10) as $y): ?>
        <div class="row-flex between small" style="padding:8px 12px;background:var(--surface-2);border-radius:9px">
            <code><?= e(basename($y)) ?></code>
            <span class="text-muted"><?= number_format(filesize($y) / 1024, 0, ',', '.') ?> KB</span>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="form-hint mt-2">Geri dönmek gerekirse: yedek ZIP'ini bu sayfadan "ZIP Paketi Yükle" ile kurabilirsiniz.</div>
    <?php endif; ?>
</div>

<script>
async function versionCheck() {
    const box = document.getElementById('ghStatus');
    box.textContent = 'Denetleniyor...';
    const j = await api('version_check', {});
    if (!j.ok) { box.textContent = j.error || 'Denetlenemedi.'; return; }
    if (j.new_var) {
        box.innerHTML = '🆕 Yeni sürüm var: <b>' + j.last + '</b> (kurulu: v' + j.current + ')' + (j.notes ? '<br><span class="text-muted">' + j.notes + '</span>' : '');
        document.getElementById('ghSetupForm').style.display = 'inline';
    } else {
        box.innerHTML = '✅ Güncelsiniz: v' + j.current + (j.last ? ' (GitHub: ' + j.last + ')' : '');
    }
}
</script>
<?php page_end(); ?>

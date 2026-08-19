<?php
/**
 * SADA One — Panel İçi Güncelleme Sistemi
 * ZIP yükleyerek veya GitHub'daki son sürümü indirerek güncelleme.
 * Aynı ZIP paketi sıfırdan kurulum için de kullanılır (install/ içerir).
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/migrasyon.php';
require_once __DIR__ . '/includes/guncelleyici.php';
$u = require_admin();
/* ---------------- POST işlemleri ---------------- */
$sonuc = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    set_time_limit(300);
    $islem = $_POST['islem'] ?? '';

    if ($islem === 'zip' && !empty($_FILES['paket']['tmp_name'])) {
        if (!preg_match('/\.zip$/i', $_FILES['paket']['name'])) {
            $sonuc = [false, 'Lütfen .zip uzantılı bir paket yükleyin.', []];
        } else {
            $sonuc = paket_kur($_FILES['paket']['tmp_name']);
        }
    } elseif ($islem === 'github') {
        $rel = github_json('https://api.github.com/repos/' . GITHUB_DEPO . '/releases/latest');
        $url = null;
        foreach (($rel['assets'] ?? []) as $a) {
            if (str_ends_with($a['name'] ?? '', '.zip')) { $url = $a['browser_download_url']; break; }
        }
        $url = $url ?: ($rel['zipball_url'] ?? null);
        if (!$url) {
            $sonuc = [false, 'GitHub\'da indirilebilir bir sürüm paketi bulunamadı.', []];
        } else {
            $tmp = tempnam(sys_get_temp_dir(), 'sadaone') . '.zip';
            if (!dosya_indir($url, $tmp)) {
                $sonuc = [false, 'Paket indirilemedi — sunucunun dışarı bağlantısına izin verilmiyor olabilir. ZIP yükleme yöntemini deneyin.', []];
            } else {
                $sonuc = paket_kur($tmp);
                @unlink($tmp);
            }
        }
    }
    // Güncelleme sonrası yeni sürüm numarasını taze dosyadan oku
    if ($sonuc && $sonuc[0]) {
        $yeniSurum = null;
        if (preg_match("/const SURUM = '([^']+)'/", (string)@file_get_contents(ROOT . '/includes/init.php'), $m)) $yeniSurum = $m[1];
        if ($yeniSurum) $sonuc[1] = 'Güncelleme tamamlandı: v' . SURUM . ' → v' . $yeniSurum . '. Sayfayı yenilediğinizde yeni sürüm etkin olur.';
    }
}

$yedekler = is_dir(ROOT . '/backups') ? array_reverse(glob(ROOT . '/backups/yedek-*.zip') ?: []) : [];

sayfa_basi('Güncelleme', 'guncelleme');
?>
<div class="sayfa-ust"><div><div class="sayfa-baslik">Sistem Güncelleme</div><div class="sayfa-alt">Mevcut sürüm: <b>v<?= SURUM ?></b> — paketle veya GitHub üzerinden güncelleyin</div></div></div>

<?php if ($sonuc): [$ok, $mesaj, $d] = $sonuc; ?>
<div class="kart mb-3" style="border-color:<?= $ok ? 'var(--basari)' : 'var(--tehlike)' ?>">
    <div class="kart-baslik mb-2"><?= $ok ? '✅' : '⚠️' ?> <?= e($mesaj) ?></div>
    <?php if ($d): ?>
    <div class="metin-2 kucuk" style="line-height:1.9">
        Yazılan dosya: <b><?= (int)($d['yazilan'] ?? 0) ?></b> · Korunan/atlanan: <b><?= (int)($d['atlanan'] ?? 0) ?></b> ·
        Şema: <b><?= (int)($d['mig_ok'] ?? 0) ?> yeni</b>, <?= (int)($d['mig_atla'] ?? 0) ?> zaten güncel ·
        Yedek: <code><?= e($d['yedek'] ?? '-') ?></code>
        <?php foreach (($d['dosya_hata'] ?? []) as $h): ?><br>❌ Yazılamadı: <code><?= e($h) ?></code><?php endforeach; ?>
        <?php foreach (($d['mig_hata'] ?? []) as $h): ?><br>❌ Şema: <code><?= e(mb_substr($h, 0, 160)) ?></code><?php endforeach; ?>
    </div>
    <?php if ($ok): ?><a href="guncelleme.php" class="btn btn-marka mt-2">Sayfayı Yenile</a><?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="izgara izgara-2">
    <div class="kart">
        <div class="kart-baslik mb-2"><?= ikon('web', 16) ?> GitHub'dan Güncelle</div>
        <div class="hucre-alt mb-3">Depo: <code><?= GITHUB_DEPO ?></code> — son yayınlanan sürüm denetlenir, tek tıkla indirilip kurulur.</div>
        <div id="ghDurum" class="kucuk metin-2 mb-3">Denetlemek için butona basın.</div>
        <div class="satir-esnek" style="gap:10px">
            <button class="btn" id="ghKontrolBtn" onclick="surumKontrol()">Sürüm Denetle</button>
            <form method="post" id="ghKurForm" style="display:none" onsubmit="return confirm('Son sürüm indirilip kurulacak. Otomatik yedek alınır. Devam edilsin mi?')">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="islem" value="github">
                <button class="btn btn-marka" type="submit">İndir ve Kur</button>
            </form>
        </div>
    </div>

    <div class="kart">
        <div class="kart-baslik mb-2"><?= ikon('atac', 16) ?> ZIP Paketi Yükle</div>
        <div class="hucre-alt mb-3">Size iletilen <code>sada-one.zip</code> paketini seçin. <code>config.php</code>, <code>uploads/</code> ve yedekler korunur; veritabanı şeması otomatik güncellenir.</div>
        <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Paket mevcut kurulumun üzerine uygulanacak. Otomatik yedek alınır. Devam edilsin mi?')">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
            <input type="hidden" name="islem" value="zip">
            <div class="form-grup"><input type="file" name="paket" class="girdi" accept=".zip" required></div>
            <button class="btn btn-marka" type="submit">Yükle ve Güncelle</button>
        </form>
    </div>
</div>

<div class="kart mt-3">
    <div class="kart-baslik mb-2">Yedekler</div>
    <?php if (!$yedekler): ?><div class="metin-muted kucuk">Henüz yedek yok. Her güncelleme öncesi otomatik alınır.</div>
    <?php else: ?>
    <div class="dikey" style="gap:6px">
        <?php foreach (array_slice($yedekler, 0, 10) as $y): ?>
        <div class="satir-esnek arasi kucuk" style="padding:8px 12px;background:var(--surface-2);border-radius:9px">
            <code><?= e(basename($y)) ?></code>
            <span class="metin-muted"><?= number_format(filesize($y) / 1024, 0, ',', '.') ?> KB</span>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="form-ipucu mt-2">Geri dönmek gerekirse: yedek ZIP'ini bu sayfadan "ZIP Paketi Yükle" ile kurabilirsiniz.</div>
    <?php endif; ?>
</div>

<script>
async function surumKontrol() {
    const kutu = document.getElementById('ghDurum');
    kutu.textContent = 'Denetleniyor...';
    const j = await api('surum_kontrol', {});
    if (!j.ok) { kutu.textContent = j.hata || 'Denetlenemedi.'; return; }
    if (j.yeni_var) {
        kutu.innerHTML = '🆕 Yeni sürüm var: <b>' + j.son + '</b> (kurulu: v' + j.mevcut + ')' + (j.notlar ? '<br><span class="metin-muted">' + j.notlar + '</span>' : '');
        document.getElementById('ghKurForm').style.display = 'inline';
    } else {
        kutu.innerHTML = '✅ Güncelsiniz: v' + j.mevcut + (j.son ? ' (GitHub: ' + j.son + ')' : '');
    }
}
</script>
<?php sayfa_sonu(); ?>

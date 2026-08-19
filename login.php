<?php
require __DIR__ . '/includes/init.php';

if (user()) { header('Location: index.php'); exit; }

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eposta = mb_strtolower(trim($_POST['eposta'] ?? ''));
    $sifre = $_POST['sifre'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Brute-force koruması: son 15 dakikada 5+ başarısız deneme → kilit
    $deneme = (int)val("SELECT COUNT(*) FROM giris_denemeleri WHERE (eposta=? OR ip=?) AND basarili=0 AND created > DATE_SUB(NOW(), INTERVAL 15 MINUTE)", [$eposta, $ip]);
    if ($deneme >= 5) {
        $hata = 'Çok fazla başarısız deneme. Güvenlik için 15 dakika bekleyin.';
    } else {
        $u = row("SELECT * FROM users WHERE eposta=? AND aktif=1", [$eposta]);
        if ($u && password_verify($sifre, $u['sifre'])) {
            insert('giris_denemeleri', ['eposta' => $eposta, 'ip' => $ip, 'basarili' => 1, 'created' => date('Y-m-d H:i:s')]);
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            guncelle('users', ['son_giris' => date('Y-m-d H:i:s')], 'id=?', [$u['id']]);
            header('Location: index.php');
            exit;
        }
        insert('giris_denemeleri', ['eposta' => $eposta, 'ip' => $ip, 'basarili' => 0, 'created' => date('Y-m-d H:i:s')]);
        // 5. başarısız denemede yöneticilere haber ver
        if ($deneme === 4) {
            foreach (rows("SELECT id FROM users WHERE rol='yonetici' AND aktif=1") as $yon) {
                q("INSERT INTO bildirimler (user_id, baslik, mesaj, link, okundu, created) VALUES (?,?,?,?,0,NOW())",
                    [$yon['id'], '⚠️ Şüpheli giriş denemesi', $eposta . ' hesabına art arda başarısız giriş denemesi yapıldı (IP: ' . $ip . '). Hesap 15 dk kilitlendi.', 'kullanicilar.php']);
            }
        }
        $hata = 'E-posta veya şifre hatalı.';
        usleep(400000);
    }
}
$tema = ayar('varsayilan_tema', 'lime');
$siteAdi = ayar('site_adi', 'SADA One');
?>
<!DOCTYPE html>
<html lang="tr" data-theme="<?= e($tema) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Giriş — <?= e($siteAdi) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= SURUM ?>">
<?php if (ayar('site_favicon')): ?><link rel="icon" href="uploads/<?= e(ayar('site_favicon')) ?>"><?php endif; ?>
</head>
<body class="giris-govde">
<div class="giris-kutu">
    <?php if (ayar('site_logo')): ?>
    <div style="text-align:center;margin-bottom:6px"><img src="uploads/<?= e(ayar('site_logo')) ?>" alt="<?= e($siteAdi) ?>" style="max-height:64px;max-width:240px;object-fit:contain"></div>
    <?php else: ?>
    <div class="giris-logo">SADA<span>.</span></div>
    <?php endif; ?>
    <div class="giris-alt"><?= e($siteAdi) ?> — Ajans Yönetim Sistemi</div>
    <div class="giris-panel">
        <?php if ($hata): ?>
        <div class="toast hata" style="margin-bottom:20px;animation:none">
            <svg class="toast-ikon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/></svg>
            <span><?= e($hata) ?></span>
        </div>
        <?php endif; ?>
        <form method="post">
            <div class="form-grup">
                <label class="form-etiket">E-posta</label>
                <input type="email" name="eposta" class="girdi" required autofocus value="<?= e($_POST['eposta'] ?? '') ?>" placeholder="ornek@sada.com">
            </div>
            <div class="form-grup">
                <label class="form-etiket">Şifre</label>
                <input type="password" name="sifre" class="girdi" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn btn-marka btn-blok" style="margin-top:8px">Giriş Yap</button>
        </form>
    </div>
    <div class="giris-alt" style="margin-top:20px;font-size:12.5px">© <?= date('Y') ?> <?= e($siteAdi) ?></div>
</div>
</body>
</html>

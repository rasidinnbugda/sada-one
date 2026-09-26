<?php
require __DIR__ . '/includes/init.php';

if (user()) { header('Location: index.php'); exit; }

$error = '';
// CSRF check: the login form is a state-changing request too (login CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $error = 'Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Brute-force protection: 5+ failed attempts in the last 15 minutes → lockout
    $attempt = (int)val("SELECT COUNT(*) FROM login_attempts WHERE (email=? OR ip=?) AND is_success=0 AND created > DATE_SUB(NOW(), INTERVAL 15 MINUTE)", [$email, $ip]);
    if ($attempt >= 5) {
        $error = 'Çok fazla başarısız deneme. Güvenlik için 15 dakika bekleyin.';
    } else {
        $u = row("SELECT * FROM users WHERE email=? AND is_active=1", [$email]);
        if ($u && password_verify($password, $u['password'])) {
            insert('login_attempts', ['email' => $email, 'ip' => $ip, 'is_success' => 1, 'created' => date('Y-m-d H:i:s')]);
            @session_start(); // reopen: init releases the lock early
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            session_write_close();
            update_row('users', ['last_login' => date('Y-m-d H:i:s')], 'id=?', [$u['id']]);
            header('Location: index.php');
            exit;
        }
        insert('login_attempts', ['email' => $email, 'ip' => $ip, 'is_success' => 0, 'created' => date('Y-m-d H:i:s')]);
        // Notify admins on the 5th failed attempt
        if ($attempt === 4) {
            foreach (rows("SELECT id FROM users WHERE role='admin' AND is_active=1") as $adminUser) {
                q("INSERT INTO notifications (user_id, title, message, link, is_read, created) VALUES (?,?,?,?,0,NOW())",
                    [$adminUser['id'], '⚠️ Şüpheli giriş denemesi', $email . ' hesabına art arda başarısız giriş denemesi yapıldı (IP: ' . $ip . '). Hesap 15 dk kilitlendi.', 'users.php']);
            }
        }
        $error = 'E-posta veya şifre hatalı.';
        usleep(400000);
    }
}
$theme = setting('default_theme', 'studio');
$siteName = setting('site_name', 'SADA One');
?>
<!DOCTYPE html>
<html lang="tr" data-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Giriş — <?= e($siteName) ?></title>
<link rel="stylesheet" href="assets/css/fonts.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
<?php if (theme_favicon()): ?><link rel="icon" href="uploads/<?= e(theme_favicon()) ?>"><?php endif; ?>
</head>
<body class="login-body">
<div class="login-box">
    <?php if (theme_logo()): ?>
    <div style="text-align:center;margin-bottom:6px"><img src="uploads/<?= e(theme_logo()) ?>" alt="<?= e($siteName) ?>" style="max-height:64px;max-width:240px;object-fit:contain"></div>
    <?php else: ?>
    <div class="login-logo">SADA<span>.</span></div>
    <?php endif; ?>
    <div class="login-bottom"><?= e($siteName) ?> — Ajans Yönetim Sistemi</div>
    <div class="login-panel">
        <?php if ($error): ?>
        <div class="toast error" style="margin-bottom:20px;animation:none">
            <svg class="toast-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/></svg>
            <span><?= e($error) ?></span>
        </div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div class="form-group">
                <label class="form-label">E-posta</label>
                <input type="email" name="email" class="input" required autofocus value="<?= e($_POST['email'] ?? '') ?>" placeholder="ornek@sada.com">
            </div>
            <div class="form-group">
                <label class="form-label">Şifre</label>
                <input type="password" name="password" class="input" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn btn-brand btn-block" style="margin-top:8px">Giriş Yap</button>
        </form>
    </div>
    <div class="login-bottom" style="margin-top:20px;font-size:12.5px">© <?= date('Y') ?> <?= e($siteName) ?></div>
</div>
</body>
</html>

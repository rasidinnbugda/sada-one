<?php
/**
 * SADA One — Answer an approval without an account
 * The client opens the approval's secret link (approve.php?t=…), sees what was sent and answers it. Only a pending
 * approval that is still the current one for its work / month can be answered; an older link shows what happened.
 */
require __DIR__ . '/includes/init.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer'); // the token must not leak to Drive or other linked sites

$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$token = preg_match('~^[a-f0-9]{32}$~', $token) ? $token : '';
$approval = $token ? row("SELECT o.*, p.name project_name, c.name client_name, us.name sender_name FROM approvals o
    JOIN projects p ON p.id=o.project_id JOIN clients c ON c.id=p.client_id LEFT JOIN users us ON us.id=o.sender_id WHERE o.token=?", [$token]) : null;
$current = $approval && approval_is_current($approval);
$answerable = $approval && $approval['status'] === 'pending' && $current;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $approval) {
    $status = (string)($_POST['status'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    if (!$answerable) $error = 'Bu onay artık cevap beklemiyor.';
    elseif (!in_array($status, ['approved', 'revision', 'rejected'], true)) $error = 'Bir cevap seçin.';
    elseif ($status !== 'approved' && $note === '') $error = 'Revize ya da ret için ne değişmesi gerektiğini kısaca yazın.';
    else {
        approval_apply_reply($approval, $status, mb_substr($note, 0, 2000), null, $name);
        header('Location: approve.php?t=' . $token . '&done=1');
        exit;
    }
}

$file = $approval && $approval['archive_id'] ? row("SELECT * FROM archive WHERE id=?", [$approval['archive_id']]) : null;
$isImage = $file && in_array($file['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
$plan = $approval && $approval['task_id'] ? row("SELECT publish_date, publish_time, platforms FROM tasks WHERE id=?", [$approval['task_id']]) : null;
$theme = setting('default_theme', 'studio');
$siteName = setting('site_name', 'SADA One');
?>
<!DOCTYPE html>
<html lang="tr" data-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= $approval ? e($approval['title']) . ' — ' : '' ?>Onay — <?= e($siteName) ?></title>
<link rel="stylesheet" href="assets/css/fonts.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
<?php if (theme_favicon()): ?><link rel="icon" href="uploads/<?= e(theme_favicon()) ?>"><?php endif; ?>
</head>
<body class="login-body">
<div class="login-box approve-box">
    <?php if (theme_logo()): ?>
    <div style="text-align:center;margin-bottom:6px"><img src="uploads/<?= e(theme_logo()) ?>" alt="<?= e($siteName) ?>" style="max-height:56px;max-width:220px;object-fit:contain"></div>
    <?php else: ?>
    <div class="login-logo">SADA<span>.</span></div>
    <?php endif; ?>
    <div class="login-panel">
        <?php if (!$approval): ?>
        <div class="card-title" style="font-size:18px">Bu bağlantı geçersiz</div>
        <p class="small text-2 mt-2">Bağlantı eksik kopyalanmış olabilir. Size iletilen mesajdaki bağlantıyı yeniden açın ya da ajansınıza yazın.</p>
        <?php else: ?>
        <div class="cell-bottom"><?= e($approval['client_name']) ?> · <?= e($approval['project_name']) ?></div>
        <div class="row-flex wrap mt-1" style="gap:10px;align-items:center">
            <div class="card-title" style="font-size:20px"><?= e($approval['title']) ?></div>
            <?= badge($approval['status'], APPROVAL_STATUSES) ?>
            <?php if ($approval['period_id']): ?><span class="badge badge-type">Aylık plan</span><?php endif; ?>
        </div>
        <div class="cell-bottom mt-1"><?= e($approval['sender_name'] ?? $siteName) ?> gönderdi · <?= format_date($approval['created'], true) ?></div>
        <?php if ($plan && $plan['publish_date']): ?><div class="small text-2 mt-2">Yayın: <?= format_date($plan['publish_date']) ?><?= $plan['publish_time'] ? ' ' . substr($plan['publish_time'], 0, 5) : '' ?><?= $plan['platforms'] ? ' · ' . e(implode(', ', array_map(fn($k) => PLATFORMS[$k] ?? $k, explode(',', $plan['platforms'])))) : '' ?></div><?php endif; ?>

        <?php if ($approval['description']): ?><div class="approve-text mt-3"><?= nl2br(e($approval['description'])) ?></div><?php endif; ?>
        <?php if ($file): ?>
        <div class="mt-3">
            <?php if ($isImage): ?><a href="uploads/<?= e($file['file_path']) ?>" target="_blank" rel="noopener"><img src="uploads/<?= e($file['file_path']) ?>" alt="<?= e($file['name']) ?>" class="approve-image"></a>
            <?php else: ?><a href="uploads/<?= e($file['file_path']) ?>" target="_blank" rel="noopener" class="btn btn-sm"><?= icon('paperclip', 13) ?> <?= e($file['name']) ?></a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($approval['drive_link']): ?><a href="<?= e($approval['drive_link']) ?>" target="_blank" rel="noopener" class="btn btn-sm mt-3"><?= icon('web', 13) ?> Drive'da görüntüle</a><?php endif; ?>

        <?php if (isset($_GET['done']) && $approval['status'] !== 'pending'): ?>
        <div class="approve-result mt-3"><b>Teşekkürler, cevabınız iletildi.</b><br><span class="small text-2"><?= $approval['status'] === 'approved' ? 'Ekip işe devam ediyor.' : 'Ekip notunuzu gördü ve düzeltmeye başlıyor.' ?></span></div>
        <?php elseif ($answerable): ?>
        <?php if ($error): ?><div class="toast error mt-3" style="animation:none"><span><?= e($error) ?></span></div><?php endif; ?>
        <form method="post" class="mt-3">
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <div class="form-group"><label class="form-label">Notunuz</label><textarea name="note" class="text-area" rows="3" placeholder="Değişiklik isteğiniz ya da eklemek istedikleriniz (revize ve ret için gerekli)"><?= e($_POST['note'] ?? '') ?></textarea></div>
            <div class="form-group"><label class="form-label">Adınız <span class="text-muted" style="font-weight:400">(isteğe bağlı)</span></label><input name="name" class="input" maxlength="100" value="<?= e($_POST['name'] ?? '') ?>"></div>
            <div class="approve-actions">
                <button type="submit" name="status" value="approved" class="btn btn-brand" style="background:var(--success);color:#fff">✓ Onaylıyorum</button>
                <button type="submit" name="status" value="revision" class="btn">↻ Revize istiyorum</button>
                <button type="submit" name="status" value="rejected" class="btn btn-danger">✕ Reddet</button>
            </div>
        </form>
        <?php elseif ($approval['status'] !== 'pending'): ?>
        <div class="approve-result mt-3">Bu onay <?= format_date($approval['reply_date'] ?: $approval['created'], true) ?> tarihinde <b><?= APPROVAL_STATUSES[$approval['status']] ?></b> olarak yanıtlandı.<?php if ($approval['reply_note']): ?><div class="small text-2 mt-1" style="white-space:pre-wrap"><?= e($approval['reply_note']) ?></div><?php endif; ?></div>
        <?php else: ?>
        <div class="approve-result mt-3">Bu gönderimin yerini daha yeni bir gönderim aldı. Güncel bağlantı size ayrıca iletilir.</div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="login-bottom" style="margin-top:18px;font-size:12.5px"><?= e($siteName) ?></div>
</div>
</body>
</html>

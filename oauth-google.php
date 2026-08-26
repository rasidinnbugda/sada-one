<?php
/**
 * Google OAuth endpoint (admin only).
 *  ?baslat=1  → redirect to the Google consent screen
 *  ?code=...  → exchange the code, store the refresh token, back to settings
 * The redirect URI to register in Google Cloud Console is exactly this file's URL
 * (shown ready-to-copy on the settings page).
 */
require __DIR__ . '/includes/init.php';
require_admin();
require_once __DIR__ . '/includes/google-drive.php';

$redirect = full_url('oauth-google.php');

if (isset($_GET['baslat'])) {
    if (setting('google_client_id') === '' || setting('google_client_secret') === '') {
        header('Location: settings.php?drive_err=' . urlencode('Önce Client ID ve Client Secret alanlarını kaydedin.'));
        exit;
    }
    header('Location: ' . drive_auth_url($redirect));
    exit;
}

if (isset($_GET['code'])) {
    $r = drive_oauth_exchange((string)$_GET['code'], $redirect);
    if ($r['ok']) {
        log_activity('Google Drive bağlandı: ' . ($r['email'] ?: '?'));
        header('Location: settings.php?drive_ok=' . urlencode($r['email']));
    } else {
        header('Location: settings.php?drive_err=' . urlencode($r['error']));
    }
    exit;
}

// User cancelled on the consent screen (?error=access_denied) or opened directly
header('Location: settings.php' . (isset($_GET['error']) ? '?drive_err=' . urlencode('Bağlantı iptal edildi.') : ''));
exit;

<?php
/**
 * SADA One — Google Drive integration core (no external libraries).
 * Auth: a Google Cloud service-account JSON key stored at storage/google-service.json.
 * The panel signs a JWT with the key (RS256 via openssl) and exchanges it for an
 * access token, then queries the Drive v3 API read-only.
 *
 * Setup (admin, once): Settings → Drive Entegrasyonu — upload the JSON key and
 * share the tracked Drive folders with the service-account e-mail address.
 */

const GOOGLE_KEY_PATH = ROOT . '/storage/google-service.json';

/** Is the Drive integration configured (either auth method)? */
function drive_configured(): bool {
    return drive_oauth_configured() || (is_file(GOOGLE_KEY_PATH) && function_exists('openssl_sign'));
}

/** OAuth (recommended): the panel is connected to the agency's own Google account. */
function drive_oauth_configured(): bool {
    return setting('google_client_id') !== '' && setting('google_client_secret') !== '' && setting('google_refresh_token') !== '';
}

/** Google consent-screen URL (admin clicks "Google ile Bağlan"). */
function drive_auth_url(string $redirect): string {
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => setting('google_client_id'),
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive',
        'access_type' => 'offline',
        'prompt' => 'consent', // ask every time so a refresh token is always issued
    ]);
}

/** Exchange the one-time code for tokens and store the refresh token. */
function drive_oauth_exchange(string $code, string $redirect): array {
    $j = json_decode((string)drive_http('https://oauth2.googleapis.com/token', http_build_query([
        'code' => $code, 'client_id' => setting('google_client_id'),
        'client_secret' => setting('google_client_secret'),
        'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
    ])), true);
    if (empty($j['refresh_token'])) {
        return ['ok' => false, 'error' => mb_substr((string)($j['error_description'] ?? $j['error'] ?? 'Google yanıt vermedi'), 0, 200)];
    }
    q("INSERT INTO settings (setting_key, setting_value) VALUES ('google_refresh_token',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$j['refresh_token']]);
    q("INSERT INTO settings (setting_key, setting_value) VALUES ('google_drive_token',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
        [json_encode(['token' => $j['access_token'] ?? '', 'exp' => time() + (int)($j['expires_in'] ?? 0)])]);
    // Connected account address (shown on the settings page)
    $about = json_decode((string)drive_http('https://www.googleapis.com/drive/v3/about?fields=user', null,
        ['Authorization: Bearer ' . ($j['access_token'] ?? '')]), true);
    $email = $about['user']['emailAddress'] ?? '';
    q("INSERT INTO settings (setting_key, setting_value) VALUES ('google_drive_email',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$email]);
    return ['ok' => true, 'email' => $email];
}

/** Base64url without padding (JWT segments). */
function drive_b64url(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/**
 * Access token for the Drive read-only scope.
 * Cached in the settings table until ~5 minutes before expiry.
 * Returns the token string, or null with $error filled on failure.
 */
function drive_token(?string &$error = null): ?string {
    if (!drive_configured()) { $error = 'Drive bağlantısı kurulmamış (Ayarlar → Drive).'; return null; }
    $cached = json_decode((string)setting('google_drive_token'), true);
    if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 300) return $cached['token'];

    // OAuth (preferred): refresh the access token
    if (drive_oauth_configured()) {
        $now = time();
        $j = json_decode((string)drive_http('https://oauth2.googleapis.com/token', http_build_query([
            'client_id' => setting('google_client_id'), 'client_secret' => setting('google_client_secret'),
            'refresh_token' => setting('google_refresh_token'), 'grant_type' => 'refresh_token',
        ])), true);
        if (empty($j['access_token'])) {
            $error = 'Google bağlantısı yenilenemedi: ' . mb_substr((string)($j['error_description'] ?? $j['error'] ?? 'yanıt yok'), 0, 200);
            return null;
        }
        q("INSERT INTO settings (setting_key, setting_value) VALUES ('google_drive_token', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
            [json_encode(['token' => $j['access_token'], 'exp' => $now + (int)($j['expires_in'] ?? 3600)])]);
        return $j['access_token'];
    }

    $key = json_decode((string)file_get_contents(GOOGLE_KEY_PATH), true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
        $error = 'Anahtar dosyası geçersiz (client_email/private_key yok).';
        return null;
    }
    $now = time();
    $header = drive_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = drive_b64url(json_encode([
        'iss' => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now, 'exp' => $now + 3600,
    ]));
    $signature = '';
    if (!openssl_sign("$header.$claims", $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
        $error = 'JWT imzalanamadı (private_key hatalı olabilir).';
        return null;
    }
    $jwt = "$header.$claims." . drive_b64url($signature);

    $response = drive_http('https://oauth2.googleapis.com/token', http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
    ]));
    $j = json_decode((string)$response, true);
    if (empty($j['access_token'])) {
        $error = 'Token alınamadı: ' . mb_substr((string)($j['error_description'] ?? $response ?? 'bağlantı yok'), 0, 200);
        return null;
    }
    q("INSERT INTO settings (setting_key, setting_value) VALUES ('google_drive_token', ?) ON DUPLICATE KEY UPDATE setting_value=?",
        [json_encode(['token' => $j['access_token'], 'exp' => $now + (int)($j['expires_in'] ?? 3600)]),
         json_encode(['token' => $j['access_token'], 'exp' => $now + (int)($j['expires_in'] ?? 3600)])]);
    return $j['access_token'];
}

/** Small HTTP helper: POST when $body given, else GET. Returns the raw body or null. */
function drive_http(string $url, ?string $body = null, array $headers = []): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $out = curl_exec($ch);
        curl_close($ch);
        return $out === false ? null : $out;
    }
    $ctx = stream_context_create(['http' => [
        'method' => $body !== null ? 'POST' : 'GET',
        'header' => implode("\r\n", array_merge($headers, $body !== null ? ['Content-Type: application/x-www-form-urlencoded'] : [])),
        'content' => $body, 'timeout' => 20,
    ]]);
    $out = @file_get_contents($url, false, $ctx);
    return $out === false ? null : $out;
}

/**
 * Count the files created in a folder after a given moment.
 * Returns ['ok' => bool, 'count' => int, 'sample' => ?string, 'error' => ?string].
 */
function drive_files_after(string $folderId, string $afterIso, ?string $token = null): array {
    $token = $token ?? drive_token($error);
    if (!$token) return ['ok' => false, 'count' => 0, 'sample' => null, 'error' => $error ?? 'token yok'];
    $sq = sprintf("'%s' in parents and trashed=false and createdTime > '%s'", addslashes($folderId), $afterIso);
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => $sq, 'pageSize' => 10, 'fields' => 'files(id,name,createdTime)',
        'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
    ]);
    $j = json_decode((string)drive_http($url, null, ["Authorization: Bearer $token"]), true);
    if (!is_array($j) || isset($j['error'])) {
        return ['ok' => false, 'count' => 0, 'sample' => null, 'error' => mb_substr((string)($j['error']['message'] ?? 'API yanıtı alınamadı'), 0, 200)];
    }
    $files = $j['files'] ?? [];
    return ['ok' => true, 'count' => count($files), 'sample' => $files[0]['name'] ?? null, 'error' => null];
}

/**
 * Create a Drive folder. Returns ['id' => ..., 'link' => ...] or null ($error filled).
 * With OAuth the folder lands in the connected account's Drive; with a service
 * account the parent must be a folder shared to the robot with Editor rights.
 */
function drive_create_folder(string $name, ?string $parentId = null, ?string $token = null, ?string &$error = null): ?array {
    $token = $token ?? drive_token($error);
    if (!$token) return null;
    $body = ['name' => mb_substr($name, 0, 180), 'mimeType' => 'application/vnd.google-apps.folder'];
    if ($parentId) $body['parents'] = [$parentId];
    $j = json_decode((string)drive_http('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true',
        json_encode($body, JSON_UNESCAPED_UNICODE), ['Authorization: Bearer ' . $token, 'Content-Type: application/json']), true);
    if (empty($j['id'])) {
        $error = mb_substr((string)($j['error']['message'] ?? 'Klasör oluşturulamadı'), 0, 200);
        return null;
    }
    return ['id' => $j['id'], 'link' => 'https://drive.google.com/drive/folders/' . $j['id']];
}

/**
 * List a folder's files (newest first). Returns
 * ['ok' => bool, 'files' => [['name','link','mime','created'],...], 'error' => ?string].
 */
function drive_list_files(string $folderId, int $limit = 12): array {
    $token = drive_token($error);
    if (!$token) return ['ok' => false, 'files' => [], 'error' => $error];
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => sprintf("'%s' in parents and trashed=false", addslashes($folderId)),
        'pageSize' => $limit, 'orderBy' => 'createdTime desc',
        'fields' => 'files(id,name,mimeType,webViewLink,createdTime)',
        'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
    ]);
    $j = json_decode((string)drive_http($url, null, ["Authorization: Bearer $token"]), true);
    if (!is_array($j) || isset($j['error'])) {
        return ['ok' => false, 'files' => [], 'error' => mb_substr((string)($j['error']['message'] ?? 'API yanıtı alınamadı'), 0, 200)];
    }
    $files = [];
    foreach (($j['files'] ?? []) as $d) {
        $files[] = ['name' => $d['name'], 'link' => $d['webViewLink'] ?? '#',
            'mime' => $d['mimeType'] ?? '', 'created' => $d['createdTime'] ?? ''];
    }
    return ['ok' => true, 'files' => $files, 'error' => null];
}

/** The panel's root shoots folder — created once, its id kept in settings. */
function drive_ensure_root(?string $token = null): ?string {
    $root = setting('google_drive_root');
    if ($root !== '') return $root;
    $r = drive_create_folder(setting('site_adi', 'SADA One') . ' Çekimler', null, $token);
    if (!$r) return null;
    q("INSERT INTO settings (setting_key, setting_value) VALUES ('google_drive_root', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$r['id']]);
    return $r['id'];
}

/**
 * Create the upload folder for a shoot event and store it on the event.
 * Parent: the client's Drive folder if set, else the panel's root shoots folder.
 * Returns ['id','link'] or null (reason in $GLOBALS['drive_last_error']).
 */
function event_drive_folder(array $ev): ?array {
    $token = drive_token($error);
    if (!$token) { $GLOBALS['drive_last_error'] = $error; return null; }
    $parent = $ev['client_folder'] ?: drive_ensure_root($token);
    $name = date('Y-m-d', strtotime($ev['start']))
        . ($ev['client_name'] ? ' ' . $ev['client_name'] : '')
        . ' — ' . $ev['title'];
    $r = drive_create_folder($name, $parent, $token, $error);
    if (!$r) { $GLOBALS['drive_last_error'] = $error; return null; }
    update_row('events', ['drive_folder_id' => $r['id'], 'drive_link' => $r['link']], 'id=?', [(int)$ev['id']]);
    return $r;
}

/** Connection test: token + service-account e-mail (for the settings page). */
function drive_test(): array {
    $token = drive_token($error);
    if (!$token) return ['ok' => false, 'error' => $error];
    if (drive_oauth_configured()) return ['ok' => true, 'service_email' => setting('google_drive_email') ?: 'bağlı hesap'];
    $key = json_decode((string)file_get_contents(GOOGLE_KEY_PATH), true);
    return ['ok' => true, 'service_email' => $key['client_email'] ?? '?'];
}

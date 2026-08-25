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

/** Is the Drive integration configured? */
function drive_configured(): bool {
    return is_file(GOOGLE_KEY_PATH) && function_exists('openssl_sign');
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
    if (!drive_configured()) { $error = 'Servis hesabı anahtarı yüklü değil.'; return null; }
    $cached = json_decode((string)setting('google_drive_token'), true);
    if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 300) return $cached['token'];

    $key = json_decode((string)file_get_contents(GOOGLE_KEY_PATH), true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
        $error = 'Anahtar dosyası geçersiz (client_email/private_key yok).';
        return null;
    }
    $now = time();
    $header = drive_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = drive_b64url(json_encode([
        'iss' => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive.readonly',
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

/** Connection test: token + service-account e-mail (for the settings page). */
function drive_test(): array {
    $token = drive_token($error);
    if (!$token) return ['ok' => false, 'error' => $error];
    $key = json_decode((string)file_get_contents(GOOGLE_KEY_PATH), true);
    return ['ok' => true, 'service_email' => $key['client_email'] ?? '?'];
}

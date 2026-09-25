<?php
/**
 * SADA One — Updater core (shared by updater.php + ajax.php)
 */

const GITHUB_REPO = 'rasidinnbugda/sada-one';

/** Fetches JSON from the GitHub API (file_get_contents first, cURL as fallback) */
function github_json(string $url): ?array {
    $ua = 'SADA-One-Updater';
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\nAccept: application/vnd.github+json\r\n", 'timeout' => 15]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => $ua, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) ? $j : null;
}

/** Downloads a remote file, returns the saved path (null on failure) */
function download_url(string $url, string $target): bool {
    $ua = 'SADA-One-Updater';
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 120, 'follow_location' => 1]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => $ua, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => true]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    if ($raw === false || strlen($raw) < 1000) return false;
    return file_put_contents($target, $raw) !== false;
}

/** Creates a code backup of the current installation under backups/ */
function create_backup(): ?string {
    $root = ROOT;
    $backupDirectory = $root . '/backups';
    if (!is_dir($backupDirectory)) { mkdir($backupDirectory, 0755, true); }
    if (!file_exists($backupDirectory . '/.htaccess')) file_put_contents($backupDirectory . '/.htaccess', "Require all denied\n");
    if (!file_exists($backupDirectory . '/index.html')) file_put_contents($backupDirectory . '/index.html', '');
    $path = $backupDirectory . '/backup-v' . APP_VERSION . '-' . date('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) return null;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $client) {
        if ($client->isDir()) continue;
        $relative = str_replace('\\', '/', substr($client->getPathname(), strlen($root) + 1));
        // Excluded from the backup: backups, user uploads (large), git
        if (str_starts_with($relative, 'backups/') || str_starts_with($relative, 'uploads/') || str_starts_with($relative, '.git/')) continue;
        $zip->addFile($client->getPathname(), $relative);
    }
    $zip->close();
    return $path;
}

/** Applies a ZIP package over the current installation; returns [ok, message, details] */
function install_package(string $zipPath): array {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return [false, 'ZIP açılamadı — dosya bozuk olabilir.', []];

    // Validation: is it a genuine SADA One package? (also support a root prefix)
    $prefix = '';
    if ($zip->locateName('includes/init.php') === false) {
        $first = $zip->getNameIndex(0);
        $candidate = strstr($first, '/', true);
        if ($candidate !== false && $zip->locateName($candidate . '/includes/init.php') !== false) $prefix = $candidate . '/';
        else { $zip->close(); return [false, 'Bu ZIP bir SADA One paketi değil (includes/init.php bulunamadı).', []]; }
    }

    $backup = create_backup();
    if (!$backup) { $zip->close(); return [false, 'Yedek alınamadı — backups/ klasörü yazılabilir değil.', []]; }

    $written = 0; $skipped = 0; $errors = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($prefix && !str_starts_with($name, $prefix)) { $skipped++; continue; }
        $relative = $prefix ? substr($name, strlen($prefix)) : $name;
        if ($relative === '' || str_ends_with($relative, '/')) continue;
        // Security: prevent path traversal (zip slip)
        if (str_contains($relative, '..')) { $skipped++; continue; }
        // Protected: configuration, user uploads, backups
        if ($relative === 'config.php' || str_starts_with($relative, 'storage/') || str_starts_with($relative, 'uploads/') || str_starts_with($relative, 'backups/') || str_starts_with($relative, 'storage/') || str_starts_with($relative, '.git/')) { $skipped++; continue; }
        $target = ROOT . '/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory)) mkdir($directory, 0755, true);
        $content = $zip->getFromIndex($i);
        if ($content === false || file_put_contents($target, $content) === false) { $errors[] = $relative; continue; }
        $written++;
    }
    $zip->close();

    // Update the database schema
    $migResult = run_migrations(db());
    $migError = array_filter($migResult, fn($s) => $s[0] === 'error');

    $detail = [
        'written' => $written, 'skipped' => $skipped, 'client_error' => $errors,
        'mig_ok' => count(array_filter($migResult, fn($s) => $s[0] === 'ok')),
        'mig_skip' => count(array_filter($migResult, fn($s) => $s[0] === 'skip')),
        'mig_error' => array_map(fn($s) => $s[1], $migError),
        'backup' => basename($backup),
    ];
    if ($errors || $migError) return [false, 'Güncelleme kısmen uygulandı — aşağıdaki hataları inceleyin.', $detail];
    return [true, 'Güncelleme başarıyla tamamlandı.', $detail];
}

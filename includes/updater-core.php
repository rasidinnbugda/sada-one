<?php
/**
 * SADA One — Güncelleyici çekirdeği (guncelleme.php + ajax.php ortak kullanır)
 */

const GITHUB_DEPO = 'rasidinnbugda/sada-one';

/** GitHub API'den JSON çeker (önce file_get_contents, olmazsa cURL) */
function github_json(string $url): ?array {
    $ua = 'SADA-One-Guncelleyici';
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\nAccept: application/vnd.github+json\r\n", 'timeout' => 15]]);
    $ham = @file_get_contents($url, false, $ctx);
    if ($ham === false && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => $ua, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
        $ham = curl_exec($ch);
        curl_close($ch);
    }
    $j = $ham ? json_decode($ham, true) : null;
    return is_array($j) ? $j : null;
}

/** Uzak dosyayı indirir, kaydedilen yolu döner (başarısızsa null) */
function download_url(string $url, string $target): bool {
    $ua = 'SADA-One-Guncelleyici';
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 120, 'follow_location' => 1]]);
    $ham = @file_get_contents($url, false, $ctx);
    if ($ham === false && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => $ua, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => true]);
        $ham = curl_exec($ch);
        curl_close($ch);
    }
    if ($ham === false || strlen($ham) < 1000) return false;
    return file_put_contents($target, $ham) !== false;
}

/** Mevcut kurulumun kod yedeğini backups/ altına alır */
function create_backup(): ?string {
    $kok = ROOT;
    $backupDirectory = $kok . '/backups';
    if (!is_dir($backupDirectory)) { mkdir($backupDirectory, 0755, true); }
    if (!file_exists($backupDirectory . '/.htaccess')) file_put_contents($backupDirectory . '/.htaccess', "Require all denied\n");
    if (!file_exists($backupDirectory . '/index.html')) file_put_contents($backupDirectory . '/index.html', '');
    $path = $backupDirectory . '/backup-v' . SURUM . '-' . date('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) return null;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $client) {
        if ($client->isDir()) continue;
        $goreli = str_replace('\\', '/', substr($client->getPathname(), strlen($kok) + 1));
        // Yedeğe girmeyecekler: yedekler, kullanıcı yüklemeleri (büyük), git
        if (str_starts_with($goreli, 'backups/') || str_starts_with($goreli, 'uploads/') || str_starts_with($goreli, '.git/')) continue;
        $zip->addFile($client->getPathname(), $goreli);
    }
    $zip->close();
    return $path;
}

/** ZIP paketini mevcut kurulumun üzerine uygular; [ok, mesaj, detaylar] döner */
function install_package(string $zipPath): array {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return [false, 'ZIP açılamadı — dosya bozuk olabilir.', []];

    // Doğrulama: gerçek bir SADA One paketi mi? (kök önekini de destekle)
    $onek = '';
    if ($zip->locateName('includes/init.php') === false) {
        $first = $zip->getNameIndex(0);
        $aday = strstr($first, '/', true);
        if ($aday !== false && $zip->locateName($aday . '/includes/init.php') !== false) $onek = $aday . '/';
        else { $zip->close(); return [false, 'Bu ZIP bir SADA One paketi değil (includes/init.php bulunamadı).', []]; }
    }

    $backup = create_backup();
    if (!$backup) { $zip->close(); return [false, 'Yedek alınamadı — backups/ klasörü yazılabilir değil.', []]; }

    $yazilan = 0; $atlanan = 0; $errors = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($onek && !str_starts_with($name, $onek)) { $atlanan++; continue; }
        $goreli = $onek ? substr($name, strlen($onek)) : $name;
        if ($goreli === '' || str_ends_with($goreli, '/')) continue;
        // Güvenlik: dizin dışına çıkma (zip slip) engeli
        if (str_contains($goreli, '..')) { $atlanan++; continue; }
        // Korunanlar: yapılandırma, kullanıcı yüklemeleri, yedekler
        if ($goreli === 'config.php' || str_starts_with($goreli, 'uploads/') || str_starts_with($goreli, 'backups/') || str_starts_with($goreli, '.git/')) { $atlanan++; continue; }
        $target = ROOT . '/' . $goreli;
        $directory = dirname($target);
        if (!is_dir($directory)) mkdir($directory, 0755, true);
        $content = $zip->getFromIndex($i);
        if ($content === false || file_put_contents($target, $content) === false) { $errors[] = $goreli; continue; }
        $yazilan++;
    }
    $zip->close();

    // Veritabanı şemasını güncelle
    $migResult = run_migrations(db());
    $migError = array_filter($migResult, fn($s) => $s[0] === 'hata');

    $detay = [
        'yazilan' => $yazilan, 'atlanan' => $atlanan, 'client_error' => $errors,
        'mig_ok' => count(array_filter($migResult, fn($s) => $s[0] === 'ok')),
        'mig_skip' => count(array_filter($migResult, fn($s) => $s[0] === 'skip')),
        'mig_error' => array_map(fn($s) => $s[1], $migError),
        'backup' => basename($backup),
    ];
    if ($errors || $migError) return [false, 'Güncelleme kısmen uygulandı — aşağıdaki hataları inceleyin.', $detay];
    return [true, 'Güncelleme başarıyla tamamlandı.', $detay];
}

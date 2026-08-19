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
function dosya_indir(string $url, string $hedef): bool {
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
    return file_put_contents($hedef, $ham) !== false;
}

/** Mevcut kurulumun kod yedeğini backups/ altına alır */
function yedek_al(): ?string {
    $kok = ROOT;
    $yedekDizin = $kok . '/backups';
    if (!is_dir($yedekDizin)) { mkdir($yedekDizin, 0755, true); }
    if (!file_exists($yedekDizin . '/.htaccess')) file_put_contents($yedekDizin . '/.htaccess', "Require all denied\n");
    if (!file_exists($yedekDizin . '/index.html')) file_put_contents($yedekDizin . '/index.html', '');
    $yol = $yedekDizin . '/yedek-v' . SURUM . '-' . date('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($yol, ZipArchive::CREATE) !== true) return null;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $dosya) {
        if ($dosya->isDir()) continue;
        $goreli = str_replace('\\', '/', substr($dosya->getPathname(), strlen($kok) + 1));
        // Yedeğe girmeyecekler: yedekler, kullanıcı yüklemeleri (büyük), git
        if (str_starts_with($goreli, 'backups/') || str_starts_with($goreli, 'uploads/') || str_starts_with($goreli, '.git/')) continue;
        $zip->addFile($dosya->getPathname(), $goreli);
    }
    $zip->close();
    return $yol;
}

/** ZIP paketini mevcut kurulumun üzerine uygular; [ok, mesaj, detaylar] döner */
function paket_kur(string $zipYolu): array {
    $zip = new ZipArchive();
    if ($zip->open($zipYolu) !== true) return [false, 'ZIP açılamadı — dosya bozuk olabilir.', []];

    // Doğrulama: gerçek bir SADA One paketi mi? (kök önekini de destekle)
    $onek = '';
    if ($zip->locateName('includes/init.php') === false) {
        $ilk = $zip->getNameIndex(0);
        $aday = strstr($ilk, '/', true);
        if ($aday !== false && $zip->locateName($aday . '/includes/init.php') !== false) $onek = $aday . '/';
        else { $zip->close(); return [false, 'Bu ZIP bir SADA One paketi değil (includes/init.php bulunamadı).', []]; }
    }

    $yedek = yedek_al();
    if (!$yedek) { $zip->close(); return [false, 'Yedek alınamadı — backups/ klasörü yazılabilir değil.', []]; }

    $yazilan = 0; $atlanan = 0; $hatalar = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $ad = $zip->getNameIndex($i);
        if ($onek && !str_starts_with($ad, $onek)) { $atlanan++; continue; }
        $goreli = $onek ? substr($ad, strlen($onek)) : $ad;
        if ($goreli === '' || str_ends_with($goreli, '/')) continue;
        // Güvenlik: dizin dışına çıkma (zip slip) engeli
        if (str_contains($goreli, '..')) { $atlanan++; continue; }
        // Korunanlar: yapılandırma, kullanıcı yüklemeleri, yedekler
        if ($goreli === 'config.php' || str_starts_with($goreli, 'uploads/') || str_starts_with($goreli, 'backups/') || str_starts_with($goreli, '.git/')) { $atlanan++; continue; }
        $hedef = ROOT . '/' . $goreli;
        $dizin = dirname($hedef);
        if (!is_dir($dizin)) mkdir($dizin, 0755, true);
        $icerik = $zip->getFromIndex($i);
        if ($icerik === false || file_put_contents($hedef, $icerik) === false) { $hatalar[] = $goreli; continue; }
        $yazilan++;
    }
    $zip->close();

    // Veritabanı şemasını güncelle
    $migSonuc = migrasyon_calistir(db());
    $migHata = array_filter($migSonuc, fn($s) => $s[0] === 'hata');

    $detay = [
        'yazilan' => $yazilan, 'atlanan' => $atlanan, 'dosya_hata' => $hatalar,
        'mig_ok' => count(array_filter($migSonuc, fn($s) => $s[0] === 'ok')),
        'mig_atla' => count(array_filter($migSonuc, fn($s) => $s[0] === 'atla')),
        'mig_hata' => array_map(fn($s) => $s[1], $migHata),
        'yedek' => basename($yedek),
    ];
    if ($hatalar || $migHata) return [false, 'Güncelleme kısmen uygulandı — aşağıdaki hataları inceleyin.', $detay];
    return [true, 'Güncelleme başarıyla tamamlandı.', $detay];
}

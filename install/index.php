<?php
/**
 * SADA Dijital — Kurulum Sihirbazı
 * Sistem gereksinimlerini kontrol eder, veritabanını kurar,
 * yönetici hesabını oluşturur ve config.php dosyasını yazar.
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');

$configPath = dirname(__DIR__) . '/config.php';

// Zaten kuruluysa engelle
if (file_exists($configPath)) {
    $cfg = include $configPath;
    if (!empty($cfg['installed'])) {
        die('<div style="font-family:sans-serif;padding:40px;text-align:center">Sistem zaten kurulu. Güvenlik için <b>install</b> klasörünü sunucudan silin.<br><br><a href="../login.php">Giriş sayfasına git</a></div>');
    }
}

$adim = isset($_GET['adim']) ? (int)$_GET['adim'] : 1;
$hata = '';

/* ---------- Adım 1: Gereksinim kontrolü ---------- */
$gereksinimler = [
    'PHP 7.4 veya üzeri' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO MySQL eklentisi' => extension_loaded('pdo_mysql'),
    'mbstring eklentisi' => extension_loaded('mbstring'),
    'JSON eklentisi' => function_exists('json_encode'),
    'GD veya dosya yükleme desteği' => true,
    'Ana dizin yazılabilir (config.php için)' => is_writable(dirname(__DIR__)),
    'uploads/ klasörü yazılabilir' => is_writable(dirname(__DIR__) . '/uploads') || @mkdir(dirname(__DIR__) . '/uploads', 0755, true),
];
$gereksinimTamam = !in_array(false, $gereksinimler, true);

/* ---------- Adım 2: Veritabanı bağlantısı ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adim === 2) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $_SESSION['kurulum_db'] = ['host' => $dbHost, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass];
        header('Location: ?adim=3');
        exit;
    } catch (PDOException $e) {
        $hata = 'Veritabanına bağlanılamadı: ' . $e->getMessage();
    }
}

/* ---------- Adım 3: Site + yönetici → kurulumu çalıştır ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adim === 3) {
    if (empty($_SESSION['kurulum_db'])) { header('Location: ?adim=2'); exit; }
    $siteAdi   = trim($_POST['site_adi'] ?? 'SADA Dijital');
    $adminAd   = trim($_POST['admin_ad'] ?? '');
    $adminMail = trim($_POST['admin_eposta'] ?? '');
    $adminSifre = $_POST['admin_sifre'] ?? '';
    $adminSifre2 = $_POST['admin_sifre2'] ?? '';

    if ($adminAd === '' || !filter_var($adminMail, FILTER_VALIDATE_EMAIL)) {
        $hata = 'Ad ve geçerli bir e-posta adresi girin.';
    } elseif (strlen($adminSifre) < 6) {
        $hata = 'Şifre en az 6 karakter olmalı.';
    } elseif ($adminSifre !== $adminSifre2) {
        $hata = 'Şifreler eşleşmiyor.';
    } else {
        $db = $_SESSION['kurulum_db'];
        try {
            $pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            kurulum_calistir($pdo, $siteAdi, $adminAd, $adminMail, $adminSifre);

            $configIcerik = "<?php\nreturn [\n"
                . "    'db_host' => " . var_export($db['host'], true) . ",\n"
                . "    'db_name' => " . var_export($db['name'], true) . ",\n"
                . "    'db_user' => " . var_export($db['user'], true) . ",\n"
                . "    'db_pass' => " . var_export($db['pass'], true) . ",\n"
                . "    'installed' => true,\n"
                . "];\n";
            if (file_put_contents($configPath, $configIcerik) === false) {
                $hata = 'config.php yazılamadı. Ana dizin izinlerini kontrol edin.';
            } else {
                unset($_SESSION['kurulum_db']);
                header('Location: ?adim=4');
                exit;
            }
        } catch (PDOException $e) {
            $hata = 'Kurulum hatası: ' . $e->getMessage();
        }
    }
}

/* ---------- Şema + başlangıç verileri ---------- */
function kurulum_calistir(PDO $pdo, $siteAdi, $adminAd, $adminMail, $adminSifre) {
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    anahtar VARCHAR(64) PRIMARY KEY,
    deger TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(100) NOT NULL,
    eposta VARCHAR(150) NOT NULL UNIQUE,
    sifre VARCHAR(255) NOT NULL,
    rol ENUM('yonetici','pm','ekip','finans','stajyer','musteri') NOT NULL DEFAULT 'ekip',
    unvan VARCHAR(100) DEFAULT NULL,
    dosya_id INT DEFAULT NULL,
    tema VARCHAR(20) NOT NULL DEFAULT 'lime',
    renk VARCHAR(7) NOT NULL DEFAULT '#182f5d',
    avatar VARCHAR(255) DEFAULT NULL,
    gorev_gorunum VARCHAR(10) NOT NULL DEFAULT 'kanban',
    maas DECIMAL(12,2) NOT NULL DEFAULT 0,
    haftalik_kapasite SMALLINT NOT NULL DEFAULT 45,
    izinler TEXT,
    bildirim_tercihleri TEXT,
    widgetler TEXT,
    karalama MEDIUMTEXT,
    gorulen_surum VARCHAR(10) DEFAULT NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    son_giris DATETIME DEFAULT NULL,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS dosyalar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(150) NOT NULL,
    tur ENUM('marka','sirket','stk') NOT NULL DEFAULT 'marka',
    renk VARCHAR(7) NOT NULL DEFAULT '#182f5d',
    logo VARCHAR(255) DEFAULT NULL,
    aciklama TEXT,
    iletisim_ad VARCHAR(100) DEFAULT NULL,
    iletisim_eposta VARCHAR(150) DEFAULT NULL,
    iletisim_tel VARCHAR(30) DEFAULT NULL,
    durum ENUM('aktif','pasif') NOT NULL DEFAULT 'aktif',
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS projeler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dosya_id INT NOT NULL,
    ad VARCHAR(200) NOT NULL,
    tur ENUM('aylik','donemsel','tek') NOT NULL DEFAULT 'aylik',
    aciklama TEXT,
    durum ENUM('aktif','beklemede','tamamlandi','iptal') NOT NULL DEFAULT 'aktif',
    baslangic DATE DEFAULT NULL,
    bitis DATE DEFAULT NULL,
    pm_id INT DEFAULT NULL,
    sozlesme_tutari DECIMAL(12,2) NOT NULL DEFAULT 0,
    created DATETIME NOT NULL,
    INDEX(dosya_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS donemler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proje_id INT NOT NULL,
    yil SMALLINT NOT NULL,
    ay TINYINT NOT NULL,
    durum ENUM('acik','kapali') NOT NULL DEFAULT 'acik',
    created DATETIME NOT NULL,
    UNIQUE KEY uniq_donem (proje_id, yil, ay)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS akis_sablonlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(120) NOT NULL,
    aciklama VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS sablon_adimlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sablon_id INT NOT NULL,
    sira TINYINT NOT NULL DEFAULT 1,
    ad VARCHAR(120) NOT NULL,
    INDEX(sablon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS gorevler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proje_id INT NOT NULL,
    donem_id INT DEFAULT NULL,
    baslik VARCHAR(200) NOT NULL,
    aciklama TEXT,
    atanan_id INT DEFAULT NULL,
    olusturan_id INT NOT NULL,
    oncelik ENUM('dusuk','normal','yuksek','acil') NOT NULL DEFAULT 'normal',
    durum ENUM('yapilacak','devam','incelemede','onayda','tamamlandi') NOT NULL DEFAULT 'yapilacak',
    son_tarih DATE DEFAULT NULL,
    tamamlanma DATETIME DEFAULT NULL,
    sira INT NOT NULL DEFAULT 0,
    bagimli_id INT DEFAULT NULL,
    kilit_acik TINYINT(1) NOT NULL DEFAULT 0,
    tekrar ENUM('yok','haftalik','aylik') NOT NULL DEFAULT 'yok',
    son_tekrar VARCHAR(10) DEFAULT NULL,
    etiketler VARCHAR(255) DEFAULT NULL,
    tahmini_dakika INT NOT NULL DEFAULT 0,
    baslangic_tarihi DATE DEFAULT NULL,
    arsivlendi TINYINT(1) NOT NULL DEFAULT 0,
    icerik_id INT DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(proje_id), INDEX(atanan_id), INDEX(donem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS proje_uyeleri (
    proje_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (proje_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS dosya_uyeleri (
    dosya_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (dosya_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS gorev_kontrol (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gorev_id INT NOT NULL,
    ad VARCHAR(200) NOT NULL,
    tamam TINYINT(1) NOT NULL DEFAULT 0,
    sira TINYINT NOT NULL DEFAULT 1,
    INDEX(gorev_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS gorev_adimlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gorev_id INT NOT NULL,
    sira TINYINT NOT NULL DEFAULT 1,
    ad VARCHAR(120) NOT NULL,
    sorumlu_id INT DEFAULT NULL,
    durum ENUM('bekliyor','aktif','tamam') NOT NULL DEFAULT 'bekliyor',
    tamam_tarih DATETIME DEFAULT NULL,
    INDEX(gorev_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS yorumlar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_tur VARCHAR(20) NOT NULL,
    ref_id INT NOT NULL,
    user_id INT NOT NULL,
    mesaj TEXT NOT NULL,
    parent_id INT DEFAULT NULL,
    arsiv_id INT DEFAULT NULL,
    duzenlendi TINYINT(1) NOT NULL DEFAULT 0,
    created DATETIME NOT NULL,
    INDEX(ref_tur, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS yorum_tepkiler (
    yorum_id INT NOT NULL,
    user_id INT NOT NULL,
    emoji VARCHAR(8) NOT NULL,
    PRIMARY KEY (yorum_id, user_id, emoji)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS gorev_izleyiciler (
    gorev_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (gorev_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS gorev_atananlar (
    gorev_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (gorev_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS giderler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tur ENUM('maas','kira','abonelik','ekipman','vergi','diger') NOT NULL DEFAULT 'diger',
    baslik VARCHAR(200) NOT NULL,
    tutar DECIMAL(12,2) NOT NULL DEFAULT 0,
    tarih DATE NOT NULL,
    durum ENUM('bekliyor','odendi') NOT NULL DEFAULT 'bekliyor',
    tekrar ENUM('yok','aylik') NOT NULL DEFAULT 'yok',
    son_tekrar VARCHAR(10) DEFAULT NULL,
    user_id INT DEFAULT NULL,
    aciklama VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(tarih)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS duyurular (
    id INT AUTO_INCREMENT PRIMARY KEY,
    baslik VARCHAR(200) NOT NULL,
    metin TEXT,
    onemli TINYINT(1) NOT NULL DEFAULT 0,
    olusturan_id INT NOT NULL,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS duyuru_okuyanlar (
    duyuru_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (duyuru_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS giris_denemeleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eposta VARCHAR(150) NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    basarili TINYINT(1) NOT NULL DEFAULT 0,
    created DATETIME NOT NULL,
    INDEX(eposta, created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS ekipmanlar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kod VARCHAR(20) DEFAULT NULL,
    ad VARCHAR(150) NOT NULL,
    kategori ENUM('kamera','lens','sd_kart','tripod','isik','ses','drone','aksesuar','diger') NOT NULL DEFAULT 'diger',
    foto VARCHAR(255) DEFAULT NULL,
    durum ENUM('studyoda','zimmette','cekimde','arizali','bakimda') NOT NULL DEFAULT 'studyoda',
    zimmet_user_id INT DEFAULT NULL,
    zimmet_etkinlik_id INT DEFAULT NULL,
    ariza_notu VARCHAR(255) DEFAULT NULL,
    satin_alma DATE DEFAULT NULL,
    fiyat DECIMAL(12,2) NOT NULL DEFAULT 0,
    sd_durum ENUM('bos','dolu','aktarildi') DEFAULT NULL,
    sd_icerik VARCHAR(255) DEFAULT NULL,
    sd_drive_link VARCHAR(255) DEFAULT NULL,
    aciklama VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(kategori), INDEX(durum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS ekipman_hareketleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ekipman_id INT NOT NULL,
    user_id INT NOT NULL,
    hedef_user_id INT DEFAULT NULL,
    etkinlik_id INT DEFAULT NULL,
    tur VARCHAR(20) NOT NULL,
    aciklama VARCHAR(500) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(ekipman_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS etkinlik_ekipmanlari (
    etkinlik_id INT NOT NULL,
    ekipman_id INT NOT NULL,
    PRIMARY KEY (etkinlik_id, ekipman_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS sozlesmeler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dosya_id INT NOT NULL,
    baslik VARCHAR(200) NOT NULL,
    baslangic DATE DEFAULT NULL,
    bitis DATE DEFAULT NULL,
    tutar DECIMAL(12,2) NOT NULL DEFAULT 0,
    arsiv_id INT DEFAULT NULL,
    aciklama VARCHAR(255) DEFAULT NULL,
    hatirlatildi TINYINT(1) NOT NULL DEFAULT 0,
    created DATETIME NOT NULL,
    INDEX(dosya_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS zaman_kayitlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gorev_id INT NOT NULL,
    user_id INT NOT NULL,
    dakika INT NOT NULL DEFAULT 0,
    tarih DATE NOT NULL,
    aciklama VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(gorev_id), INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS arsiv (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dosya_id INT DEFAULT NULL,
    proje_id INT DEFAULT NULL,
    gorev_id INT DEFAULT NULL,
    ad VARCHAR(200) NOT NULL,
    dosya_yolu VARCHAR(255) NOT NULL,
    boyut INT NOT NULL DEFAULT 0,
    uzanti VARCHAR(10) DEFAULT NULL,
    url VARCHAR(500) DEFAULT NULL,
    yukleyen_id INT NOT NULL,
    created DATETIME NOT NULL,
    INDEX(dosya_id), INDEX(proje_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS icerikler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dosya_id INT DEFAULT NULL,
    proje_id INT DEFAULT NULL,
    baslik VARCHAR(200) NOT NULL,
    aciklama TEXT,
    platform VARCHAR(120) NOT NULL DEFAULT 'instagram',
    tarih DATE NOT NULL,
    saat TIME DEFAULT NULL,
    durum ENUM('taslak','ic_onay','musteri_onay','revize','onaylandi','yayinlandi') NOT NULL DEFAULT 'taslak',
    olusturan_id INT NOT NULL,
    created DATETIME NOT NULL,
    INDEX(dosya_id), INDEX(proje_id), INDEX(tarih)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS proje_sablonlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(150) NOT NULL,
    aciklama VARCHAR(255) DEFAULT NULL,
    gorevler TEXT,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS dosya_notlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dosya_id INT NOT NULL,
    baslik VARCHAR(150) NOT NULL,
    metin TEXT,
    sira INT NOT NULL DEFAULT 0,
    guncelleyen_id INT DEFAULT NULL,
    guncelleme DATETIME DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(dosya_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS belgeler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tur ENUM('teklif','fatura') NOT NULL DEFAULT 'teklif',
    no VARCHAR(20) NOT NULL,
    dosya_id INT DEFAULT NULL,
    baslik VARCHAR(200) NOT NULL,
    kalemler TEXT,
    kdv_oran TINYINT NOT NULL DEFAULT 20,
    durum ENUM('taslak','gonderildi','onaylandi','reddedildi') NOT NULL DEFAULT 'taslak',
    gecerlilik DATE DEFAULT NULL,
    notlar VARCHAR(500) DEFAULT NULL,
    olusturan_id INT NOT NULL,
    created DATETIME NOT NULL,
    INDEX(dosya_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS sosyal_hesaplar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dosya_id INT NOT NULL,
    platform VARCHAR(20) NOT NULL DEFAULT 'instagram',
    kullanici_adi VARCHAR(100) NOT NULL,
    url VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(dosya_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS sosyal_metrikler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hesap_id INT NOT NULL,
    tarih DATE NOT NULL,
    takipci INT NOT NULL DEFAULT 0,
    gonderi INT DEFAULT NULL,
    etkilesim INT DEFAULT NULL,
    girilen_id INT DEFAULT NULL,
    created DATETIME NOT NULL,
    UNIQUE KEY uniq_metrik (hesap_id, tarih)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS etkinlikler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dosya_id INT DEFAULT NULL,
    proje_id INT DEFAULT NULL,
    baslik VARCHAR(200) NOT NULL,
    tur ENUM('cekim','toplanti','teslim','diger') NOT NULL DEFAULT 'cekim',
    baslangic DATETIME NOT NULL,
    bitis DATETIME DEFAULT NULL,
    yer VARCHAR(200) DEFAULT NULL,
    aciklama TEXT,
    katilimcilar VARCHAR(255) DEFAULT NULL,
    online_link VARCHAR(255) DEFAULT NULL,
    hatirlatildi TINYINT(1) NOT NULL DEFAULT 0,
    olusturan_id INT NOT NULL,
    created DATETIME NOT NULL,
    INDEX(baslangic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS etkinlik_katilimcilari (
    etkinlik_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (etkinlik_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS kisisel_notlar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    baslik VARCHAR(150) DEFAULT NULL,
    metin TEXT,
    renk VARCHAR(20) NOT NULL DEFAULT 'varsayilan',
    created DATETIME NOT NULL,
    guncelleme DATETIME DEFAULT NULL,
    INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS kisisel_isler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ad VARCHAR(255) NOT NULL,
    tamam TINYINT(1) NOT NULL DEFAULT 0,
    sira INT NOT NULL DEFAULT 0,
    INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS musteri_dosyalari (
    user_id INT NOT NULL,
    dosya_id INT NOT NULL,
    PRIMARY KEY (user_id, dosya_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS puanlar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_tur ENUM('gorev','onay') NOT NULL,
    ref_id INT NOT NULL,
    proje_id INT NOT NULL,
    user_id INT NOT NULL,
    puan TINYINT NOT NULL,
    yorum VARCHAR(500) DEFAULT NULL,
    created DATETIME NOT NULL,
    UNIQUE KEY uniq_puan (ref_tur, ref_id, user_id),
    INDEX(proje_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS randevular (
    id INT AUTO_INCREMENT PRIMARY KEY,
    musteri_id INT NOT NULL,
    dosya_id INT DEFAULT NULL,
    konu VARCHAR(200) NOT NULL,
    tarih DATETIME NOT NULL,
    online_istek TINYINT(1) NOT NULL DEFAULT 0,
    notlar VARCHAR(500) DEFAULT NULL,
    durum ENUM('bekliyor','onaylandi','alternatif','reddedildi') NOT NULL DEFAULT 'bekliyor',
    alternatif_tarih DATETIME DEFAULT NULL,
    online_link VARCHAR(255) DEFAULT NULL,
    etkinlik_id INT DEFAULT NULL,
    cevap_notu VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(musteri_id), INDEX(durum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS kisisel_linkler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ad VARCHAR(150) NOT NULL,
    url VARCHAR(500) NOT NULL,
    INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS onaylar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proje_id INT NOT NULL,
    baslik VARCHAR(200) NOT NULL,
    aciklama TEXT,
    arsiv_id INT DEFAULT NULL,
    drive_link VARCHAR(500) DEFAULT NULL,
    icerik_id INT DEFAULT NULL,
    gorev_id INT DEFAULT NULL,
    durum ENUM('bekliyor','onaylandi','revize','reddedildi') NOT NULL DEFAULT 'bekliyor',
    gonderen_id INT NOT NULL,
    cevap_notu TEXT,
    cevap_tarih DATETIME DEFAULT NULL,
    cevaplayan_id INT DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(proje_id), INDEX(durum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS kanallar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(120) NOT NULL,
    tur ENUM('genel','proje','ozel','musteri') NOT NULL DEFAULT 'genel',
    proje_id INT DEFAULT NULL,
    simge VARCHAR(8) DEFAULT NULL,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS kanal_uyeleri (
    kanal_id INT NOT NULL,
    user_id INT NOT NULL,
    son_okuma DATETIME DEFAULT NULL,
    arsiv TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (kanal_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS mesajlar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kanal_id INT NOT NULL,
    user_id INT NOT NULL,
    mesaj TEXT NOT NULL,
    created DATETIME NOT NULL,
    INDEX(kanal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS form_sablonlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(150) NOT NULL,
    aciklama VARCHAR(255) DEFAULT NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS form_alanlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sablon_id INT NOT NULL,
    sira TINYINT NOT NULL DEFAULT 1,
    etiket VARCHAR(150) NOT NULL,
    tip ENUM('metin','uzun_metin','secim','tarih','sayi','dosya') NOT NULL DEFAULT 'metin',
    secenekler TEXT,
    zorunlu TINYINT(1) NOT NULL DEFAULT 1,
    INDEX(sablon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS talepler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sablon_id INT NOT NULL,
    dosya_id INT DEFAULT NULL,
    proje_id INT DEFAULT NULL,
    gonderen_id INT NOT NULL,
    baslik VARCHAR(200) NOT NULL,
    durum ENUM('yeni','inceleniyor','gorev_olusturuldu','tamamlandi','reddedildi') NOT NULL DEFAULT 'yeni',
    atanan_id INT DEFAULT NULL,
    gorev_id INT DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(durum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS talep_cevaplari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    talep_id INT NOT NULL,
    alan_id INT NOT NULL,
    deger TEXT,
    INDEX(talep_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS odemeler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proje_id INT NOT NULL,
    tur ENUM('fatura','tahsilat') NOT NULL DEFAULT 'fatura',
    baslik VARCHAR(200) NOT NULL,
    tutar DECIMAL(12,2) NOT NULL DEFAULT 0,
    tarih DATE NOT NULL,
    durum ENUM('bekliyor','odendi','gecikti') NOT NULL DEFAULT 'bekliyor',
    aciklama VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(proje_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS aktiviteler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ref_tur VARCHAR(20) DEFAULT NULL,
    ref_id INT DEFAULT NULL,
    aciklama VARCHAR(255) NOT NULL,
    created DATETIME NOT NULL,
    INDEX(ref_tur, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS bildirimler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    baslik VARCHAR(150) NOT NULL,
    mesaj VARCHAR(255) DEFAULT NULL,
    link VARCHAR(255) DEFAULT NULL,
    okundu TINYINT(1) NOT NULL DEFAULT 0,
    created DATETIME NOT NULL,
    INDEX(user_id, okundu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
SQL;

    // Tabloları tek tek çalıştır
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') $pdo->exec($stmt);
    }

    $now = date('Y-m-d H:i:s');

    // Yönetici hesabı
    $st = $pdo->prepare("INSERT INTO users (ad, eposta, sifre, rol, unvan, tema, renk, aktif, created) VALUES (?, ?, ?, 'yonetici', 'Kurucu', 'lime', '#b1fb01', 1, ?)");
    $st->execute([$adminAd, $adminMail, password_hash($adminSifre, PASSWORD_DEFAULT), $now]);
    $adminId = (int)$pdo->lastInsertId();

    // Ayarlar
    $ayarlar = [
        'site_adi' => $siteAdi,
        'varsayilan_tema' => 'lime',
        'smtp_aktif' => '0',
        'smtp_host' => 'smtp.hostinger.com',
        'smtp_port' => '465',
        'smtp_kullanici' => '',
        'smtp_sifre' => '',
        'smtp_gonderen' => '',
        'eposta_bildirim' => '1',
    ];
    $st = $pdo->prepare("INSERT IGNORE INTO settings (anahtar, deger) VALUES (?, ?)");
    foreach ($ayarlar as $k => $v) $st->execute([$k, $v]);

    // Varsayılan akış şablonları
    $akislar = [
        ['Sosyal Medya İçerik Üretimi', 'Aylık düzenli içerik üretim akışı', ['Brief & Konsept', 'Tasarım / Üretim', 'İç Onay', 'Müşteri Onayı', 'Yayın / Planlama']],
        ['Video Prodüksiyon', 'Çekim ve kurgu süreci', ['Senaryo & Plan', 'Çekim', 'Kurgu', 'İç Onay', 'Müşteri Onayı', 'Teslim']],
        ['Web Sitesi Projesi', 'Web sitesi yapım akışı', ['Analiz & Brief', 'Tasarım', 'Geliştirme', 'İçerik Girişi', 'Test', 'Yayına Alma']],
        ['Grafik Tasarım', 'Tek seferlik tasarım işleri', ['Brief', 'Tasarım', 'Revizyon', 'Müşteri Onayı', 'Teslim']],
    ];
    $stA = $pdo->prepare("INSERT INTO akis_sablonlari (ad, aciklama, created) VALUES (?, ?, ?)");
    $stB = $pdo->prepare("INSERT INTO sablon_adimlari (sablon_id, sira, ad) VALUES (?, ?, ?)");
    foreach ($akislar as $a) {
        $stA->execute([$a[0], $a[1], $now]);
        $sid = (int)$pdo->lastInsertId();
        foreach ($a[2] as $i => $adimAd) $stB->execute([$sid, $i + 1, $adimAd]);
    }

    // Hazır talep form şablonları
    $formlar = [
        ['Yeni İş Talebi', 'Yeni bir iş veya proje talebi iletin', [
            ['Talep konusu', 'metin', null], ['Detaylı açıklama', 'uzun_metin', null],
            ['İstenen teslim tarihi', 'tarih', null], ['Öncelik', 'secim', "Normal\nYüksek\nAcil"],
        ]],
        ['Revizyon Talebi', 'Mevcut bir iş için revizyon isteyin', [
            ['Hangi iş / içerik için?', 'metin', null], ['İstenen değişiklikler', 'uzun_metin', null],
        ]],
        ['Çekim Talebi', 'Fotoğraf / video çekimi planlayın', [
            ['Çekim konusu', 'metin', null], ['Tercih edilen tarih', 'tarih', null],
            ['Lokasyon', 'metin', null], ['Çekim türü', 'secim', "Fotoğraf\nVideo\nFotoğraf + Video\nDrone"],
            ['Ek notlar', 'uzun_metin', null],
        ]],
        ['Destek Talebi', 'Teknik veya genel destek isteyin', [
            ['Konu', 'metin', null], ['Açıklama', 'uzun_metin', null],
        ]],
    ];
    $stF = $pdo->prepare("INSERT INTO form_sablonlari (ad, aciklama, aktif, created) VALUES (?, ?, 1, ?)");
    $stFa = $pdo->prepare("INSERT INTO form_alanlari (sablon_id, sira, etiket, tip, secenekler, zorunlu) VALUES (?, ?, ?, ?, ?, 1)");
    foreach ($formlar as $f) {
        $stF->execute([$f[0], $f[1], $now]);
        $fid = (int)$pdo->lastInsertId();
        foreach ($f[2] as $i => $alan) $stFa->execute([$fid, $i + 1, $alan[0], $alan[1], $alan[2]]);
    }

    // Genel kanal + yöneticiyi üye yap
    $pdo->prepare("INSERT INTO kanallar (ad, tur, created) VALUES ('Genel', 'genel', ?)")->execute([$now]);
    $kanalId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO kanal_uyeleri (kanal_id, user_id) VALUES (?, ?)")->execute([$kanalId, $adminId]);
}

$adimBasliklari = [1 => 'Gereksinimler', 2 => 'Veritabanı', 3 => 'Site & Yönetici', 4 => 'Tamamlandı'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SADA Dijital — Kurulum</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
<style>
:root { --lime:#b1fb01; --navy:#182f5d; --cream:#f8f2cb; --maroon:#610714; --ink:#0a0f1e; --surface:#101830; --surface2:#182448; --border:rgba(248,242,203,.12); --text:#f2f4f8; --muted:#8b93ab; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:var(--ink); color:var(--text); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
  background-image: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(177,251,1,.08), transparent), radial-gradient(ellipse 60% 40% at 90% 110%, rgba(97,7,20,.25), transparent); }
.kutu { width:100%; max-width:640px; animation:giris .6s cubic-bezier(.22,1,.36,1); }
@keyframes giris { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:none; } }
.logo { font-family:'Unbounded',sans-serif; font-weight:700; font-size:26px; letter-spacing:.06em; margin-bottom:6px; }
.logo span { color:var(--lime); }
.altbaslik { color:var(--muted); font-size:14px; margin-bottom:28px; }
.adimlar { display:flex; gap:8px; margin-bottom:28px; }
.adim-nokta { flex:1; height:4px; border-radius:99px; background:var(--surface2); position:relative; overflow:hidden; }
.adim-nokta.tamam::after { content:''; position:absolute; inset:0; background:var(--lime); border-radius:99px; animation:dolum .5s ease; }
@keyframes dolum { from { transform:scaleX(0); transform-origin:left; } to { transform:scaleX(1); } }
.panel { background:var(--surface); border:1px solid var(--border); border-radius:20px; padding:32px; }
h1 { font-family:'Space Grotesk',sans-serif; font-size:22px; font-weight:600; margin-bottom:4px; }
.aciklama { color:var(--muted); font-size:13.5px; margin-bottom:24px; }
.gereksinim { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:var(--surface2); border-radius:12px; margin-bottom:8px; font-size:14px; }
.rozet { font-size:12px; font-weight:600; padding:4px 12px; border-radius:99px; }
.rozet.ok { background:rgba(177,251,1,.15); color:var(--lime); }
.rozet.no { background:rgba(255,80,80,.15); color:#ff7070; }
label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--text); }
input { width:100%; padding:12px 14px; background:var(--surface2); border:1px solid var(--border); border-radius:12px; color:var(--text); font-family:inherit; font-size:14px; margin-bottom:16px; transition:border-color .2s, box-shadow .2s; }
input:focus { outline:none; border-color:var(--lime); box-shadow:0 0 0 3px rgba(177,251,1,.12); }
.satir { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.btn { display:inline-flex; align-items:center; gap:8px; padding:13px 28px; background:var(--lime); color:var(--ink); border:none; border-radius:12px; font-family:'Space Grotesk',sans-serif; font-weight:600; font-size:15px; cursor:pointer; transition:transform .15s, box-shadow .15s; text-decoration:none; }
.btn:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(177,251,1,.25); }
.btn:disabled { opacity:.4; cursor:not-allowed; transform:none; box-shadow:none; }
.hata { background:rgba(255,80,80,.12); border:1px solid rgba(255,80,80,.3); color:#ff9090; padding:12px 16px; border-radius:12px; font-size:13.5px; margin-bottom:20px; }
.ipucu { background:rgba(177,251,1,.06); border:1px solid rgba(177,251,1,.15); padding:12px 16px; border-radius:12px; font-size:13px; color:var(--muted); margin-bottom:20px; line-height:1.6; }
.ipucu b { color:var(--lime); }
.tamam-ikon { width:72px; height:72px; border-radius:50%; background:rgba(177,251,1,.12); display:flex; align-items:center; justify-content:center; margin:0 auto 20px; animation:zipla .6s cubic-bezier(.34,1.56,.64,1) .2s both; }
@keyframes zipla { from { transform:scale(0); } to { transform:scale(1); } }
.tamam-ikon svg { width:36px; height:36px; stroke:var(--lime); }
.merkez { text-align:center; }
</style>
</head>
<body>
<div class="kutu">
    <div class="logo">SADA<span>.</span></div>
    <div class="altbaslik">Ajans Yönetim Sistemi — Kurulum Sihirbazı · Adım <?= $adim ?>/4: <?= $adimBasliklari[$adim] ?></div>
    <div class="adimlar">
        <?php for ($i = 1; $i <= 4; $i++): ?><div class="adim-nokta <?= $i <= $adim ? 'tamam' : '' ?>"></div><?php endfor; ?>
    </div>
    <div class="panel">
    <?php if ($hata): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

    <?php if ($adim === 1): ?>
        <h1>Sistem Gereksinimleri</h1>
        <p class="aciklama">Sunucunuzun gereksinimleri karşılayıp karşılamadığını kontrol ediyoruz.</p>
        <?php foreach ($gereksinimler as $ad => $ok): ?>
            <div class="gereksinim"><span><?= $ad ?></span><span class="rozet <?= $ok ? 'ok' : 'no' ?>"><?= $ok ? 'Uygun' : 'Eksik' ?></span></div>
        <?php endforeach; ?>
        <div style="margin-top:24px; text-align:right">
            <?php if ($gereksinimTamam): ?><a class="btn" href="?adim=2">Devam Et →</a>
            <?php else: ?><button class="btn" disabled>Eksikleri giderin</button><?php endif; ?>
        </div>

    <?php elseif ($adim === 2): ?>
        <h1>Veritabanı Bağlantısı</h1>
        <p class="aciklama">MySQL veritabanı bilgilerinizi girin.</p>
        <div class="ipucu"><b>Hostinger ipucu:</b> hPanel → Veritabanları → MySQL Veritabanları bölümünden yeni bir veritabanı oluşturun. Sunucu adresi genellikle <b>localhost</b>'tur. Veritabanı adı ve kullanıcı adı <b>u123456789_</b> önekiyle başlar.</div>
        <form method="post" action="?adim=2">
            <label>Veritabanı Sunucusu</label>
            <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
            <label>Veritabanı Adı</label>
            <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" placeholder="u123456789_sada" required>
            <div class="satir">
                <div><label>Kullanıcı Adı</label><input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required></div>
                <div><label>Şifre</label><input type="password" name="db_pass"></div>
            </div>
            <div style="text-align:right"><button class="btn" type="submit">Bağlantıyı Test Et →</button></div>
        </form>

    <?php elseif ($adim === 3): ?>
        <?php if (empty($_SESSION['kurulum_db'])): header('Location: ?adim=2'); exit; endif; ?>
        <h1>Site Bilgileri & Yönetici Hesabı</h1>
        <p class="aciklama">Sisteme giriş yapacağınız yönetici hesabını oluşturun.</p>
        <form method="post" action="?adim=3">
            <label>Site / Ajans Adı</label>
            <input type="text" name="site_adi" value="<?= htmlspecialchars($_POST['site_adi'] ?? 'SADA Dijital') ?>" required>
            <label>Adınız Soyadınız</label>
            <input type="text" name="admin_ad" value="<?= htmlspecialchars($_POST['admin_ad'] ?? '') ?>" required>
            <label>E-posta Adresi</label>
            <input type="email" name="admin_eposta" value="<?= htmlspecialchars($_POST['admin_eposta'] ?? '') ?>" required>
            <div class="satir">
                <div><label>Şifre (en az 6 karakter)</label><input type="password" name="admin_sifre" required minlength="6"></div>
                <div><label>Şifre Tekrar</label><input type="password" name="admin_sifre2" required minlength="6"></div>
            </div>
            <div style="text-align:right"><button class="btn" type="submit">Kurulumu Başlat →</button></div>
        </form>

    <?php elseif ($adim === 4): ?>
        <div class="merkez">
            <div class="tamam-ikon"><svg fill="none" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg></div>
            <h1>Kurulum Tamamlandı 🎉</h1>
            <p class="aciklama" style="margin-top:8px">Veritabanı tabloları oluşturuldu, yönetici hesabınız hazır.<br>Varsayılan akış şablonları ve talep formları da yüklendi.</p>
            <div class="ipucu" style="text-align:left"><b>Önemli:</b> Güvenlik için sunucudaki <b>install</b> klasörünü hemen silin. Bu klasör silinene kadar sistem uyarı gösterecektir.</div>
            <a class="btn" href="../login.php">Giriş Yap →</a>
        </div>
    <?php endif; ?>
    </div>
</div>
</body>
</html>

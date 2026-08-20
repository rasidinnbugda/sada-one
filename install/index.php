<?php
/**
 * SADA One — Installation Wizard
 * Checks system requirements, sets up the database,
 * creates the admin account and writes the config.php file.
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');

$configPath = dirname(__DIR__) . '/config.php';

// Block if already installed
if (file_exists($configPath)) {
    $cfg = include $configPath;
    if (!empty($cfg['installed'])) {
        die('<div style="font-family:sans-serif;padding:40px;text-align:center">Sistem zaten kurulu. Güvenlik için <b>install</b> klasörünü sunucudan silin.<br><br><a href="../login.php">Giriş sayfasına git</a></div>');
    }
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';

/* ---------- Step 1: Requirements check ---------- */
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

/* ---------- Step 2: Database connection ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $_SESSION['install_db'] = ['host' => $dbHost, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass];
        header('Location: ?step=3');
        exit;
    } catch (PDOException $e) {
        $error = 'Veritabanına bağlanılamadı: ' . $e->getMessage();
    }
}

/* ---------- Step 3: Site + admin → run the installation ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    if (empty($_SESSION['install_db'])) { header('Location: ?step=2'); exit; }
    $siteName   = trim($_POST['site_name'] ?? 'SADA One');
    $adminAd   = trim($_POST['admin_name'] ?? '');
    $adminMail = trim($_POST['admin_email'] ?? '');
    $adminSifre = $_POST['admin_password'] ?? '';
    $adminSifre2 = $_POST['admin_password2'] ?? '';

    if ($adminAd === '' || !filter_var($adminMail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ad ve geçerli bir e-posta adresi girin.';
    } elseif (strlen($adminSifre) < 6) {
        $error = 'Şifre en az 6 karakter olmalı.';
    } elseif ($adminSifre !== $adminSifre2) {
        $error = 'Şifreler eşleşmiyor.';
    } else {
        $db = $_SESSION['install_db'];
        try {
            $pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            install_run($pdo, $siteName, $adminAd, $adminMail, $adminSifre);

            $configIcerik = "<?php\nreturn [\n"
                . "    'db_host' => " . var_export($db['host'], true) . ",\n"
                . "    'db_name' => " . var_export($db['name'], true) . ",\n"
                . "    'db_user' => " . var_export($db['user'], true) . ",\n"
                . "    'db_pass' => " . var_export($db['pass'], true) . ",\n"
                . "    'installed' => true,\n"
                . "];\n";
            if (file_put_contents($configPath, $configIcerik) === false) {
                $error = 'config.php yazılamadı. Ana dizin izinlerini kontrol edin.';
            } else {
                unset($_SESSION['install_db']);
                header('Location: ?step=4');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Kurulum hatası: ' . $e->getMessage();
        }
    }
}

/* ---------- Schema + seed data ---------- */
function install_run(PDO $pdo, $siteName, $adminAd, $adminMail, $adminSifre) {
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('yonetici','pm','ekip','finans','stajyer','musteri') NOT NULL DEFAULT 'ekip',
    job_title VARCHAR(100) DEFAULT NULL,
    client_id INT DEFAULT NULL,
    theme VARCHAR(20) NOT NULL DEFAULT 'lime',
    color VARCHAR(7) NOT NULL DEFAULT '#182f5d',
    avatar VARCHAR(255) DEFAULT NULL,
    task_view VARCHAR(10) NOT NULL DEFAULT 'kanban',
    salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    weekly_capacity SMALLINT NOT NULL DEFAULT 45,
    permissions TEXT,
    notification_preferences TEXT,
    widgets TEXT,
    scratchpad MEDIUMTEXT,
    seen_version VARCHAR(10) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login DATETIME DEFAULT NULL,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type ENUM('marka','sirket','stk') NOT NULL DEFAULT 'marka',
    color VARCHAR(7) NOT NULL DEFAULT '#182f5d',
    logo VARCHAR(255) DEFAULT NULL,
    description TEXT,
    contact_name VARCHAR(100) DEFAULT NULL,
    contact_email VARCHAR(150) DEFAULT NULL,
    contact_phone VARCHAR(30) DEFAULT NULL,
    status ENUM('aktif','pasif') NOT NULL DEFAULT 'aktif',
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    type ENUM('aylik','donemsel','tek') NOT NULL DEFAULT 'aylik',
    description TEXT,
    status ENUM('aktif','beklemede','tamamlandi','iptal') NOT NULL DEFAULT 'aktif',
    start DATE DEFAULT NULL,
    `end` DATE DEFAULT NULL,
    pm_id INT DEFAULT NULL,
    contract_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    created DATETIME NOT NULL,
    INDEX(client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    year SMALLINT NOT NULL,
    month TINYINT NOT NULL,
    status ENUM('acik','kapali') NOT NULL DEFAULT 'acik',
    created DATETIME NOT NULL,
    UNIQUE KEY uniq_period (project_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS workflow_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS template_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    sort_order TINYINT NOT NULL DEFAULT 1,
    name VARCHAR(120) NOT NULL,
    INDEX(template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    period_id INT DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    assignee_id INT DEFAULT NULL,
    created_by INT NOT NULL,
    priority ENUM('dusuk','normal','yuksek','acil') NOT NULL DEFAULT 'normal',
    status ENUM('yapilacak','devam','incelemede','onayda','tamamlandi') NOT NULL DEFAULT 'yapilacak',
    due_date DATE DEFAULT NULL,
    completion DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    bagimli_id INT DEFAULT NULL,
    lock_bypassed TINYINT(1) NOT NULL DEFAULT 0,
    `repeat` ENUM('yok','haftalik','aylik') NOT NULL DEFAULT 'yok',
    last_repeat VARCHAR(10) DEFAULT NULL,
    tags VARCHAR(255) DEFAULT NULL,
    estimated_minutes INT NOT NULL DEFAULT 0,
    start_date DATE DEFAULT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    content_id INT DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(project_id), INDEX(assignee_id), INDEX(period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS project_members (
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (project_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS client_members (
    client_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (client_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS task_checklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    sort_order TINYINT NOT NULL DEFAULT 1,
    INDEX(task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS task_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    sort_order TINYINT NOT NULL DEFAULT 1,
    name VARCHAR(120) NOT NULL,
    owner_id INT DEFAULT NULL,
    status ENUM('bekliyor','aktif','tamam') NOT NULL DEFAULT 'bekliyor',
    done_date DATETIME DEFAULT NULL,
    INDEX(task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_type VARCHAR(20) NOT NULL,
    ref_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    parent_id INT DEFAULT NULL,
    archive_id INT DEFAULT NULL,
    is_edited TINYINT(1) NOT NULL DEFAULT 0,
    created DATETIME NOT NULL,
    INDEX(ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS comment_box_reactions (
    comment_box_id INT NOT NULL,
    user_id INT NOT NULL,
    emoji VARCHAR(8) NOT NULL,
    PRIMARY KEY (comment_box_id, user_id, emoji)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS task_watchers (
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (task_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS task_assignees (
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (task_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('maas','kira','abonelik','ekipman','vergi','diger') NOT NULL DEFAULT 'diger',
    title VARCHAR(200) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    date DATE NOT NULL,
    status ENUM('bekliyor','odendi') NOT NULL DEFAULT 'bekliyor',
    `repeat` ENUM('yok','aylik') NOT NULL DEFAULT 'yok',
    last_repeat VARCHAR(10) DEFAULT NULL,
    user_id INT DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    text TEXT,
    is_important TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS announcement_readers (
    announcement_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (announcement_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    is_success TINYINT(1) NOT NULL DEFAULT 0,
    created DATETIME NOT NULL,
    INDEX(email, created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS equipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    category ENUM('kamera','lens','sd_kart','tripod','isik','ses','drone','aksesuar','diger') NOT NULL DEFAULT 'diger',
    photo VARCHAR(255) DEFAULT NULL,
    status ENUM('studyoda','zimmette','cekimde','arizali','bakimda') NOT NULL DEFAULT 'studyoda',
    custody_user_id INT DEFAULT NULL,
    custody_event_id INT DEFAULT NULL,
    fault_note VARCHAR(255) DEFAULT NULL,
    purchase_date DATE DEFAULT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sd_status ENUM('bos','dolu','aktarildi') DEFAULT NULL,
    sd_content VARCHAR(255) DEFAULT NULL,
    sd_drive_link VARCHAR(255) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(category), INDEX(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS equipment_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    user_id INT NOT NULL,
    target_user_id INT DEFAULT NULL,
    event_id INT DEFAULT NULL,
    type VARCHAR(20) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS event_equipment (
    event_id INT NOT NULL,
    equipment_id INT NOT NULL,
    PRIMARY KEY (event_id, equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    start DATE DEFAULT NULL,
    `end` DATE DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    archive_id INT DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_reminded TINYINT(1) NOT NULL DEFAULT 0,
    created DATETIME NOT NULL,
    INDEX(client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS time_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    minutes INT NOT NULL DEFAULT 0,
    date DATE NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(task_id), INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS archive (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT DEFAULT NULL,
    project_id INT DEFAULT NULL,
    task_id INT DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    size INT NOT NULL DEFAULT 0,
    extension VARCHAR(10) DEFAULT NULL,
    url VARCHAR(500) DEFAULT NULL,
    uploader_id INT NOT NULL,
    created DATETIME NOT NULL,
    INDEX(client_id), INDEX(project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS contents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT DEFAULT NULL,
    project_id INT DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    platform VARCHAR(120) NOT NULL DEFAULT 'instagram',
    date DATE NOT NULL,
    time TIME DEFAULT NULL,
    status ENUM('taslak','ic_onay','musteri_onay','revize','onaylandi','yayinlandi') NOT NULL DEFAULT 'taslak',
    created_by INT NOT NULL,
    created DATETIME NOT NULL,
    INDEX(client_id), INDEX(project_id), INDEX(date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS project_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    tasks TEXT,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS client_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    text TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    updated_by INT DEFAULT NULL,
    `update` DATETIME DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('teklif','fatura') NOT NULL DEFAULT 'teklif',
    doc_no VARCHAR(20) NOT NULL,
    client_id INT DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    items TEXT,
    vat_rate TINYINT NOT NULL DEFAULT 20,
    status ENUM('taslak','gonderildi','onaylandi','reddedildi') NOT NULL DEFAULT 'taslak',
    valid_until DATE DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    created_by INT NOT NULL,
    created DATETIME NOT NULL,
    INDEX(client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS social_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    platform VARCHAR(20) NOT NULL DEFAULT 'instagram',
    username VARCHAR(100) NOT NULL,
    url VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS social_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    date DATE NOT NULL,
    followers INT NOT NULL DEFAULT 0,
    post INT DEFAULT NULL,
    engagement INT DEFAULT NULL,
    entered_by INT DEFAULT NULL,
    created DATETIME NOT NULL,
    UNIQUE KEY uniq_metric (account_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT DEFAULT NULL,
    project_id INT DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    type ENUM('cekim','toplanti','teslim','diger') NOT NULL DEFAULT 'cekim',
    start DATETIME NOT NULL,
    `end` DATETIME DEFAULT NULL,
    place VARCHAR(200) DEFAULT NULL,
    description TEXT,
    participants VARCHAR(255) DEFAULT NULL,
    online_link VARCHAR(255) DEFAULT NULL,
    is_reminded TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    created DATETIME NOT NULL,
    INDEX(start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS event_participants (
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (event_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS personal_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) DEFAULT NULL,
    text TEXT,
    color VARCHAR(20) NOT NULL DEFAULT 'varsayilan',
    created DATETIME NOT NULL,
    `update` DATETIME DEFAULT NULL,
    INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS personal_todos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS customer_clients (
    user_id INT NOT NULL,
    client_id INT NOT NULL,
    PRIMARY KEY (user_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_type ENUM('gorev','onay') NOT NULL,
    ref_id INT NOT NULL,
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    rating TINYINT NOT NULL,
    comment_box VARCHAR(500) DEFAULT NULL,
    created DATETIME NOT NULL,
    UNIQUE KEY uniq_rating (ref_type, ref_id, user_id),
    INDEX(project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    client_id INT DEFAULT NULL,
    topic VARCHAR(200) NOT NULL,
    date DATETIME NOT NULL,
    online_request TINYINT(1) NOT NULL DEFAULT 0,
    notes VARCHAR(500) DEFAULT NULL,
    status ENUM('bekliyor','onaylandi','alternatif','reddedildi') NOT NULL DEFAULT 'bekliyor',
    alternative_date DATETIME DEFAULT NULL,
    online_link VARCHAR(255) DEFAULT NULL,
    event_id INT DEFAULT NULL,
    reply_note VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(customer_id), INDEX(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS personal_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    url VARCHAR(500) NOT NULL,
    INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    archive_id INT DEFAULT NULL,
    drive_link VARCHAR(500) DEFAULT NULL,
    content_id INT DEFAULT NULL,
    task_id INT DEFAULT NULL,
    status ENUM('bekliyor','onaylandi','revize','reddedildi') NOT NULL DEFAULT 'bekliyor',
    sender_id INT NOT NULL,
    reply_note TEXT,
    reply_date DATETIME DEFAULT NULL,
    responder_id INT DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(project_id), INDEX(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    type ENUM('genel','proje','ozel','musteri') NOT NULL DEFAULT 'genel',
    project_id INT DEFAULT NULL,
    icon VARCHAR(8) DEFAULT NULL,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS channel_members (
    channel_id INT NOT NULL,
    user_id INT NOT NULL,
    last_read DATETIME DEFAULT NULL,
    archive TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (channel_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    created DATETIME NOT NULL,
    INDEX(channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS form_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS form_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    sort_order TINYINT NOT NULL DEFAULT 1,
    tag VARCHAR(150) NOT NULL,
    type ENUM('metin','uzun_metin','secim','tarih','sayi','dosya') NOT NULL DEFAULT 'metin',
    options TEXT,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    INDEX(template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    client_id INT DEFAULT NULL,
    project_id INT DEFAULT NULL,
    sender_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    status ENUM('yeni','inceleniyor','gorev_olusturuldu','tamamlandi','reddedildi') NOT NULL DEFAULT 'yeni',
    assignee_id INT DEFAULT NULL,
    task_id INT DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS request_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    field_id INT NOT NULL,
    setting_value TEXT,
    INDEX(request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    type ENUM('fatura','tahsilat') NOT NULL DEFAULT 'fatura',
    title VARCHAR(200) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    date DATE NOT NULL,
    status ENUM('bekliyor','odendi','gecikti') NOT NULL DEFAULT 'bekliyor',
    description VARCHAR(255) DEFAULT NULL,
    created DATETIME NOT NULL,
    INDEX(project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ref_type VARCHAR(20) DEFAULT NULL,
    ref_id INT DEFAULT NULL,
    description VARCHAR(255) NOT NULL,
    created DATETIME NOT NULL,
    INDEX(ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(255) DEFAULT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created DATETIME NOT NULL,
    INDEX(user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
SQL;

    // Run the table statements one by one
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') $pdo->exec($stmt);
    }

    $now = date('Y-m-d H:i:s');

    // Admin account
    $st = $pdo->prepare("INSERT INTO users (name, email, password, role, job_title, theme, color, is_active, created) VALUES (?, ?, ?, 'yonetici', 'Kurucu', 'lime', '#b1fb01', 1, ?)");
    $st->execute([$adminAd, $adminMail, password_hash($adminSifre, PASSWORD_DEFAULT), $now]);
    $adminId = (int)$pdo->lastInsertId();

    // Settings
    $settings = [
        'site_name' => $siteName,
        'default_theme' => 'lime',
        'smtp_is_active' => '0',
        'smtp_host' => 'smtp.hostinger.com',
        'smtp_port' => '465',
        'smtp_user' => '',
        'smtp_password' => '',
        'smtp_sender' => '',
        'email_notification' => '1',
    ];
    $st = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as $k => $v) $st->execute([$k, $v]);

    // Default workflow templates
    $workflows = [
        ['Sosyal Medya İçerik Üretimi', 'Aylık düzenli içerik üretim akışı', ['Brief & Konsept', 'Tasarım / Üretim', 'İç Onay', 'Müşteri Onayı', 'Yayın / Planlama']],
        ['Video Prodüksiyon', 'Çekim ve kurgu süreci', ['Senaryo & Plan', 'Çekim', 'Kurgu', 'İç Onay', 'Müşteri Onayı', 'Teslim']],
        ['Web Sitesi Projesi', 'Web sitesi yapım akışı', ['Analiz & Brief', 'Tasarım', 'Geliştirme', 'İçerik Girişi', 'Test', 'Yayına Alma']],
        ['Grafik Tasarım', 'Tek seferlik tasarım işleri', ['Brief', 'Tasarım', 'Revizyon', 'Müşteri Onayı', 'Teslim']],
    ];
    $stA = $pdo->prepare("INSERT INTO workflow_templates (name, description, created) VALUES (?, ?, ?)");
    $stB = $pdo->prepare("INSERT INTO template_steps (template_id, sort_order, name) VALUES (?, ?, ?)");
    foreach ($workflows as $a) {
        $stA->execute([$a[0], $a[1], $now]);
        $sid = (int)$pdo->lastInsertId();
        foreach ($a[2] as $i => $stepName) $stB->execute([$sid, $i + 1, $stepName]);
    }

    // Ready-made request form templates
    $forms = [
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
    $stF = $pdo->prepare("INSERT INTO form_templates (name, description, is_active, created) VALUES (?, ?, 1, ?)");
    $stFa = $pdo->prepare("INSERT INTO form_fields (template_id, sort_order, tag, type, options, is_required) VALUES (?, ?, ?, ?, ?, 1)");
    foreach ($forms as $f) {
        $stF->execute([$f[0], $f[1], $now]);
        $fid = (int)$pdo->lastInsertId();
        foreach ($f[2] as $i => $field) $stFa->execute([$fid, $i + 1, $field[0], $field[1], $field[2]]);
    }

    // Central migration: apply all schema changes added later
    require_once dirname(__DIR__) . '/includes/migration.php';
    run_migrations($pdo);

    // General channel + make the admin a member
    $pdo->prepare("INSERT INTO channels (name, type, created) VALUES ('Genel', 'genel', ?)")->execute([$now]);
    $channelId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO channel_members (channel_id, user_id) VALUES (?, ?)")->execute([$channelId, $adminId]);
}

$adimBasliklari = [1 => 'Gereksinimler', 2 => 'Veritabanı', 3 => 'Site & Yönetici', 4 => 'Tamamlandı'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SADA One — Kurulum</title>
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
    <div class="altbaslik">Ajans Yönetim Sistemi — Kurulum Sihirbazı · Adım <?= $step ?>/4: <?= $adimBasliklari[$step] ?></div>
    <div class="adimlar">
        <?php for ($i = 1; $i <= 4; $i++): ?><div class="adim-nokta <?= $i <= $step ? 'tamam' : '' ?>"></div><?php endfor; ?>
    </div>
    <div class="panel">
    <?php if ($error): ?><div class="hata"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($step === 1): ?>
        <h1>Sistem Gereksinimleri</h1>
        <p class="aciklama">Sunucunuzun gereksinimleri karşılayıp karşılamadığını kontrol ediyoruz.</p>
        <?php foreach ($gereksinimler as $name => $ok): ?>
            <div class="gereksinim"><span><?= $name ?></span><span class="rozet <?= $ok ? 'ok' : 'doc_no' ?>"><?= $ok ? 'Uygun' : 'Eksik' ?></span></div>
        <?php endforeach; ?>
        <div style="margin-top:24px; text-align:right">
            <?php if ($gereksinimTamam): ?><a class="btn" href="?step=2">Devam Et →</a>
            <?php else: ?><button class="btn" disabled>Eksikleri giderin</button><?php endif; ?>
        </div>

    <?php elseif ($step === 2): ?>
        <h1>Veritabanı Bağlantısı</h1>
        <p class="aciklama">MySQL veritabanı bilgilerinizi girin.</p>
        <div class="ipucu"><b>Hostinger ipucu:</b> hPanel → Veritabanları → MySQL Veritabanları bölümünden yeni bir veritabanı oluşturun. Sunucu adresi genellikle <b>localhost</b>'tur. Veritabanı adı ve kullanıcı adı <b>u123456789_</b> önekiyle başlar.</div>
        <form method="post" action="?step=2">
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

    <?php elseif ($step === 3): ?>
        <?php if (empty($_SESSION['install_db'])): header('Location: ?step=2'); exit; endif; ?>
        <h1>Site Bilgileri & Yönetici Hesabı</h1>
        <p class="aciklama">Sisteme giriş yapacağınız yönetici hesabını oluşturun.</p>
        <form method="post" action="?step=3">
            <label>Site / Ajans Adı</label>
            <input type="text" name="site_name" value="<?= htmlspecialchars($_POST['site_name'] ?? 'SADA One') ?>" required>
            <label>Adınız Soyadınız</label>
            <input type="text" name="admin_name" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required>
            <label>E-posta Adresi</label>
            <input type="email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
            <div class="satir">
                <div><label>Şifre (en az 6 karakter)</label><input type="password" name="admin_password" required minlength="6"></div>
                <div><label>Şifre Tekrar</label><input type="password" name="admin_password2" required minlength="6"></div>
            </div>
            <div style="text-align:right"><button class="btn" type="submit">Kurulumu Başlat →</button></div>
        </form>

    <?php elseif ($step === 4): ?>
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

<?php
/**
 * SADA One — Core bootstrap file
 * Session, database connection, authorization checks and helper functions.
 */
// Session security: cookie hardening (reduces cookie theft via XSS and the CSRF surface)
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
session_start();
// Ensure the CSRF token exists, then RELEASE THE SESSION LOCK immediately.
// PHP holds an exclusive lock on the session file for the whole request by default;
// with live-sync polling and multiple tabs this makes requests queue behind each
// other and pages appear "stuck" in one browser while working in another.
// $_SESSION stays readable after the close; the rare writes reopen it explicitly.
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(20));
session_write_close();

/** Reopen the session briefly for a write, then release the lock again. */
function session_write(callable $fn): void {
    @session_start();
    $fn();
    session_write_close();
}
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Istanbul');

/* Production error handling: never leak stack traces / paths / SQL to visitors.
 * Errors are appended to storage/error.log (web-blocked); users get a plain page. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(dirname(__DIR__) . '/storage') || @mkdir(dirname(__DIR__) . '/storage', 0755, true)) {
    ini_set('error_log', dirname(__DIR__) . '/storage/error.log');
}
set_exception_handler(function (Throwable $e) {
    error_log('[SADA] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (defined('IS_AJAX')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Sunucu hatası oluştu; kayıt alındı. Sorun sürerse yöneticinize bildirin.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Hata</title></head>'
            . '<body style="font-family:sans-serif;background:#0b0f0a;color:#eef4e6;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
            . '<div style="text-align:center;max-width:360px"><div style="font-size:26px;font-weight:800;letter-spacing:2px">SADA<span style="color:#b1fb01">.</span></div>'
            . '<p style="color:#b3c2a2;line-height:1.6">Beklenmeyen bir hata oluştu ve kayda alındı.<br>Sorun sürerse yöneticinize bildirin.</p>'
            . '<a href="index.php" style="color:#b1fb01">Panele dön</a></div></body></html>';
    }
    exit;
});
// Personalized pages must never be cached by proxies/CDN (Hostinger cache, LiteSpeed):
// a cached page from user A could otherwise be served to user B, or a stale page
// could make the site look "stuck" in one browser while fresh in another.
if (!headers_sent()) header('Cache-Control: no-store, max-age=0');

define('ROOT', dirname(__DIR__));
define('BASE_URL', rtrim(dirname($_SERVER['SCRIPT_NAME']) === '/' ? '' : str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/'));

// Installation check
if (!file_exists(ROOT . '/config.php')) {
    header('Location: install/index.php');
    exit;
}
$GLOBALS['config'] = include ROOT . '/config.php';

/**
 * v5.0 self-healing upgrade: installs created before the English schema rename
 * still have Turkish table names. Detect that on boot and localize the schema once.
 */
function legacy_schema_check(): void {
    try {
        if (db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='dosyalar'")->fetchColumn()) {
            require_once __DIR__ . '/legacy-migration.php';
            legacy_localization(db());
        }
        // Self-healing schema: whenever the code version moves ahead of the DB,
        // run the (idempotent) migrations once. Prevents "files updated but the
        // schema wasn't" 500s after manual file uploads.
        $st = db()->prepare("SELECT setting_value FROM settings WHERE setting_key='schema_version'");
        $st->execute();
        if ($st->fetchColumn() !== APP_VERSION) {
            require_once __DIR__ . '/migration.php';
            run_migrations(db());
            db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([APP_VERSION, APP_VERSION]);
        }
    } catch (Throwable $e) { /* connection problems surface later with a clearer error */ }
}

/* ---------------- Database ---------------- */

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['config'];
        $pdo = new PDO(
            "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",
            $c['db_user'], $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []) { return q($sql, $p)->fetch() ?: null; }
function val(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }

function insert(string $table, array $data): int {
    // Column names are backtick-quoted: some renamed columns (`repeat`, `end`, `update`)
    // collide with SQL reserved words.
    $columns = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
    $place = implode(',', array_fill(0, count($data), '?'));
    q("INSERT INTO $table ($columns) VALUES ($place)", array_values($data));
    return (int)db()->lastInsertId();
}

function update_row(string $table, array $data, string $where_sql, array $kosulP = []): void {
    $set = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
    q("UPDATE $table SET $set WHERE $where_sql", array_merge(array_values($data), $kosulP));
}

/* ---------------- Settings ---------------- */

function setting(string $setting_key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (rows("SELECT setting_key, setting_value FROM settings") as $r) $cache[$r['setting_key']] = $r['setting_value'];
    }
    return $cache[$setting_key] ?? $default;
}

/* ---------------- Session & Authorization ---------------- */

function user(): ?array {
    static $u = false;
    if ($u === false) {
        $u = empty($_SESSION['uid']) ? null : row("SELECT * FROM users WHERE id=? AND is_active=1", [$_SESSION['uid']]);
    }
    return $u;
}

function require_login(): array {
    $u = user();
    if (!$u) { header('Location: login.php'); exit; }
    return $u;
}

function is_admin(): bool { return (user()['role'] ?? '') === 'yonetici'; }
function is_pm(): bool { return in_array(user()['role'] ?? '', ['yonetici', 'pm']); }
function is_staff(): bool { return in_array(user()['role'] ?? '', ['yonetici', 'pm', 'ekip', 'finans', 'stajyer']); }
function is_finance(): bool { return (user()['role'] ?? '') === 'finans'; }
function is_intern(): bool { return (user()['role'] ?? '') === 'stajyer'; }
function is_customer(): bool { return (user()['role'] ?? '') === 'musteri'; }

/** On unauthorized access: JSON 403 for AJAX requests, redirect for regular pages */
function deny(): void {
    if (defined('IS_AJAX')) {
        json_out(['ok' => false, 'error' => 'Bu işlem için yetkiniz yok.'], 403);
    }
    header('Location: index.php');
    exit;
}

function require_staff(): array {
    $u = require_login();
    if (!is_staff()) deny();
    return $u;
}
function require_pm(): array {
    $u = require_login();
    if (!is_pm()) deny();
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if (!is_admin()) deny();
    return $u;
}

/* ---------------- Per-user permissions ----------------
 * Role defaults + per-user overrides (users.izinler JSON).
 * Keys: finans, rapor, dosya_yonet, gorev_sil, icerik_yonet, kapasite
 */
const PERMISSION_KEYS = [
    'finans' => 'Finans sayfası',
    'rapor' => 'Raporlar sayfası',
    'kapasite' => 'Kapasite takibi',
    'dosya_yonet' => 'Dosya/Proje oluştur-düzenle',
    'gorev_olustur' => 'Görev oluşturma',
    'gorev_sil' => 'Görev silme',
    'icerik_yonet' => 'İçerik takvimi yönetimi',
    'ekipman_yonet' => 'Ekipman envanteri yönetimi',
    'onay_gonder' => 'Müşteri onayına gönderme',
    'duyuru_yayinla' => 'Duyuru yayınlama',
    'takvim_yonet' => 'Etkinlik/toplantı oluşturma',
    'kanal_kur' => 'Sohbet kanalı kurma',
    'belge_olustur' => 'Teklif/fatura oluşturma',
    'arsiv_sil' => 'Arşivden dosya silme',
    'talep_yonet' => 'Talepleri yönetme',
    'butce_gor' => 'Proje bütçelerini görme (istasyon)',
    'finans_yonet' => 'Finans kaydı ekleme/düzenleme/silme',
    'randevu_yonet' => 'Randevuları yanıtlama (onay/red/alternatif)',
    'havuz_yonet' => 'Çalışan havuzu yönetimi',
    'mentorluk_yonet' => 'Gelişim & mentörlük yönetimi',
    'ai_kullan' => 'Yapay zeka özelliklerini kullanma',
];

function permission(string $setting_key): bool {
    $u = user();
    if (!$u) return false;
    if ($u['role'] === 'yonetici') return true;
    if ($u['role'] === 'musteri') return false;
    // Per-user override
    $ozel = json_decode($u['permissions'] ?? '', true);
    if (is_array($ozel) && array_key_exists($setting_key, $ozel)) return (bool)$ozel[$setting_key];
    // Role defaults
    $default = [
        'pm'      => ['finans' => 1, 'rapor' => 1, 'kapasite' => 1, 'dosya_yonet' => 1, 'gorev_olustur' => 1, 'gorev_sil' => 1, 'icerik_yonet' => 1, 'ekipman_yonet' => 1, 'onay_gonder' => 1, 'duyuru_yayinla' => 1, 'takvim_yonet' => 1, 'kanal_kur' => 1, 'belge_olustur' => 1, 'arsiv_sil' => 1, 'talep_yonet' => 1, 'finans_yonet' => 1, 'randevu_yonet' => 1, 'havuz_yonet' => 1, 'mentorluk_yonet' => 1, 'ai_kullan' => 1],
        'ekip'    => ['finans' => 0, 'rapor' => 0, 'kapasite' => 0, 'dosya_yonet' => 0, 'gorev_olustur' => 1, 'gorev_sil' => 0, 'icerik_yonet' => 1, 'ekipman_yonet' => 0, 'onay_gonder' => 1, 'duyuru_yayinla' => 0, 'takvim_yonet' => 1, 'kanal_kur' => 1, 'belge_olustur' => 0, 'arsiv_sil' => 0, 'talep_yonet' => 0, 'finans_yonet' => 0, 'randevu_yonet' => 0, 'havuz_yonet' => 0, 'mentorluk_yonet' => 0, 'ai_kullan' => 1],
        'finans'  => ['finans' => 1, 'rapor' => 1, 'kapasite' => 1, 'dosya_yonet' => 0, 'gorev_olustur' => 0, 'gorev_sil' => 0, 'icerik_yonet' => 0, 'ekipman_yonet' => 0, 'onay_gonder' => 0, 'duyuru_yayinla' => 0, 'takvim_yonet' => 0, 'kanal_kur' => 1, 'belge_olustur' => 1, 'arsiv_sil' => 0, 'talep_yonet' => 0, 'finans_yonet' => 1, 'randevu_yonet' => 0, 'havuz_yonet' => 0, 'mentorluk_yonet' => 0, 'ai_kullan' => 1],
        'stajyer' => ['finans' => 0, 'rapor' => 0, 'kapasite' => 0, 'dosya_yonet' => 0, 'gorev_olustur' => 0, 'gorev_sil' => 0, 'icerik_yonet' => 0, 'ekipman_yonet' => 0, 'onay_gonder' => 0, 'duyuru_yayinla' => 0, 'takvim_yonet' => 0, 'kanal_kur' => 0, 'belge_olustur' => 0, 'arsiv_sil' => 0, 'talep_yonet' => 0, 'finans_yonet' => 0, 'randevu_yonet' => 0, 'havuz_yonet' => 0, 'mentorluk_yonet' => 0, 'ai_kullan' => 0],
    ];
    return (bool)($default[$u['role']][$setting_key] ?? 0);
}

function require_permission(string $setting_key): array {
    $u = require_login();
    if (!permission($setting_key)) deny();
    return $u;
}

/** Client ids the customer can access (primary client + extra assignments) */
function customer_client_ids(?int $userId = null): array {
    static $cache = [];
    $u = user();
    $userId = $userId ?? (int)($u['id'] ?? 0);
    if (isset($cache[$userId])) return $cache[$userId];
    $ids = array_map('intval', array_column(rows("SELECT client_id FROM customer_clients WHERE user_id=?", [$userId]), 'client_id'));
    $birincil = (int)val("SELECT client_id FROM users WHERE id=?", [$userId]);
    if ($birincil && !in_array($birincil, $ids)) $ids[] = $birincil;
    return $cache[$userId] = $ids;
}

/** Builds placeholders for IN (...) queries; returns an impossible condition for an empty list */
function in_clause(array $ids): array {
    if (!$ids) return ['(SELECT 0 WHERE 1=0)', []]; // no clients at all → empty result
    return ['(' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

/** Is this project accessible to the customer user? */
function project_access(int $projectId): bool {
    $u = user();
    if (!$u) return false;
    if (is_staff()) return true;
    $ids = customer_client_ids();
    if (!$ids) return false;
    [$in, $p] = in_clause($ids);
    return (bool)val("SELECT COUNT(*) FROM projects WHERE id=? AND client_id IN $in", array_merge([$projectId], $p));
}

/** Can the customer access this client? */
function client_access(int $clientId): bool {
    if (is_staff()) return true;
    return in_array($clientId, customer_client_ids());
}

/* ---------------- CSRF ---------------- */

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(20));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $t = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
        json_out(['ok' => false, 'error' => 'Oturum doğrulaması başarısız. Sayfayı yenileyin.'], 403);
    }
}

/* ---------------- Helpers ---------------- */

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function json_out($data, int $code = 200): void {
    // The endpoints answer with 'mesaj', the front-end reads j.message — serve both
    if (is_array($data) && isset($data['mesaj']) && !isset($data['message'])) $data['message'] = $data['mesaj'];
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

const MONTHS = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
const DAYS = ['Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar'];

function format_date(?string $dt, bool $saatli = false): string {
    if (!$dt || $dt === '0000-00-00') return '—';
    $ts = strtotime($dt);
    $s = date('j', $ts) . ' ' . MONTHS[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    if ($saatli) $s .= ' ' . date('H:i', $ts);
    return $s;
}

function time_ago(?string $dt): string {
    if (!$dt) return '—';
    $fark = time() - strtotime($dt);
    if ($fark < 60) return 'az önce';
    if ($fark < 3600) return floor($fark / 60) . ' dk önce';
    if ($fark < 86400) return floor($fark / 3600) . ' saat önce';
    if ($fark < 604800) return floor($fark / 86400) . ' gün önce';
    return format_date($dt);
}

function format_minutes(int $min): string {
    if ($min < 60) return $min . ' dk';
    $s = intdiv($min, 60); $k = $min % 60;
    return $s . 'sa' . ($k ? ' ' . $k . 'min' : '');
}

function money(?float $t): string { return number_format((float)$t, 2, ',', '.') . ' ₺'; }

function initials(string $name): string {
    $parcalar = preg_split('/\s+/', trim($name));
    $h = mb_substr($parcalar[0], 0, 1);
    if (count($parcalar) > 1) $h .= mb_substr(end($parcalar), 0, 1);
    return mb_strtoupper($h);
}

function avatar(?array $u, int $size = 34): string {
    if (!$u) return '<span class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;background:var(--surface-2)">?</span>';
    if (!empty($u['avatar'])) {
        return '<span class="avatar" title="' . e($u['name']) . '" style="width:' . $size . 'px;height:' . $size . 'px;background-image:url(\'uploads/' . e($u['avatar']) . '\');background-size:cover;background-position:center"></span>';
    }
    $color = e($u['color'] ?? '#182f5d');
    return '<span class="avatar" title="' . e($u['name']) . '" style="width:' . $size . 'px;height:' . $size . 'px;background:' . $color . '22;color:' . $color . ';border:1.5px solid ' . $color . '55">' . e(initials($u['name'])) . '</span>';
}

/** Client logo or a colored initials box */
function client_logo(array $d, int $size = 40, int $fontPx = 15): string {
    if (!empty($d['logo'])) {
        return '<span class="dosya-avatar" style="width:' . $size . 'px;height:' . $size . 'px;background-image:url(\'uploads/' . e($d['logo']) . '\');background-size:cover;background-position:center"></span>';
    }
    $color = e($d['color'] ?? '#182f5d');
    return '<span class="dosya-avatar" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . $fontPx . 'px;background:' . $color . '22;color:' . $color . '">' . e(initials($d['name'])) . '</span>';
}

/* ---------------- Label dictionaries ---------------- */

const PROJECT_TYPES = ['aylik' => 'Aylık Düzenli', 'donemsel' => 'Dönemsel', 'tek' => 'Tek Seferlik'];
const CLIENT_TYPES = ['marka' => 'Marka', 'sirket' => 'Şirket', 'stk' => 'STK'];
const TASK_STATUSES = ['yapilacak' => 'Yapılacak', 'devam' => 'Devam Ediyor', 'incelemede' => 'İncelemede', 'onayda' => 'Onayda', 'tamamlandi' => 'Tamamlandı'];
const PRIORITIES = ['dusuk' => 'Düşük', 'normal' => 'Normal', 'yuksek' => 'Yüksek', 'acil' => 'Acil'];
const PROJECT_STATUSES = ['is_active' => 'Aktif', 'beklemede' => 'Beklemede', 'tamamlandi' => 'Tamamlandı', 'iptal' => 'İptal'];
const CONTENT_STATUSES = ['taslak' => 'Taslak', 'internal_approval' => 'İç Onayda', 'customer_approval' => 'Müşteri Onayında', 'revize' => 'Revize', 'onaylandi' => 'Onaylandı', 'yayinlandi' => 'Yayınlandı'];
const APPROVAL_STATUSES = ['bekliyor' => 'Bekliyor', 'onaylandi' => 'Onaylandı', 'revize' => 'Revize İstendi', 'reddedildi' => 'Reddedildi'];
const REQUEST_STATUSES = ['yeni' => 'Yeni', 'inceleniyor' => 'İnceleniyor', 'gorev_olusturuldu' => 'Göreve Dönüştürüldü', 'tamamlandi' => 'Tamamlandı', 'reddedildi' => 'Reddedildi'];
const PLATFORMS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'x' => 'X (Twitter)', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'web' => 'Web Sitesi', 'diger' => 'Diğer'];
const EVENT_TYPES = ['cekim' => 'Çekim', 'toplanti' => 'Toplantı', 'is_delivered' => 'Teslim', 'diger' => 'Diğer'];
const ROLES = ['yonetici' => 'Yönetici', 'pm' => 'Proje Yöneticisi', 'ekip' => 'Ekip Üyesi', 'finans' => 'Finans', 'stajyer' => 'Stajyer', 'musteri' => 'Müşteri'];
const REPEAT_OPTIONS = ['yok' => 'Tekrarlamaz', 'haftalik' => 'Her Hafta', 'aylik' => 'Her Ay'];
const EXPENSE_TYPES = ['maas' => 'Maaş', 'kira' => 'Kira', 'abonelik' => 'Abonelik', 'ekipman' => 'Ekipman', 'vergi' => 'Vergi', 'diger' => 'Diğer'];

/* ---------------- Version & update notes ---------------- */
const APP_VERSION = '6.7';
const VERSION_NOTES = [
    '6.7' => [
        'Rapor maili tasarımı baştan: kapak görseli, dönem etiketi, "Markanız İçin Ürettik" kartları, kırmızı "Bu Ayın Favorisi" bloğu (görsel + öne çıkan sayı), 2x2 istatistik kartları (artış/azalış oklu) ve dosya sorumlusunun iletişimiyle kapanış',
        'Mail modalına 🎨 Tasarımı Düzenle bölümü: kapak/favori görseli yükleme, istatistik kartlarını doldurma — kaydet deyince önizleme anında yenileniyor',
        'SMTP gönderim hatası düzeltildi: uzun HTML satırları Gmail\'in satır sınırına takılıyordu; ayrıca hata artık sunucunun gerçek yanıtını gösteriyor',
    ],
    '6.6' => [
        'Aylık rapor artık tek tıkla şık bir HTML müşteri mailine dönüşüyor: Aylık Raporlar → 📧 Müşteri Maili — önizleme, alıcı/konu düzenleme ve panelden gönderim',
        'Mail, markalı tasarımla raporun özet/çalışmalar/metrik/plan bölümlerini içeriyor; iç finans verileri müşteriye gitmiyor',
        'Farklı gönderen adresleri: Ayarlar → SMTP → Ek Gönderen Adresleri — Gmail\'de "şu adres olarak gönder" yetkisi verilmiş Workspace adreslerinizden gönderim yapılabiliyor',
        'Gönderim tarihçesi raporda görünüyor (kime, ne zaman)',
    ],
    '6.5' => [
        'Çekim klasöründeki dosyalar artık tür özetiyle görünüyor (🎬 3 video · 🖼️ 12 fotoğraf) ve tek tık Drive\'da açılıyor',
        'Yeni onay akışı: klasörde dosya görülünce panel otomatik "aktarıldı" demiyor — ekibe "yüklenmesi gereken her şey yüklendi mi?" diye soruyor; onay çekim listesindeki ✔ Tümü yüklendi düğmesiyle veriliyor',
        'Onay verilmeden geçen her gün nazik bir hatırlatma, hiç dosya yoksa sert uyarı gidiyor',
        'Hata düzeltmesi: otomatik açılan klasörün linki "elle eklenen link" sayılıp çekimi kendiliğinden aktarıldı işaretliyordu — artık yalnızca gerçek insan onayı sayılıyor',
    ],
    '6.4' => [
        '@ ile kişi bahsetme (yorum, tartışma, DM) onarıldı — kişi listesi yanlış ada yazıldığı için açılır liste hiç dolmuyordu',
        'İş akışı adımları onarıldı: tamamlama/geri alma artık ekranda anında görünüyor; geri alınan adım yeniden "sıradaki adım" oluyor, sonrakiler bekliyor durumuna dönüyor',
        'Sürüm yenilik kartı artık yalnızca yöneticilere gösteriliyor',
        'Yetkiler detaylandırıldı — 5 yeni izin: finans kaydı düzenleme (görüntülemeden ayrıldı), randevu yanıtlama, çalışan havuzu yönetimi, mentörlük yönetimi, yapay zeka kullanımı. Hepsi kullanıcı bazında açılıp kapatılabilir',
    ],
    '6.3' => [
        'Sekmeler kökten onarıldı: sayfa sekmelerinde (proje, finans...) eski içerik ekranda kalıp yenisi altına açılıyordu; sağ alttaki Alanım panelinin sekmeleri hiç değişmiyordu — ikisi de düzeldi',
        'Aynı kök sorunun (çeviri kalıntısı sınıf adları) son 31 örneği tek taramada bulunup temizlendi: görev kontrol listesi işaretleme, takvim etkinlik pencereleri, form/proje şablonu düzenleme satırları, kanal üyeleri, renk seçimleri ve daha fazlası',
        'Google Gemini desteği: Ayarlar → Yapay Zeka bölümünden sağlayıcı olarak Claude veya Gemini seçilebilir; Gemini Flash için günlük kotalı ücretsiz katman kullanılabilir',
        'Çekim klasöründeki Drive dosyaları artık çekim listesinde otomatik görünüyor (ilk 6 dosya, tıklayınca Drive\'da açılır; fazlası için klasör linki)',
    ],
    '6.2' => [
        'Drive artık JSON dosyası olmadan bağlanıyor: Ayarlar → Drive kartına Client ID + Secret girip "Google ile Bağlan"a basmanız yeterli — klasörler kendi Drive hesabınızda açılır (JSON servis hesabı alternatif olarak duruyor)',
        'Çekim planlanınca Drive yükleme klasörü otomatik oluşturuluyor (müşterinin Drive klasörü altında; yoksa panel kök klasöründe) ve çekim kartına bağlanıyor',
        'Çekim listesinde gelecek çekimlerde "klasöre yükle" linki; klasörü olmayan çekimler için tek tık "Klasör oluştur" düğmesi',
        'Çekimin üstünden 24 saat geçip görüntüler Drive\'da görülmezse ekip + dosya yöneticisi uyarılıyor (bildirim + e-posta); Drive bağlıysa klasör otomatik denetlenip "aktarıldı" işaretleniyor',
        'SD kart çekimle bağlantılı: kart "Drive\'a aktarıldı" işaretlenince bağlı çekim de aktarıldı sayılıyor; çekim Drive\'da doğrulanmadan kart boşaltılırsa dosya yöneticisine uyarı gidiyor',
        'Panel genelinde başarı bildirimleri (yeşil mesajlar) görünmüyordu, düzeltildi; sohbette mesaj gönderme hatası giderildi',
    ],
    '6.1' => [
        'Kullanıcılar sayfasında arama ve rol filtresi onarıldı — ayrıca aynı sorun 10 sayfada daha vardı (dosyalar, projeler, görevler, ekipman, onaylar, talepler, arşiv, mesajlar, yetenek havuzu, yönetici takip); hepsinde arama/filtre yeniden çalışıyor',
        'Kullanıcı bazlı özel izinler artık gerçekten çalışıyor: düzenleme modalındaki izin ızgarası rol varsayılanlarını doğru gösteriyor, işaretlemeler kaydediliyor ve rol varsayılanlarını kişi bazında geçersiz kılıyor',
        'Müşteri kullanıcısının erişebileceği dosya seçimi de aynı sebepten kaydedilmiyordu; düzeltildi',
        'Kullanıcı silme eklendi: görev atamaları, üyelikler ve kişisel veriler temizlenir; yorumlar ve geçmiş kayıtlar okunabilir kalır. Kendinizi ve son aktif yöneticiyi silmek engellidir',
    ],
    '6.0.4' => [
        'Uzun (kaydırılabilir) modallarda köşe yuvarlaklığının kaybolması düzeltildi — kaydırma çubuğu dahil her şey artık yuvarlak köşeye kırpılıyor',
        'Özel seçim kutuları (Tür gibi) düz metin gibi görünüyordu; artık kenarlıklı, ok işaretli gerçek kutu görünümünde',
    ],
    '6.0.3' => [
        'Modal başlık ve Kaydet/İptal şeritleri şeffaf kalıyordu; altlarından kayan form görünüyordu — artık modalın zeminini kullanıyorlar',
        'Modal köşeleri: sabit duran başlık/alt şeritler kare zemin boyayıp yuvarlak köşeleri bozuyordu, düzeltildi (her temanın kendi köşe yarıçapına uyuyor)',
        'Modal içi kaydırma çubuğu da inceltildi; boştayken görünmüyor',
    ],
    '6.0.2' => [
        'Açılır menüler (bildirimler, profil, hızlı oluştur) artık tekrar basınca ve dışarı tıklayınca kapanıyor',
        'Üst bardaki global arama tamamen çalışır hale geldi — sonuç paneli görünmüyordu',
        'Özel açılır seçim kutuları (tarih, saat ve tüm seçim menüleri) hiçbir sayfada devreye girmiyordu; düzeltildi',
        'Proje üyeleri ve görev atananları kaydedilmiyordu — seçim kutuları forma bağlanmamıştı',
        '@bahsetme listesi, tarih seçicide seçili gün/saat işareti ve sıralama okları onarıldı',
        'Cam temalarda (Liquid Glass / Glassmorphism) açılır paneller, arama sonuçları ve modallar artık arkasını göstermiyor: opak zemin + daha güçlü bulanıklık',
        'Sidebar ve panel içi kaydırma çubukları inceltildi; boştayken görünmüyor, üzerine gelince beliriyor',
        'Ekipman sayfasındaki "Çekimde" sayacı ve proje dönem etiketleri (Açık/Kapalı) düzeltildi',
    ],
    '6.0.1' => [
        'Modal kapatma (çarpı) düğmeleri ve panel genelinde onlarca ölü düğme onarıldı (randevu onayı, puanlama, etkinlik/içerik taşıma, ekipman işlemleri, ödeme/gider durumları, adım tamamlama, kullanıcı/akış düzenleme, yorum ve tepkiler...)',
        'Bildirimler: "Okundu işaretle", bildirim silme, canlı sayaç rozeti ve mobil menü aç/kapat düzeltildi',
        'Kanban sürükle-bırak geri alma ve sıralama okları (form/akış şablonları) düzeltildi',
        'Aylık raporlar 500 hatasının kökü: şema sürümü artık her açılışta denetleniyor, eksik migration otomatik uygulanıyor',
        'Ek talepler tablosu taşınırken veri kaybına yol açabilecek sıralama hatası giderildi',
        'Güvenlik: üretimde hatalar ekrana değil storage/error.log\'a yazılır; dosya yüklemede gerçek içerik (MIME) denetimi; şifre en az 8 karakter; e-posta alıcı doğrulaması; HSTS ve Permissions-Policy başlıkları',
    ],
    '6.0' => [
        'Google Drive takibi: çekim görüntüleri Drive\'a aktarılmadıysa panel uyarır; servis hesabı kurulunca klasörü kendisi denetleyip otomatik işaretler',
        'Dosyalara ve çekimlere Drive klasörü bağlanabiliyor; çekim listesinde "Aktarıldı/Aktarılmadı" durumu',
        'E-posta zinciri: son tarihi yaklaşan/geciken görev mailleri + yöneticilere her sabah günlük özet maili',
        'Yapay zeka (Claude): aylık rapor taslağını tek tıkla doldurma, fikir panosunda AI ile fikir üretme, görev/tartışma özetleme',
        'Panel artık telefona kurulabilir uygulama (PWA): ana ekrana ekleyin, tam ekran çalışır',
        'Aylık raporda dosya bazlı otomatik finans özeti; çekim maliyeti girilince Finans\'a otomatik gider kaydı',
    ],
    '5.0' => [
        'Takılma sorunu çözüldü: oturum kilidi artık anında bırakılıyor — çok sekmede sayfalar birbirini beklemiyor',
        'Kişisel sayfaların CDN/proxy önbelleğine takılması engellendi',
        'Güvenlik: giriş formuna CSRF doğrulaması, detay pencerelerinde XSS kaçışları',
        'Kod tabanı tamamen İngilizce: dosya adları, veritabanı şeması, değişkenler, yorumlar (arayüz Türkçe)',
        'Eski kurulumlar kendini onarır: ilk açılışta şema otomatik taşınır, eski linkler yönlenir',
        '5 yeni ferah tema: Liquid Glass, Glassmorphism (koyu/aydınlık) ve Claymorphism',
        'Koyu temalar için ayrı logo ve favicon yüklenebiliyor',
    ],
    '4.0' => [
        'Panel artık SADA One — yeni kimlik her yerde',
        'Panel içi güncelleme sistemi: ZIP yükle veya GitHub\'dan tek tıkla kur (otomatik yedekli)',
        'Proje İstasyonu: SOP künyesi, bütçe & ek talepler (izinli), teknik kontrol listesi, post-mortem değerlendirme',
        'Gelişim & Mentörlük programı: üye-mentör eşleşmeleri ve çıktı takibi',
        'Çalışan Havuzu: yetkinlik + CV arşivi; Fikir Panosu: ekipten içerik fikirleri',
        'Aylık müşteri raporları panelde dolduruluyor',
        'Yönetici Takip: tüm görevler + kişiye özel yönetici not kolonları',
        'Çekim Listesi: kim gidiyor, hangi ekipman, alınacaklar, ihtiyaçlar',
        'Anlık gezinme (ön-yükleme), gezinme çubuğu ve canlı bildirim sayacı',
    ],
    '3.3' => [
        'Kanban sürüklemesi yenilendi: eğik süzülen kart, kesikli yuva izi, yumuşak oturma animasyonu',
        'Görsel ağırlık azaltıldı: gölgeler, parıltılar ve modal perdesi hafifledi',
        'Modal, seçici ve bildirimlere zarif yay animasyonları eklendi',
        'Geçişler kısaltıldı, sayfalar arası akıcı geçiş (Chrome/Edge) eklendi',
        'Kanban panosu bir tık sıkılaştı — ekrana daha çok kart sığıyor',
    ],
    '3.2' => [
        'Bildirimler tek tek veya tümüyle silinebiliyor',
        'Adımlarım bölümleri katlanabilir; Profil\'den "yalnızca sorumlusu olduğum adımlar" seçilebiliyor',
        'Saat seçicide artık istediğin dakikayı yazabilirsin',
        'Özel tarih/saat seçici tüm dinamik pencerelere uygulandı',
        'Üst üste binen kontroller (görev durumu, Drive/dosya butonları) düzeltildi',
        'SMTP kurulum rehberi Google Workspace adımlarıyla güncellendi',
    ],
    '3.1' => [
        'Ekranın sağ altında kişisel dock: yapılacaklar, karalama, notlar ve yer imleri her sayfadan bir tık uzakta',
        'Sidebar altında Hızlı Oluştur: görev, etkinlik, toplantı, içerik ve not kısayolları (temalar Profil sayfasına taşındı)',
        'Görev teslimi ve onaylarda Drive linki bırakılabiliyor',
        'Klasik Açık ve Klasik Koyu temalar eklendi',
        'Adımlarım artık sorumlusuz aktif adımları ve sıradaki adımları da gösteriyor',
        'Talep formlarına dosya yükleme alanı eklenebiliyor',
        'Genel tasarım ferahlatıldı; her pazartesi yöneticilere haftalık özet gidiyor',
    ],
    '3.0' => [
        'İçerikler artık göreve bağlanabiliyor — görev bitince içerik onaylanır, içerik yayınlanınca görev tamamlanır',
        'Takvimlerde içerik ve etkinlikler sürüklenerek başka güne taşınabiliyor',
        'Akış adımı sorumluları: aktif adımların panelde ve Görevler sayfasında "Adımlarım" olarak görünüyor',
        'Yetki sistemi 15 anahtara genişledi — kullanıcı bazında ince ayar',
        'Proje şablonları: hazır görev setiyle tek tıkla proje kurulumu',
        'Dosya bilgi bankası: marka rehberi ve süreç notları',
        'Tarayıcı önbelleği sorunu giderildi (özel seçiciler her tarayıcıda etkin)',
    ],
    '2.9' => [
        'Tüm arayüz emojileri profesyonel çizgi ikonlarla değiştirildi',
        'Tarih, saat ve açılır listeler artık panelin kendi tasarımında (tarayıcı varsayılanı yok)',
        'Finans: numaralı Teklif & Fatura belgeleri — yazdırılabilir, onaylanan teklif gelire dönüşür',
        'Finans: 3 aylık nakit akış projeksiyonu ve aylık gelir hedefi takibi',
        'Finans: dosya bazlı Cari Hesap ve yazdırılabilir ekstre',
        'İçerikler dosyaya bağlandı, çoklu platform seçimi ve sosyal medya takipçi takibi (v2.8)',
    ],
];
const RANDEVU_DURUMLARI = ['bekliyor' => 'Bekliyor', 'onaylandi' => 'Onaylandı', 'alternative' => 'Farklı Saat Önerildi', 'reddedildi' => 'Reddedildi'];

/* ---------------- Central SVG icon library (monochrome line) ---------------- */
const ICONS = [
    // Social platforms
    'instagram' => 'M7 3h10a4 4 0 014 4v10a4 4 0 01-4 4H7a4 4 0 01-4-4V7a4 4 0 014-4zm5 5.5a3.5 3.5 0 100 7 3.5 3.5 0 000-7zM17.2 6.8h.01',
    'facebook'  => 'M15 3h-2.5A3.5 3.5 0 009 6.5V9H6v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3V3z',
    'x'         => 'M4 4l7.2 9.4L4.4 20h2.4l5.5-5.5L16.8 20H20l-7.5-9.8L18.9 4h-2.4l-4.9 5L8 4H4z',
    'linkedin'  => 'M6.5 9v11M6.5 4.5v.01M11 20v-6a3 3 0 016 0v6M11 9v11',
    'youtube'   => 'M21 8a3 3 0 00-2-2c-2-.5-7-.5-7-.5s-5 0-7 .5a3 3 0 00-2 2 30 30 0 000 8 3 3 0 002 2c2 .5 7 .5 7 .5s5 0 7-.5a3 3 0 002-2 30 30 0 000-8zM10 9.5l5 2.5-5 2.5v-5z',
    'tiktok'    => 'M14 4v9.5a3.5 3.5 0 11-3.5-3.5M14 4a5 5 0 005 5',
    'web'       => 'M12 21a9 9 0 100-18 9 9 0 000 18zM3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z',
    'diger'     => 'M12 8v8m-4-4h8M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    // Equipment categories
    'kamera'    => 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z',
    'lens'      => 'M12 19a7 7 0 100-14 7 7 0 000 14zm0-3.5a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM19 5l1.5-1.5',
    'sd_kart'   => 'M8 3h9a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V7l3-4zM9 7v3m3-3v3m3-3v3',
    'tripod'    => 'M9 4h6v4H9zM12 8v4m0 0l-5 8m5-8l5 8m-5-8v8',
    'isik'      => 'M9 18h6M10 21h4M12 3a6 6 0 00-4 10.5c.7.6 1 1.5 1 2.5h6c0-1 .3-1.9 1-2.5A6 6 0 0012 3z',
    'ses'       => 'M12 15a3 3 0 003-3V6a3 3 0 10-6 0v6a3 3 0 003 3zm-7-3a7 7 0 0014 0M12 19v3',
    'drone'     => 'M4 6a2 2 0 104 0 2 2 0 10-4 0zm12 0a2 2 0 104 0 2 2 0 10-4 0zM4 18a2 2 0 104 0 2 2 0 10-4 0zm12 0a2 2 0 104 0 2 2 0 10-4 0zM7.5 7.5l3 3m6-3l-3 3m-6 6l3-3m6 3l-3-3m-3 3v-3a3 3 0 013-3',
    'aksesuar'  => 'M6 7h12l1 4H5l1-4zm-1 4v8a1 1 0 001 1h12a1 1 0 001-1v-8M12 7V4',
    // General UI
    'archive'     => 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
    'megafon'   => 'M11 5.88V19.24a1.76 1.76 0 01-3.42.6L5.44 14M18.7 4a9 9 0 01.3 13.3M5.44 14A2 2 0 015 10h1a8 8 0 005-2l3-2v12l-3-2a8 8 0 00-5-2H5.44z',
    'pin'       => 'M12 21s-7-5.5-7-11a7 7 0 1114 0c0 5.5-7 11-7 11zm0-8.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
    'takvim'    => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    'time'      => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    'lock'     => 'M7 11V7a5 5 0 0110 0v4M5 11h14v9a1 1 0 01-1 1H6a1 1 0 01-1-1v-9z',
    'lock-open' => 'M7 11V7a5 5 0 019.5-2M5 11h14v9a1 1 0 01-1 1H6a1 1 0 01-1-1v-9z',
    'repeat'    => 'M17 2l4 4-4 4M3 11V9a4 4 0 014-4h14M7 22l-4-4 4-4m14-3v2a4 4 0 01-4 4H3',
    'atac'      => 'M21.4 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.2-9.19a4 4 0 015.65 5.66l-9.2 9.19a2 2 0 01-2.82-2.83l8.49-8.48',
    'klasor'    => 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
    'video'     => 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z',
    'person'      => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    'people'   => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3',
    'sohbet'    => 'M8 12h8m-8-4h8m-9 8l-4 4V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H7z',
    'el-sikisma' => 'M11 17l-1.5 1.5a2 2 0 01-3-3L8 14m3 3l2 2a2 2 0 003-3l-.5-.5M11 17l3-3m-6 0L5.5 11.5a2 2 0 010-3L8 6l4 1 3.5-1.5a2 2 0 012.5.5L21 9l-3 5.5M8 14l3-3',
    'item'     => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z',
    'cop'       => 'M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3',
    'box'      => 'M21 8l-9-5-9 5m18 0l-9 5m9-5v8l-9 5m0-8L3 8m9 5v8m-9-13v8l9 5',
    'onay'      => 'M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    'grafik'    => 'M9 19v-6M15 19v-2M12 19v-9M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z',
    'star'    => 'M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2z',
    'gunes'     => 'M12 17a5 5 0 100-10 5 5 0 000 10zm0-15v2m0 16v2M4.2 4.2l1.4 1.4m12.8 12.8l1.4 1.4M2 12h2m16 0h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
    'roket'     => 'M4.5 16.5c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2 0-2.8-.8-.7-2-.7-3 0zM12 15l-3-3a22 22 0 012-4c3.2-3.2 7-4.5 10-4 .5 3-1 6.8-4 10a22 22 0 01-4 2l-1-1zM9 12H4s.5-3.5 2-5c1.7-1.7 5 0 5 0m1 8v5s3.5-.5 5-2c1.7-1.7 0-5 0-5M15 9h.01',
    'warning'     => 'M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z',
    'money'      => 'M12 8c-2.21 0-4 .9-4 2s1.79 2 4 2 4 .9 4 2-1.79 2-4 2m0-8c1.66 0 3.07.5 3.6 1.2M12 8V6m0 12v-2m0 2c-1.66 0-3.07-.5-3.6-1.2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    'document'     => 'M9 17h6M9 13h6M9 9h1m4 12H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6a1 1 0 01.7.3l5.4 5.4a1 1 0 01.3.7V19a2 2 0 01-2 2z',
];

/** Renders a monochrome line SVG icon (currentColor — inherits the surrounding text color) */
function icon(string $name, int $size = 16, string $stil = ''): string {
    $path = ICONS[$name] ?? ICONS['diger'];
    return '<svg class="ikon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"' . ($stil ? ' style="' . $stil . '"' : '') . '><path d="' . $path . '"/></svg>';
}

/** Converts CSV-stored multi-platform values into icon badges */
function platform_badges(?string $csv, bool $onlyIcon = false): string {
    if (!$csv) return '';
    $h = '';
    foreach (array_filter(array_map('trim', explode(',', $csv))) as $pl) {
        $tag = PLATFORMS[$pl] ?? $pl;
        $svg = icon(isset(ICONS[$pl]) ? $pl : 'diger', $onlyIcon ? 13 : 13);
        $h .= $onlyIcon
            ? '<span class="p-ikon" title="' . e($tag) . '">' . $svg . '</span>'
            : '<span class="rozet" style="padding:2px 8px;gap:5px">' . $svg . ' ' . e($tag) . '</span> ';
    }
    return $h;
}

/** Renders a 1-5 star visual */
function stars(float $rating, int $size = 14): string {
    $h = '<span class="yildizlar" style="font-size:' . $size . 'px">';
    for ($i = 1; $i <= 5; $i++) $h .= '<span style="opacity:' . ($i <= round($rating) ? '1' : '.25') . '">★</span>';
    return $h . '</span>';
}

/* Equipment module constants */
const EQUIPMENT_CATEGORIES = ['kamera' => 'Kamera', 'lens' => 'Lens', 'sd_kart' => 'SD Kart', 'tripod' => 'Tripod', 'isik' => 'Işık', 'ses' => 'Ses', 'drone' => 'Drone', 'aksesuar' => 'Aksesuar', 'diger' => 'Diğer'];
const EKIPMAN_DURUMLARI = ['studyoda' => 'Stüdyoda', 'zimmette' => 'Zimmette', 'cekimde' => 'Çekimde', 'arizali' => 'Arızalı', 'bakimda' => 'Bakımda'];
const SD_DURUMLARI = ['bos' => 'Boş / Hazır', 'dolu' => 'Dolu', 'aktarildi' => "Drive'a Aktarıldı"];
const EKIPMAN_HAREKET_TURLERI = [
    'eklendi' => 'envantere eklendi', 'custody' => 'zimmet verildi', 'return' => 'iade edildi',
    'shoot_output' => 'çekime çıktı', 'cekimden_dondu' => 'çekimden döndü',
    'sd_full' => 'dolu işaretlendi', 'sd_aktarildi' => "Drive'a aktarıldı", 'sd_bosaltildi' => 'boşaltıldı',
    'fault' => 'arızalı işaretlendi', 'bakim' => 'bakıma alındı', 'duzeltildi' => 'kullanıma döndü',
];

/** Records an equipment movement log entry */
function log_equipment(int $equipmentId, string $type, string $description = '', ?int $targetUserId = null, ?int $eventId = null): void {
    insert('equipment_logs', [
        'equipment_id' => $equipmentId, 'user_id' => (int)(user()['id'] ?? 0),
        'target_user_id' => $targetUserId, 'event_id' => $eventId,
        'type' => $type, 'description' => $description, 'created' => date('Y-m-d H:i:s'),
    ]);
}

/* ---------------- Theme-aware branding ---------------- */

/** The theme the current visitor sees (user preference or site default). */
function active_theme(): string {
    $u = user();
    $theme = $u['theme'] ?? '';
    return isset(THEMES[$theme]) ? $theme : setting('varsayilan_tema', 'lime');
}
/** Is the active theme a dark one? */
function theme_is_dark(): bool {
    return (bool)(THEMES[active_theme()][2] ?? true);
}
/** Logo path for the active theme: dark themes prefer the dark logo when set. */
function theme_logo(): string {
    if (theme_is_dark() && setting('site_logo_dark')) return setting('site_logo_dark');
    return setting('site_logo');
}
/** Favicon path for the active theme. */
function theme_favicon(): string {
    if (theme_is_dark() && setting('site_favicon_dark')) return setting('site_favicon_dark');
    return setting('site_favicon');
}

/* Themes: key => [Label, accent color, is dark] */
const THEMES = [
    'lime'         => ['Lime', '#b1fb01', true],
    'lime-light'   => ['Lime Aydınlık', '#76a900', false],
    'navy'         => ['Lacivert', '#2f5fb5', true],
    'navy-light'   => ['Lacivert Aydınlık', '#182f5d', false],
    'cream'        => ['Krem', '#b8892b', false],
    'maroon'       => ['Bordo', '#d64560', true],
    'maroon-light' => ['Bordo Aydınlık', '#610714', false],
    'gece'         => ['Gece', '#f8f2cb', true],
    'koyu'         => ['Klasik Koyu', '#60a5fa', true],
    'acik'         => ['Klasik Açık', '#2563eb', false],
    // v5.0 airy styles: liquid glass / glassmorphism / claymorphism
    'liquid-glass'       => ['Liquid Glass', '#8ad8ff', true],
    'liquid-glass-light' => ['Liquid Glass Aydınlık', '#0284c7', false],
    'glass'              => ['Glassmorphism', '#c4b5fd', true],
    'glass-light'        => ['Glassmorphism Aydınlık', '#7c3aed', false],
    'clay'               => ['Claymorphism', '#2563eb', false],
];

/* Notification categories (subject to user preference) */
const NOTIFICATION_CATEGORIES = [
    'gorev' => 'Görev atama ve durum değişiklikleri',
    'onay' => 'Onay talepleri ve yanıtları',
    'talep' => 'Yeni talepler',
    'mesaj' => 'Mesajlar',
];

function notification_pref(array $alici, string $category): array {
    // Returns: [panel_notification_on, email_on]
    $t = json_decode($alici['notification_preferences'] ?? '', true);
    if (!is_array($t)) return [true, true]; // default: everything on
    $panel = !isset($t[$category]) || (bool)$t[$category];
    $email = !isset($t['email']) || (bool)$t['email'];
    return [$panel, $email];
}

function badge(string $setting_value, array $sozluk, string $sinifOn = ''): string {
    $tag = $sozluk[$setting_value] ?? $setting_value;
    return '<span class="rozet r-' . ($sinifOn ? $sinifOn . '-' : '') . e($setting_value) . '">' . e($tag) . '</span>';
}

/* ---------------- Notifications & Activity ---------------- */

function notify(int $userId, string $title, string $message = '', string $link = '', string $category = 'gorev', bool $email = true): void {
    if ($userId === (int)(user()['id'] ?? 0)) return; // no self-notifications
    $alici = row("SELECT * FROM users WHERE id=? AND is_active=1", [$userId]);
    if (!$alici) return;
    [$panelOpen, $emailOpen] = notification_pref($alici, $category);
    if (!$panelOpen) return; // the user has turned this category off
    insert('notifications', [
        'user_id' => $userId, 'title' => $title, 'message' => $message,
        'link' => $link, 'is_read' => 0, 'created' => date('Y-m-d H:i:s'),
    ]);
    if ($email && $emailOpen && setting('eposta_bildirim') === '1' && setting('smtp_aktif') === '1') {
        require_once __DIR__ . '/mailer.php';
        send_email($alici['email'], $title, $message . ($link ? "\n\nGörüntüle: " . full_url($link) : ''));
    }
}

function full_url(string $path): string {
    $protokol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $protokol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . '/' . ltrim($path, '/');
}

/**
 * The most recent shoot (last 30 days) an SD card was checked out to whose
 * footage has NOT been confirmed in Drive yet — the hook for the SD warnings.
 */
function sd_last_shoot(int $equipmentId): ?array {
    $r = row("SELECT e.id, e.title, e.created_by, c.manager_id
        FROM event_equipment ee JOIN events e ON e.id=ee.event_id
        LEFT JOIN clients c ON c.id = COALESCE(e.client_id, (SELECT client_id FROM projects WHERE id=e.project_id))
        WHERE ee.equipment_id=? AND e.type='cekim' AND e.drive_status='bekliyor'
        AND e.start > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY e.start DESC LIMIT 1", [$equipmentId]);
    return $r ?: null;
}

function log_activity(string $description, ?string $refType = null, ?int $refId = null): void {
    insert('activities', [
        'user_id' => (int)(user()['id'] ?? 0), 'ref_type' => $refType, 'ref_id' => $refId,
        'description' => $description, 'created' => date('Y-m-d H:i:s'),
    ]);
}

/* ---------------- File upload ---------------- */

function file_upload(string $field): ?array {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $f = $_FILES[$field];
    if ($f['size'] > 50 * 1024 * 1024) return null; // 50 MB limit
    $extension = mb_strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    // Security: allow only known-safe types (whitelist)
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'zip', 'rar', '7z', 'mp4', 'mov', 'avi', 'mp3', 'wav', 'aac', 'psd', 'ai', 'indd', 'srt', 'otf', 'ttf'];
    if (!in_array($extension, $allowed)) return null;
    // Security: verify the actual content, not just the extension
    if (function_exists('finfo_open')) {
        $mime = (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']);
        // Never accept anything the server could interpret as script/markup
        if (preg_match('~php|x-httpd|/html|xhtml|^text/xml|^application/xml|javascript~i', $mime)) return null;
        // Image extensions must really contain image data
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']) && !str_starts_with($mime, 'image/')) return null;
    }
    $newName = date('Ym') . '/' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetKlasor = ROOT . '/uploads/' . date('Ym');
    if (!is_dir($targetKlasor)) mkdir($targetKlasor, 0755, true);
    if (!move_uploaded_file($f['tmp_name'], ROOT . '/uploads/' . $newName)) return null;
    return ['path' => $newName, 'name' => $f['name'], 'size' => $f['size'], 'extension' => $extension];
}

function format_size(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}

/* ---------------- Period helpers ---------------- */

function period_name(array $d): string { return MONTHS[(int)$d['month']] . ' ' . $d['year']; }

function get_or_create_period(int $projectId, int $year, int $month): int {
    $d = row("SELECT id FROM periods WHERE project_id=? AND year=? AND month=?", [$projectId, $year, $month]);
    if ($d) return (int)$d['id'];
    return insert('periods', ['project_id' => $projectId, 'year' => $year, 'month' => $month, 'status' => 'acik', 'created' => date('Y-m-d H:i:s')]);
}

/* ---------------- Mentions (@mention) & task tags ---------------- */

/** Highlights @First Last mentions in text (matched against active user names, longest name first) */
function highlight_mentions(string $kacisliText): string {
    static $names = null;
    if ($names === null) {
        $names = array_column(rows("SELECT name FROM users WHERE is_active=1 ORDER BY CHAR_LENGTH(name) DESC"), 'name');
    }
    foreach ($names as $name) {
        $kacisli = e($name); // the text is already escaped with e()
        $kacisliText = str_ireplace('@' . $kacisli, '<span class="mention">@' . $kacisli . '</span>', $kacisliText);
    }
    return $kacisliText;
}

/** Converts comma-separated task tags into colored chips */
function tag_chips(?string $tags, string $ekSinif = ''): string {
    if (!$tags) return '';
    $h = '';
    foreach (array_filter(array_map('trim', explode(',', $tags))) as $et) {
        $ton = crc32(mb_strtolower($et)) % 360; // stable color per tag
        $h .= '<span class="etiket-cip ' . $ekSinif . '" style="--cip-ton:' . $ton . '">' . e($et) . '</span>';
    }
    return $h;
}

/** Notifies mentioned user ids (expects a JSON array) */
function notify_mentions(string $tagsJson, string $title, string $message, string $link): void {
    $ids = json_decode($tagsJson, true);
    if (!is_array($ids)) return;
    foreach (array_unique(array_map('intval', $ids)) as $uid) {
        if ($uid > 0) notify($uid, $title, $message, $link, 'mesaj');
    }
}

/* ---------------- Recurring task automation ----------------
 * Requires no cron setup on the server: triggered hourly during page loads.
 * Optionally, /cron.php can also be wired to a real cron job.
 */
function run_recurring_jobs(bool $force = false): int {
    $last = (int)val("SELECT setting_value FROM settings WHERE setting_key='son_tekrar_kontrol'");
    if (!$force && time() - $last < 3600) return 0;
    q("INSERT INTO settings (setting_key, setting_value) VALUES ('son_tekrar_kontrol', ?) ON DUPLICATE KEY UPDATE setting_value=?", [time(), time()]);

    $count = 0;
    foreach (rows("SELECT * FROM tasks WHERE `repeat`!='yok'") as $g) {
        $periodKey = $g['repeat'] === 'haftalik' ? date('o-W') : date('Y-m');
        if ($g['last_repeat'] === $periodKey) continue;
        if ($g['last_repeat'] === null) {
            // First period: the task itself is already this period's work — just stamp it
            update_row('tasks', ['last_repeat' => $periodKey], 'id=?', [$g['id']]);
            continue;
        }
        // A new period has started: create a fresh copy from the template task
        $newLastDate = $g['repeat'] === 'haftalik' ? date('Y-m-d', strtotime('sunday this week')) : date('Y-m-t');
        $periodId = null;
        $projectType = val("SELECT type FROM projects WHERE id=?", [$g['project_id']]);
        if ($projectType === 'aylik') $periodId = get_or_create_period((int)$g['project_id'], (int)date('Y'), (int)date('n'));
        $newId = insert('tasks', [
            'project_id' => $g['project_id'], 'period_id' => $periodId,
            'title' => $g['title'],
            'description' => $g['description'],
            'assignee_id' => $g['assignee_id'], 'created_by' => $g['created_by'],
            'priority' => $g['priority'], 'status' => 'yapilacak',
            'due_date' => $newLastDate, 'repeat' => 'yok',
            'created' => date('Y-m-d H:i:s'),
        ]);
        // Copy the workflow steps in a reset state
        $steps = rows("SELECT * FROM task_steps WHERE task_id=? ORDER BY sort_order", [$g['id']]);
        foreach ($steps as $i => $a) {
            insert('task_steps', [
                'task_id' => $newId, 'sort_order' => $a['sort_order'], 'name' => $a['name'],
                'owner_id' => $a['owner_id'], 'status' => $i === 0 ? 'aktif' : 'bekliyor',
            ]);
        }
        // Copy the checklist in a reset state
        foreach (rows("SELECT * FROM task_checklist WHERE task_id=? ORDER BY sort_order", [$g['id']]) as $k) {
            insert('task_checklist', ['task_id' => $newId, 'name' => $k['name'], 'is_done' => 0, 'sort_order' => $k['sort_order']]);
        }
        update_row('tasks', ['last_repeat' => $periodKey], 'id=?', [$g['id']]);
        if ($g['assignee_id']) notify((int)$g['assignee_id'], 'Tekrarlayan görev oluşturuldu', $g['title'], 'task.php?id=' . $newId, 'gorev');
        $count++;
    }

    /* --- Monthly salary expenses: auto-created at the start of each month --- */
    $buMonth = date('Y-m');
    foreach (rows("SELECT id, name, salary FROM users WHERE salary>0 AND is_active=1") as $person) {
        $var = val("SELECT COUNT(*) FROM expenses WHERE type='maas' AND user_id=? AND last_repeat=?", [$person['id'], $buMonth]);
        if (!$var) {
            insert('expenses', [
                'type' => 'maas', 'title' => $person['name'] . ' — ' . MONTHS[(int)date('n')] . ' maaşı',
                'amount' => $person['salary'], 'date' => date('Y-m-01'), 'status' => 'bekliyor',
                'repeat' => 'yok', 'last_repeat' => $buMonth, 'user_id' => $person['id'], 'created' => date('Y-m-d H:i:s'),
            ]);
        }
    }
    /* --- Monthly recurring expenses (rent, subscriptions, etc.) --- */
    foreach (rows("SELECT * FROM expenses WHERE `repeat`='aylik'") as $gd) {
        if ($gd['last_repeat'] === $buMonth) continue;
        if ($gd['last_repeat'] === null) { update_row('expenses', ['last_repeat' => $buMonth], 'id=?', [$gd['id']]); continue; }
        insert('expenses', [
            'type' => $gd['type'], 'title' => $gd['title'], 'amount' => $gd['amount'],
            'date' => date('Y-m-01'), 'status' => 'bekliyor', 'repeat' => 'yok',
            'last_repeat' => $buMonth, 'user_id' => $gd['user_id'], 'description' => $gd['description'],
            'created' => date('Y-m-d H:i:s'),
        ]);
        update_row('expenses', ['last_repeat' => $buMonth], 'id=?', [$gd['id']]);
    }

    /* --- Meeting reminder: notify participants ~1 hour ahead --- */
    $upcomingMeetings = rows("SELECT * FROM events WHERE type='toplanti' AND is_reminded=0
        AND start BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 75 MINUTE)");
    foreach ($upcomingMeetings as $top) {
        $time = date('H:i', strtotime($top['start']));
        $messageText = $time . ' — ' . ($top['place'] ?: '') . ($top['online_link'] ? ' (online)' : '');
        $alicilar = array_column(rows("SELECT user_id FROM event_participants WHERE event_id=?", [$top['id']]), 'user_id');
        $alicilar[] = (int)$top['created_by'];
        foreach (array_unique($alicilar) as $aid) {
            notify((int)$aid, '⏰ Toplantı yaklaşıyor: ' . $top['title'], $messageText, 'meetings.php', 'gorev');
        }
        update_row('events', ['is_reminded' => 1], 'id=?', [$top['id']]);
    }

    /* --- Daily digest: once a day per user — "what awaits you today" --- */
    $lastSummary = val("SELECT setting_value FROM settings WHERE setting_key='son_gunluk_ozet'");
    if ($lastSummary !== date('Y-m-d')) {
        q("INSERT INTO settings (setting_key, setting_value) VALUES ('son_gunluk_ozet', ?) ON DUPLICATE KEY UPDATE setting_value=?", [date('Y-m-d'), date('Y-m-d')]);
        $today = date('Y-m-d');
        foreach (rows("SELECT id FROM users WHERE is_active=1 AND role!='musteri'") as $person) {
            $kid = (int)$person['id'];
            $parcalar = [];
            $taskCount = (int)val("SELECT COUNT(*) FROM tasks g WHERE g.is_archived=0 AND g.status!='tamamlandi' AND g.due_date=?
                AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees ga WHERE ga.task_id=g.id AND ga.user_id=?))", [$today, $kid, $kid]);
            if ($taskCount) $parcalar[] = $taskCount . ' görev teslimi';
            $topCount = (int)val("SELECT COUNT(*) FROM events e WHERE e.type='toplanti' AND DATE(e.start)=?
                AND (e.created_by=? OR EXISTS(SELECT 1 FROM event_participants ek WHERE ek.event_id=e.id AND ek.user_id=?))", [$today, $kid, $kid]);
            if ($topCount) $parcalar[] = $topCount . ' toplantı';
            $shootCount = (int)val("SELECT COUNT(*) FROM events WHERE type!='toplanti' AND DATE(start)<=? AND DATE(COALESCE(`end`,start))>=?", [$today, $today]);
            if ($shootCount) $parcalar[] = $shootCount . ' etkinlik';
            $contentCount = (int)val("SELECT COUNT(*) FROM contents WHERE date=? AND status NOT IN ('yayinlandi')", [$today]);
            if ($contentCount) $parcalar[] = $contentCount . ' içerik yayını';
            if ($parcalar) {
                notify($kid, '🌅 Bugün seni bekleyenler', implode(' · ', $parcalar), 'index.php', 'gorev', false);
            }
        }
    }

    /* --- Weekly manager digest: once every Monday --- */
    $buWeek = date('o-W');
    if (date('N') == 1 && val("SELECT setting_value FROM settings WHERE setting_key='son_haftalik_ozet'") !== $buWeek) {
        q("INSERT INTO settings (setting_key, setting_value) VALUES ('son_haftalik_ozet', ?) ON DUPLICATE KEY UPDATE setting_value=?", [$buWeek, $buWeek]);
        $hb = date('Y-m-d', strtotime('-7 days'));
        $summary = [];
        $t1 = (int)val("SELECT COUNT(*) FROM tasks WHERE status='tamamlandi' AND completion>=?", [$hb]);
        if ($t1) $summary[] = $t1 . ' görev tamamlandı';
        $t2 = (int)val("SELECT COUNT(*) FROM tasks WHERE is_archived=0 AND status!='tamamlandi' AND due_date<CURDATE()");
        if ($t2) $summary[] = $t2 . ' görev gecikmede';
        $t3 = (int)val("SELECT COUNT(*) FROM requests WHERE created>=?", [$hb]);
        if ($t3) $summary[] = $t3 . ' yeni talep';
        $t4 = val("SELECT ROUND(AVG(rating),1) FROM ratings WHERE created>=?", [$hb]);
        if ($t4) $summary[] = 'ort. puan ' . $t4 . '★';
        $t5 = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='odendi' AND date>=?", [$hb]);
        $t6 = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='odendi' AND date>=?", [$hb]);
        if ($t5 || $t6) $summary[] = 'gelir ' . money($t5) . ' / gider ' . money($t6);
        if ($summary) {
            foreach (rows("SELECT id FROM users WHERE role IN ('yonetici','pm') AND is_active=1") as $yo) {
                notify((int)$yo['id'], '📅 Haftalık özet', implode(' · ', $summary), 'reports.php', 'gorev');
            }
        }
    }

    /* --- Contract expiry reminder (30 days ahead, once) --- */
    foreach (rows("SELECT s.*, d.name client_name FROM contracts s JOIN clients d ON d.id=s.client_id WHERE s.is_reminded=0 AND s.end IS NOT NULL AND s.end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND s.end >= CURDATE()") as $sz) {
        foreach (rows("SELECT id FROM users WHERE role IN ('yonetici','pm') AND is_active=1") as $ya) {
            notify((int)$ya['id'], '⏰ Sözleşme bitiyor: ' . $sz['client_name'], '"' . $sz['title'] . '" sözleşmesi ' . format_date($sz['end']) . ' tarihinde sona eriyor.', 'client.php?id=' . $sz['client_id'], 'gorev');
        }
        update_row('contracts', ['is_reminded' => 1], 'id=?', [$sz['id']]);
    }

    require_once __DIR__ . '/mailer.php'; // due/digest/drive mails below need it

    /* --- Task due-date chain: notification + e-mail (max once per day) --- */
    if (val("SELECT setting_value FROM settings WHERE setting_key='last_due_check'") !== date('Y-m-d')) {
        q("INSERT INTO settings (setting_key, setting_value) VALUES ('last_due_check', ?) ON DUPLICATE KEY UPDATE setting_value=?", [date('Y-m-d'), date('Y-m-d')]);
        $dueTasks = rows("SELECT g.id, g.title, g.due_date, g.assignee_id,
            (SELECT GROUP_CONCAT(ga.user_id) FROM task_assignees ga WHERE ga.task_id=g.id) assignee_ids
            FROM tasks g WHERE g.is_archived=0 AND g.status!='tamamlandi' AND g.due_date IS NOT NULL
            AND g.due_date <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)");
        foreach ($dueTasks as $dt) {
            $who = array_filter(array_unique(array_merge([(int)$dt['assignee_id']], array_map('intval', explode(',', (string)$dt['assignee_ids'])))));
            $overdue = $dt['due_date'] < date('Y-m-d');
            foreach ($who as $uid) {
                $title = $overdue ? '🔴 Görev gecikti: ' . $dt['title'] : '⏳ Son gün yarın: ' . $dt['title'];
                $body = $overdue
                    ? 'Son tarihi ' . format_date($dt['due_date']) . ' olan görev hâlâ tamamlanmadı.'
                    : 'Görevin son tarihi yarın (' . format_date($dt['due_date']) . ').';
                notify($uid, $title, $body, 'task.php?id=' . $dt['id'], 'gorev');
                $mail = val("SELECT email FROM users WHERE id=? AND is_active=1", [$uid]);
                if ($mail) send_email($mail, $title, $body . "\n\nGörev: " . full_url('task.php?id=' . $dt['id']));
            }
        }
    }

    /* --- Daily manager digest e-mail (first run of the day after 07:00) --- */
    if ((int)date('G') >= 7 && val("SELECT setting_value FROM settings WHERE setting_key='last_daily_digest'") !== date('Y-m-d')) {
        q("INSERT INTO settings (setting_key, setting_value) VALUES ('last_daily_digest', ?) ON DUPLICATE KEY UPDATE setting_value=?", [date('Y-m-d'), date('Y-m-d')]);
        $overdueList = rows("SELECT g.title, g.due_date, u.name FROM tasks g LEFT JOIN users u ON u.id=g.assignee_id WHERE g.is_archived=0 AND g.status!='tamamlandi' AND g.due_date < CURDATE() ORDER BY g.due_date LIMIT 15");
        $todayShoots = rows("SELECT title, start FROM events WHERE type='cekim' AND DATE(start)=CURDATE()");
        $pendingApprovals = (int)val("SELECT COUNT(*) FROM approvals WHERE status='bekliyor'");
        $missingDrive = (int)val("SELECT COUNT(*) FROM events WHERE type='cekim' AND drive_status='bekliyor' AND COALESCE(`end`, start) < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND start > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        if ($overdueList || $todayShoots || $pendingApprovals || $missingDrive) {
            $ozet = "Günaydın! " . date('d.m.Y') . " özeti:\n\n";
            if ($overdueList) { $ozet .= "GECİKEN GÖREVLER (" . count($overdueList) . "):\n"; foreach ($overdueList as $o2) $ozet .= "- " . $o2['title'] . ' (' . ($o2['name'] ?: 'atanmamış') . ', son: ' . format_date($o2['due_date']) . ")\n"; $ozet .= "\n"; }
            if ($todayShoots) { $ozet .= "BUGÜNÜN ÇEKİMLERİ:\n"; foreach ($todayShoots as $s2) $ozet .= "- " . $s2['title'] . ' (' . substr($s2['start'], 11, 5) . ")\n"; $ozet .= "\n"; }
            if ($pendingApprovals) $ozet .= "Bekleyen onay: $pendingApprovals\n";
            if ($missingDrive) $ozet .= "Drive'a aktarılmamış çekim: $missingDrive\n";
            $ozet .= "\nPanel: " . full_url('index.php');
            foreach (rows("SELECT email FROM users WHERE role IN ('yonetici','pm') AND is_active=1") as $yd) {
                if ($yd['email']) send_email($yd['email'], '📋 SADA One günlük özet — ' . date('d.m.Y'), $ozet);
            }
        }
    }

    /* --- Drive transfer tracking (max once per day) ---
     * Semi-automatic: a finished shoot without a Drive link/mark → warn the crew.
     * Fully automatic (if the service account is configured): look into the shoot's
     * (or client's) Drive folder; files created after the shoot start → auto-mark. */
    if (val("SELECT setting_value FROM settings WHERE setting_key='last_drive_check'") !== date('Y-m-d')) {
        q("INSERT INTO settings (setting_key, setting_value) VALUES ('last_drive_check', ?) ON DUPLICATE KEY UPDATE setting_value=?", [date('Y-m-d'), date('Y-m-d')]);
        require_once __DIR__ . '/google-drive.php';
        $driveOn = drive_configured();
        $driveToken = $driveOn ? drive_token() : null;
        $pendingShoots = rows("SELECT e.id, e.title, e.start, e.created_by, e.drive_folder_id, e.drive_link, e.drive_files_seen,
            c.drive_folder_id client_folder, c.manager_id,
            (SELECT GROUP_CONCAT(ep.user_id) FROM event_participants ep WHERE ep.event_id=e.id) participant_ids
            FROM events e LEFT JOIN clients c ON c.id = COALESCE(e.client_id, (SELECT client_id FROM projects WHERE id=e.project_id))
            WHERE e.type='cekim' AND e.drive_status='bekliyor'
            AND COALESCE(e.`end`, e.start) < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND e.start > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        foreach ($pendingShoots as $sh) {
            $folder = $sh['drive_folder_id'] ?: $sh['client_folder'];
            $who = array_filter(array_unique(array_merge(
                array_map('intval', explode(',', (string)$sh['participant_ids'])),
                [(int)$sh['created_by'], (int)$sh['manager_id']])));
            $uyar = function (string $title, string $text) use ($who) {
                foreach ($who as $uid) {
                    notify($uid, $title, $text, 'shoot-list.php', 'gorev');
                    $mail = val("SELECT email FROM users WHERE id=? AND is_active=1", [$uid]);
                    if ($mail) send_email($mail, $title, $text . "\n\nÇekim listesi: " . full_url('shoot-list.php'));
                }
            };
            // A link counts as human confirmation only when someone actually ADDED it —
            // auto-created folders store their own URL in drive_link, that must not count
            $autoLink = $sh['drive_folder_id'] ? 'https://drive.google.com/drive/folders/' . $sh['drive_folder_id'] : null;
            if ($sh['drive_link'] && $sh['drive_link'] !== $autoLink) {
                update_row('events', ['drive_status' => 'aktarildi'], 'id=?', [$sh['id']]);
                continue;
            }
            // Files in the folder are a signal, not proof of completeness: the panel ASKS
            // the crew to confirm everything expected is uploaded, instead of auto-marking
            if ($driveToken && $folder) {
                $afterIso = gmdate('Y-m-d\TH:i:s\Z', strtotime($sh['start']));
                $r = drive_files_after($folder, $afterIso, $driveToken);
                if ($r['ok'] && $r['count'] > 0) {
                    if (empty($sh['drive_files_seen'])) {
                        update_row('events', ['drive_files_seen' => 1], 'id=?', [$sh['id']]);
                        $uyar('📁 Klasörde dosyalar görüldü: ' . $sh['title'],
                            '"' . $sh['title'] . '" çekiminin klasöründe ' . $r['count'] . '+ dosya var. Yüklenmesi gereken HER ŞEY yüklendiyse çekim listesinden "Tümü yüklendi" olarak işaretleyin.');
                    } else {
                        $uyar('⏳ Yükleme onayı bekleniyor: ' . $sh['title'],
                            'Klasörde dosyalar var ama "tümü yüklendi" onayı verilmedi. Eksik kalmadıysa çekim listesinden işaretleyin.');
                    }
                    continue;
                }
            }
            // Hiç dosya yok → sert uyarı
            $uyar('📁 Drive aktarımı bekleniyor: ' . $sh['title'],
                format_date($sh['start'], true) . ' tarihli çekimin görüntüleri henüz Drive\'a aktarılmadı. Aktardıysanız çekim listesinden işaretleyin.');
        }
    }

    /* --- Monthly-report reminders (max once per day) ---
     * Window 1: last 3 days of the month  → remind the client manager about the CURRENT month
     * Window 2: first 3 days of the month → remind about the PREVIOUS month
     * Day 4:    still empty               → escalate to admins/PMs as overdue */
    $today = date('Y-m-d');
    if (val("SELECT setting_value FROM settings WHERE setting_key='last_report_reminder'") !== $today) {
        q("INSERT INTO settings (setting_key, setting_value) VALUES ('last_report_reminder', ?) ON DUPLICATE KEY UPDATE setting_value=?", [$today, $today]);
        $day = (int)date('j');
        $lastDay = (int)date('t');
        $missing = function (string $period): array {
            return rows("SELECT c.id, c.name, c.manager_id, r.status
                FROM clients c LEFT JOIN monthly_reports r ON r.client_id=c.id AND r.period=?
                WHERE c.status='aktif' AND c.manager_id IS NOT NULL AND (r.id IS NULL OR r.status='taslak')", [$period]);
        };
        if ($day >= $lastDay - 2) {
            $period = date('Y-m');
            foreach ($missing($period) as $cl) {
                notify((int)$cl['manager_id'], '📊 Aylık rapor zamanı: ' . $cl['name'],
                    ($cl['status'] === 'taslak' ? 'Taslak raporu tamamlayıp' : 'Bu ayın raporunu doldurup') . ' "Tamamlandı" olarak kaydedin.',
                    'monthly-reports.php?client=' . $cl['id'] . '&period=' . $period, 'gorev');
            }
        }
        if ($day <= 3) {
            $period = date('Y-m', strtotime('first day of last month'));
            foreach ($missing($period) as $cl) {
                notify((int)$cl['manager_id'], '📊 Geçen ayın raporu bekliyor: ' . $cl['name'],
                    'Önceki ayın (' . $period . ') raporu henüz tamamlanmadı.',
                    'monthly-reports.php?client=' . $cl['id'] . '&period=' . $period, 'gorev');
            }
        }
        if ($day === 4) {
            $period = date('Y-m', strtotime('first day of last month'));
            $late = $missing($period);
            if ($late) {
                $names = implode(', ', array_column($late, 'name'));
                foreach ($late as $cl) {
                    notify((int)$cl['manager_id'], '🔴 Rapor gecikti: ' . $cl['name'],
                        $period . ' raporu hâlâ tamamlanmadı. Lütfen en kısa sürede doldurun.',
                        'monthly-reports.php?client=' . $cl['id'] . '&period=' . $period, 'gorev');
                }
                foreach (rows("SELECT id FROM users WHERE role IN ('yonetici','pm') AND is_active=1") as $ya) {
                    notify((int)$ya['id'], '🔴 Geciken aylık raporlar', $period . ' dönemi için eksik: ' . mb_substr($names, 0, 180), 'monthly-reports.php', 'gorev');
                }
            }
        }
    }

    return $count;
}

/* ---------------- Live sync: state digests ----------------
 * Open pages check this hash every 10 s; if it changed, the page refreshes.
 */
function live_hash_task(int $id): string {
    $g = row("SELECT status, lock_bypassed, bagimli_id, assignee_id, is_archived, title, due_date FROM tasks WHERE id=?", [$id]);
    $steps = val("SELECT GROUP_CONCAT(CONCAT(id,':',status) ORDER BY sort_order) FROM task_steps WHERE task_id=?", [$id]);
    $check = val("SELECT GROUP_CONCAT(CONCAT(id,':',is_done) ORDER BY sort_order) FROM task_checklist WHERE task_id=?", [$id]);
    $comment_box = val("SELECT CONCAT(COUNT(*),':',COALESCE(MAX(id),0),':',SUM(is_edited)) FROM comments WHERE ref_type='gorev' AND ref_id=?", [$id]);
    $reaction = val("SELECT COUNT(*) FROM comment_box_reactions t JOIN comments y ON y.id=t.comment_box_id WHERE y.ref_type='gorev' AND y.ref_id=?", [$id]);
    $ek = val("SELECT COUNT(*) FROM archive WHERE task_id=?", [$id]);
    $watcher = val("SELECT COUNT(*) FROM task_watchers WHERE task_id=?", [$id]);
    // The dependency task's status also affects the lock
    $bagimliStatus = $g && $g['bagimli_id'] ? val("SELECT status FROM tasks WHERE id=?", [$g['bagimli_id']]) : '';
    return md5(json_encode([$g, $steps, $check, $comment_box, $reaction, $ek, $watcher, $bagimliStatus]));
}

function live_hash_list(): string {
    return md5((string)val("SELECT GROUP_CONCAT(CONCAT_WS(':',id,status,sort_order,is_archived,COALESCE(assignee_id,0)) ORDER BY id) FROM tasks"));
}

/** Has the user enabled the 'only steps I am responsible for' preference? */
function only_own_steps(): bool {
    $t = json_decode(user()['notification_preferences'] ?? '', true);
    return is_array($t) && !empty($t['only_own_steps']);
}

/** When a task is completed, moves the linked content to 'approved' (unless already published) */
function task_content_sync(int $taskId): void {
    $contentId = (int)val("SELECT content_id FROM tasks WHERE id=?", [$taskId]);
    if ($contentId) q("UPDATE contents SET status='onaylandi' WHERE id=? AND status NOT IN ('yayinlandi','onaylandi')", [$contentId]);
}

/* ---------------- Task lock checks ---------------- */

/** Returns the reason blocking the task from progressing; null if there is none. */
function task_lock_reason(array $task, string $targetStatus): ?string {
    if (!empty($task['lock_bypassed'])) return null; // an admin has bypassed the lock
    // Dependency: cannot move past 'yapilacak' until the linked task is finished
    if ($task['bagimli_id'] && $targetStatus !== 'yapilacak') {
        $bagimli = row("SELECT title, status FROM tasks WHERE id=?", [$task['bagimli_id']]);
        if ($bagimli && $bagimli['status'] !== 'tamamlandi') {
            return '"' . $bagimli['title'] . '" görevi tamamlanmadan bu görev ilerleyemez.';
        }
    }
    // Status lock: cannot be marked completed until the workflow steps are done
    if ($targetStatus === 'tamamlandi') {
        $eksik = (int)val("SELECT COUNT(*) FROM task_steps WHERE task_id=? AND status!='tamam'", [$task['id']]);
        if ($eksik > 0) return "Akışta $eksik tamamlanmamış adım var. Önce adımları bitirin.";
    }
    return null;
}

function project_channel(int $projectId, string $type = 'proje'): int {
    $k = row("SELECT id FROM channels WHERE project_id=? AND type=?", [$projectId, $type]);
    if ($k) return (int)$k['id'];
    $project = row("SELECT name FROM projects WHERE id=?", [$projectId]);
    $name = $project['name'] ?? 'Proje';
    $channelId = insert('channels', ['name' => $name, 'type' => $type, 'project_id' => $projectId, 'created' => date('Y-m-d H:i:s')]);
    // Auto-add team members
    foreach (rows("SELECT id FROM users WHERE role IN ('yonetici','pm','ekip') AND is_active=1") as $u) {
        q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channelId, $u['id']]);
    }
    if ($type === 'musteri') {
        $clientId = val("SELECT client_id FROM projects WHERE id=?", [$projectId]);
        foreach (rows("SELECT id FROM users WHERE role='musteri' AND client_id=? AND is_active=1", [$clientId]) as $u) {
            q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channelId, $u['id']]);
        }
    }
    return $channelId;
}

// Runs after all helpers are defined: one-time legacy schema localization
legacy_schema_check();

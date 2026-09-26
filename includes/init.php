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

/**
 * Run work AFTER the response has been delivered to the browser.
 * Reminder mails, Drive checks and similar housekeeping used to run inside the
 * page request: whoever happened to load a page first waited for every SMTP
 * handshake and API call — a stalled mail server meant a page that never opened.
 */
function after_response(callable $fn): void {
    $GLOBALS['sada_after_response'][] = $fn;
}
register_shutdown_function(function () {
    // Slow-request evidence: anything the visitor waited on for 5+ seconds is
    // logged with its URL, so a "the panel hung" report can be traced to a cause.
    $spent = microtime(true) - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    if ($spent > 5) {
        error_log(sprintf('[SADA] slow request: %.1fs %s %s', $spent, $_SERVER['REQUEST_METHOD'] ?? '?',
            ($_SERVER['REQUEST_URI'] ?? '?') . (isset($_POST['action']) ? ' action=' . $_POST['action'] : '')));
    }
    if (empty($GLOBALS['sada_after_response'])) return;
    ignore_user_abort(true);
    // Hand the finished page to the web server first; the browser is done at this point.
    $detached = true;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else { $detached = false; while (ob_get_level() > 0) @ob_end_flush(); @flush(); }
    // Without a real early finish the browser is still waiting on this connection,
    // so the work gets a short budget; loops check sada_deadline_passed() and stop.
    $GLOBALS['sada_deadline'] = max((float)($GLOBALS['sada_deadline'] ?? 0), microtime(true) + ($detached ? 100 : 12)); // cron.php sets its own, longer one
    @set_time_limit(120);
    $t = microtime(true);
    // Drained as a queue: a job may enqueue more (notify() defers its e-mail here)
    while ($fn = array_shift($GLOBALS['sada_after_response'])) {
        try { $fn(); } catch (Throwable $e) { error_log('[SADA] background job: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); }
    }
    $spent = microtime(true) - $t;
    if ($spent > 10) error_log(sprintf('[SADA] background jobs took %.1fs%s', $spent, $detached ? ' (the page was not held up)' : ' — no early response on this server, the page waited'));
});
/** True once the background-work budget of this request is spent (always false during the page itself). */
function sada_deadline_passed(): bool {
    return isset($GLOBALS['sada_deadline']) && microtime(true) > $GLOBALS['sada_deadline'];
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
 * Self-healing schema: when the code version is ahead of the database, run the
 * migrations once (under a lock) and record the new schema version.
 */
function legacy_schema_check(): void {
    try {
        // Hot path: one indexed lookup per request, nothing else.
        $st = db()->prepare("SELECT setting_value FROM settings WHERE setting_key='schema_version'");
        $st->execute();
        if ($st->fetchColumn() === APP_VERSION) return;

        // Self-healing schema: whenever the code version moves ahead of the DB, run
        // the migrations once. Exactly ONE request may do it: right after an update
        // every open tab (polls, prerenders, other users) used to start the same
        // ALTER TABLE list at once and queue on each other's metadata locks — and
        // every request touching those tables hung with them for minutes.
        $lockName = 'sada_migrate_' . substr(md5((string)($GLOBALS['config']['db_name'] ?? '')), 0, 20);
        $got = (int)db()->query("SELECT GET_LOCK(" . db()->quote($lockName) . ", 15)")->fetchColumn();
        if ($got !== 1) return; // another request is migrating; this one proceeds as-is
        try {
            $st->execute();
            if ($st->fetchColumn() === APP_VERSION) return; // finished while we waited
            // After a failed run, retry at most every 10 minutes instead of on every request
            $retry = db()->query("SELECT setting_value FROM settings WHERE setting_key='schema_retry_after'")->fetchColumn();
            if ($retry !== false && (int)$retry > time()) return;
            require_once __DIR__ . '/migration.php';
            $t0 = microtime(true); $fresh = 0; $failed = false;
            foreach (run_migrations(db()) as $mr) {
                if (($mr[0] ?? '') === 'error') { error_log('[SADA] migration error: ' . ($mr[1] ?? '?')); $failed = $failed || preg_match('~^(english|work|steps):~', (string)($mr[1] ?? '')); }
                if (($mr[0] ?? '') === 'ok') $fresh++;
            }
            if ($failed) {
                // a one-time data conversion did not finish: do not mark the schema as current
                db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_retry_after', ?) ON DUPLICATE KEY UPDATE setting_value=?")
                    ->execute([time() + 600, time() + 600]);
                return;
            }
            db()->exec("DELETE FROM settings WHERE setting_key='schema_retry_after'");
            db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([APP_VERSION, APP_VERSION]);
            error_log(sprintf('[SADA] schema updated: v%s (%d new commands, %.1fs)', APP_VERSION, $fresh, microtime(true) - $t0));
        } finally {
            db()->query("SELECT RELEASE_LOCK(" . db()->quote($lockName) . ")");
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
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_TIMEOUT => 5] // an unreachable DB fails fast instead of hanging the request
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

function update_row(string $table, array $data, string $where_sql, array $conditionP = []): void {
    $set = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
    q("UPDATE $table SET $set WHERE $where_sql", array_merge(array_values($data), $conditionP));
}

/* ---------------- Settings ---------------- */

function setting(string $key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $cache = db()->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $cache[$key] ?? $default;
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

function is_admin(): bool { return (user()['role'] ?? '') === 'admin'; }
function is_pm(): bool { return in_array(user()['role'] ?? '', ['admin', 'pm']); }
function is_staff(): bool { return in_array(user()['role'] ?? '', ['admin', 'pm', 'team', 'finance', 'intern']); }
function is_finance(): bool { return (user()['role'] ?? '') === 'finance'; }
function is_intern(): bool { return (user()['role'] ?? '') === 'intern'; }
function is_customer(): bool { return (user()['role'] ?? '') === 'customer'; }

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
 * Role defaults + per-user overrides (users.permissions JSON).
 * Keys: see PERMISSION_KEYS below
 */
const PERMISSION_KEYS = [
    'finance' => 'Finans sayfası',
    'report' => 'Raporlar sayfası',
    'capacity' => 'Kapasite takibi',
    'client_manage' => 'Dosya/Proje oluştur-düzenle',
    'task_create' => 'İş oluşturma',
    'task_delete' => 'İş silme',
    'content_manage' => 'İçerik takvimi yönetimi',
    'equipment_manage' => 'Ekipman envanteri yönetimi',
    'approval_send' => 'Müşteri onayına gönderme',
    'announcement_publish' => 'Duyuru yayınlama',
    'calendar_manage' => 'Etkinlik/toplantı oluşturma',
    'channel_create' => 'Sohbet kanalı kurma',
    'document_create' => 'Teklif/fatura oluşturma',
    'archive_delete' => 'Arşivden dosya silme',
    'request_manage' => 'Talepleri yönetme',
    'budget_view' => 'Proje bütçelerini görme (istasyon)',
    'finance_manage' => 'Finans kaydı ekleme/düzenleme/silme',
    'appointment_manage' => 'Randevuları yanıtlama (onay/red/alternatif)',
    'pool_manage' => 'Çalışan havuzu yönetimi',
    'mentorship_manage' => 'Gelişim & mentörlük yönetimi',
    'ai_use' => 'Yapay zeka özelliklerini kullanma',
];

function permission(string $key): bool {
    $u = user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    if ($u['role'] === 'customer') return false;
    // Per-user override
    $custom = json_decode($u['permissions'] ?? '', true);
    if (is_array($custom) && array_key_exists($key, $custom)) return (bool)$custom[$key];
    // Role defaults
    $default = [
        'pm'      => ['finance' => 1, 'report' => 1, 'capacity' => 1, 'client_manage' => 1, 'task_create' => 1, 'task_delete' => 1, 'content_manage' => 1, 'equipment_manage' => 1, 'approval_send' => 1, 'announcement_publish' => 1, 'calendar_manage' => 1, 'channel_create' => 1, 'document_create' => 1, 'archive_delete' => 1, 'request_manage' => 1, 'finance_manage' => 1, 'appointment_manage' => 1, 'pool_manage' => 1, 'mentorship_manage' => 1, 'ai_use' => 1],
        'team'    => ['finance' => 0, 'report' => 0, 'capacity' => 0, 'client_manage' => 0, 'task_create' => 1, 'task_delete' => 0, 'content_manage' => 1, 'equipment_manage' => 0, 'approval_send' => 1, 'announcement_publish' => 0, 'calendar_manage' => 1, 'channel_create' => 1, 'document_create' => 0, 'archive_delete' => 0, 'request_manage' => 0, 'finance_manage' => 0, 'appointment_manage' => 0, 'pool_manage' => 0, 'mentorship_manage' => 0, 'ai_use' => 1],
        'finance'  => ['finance' => 1, 'report' => 1, 'capacity' => 1, 'client_manage' => 0, 'task_create' => 0, 'task_delete' => 0, 'content_manage' => 0, 'equipment_manage' => 0, 'approval_send' => 0, 'announcement_publish' => 0, 'calendar_manage' => 0, 'channel_create' => 1, 'document_create' => 1, 'archive_delete' => 0, 'request_manage' => 0, 'finance_manage' => 1, 'appointment_manage' => 0, 'pool_manage' => 0, 'mentorship_manage' => 0, 'ai_use' => 1],
        'intern' => ['finance' => 0, 'report' => 0, 'capacity' => 0, 'client_manage' => 0, 'task_create' => 0, 'task_delete' => 0, 'content_manage' => 0, 'equipment_manage' => 0, 'approval_send' => 0, 'announcement_publish' => 0, 'calendar_manage' => 0, 'channel_create' => 0, 'document_create' => 0, 'archive_delete' => 0, 'request_manage' => 0, 'finance_manage' => 0, 'appointment_manage' => 0, 'pool_manage' => 0, 'mentorship_manage' => 0, 'ai_use' => 0],
    ];
    return (bool)($default[$u['role']][$key] ?? 0);
}

function require_permission(string $key): array {
    $u = require_login();
    if (!permission($key)) deny();
    return $u;
}

/** Client ids the customer can access (primary client + extra assignments) */
function customer_client_ids(?int $userId = null): array {
    static $cache = [];
    $u = user();
    $userId = $userId ?? (int)($u['id'] ?? 0);
    if (isset($cache[$userId])) return $cache[$userId];
    $ids = array_map('intval', array_column(rows("SELECT client_id FROM customer_clients WHERE user_id=?", [$userId]), 'client_id'));
    $primary = (int)val("SELECT client_id FROM users WHERE id=?", [$userId]);
    if ($primary && !in_array($primary, $ids)) $ids[] = $primary;
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
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

const MONTHS = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
const DAYS = ['Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar'];

function format_date(?string $dt, bool $timed = false): string {
    if (!$dt || $dt === '0000-00-00') return '—';
    $ts = strtotime($dt);
    $s = date('j', $ts) . ' ' . MONTHS[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    if ($timed) $s .= ' ' . date('H:i', $ts);
    return $s;
}

function time_ago(?string $dt): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'az önce';
    if ($diff < 3600) return floor($diff / 60) . ' dk önce';
    if ($diff < 86400) return floor($diff / 3600) . ' saat önce';
    if ($diff < 604800) return floor($diff / 86400) . ' gün önce';
    return format_date($dt);
}

function format_minutes(int $min): string {
    if ($min < 60) return $min . ' dk';
    $s = intdiv($min, 60); $k = $min % 60;
    return $s . 'sa' . ($k ? ' ' . $k . 'min' : '');
}

function money(?float $t): string { return number_format((float)$t, 2, ',', '.') . ' ₺'; }

function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $h = mb_substr($parts[0], 0, 1);
    if (count($parts) > 1) $h .= mb_substr(end($parts), 0, 1);
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
        return '<span class="file-avatar" style="width:' . $size . 'px;height:' . $size . 'px;background-image:url(\'uploads/' . e($d['logo']) . '\');background-size:cover;background-position:center"></span>';
    }
    $color = e($d['color'] ?? '#182f5d');
    return '<span class="file-avatar" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . $fontPx . 'px;background:' . $color . '22;color:' . $color . '">' . e(initials($d['name'])) . '</span>';
}

/* ---------------- Label dictionaries ---------------- */

const PROJECT_TYPES = ['monthly' => 'Aylık Düzenli', 'periodic' => 'Dönemsel', 'one_off' => 'Tek Seferlik'];
const CLIENT_TYPES = ['brand' => 'Marka', 'company' => 'Şirket', 'ngo' => 'STK'];
const TASK_STATUSES = ['todo' => 'Yapılacak', 'in_progress' => 'Devam Ediyor', 'in_review' => 'İç Onayda', 'awaiting_approval' => 'Müşteride', 'completed' => 'Tamamlandı', 'published' => 'Yayınlandı', 'cancelled' => 'İptal'];
// Completed, published and cancelled work is closed: it no longer counts as open, late or pending
const TASK_CLOSED = ['completed', 'published', 'cancelled'];
const TASK_KINDS = ['client' => 'Müşteri işi', 'internal' => 'İç iş'];
// Planned work is the month's plan; agenda work comes up during the month (news, trends)
const TASK_LANES = ['planned' => 'Planlı', 'agenda' => 'Gündem'];
const MONTH_PHASES = ['planning' => 'Planlama', 'production' => 'Üretim', 'closing' => 'Kapanış', 'closed' => 'Kapandı'];
const PLAN_STATUSES = ['none' => 'Gönderilmedi', 'pending' => 'Müşteride', 'approved' => 'Onaylandı', 'revision' => 'Revize istendi'];
const PRIORITIES = ['low' => 'Düşük', 'normal' => 'Normal', 'high' => 'Yüksek', 'urgent' => 'Acil'];
const PROJECT_STATUSES = ['active' => 'Aktif', 'on_hold' => 'Beklemede', 'completed' => 'Tamamlandı', 'cancelled' => 'İptal'];
const APPROVAL_STATUSES = ['pending' => 'Bekliyor', 'approved' => 'Onaylandı', 'revision' => 'Revize İstendi', 'rejected' => 'Reddedildi'];
const REQUEST_STATUSES = ['new' => 'Yeni', 'reviewing' => 'İnceleniyor', 'task_created' => 'İşe Dönüştürüldü', 'completed' => 'Tamamlandı', 'rejected' => 'Reddedildi'];
const PLATFORMS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'x' => 'X (Twitter)', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'web' => 'Web Sitesi', 'other' => 'Diğer'];
const EVENT_TYPES = ['shoot' => 'Çekim', 'meeting' => 'Toplantı', 'delivery' => 'Teslim', 'other' => 'Diğer'];
const ROLES = ['admin' => 'Yönetici', 'pm' => 'Proje Yöneticisi', 'team' => 'Ekip Üyesi', 'finance' => 'Finans', 'intern' => 'Stajyer', 'customer' => 'Müşteri'];
const REPEAT_OPTIONS = ['none' => 'Tekrarlamaz', 'weekly' => 'Her Hafta', 'monthly' => 'Her Ay'];
const EXPENSE_TYPES = ['salary' => 'Maaş', 'rent' => 'Kira', 'subscription' => 'Abonelik', 'equipment' => 'Ekipman', 'tax' => 'Vergi', 'other' => 'Diğer'];
const EXPENSE_STATUSES = ['pending' => 'Bekliyor', 'paid' => 'Ödendi'];
const PAYMENT_TYPES = ['invoice' => 'Fatura', 'collection' => 'Tahsilat'];
const PAYMENT_STATUSES = ['pending' => 'Bekliyor', 'paid' => 'Ödendi', 'overdue' => 'Gecikti'];
const DOCUMENT_TYPES = ['quote' => 'Teklif', 'invoice' => 'Fatura'];
const DOCUMENT_STATUSES = ['draft' => 'Taslak', 'sent' => 'Gönderildi', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi'];
const CLIENT_STATUSES = ['active' => 'Aktif', 'inactive' => 'Pasif'];
const PERIOD_STATUSES = ['open' => 'Açık', 'closed' => 'Kapalı'];
const EXTRA_REQUEST_STATUSES = ['pending' => 'Bekliyor', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi'];
const MENTORSHIP_STATUSES = ['planned' => 'Planlandı', 'in_progress' => 'Devam Ediyor', 'completed' => 'Tamamlandı'];
const IDEA_STATUSES = ['new' => 'Yeni', 'liked' => 'Beğenildi', 'implemented' => 'Uygulandı'];
const NOTE_CATEGORIES = ['general' => 'Genel', 'brand' => 'Marka Rehberi', 'access' => 'Erişim Bilgileri', 'audience' => 'Hedef Kitle', 'process' => 'Süreç'];

// Status colours shared by kanban, reports and the content calendar
const TASK_STATUS_COLORS = ['todo' => 'var(--muted)', 'in_progress' => 'var(--info)', 'in_review' => 'var(--warning)', 'awaiting_approval' => '#a58bf0', 'completed' => 'var(--success)', 'published' => 'var(--brand)', 'cancelled' => 'var(--muted)'];

/* ---------------- Version & update notes ---------------- */
const APP_VERSION = '7.3';
const VERSION_NOTES = [
    '7.3' => [
        'Aylar: aylık projenin her ayı artık Planlama → Üretim → Kapanış → Kapandı yolundan geçer. Yeni "Ay" sayfası ayın işlerini, ilerlemesini, plan onayını ve aylık raporunu tek yerde gösterir',
        'Planlı ve Gündem işler: ayın planındaki işler "Planlı", ay içinde çıkan işler (haber, trend) "Gündem" olarak işaretlenir; gündem işleri plana ve plan onayına girmez',
        'Plan onayı: ayın planı (yayın tarihleri ve platformlarıyla) tek onay olarak müşteriye gider; müşteri onaylayınca ay kendiliğinden üretime geçer, revize isterse planlamada kalır',
        'Kapanış: açık işler tek tıkla sonraki aya taşınır (aralıktan ocağa da), aylık rapor aydan açılır; açık iş kalmadan ay kapanmaz, gerekirse yeniden açılır',
        'Kapsam sinyali: ayın müşteri işi önceki üç ayın ortalamasını belirgin geçerse Ay sayfası uyarır',
        'Dosyada "Strateji & Marka" kartı: strateji, marka kiti ve onay kuralları (plan onayı, müşteri onayı atlanan iş türleri). Marka kiti müşteri işlerinin sayfasında ekibe gösterilir',
        'Ay başında bu ayı açılmamış aylık projeler, ayın 5\'inden sonra da kapanmamış geçen ay proje yöneticisine hatırlatılır',
    ],
    '7.2' => [
        'Adım motoru: Akış Şablonları artık "İş Türleri". Her tür bir adım tarifi taşır; her adımın bir uzmanlığı (Tasarım, Kurgu, Metin, Çekim, Koordinasyon, Geliştirme) ve türü (üretim, iç kontrol, müşteri onayı, yayın) vardır',
        'Adımlı işlerin durumu adımlardan gelir: üretim → Devam Ediyor, iç kontrol → İç Onayda, müşteri onayı → Müşteride, yayın → Tamamlandı (yayın bekliyor), hepsi bitince Tamamlandı / Yayınlandı. Elle yalnızca iptal edilir ya da yeniden açılır',
        'Havuz: sahibi olmayan adım uzmanlığın havuzuna düşer, o uzmanlıktaki herkese bildirim gider; İşler sayfasındaki "Havuz" kartından "Ben alıyorum" ile alınır (aynı adımı iki kişi alamaz)',
        'İş sayfasında sıradaki adımın bandı: Bitir, Onayla / Geri gönder (not işin tartışmasına düşer, iş son üretim adımına döner), Müşteriye gönder, Yayınlandı, Devret (kişilerin açık adım sayısıyla)',
        'Müşteri onaylayınca müşteri onayı adımı kendiliğinden biter; revize ya da ret iş\'i üretime geri gönderir',
        'Yeni İş açarken tür seçilir; adımlar formda listelenir, her adıma kişi ya da havuz atanır (Koordinasyon adımları varsayılan olarak proje yöneticisine gider)',
        'Kullanıcılar sayfasında kişilere uzmanlık verilir; güncellemede unvanlardan tahmin edilen uzmanlıklar önerilir. Sabit "Revizyon" adımı kaldırıldı (revize artık geri gönderme döngüsü)',
    ],
    '7.1' => [
        'Bir çıktı, tek kayıt: görev, içerik ve onay artık tek bir İş\'te birleşti. Menüde "Görevler" artık "İşler"',
        'İşin sayfasında yayın planı (tarih, saat, platformlar) ve müşteri onay geçmişi var. "Müşteriye gönder" ile onaya yollanır; müşteri onaylarsa iş tamamlanır, revize isterse üretime döner',
        'Yeni durumlar: Yayınlandı ve İptal. "İncelemede" artık "İç Onayda", "Onayda" artık "Müşteride". İptal edilen işler panoda görünmez, "İptal" filtresinde durur',
        'İç iş türü: müşteriye gitmeyen işler müşteri ekranlarında görünmez ve yayın planı taşımaz',
        'İçerik takvimi işlerin yayın planını gösteriyor: takvimden iş planlanır, sürükleyerek yayın tarihi değişir',
        'Talep işe dönüşünce müşterinin form cevapları işin açıklamasına yazılır',
        'Güncellemede veritabanının tam yedeği alınır; mevcut içerikler ve onaylar kayıpsız olarak ilgili işlere taşınır',
        'Düzeltildi: takvimde bir güne tıklayıp planlanan içerik bugünün tarihine kaydediliyordu; çok platformlu içeriklerde "Yaklaşanlar" kartında platform boş görünüyordu; proje sayfasındaki "Takvim Görünümü" projeye süzmüyordu; müşteriler takvimden içerik durumunu değiştirebiliyordu',
    ],
    '7.0.1' => [
        'Düzeltildi: dosya yüklemeleri çalışmıyordu — projeye/göreve dosya ekleme, onaya dosya ekleme, yorum eki ve sözleşme belgesi (6.11\'den beri)',
    ],
    '7.0' => [
        'Kod tabanı baştan sona İngilizceye çevrildi: CSS sınıfları, fonksiyonlar, değişkenler, AJAX eylemleri, ayar ve yetki anahtarları ile veritabanında saklanan durum/rol değerleri artık tek dilde. Arayüz Türkçe kalmaya devam ediyor',
        'Güncelleme sırasında veritabanının tam yedeği backups/ klasörüne alınıyor, ardından tüm kayıtlar kayıpsız çevriliyor',
        'Düzeltildi: randevu onaylama/reddetme çalışmıyordu',
        'Düzeltildi: görev tablo görünümünde hücreden düzenleme (atanan, durum, öncelik, tarihler, süre) "Bu alan düzenlenemez" hatası veriyordu',
        'Düzeltildi: içerik takviminde içerik detayı açılmıyordu',
        'Düzeltildi: bütçe hedefi kaydediliyor ama finans sayfasında görünmüyordu',
        'Düzeltildi: aktif projeler/dosyalar, teslim etkinlikleri ve iç onay / müşteri onayındaki içerikler bazı yerlerde yanlış etiket veya renkle görünüyordu; müşterisi olmayan taleplerde proje listesi boş geliyordu',
        'Düzeltildi: 5.0 öncesinden kalan ekipman hareketleri geçmişte ham kod olarak görünüyordu; görev maddesi silme onay sorusu gelmiyordu',
        'Düzeltildi: finansta vadesi geçmiş faturalar "Bekliyor" görünüyordu ve listeden "Gecikti" seçilince kaydedilmiyordu',
        'Düzeltildi: yorum düzenleme ve yoruma yanıt formu açılmıyordu; kişisel not düzenlenirken seçili renk işaretlenmiyordu',
        'Düzeltildi: uyarı ve bilgi renkleri (önemli duyurular, bekleyen randevular, incelemedeki görevler…) hiç uygulanmıyordu',
        'Düzeltildi: finans ve zaman CSV dışa aktarımı, paneldeki "geciken görevler" bağlantısı ve panelin "Ekip durumu" kartı çalışmıyordu',
    ],
    '6.11' => [
        'KİLİTLENME AVI: saatlik/günlük otomatik işler (hatırlatma mailleri, Drive kontrolleri) artık sayfa yüklemesinin İÇİNDE değil, sayfa tarayıcıya teslim edildikten SONRA çalışıyor — takılan bir mail sunucusu paneli bekletemez',
        'Güncelleme sonrası şema migration\'ı artık tek bir istek tarafından kilitle çalıştırılıyor; aynı anda açık sekmeler/anketler aynı ALTER TABLE listesini paralel koşturup tüm siteyi dakikalarca kilitlemiyordu artık',
        'Migration komutları bir kez başarılı olduktan sonra hash ile hatırlanıyor: her sürümde tabloları yeniden inşa eden komutlar bir daha çalışmıyor',
        'SMTP: tek bağlantı tüm mailler için tekrar kullanılıyor, her okumada 20 sn zaman aşımı var; günlük hatırlatmalar iki kez (bildirim + ayrı mail) gönderilmiyor',
        'Otomatik işlerin "bugün çalıştı mı" kontrolü atomik yapıldı: iki eşzamanlı istek aynı hatırlatmaları iki kez göndermiyor',
        'Çekim listesi Drive dosyalarını kart başına ayrı istek yerine tek toplu istekle ve 3 dk önbellekle alıyor',
        'Bağlantı önyükleme (prerender) sadece tıklama anında: her üzerine gelinen menü linki gizli bir tam sayfa isteği üretmiyor',
        'Canlı senkron özeti GROUP_CONCAT kesilmesinden kurtarıldı; DB bağlantısına 5 sn zaman aşımı; giriş deneme kaydı 30 günde temizleniyor',
        'Bildirim e-postaları (duyuru, atama, yorum…) artık tıklayan kullanıcıyı bekletmeden yanıt sonrasında gönderiliyor',
        'Tarayıcı: her istek için zaman aşımı (takılan istek düğmeyi "İşleniyor…" bırakmıyor), anketler önceki yanıt gelmeden yenisini atmıyor, görev listesi canlı senkronu yeniden yükleme fırtınasına karşı frenlendi (45 sn / 10 dk\'da 4), servis çalışanı boş yanıt üretmiyor',
        'AI ve Drive çağrılarına bağlantı zaman aşımı; cron.php eşzamanlı çalışmaya karşı kilitli; mesaj geçmişi son 300 mesajla açılıyor; yavaş istekler (5 sn+) storage/error.log\'a yazılıyor',
    ],
    '6.10.6' => [
        'KÖK NEDEN BULUNDU ve kapatıldı: migration listesinde eski sürümden kalan bir komut, HER güncellemede form alan tipleri kolonunu önce eski dar hâline çevirip bölüm/çoklu seçim/çoklu dosya tiplerini siliyor, sonra yeniden genişletiyordu — veri her turda kayboluyordu. Komut kaldırıldı; tam güncelleme simülasyonuyla verilerin artık hayatta kaldığı kanıtlandı',
        'Aynı desenden ikinci bomba: her güncellemede STAJYER kullanıcıların rolü de siliniyordu — o da kapatıldı',
        'Form şablonundaki tipleri SON bir kez seçip kaydedin: bundan sonraki hiçbir güncelleme onlara dokunmayacak',
    ],
    '6.10.4' => [
        'Form tiplerinin kaybolması KESİN olarak kapatıldı: veritabanı kolonu genişletme migration\'ı canlıda sessizce başarısız olabildiği için, form kaydetme artık kolonu kendisi denetleyip dar kalmışsa anında genişletiyor',
        'Kayıt sonrası doğrulama: yazılan tipler gönderilenle karşılaştırılıyor — veritabanı değeri kırparsa panel artık "kaydedildi" demiyor, açık hata veriyor',
        'Migration hataları artık sessizce yutulmuyor, storage/error.log\'a yazılıyor',
    ],
    '6.10.2' => [
        'Bölümlü formlar artık sekmeli: her bölüm başlığı bir sekme oluyor (bölümden önceki alanlar "Genel" sekmesinde); başka sekmedeki zorunlu alan boşsa gönderim o sekmeye otomatik atlıyor',
        'Not: 6.10 öncesinde oluşturulan şablonlarda tarih/seçim/dosya tipleri, eski sürümün veritabanı kolonu dar olduğu için kaydedilirken kaybolmuştu (güncelleme değil, kayıt anı sildi) — o şablonlarda tipleri bir kez yeniden seçmeniz gerekir; artık kalıcıdır',
    ],
    '6.10' => [
        'Form oluşturucu kökten onarıldı: tarih, seçim vb. tipler formda görünmüyordu (tip anahtarları uyuşmuyordu) ve gönderilen cevaplar hiç kaydedilmiyordu (alan adı uyuşmazlığı) — mevcut şablonlardaki bozuk tipler kendini onarır',
        'Yeni alan tipleri: Çoklu Seçim (kutucuklar), Çoklu Dosya Yükleme (tek alanda birden fazla dosya) ve Bölüm Başlığı (açıklamalı) — formlar bölümlere ayrılabiliyor',
        'Talep oluşturma penceresi genişledi: iki sütunlu ferah yerleşim, bölüm başlıkları, uzun alanlar tam satır',
        'Talep detayında çoklu dosyalar ayrı düğmeler olarak listeleniyor; tüm yüklemeler arşive de düşüyor',
    ],
    '6.9' => [
        'Rapor mailinde alıcılar artık çip (kutucuk) olarak: adres yazıp Enter — her alıcı ayrı kutucuk, ✕ ile çıkarılır, hatalı adres kırmızı görünür',
        'Dosyalara birden fazla iletişim kişisi eklenebiliyor (ad, ünvan, e-posta, telefon) — dosya sayfasında yeni "İletişim Kişileri" kartı; kayıtlı tek kişi otomatik taşındı',
        'Mail gönderirken dosyanın kişileri tek tıkla alıcılara ekleniyor (+Ayşe, +Mehmet, Hepsini ekle)',
        'Bilgi Bankası geliştirildi: kategoriler (Marka Rehberi, Erişim Bilgileri, Hedef Kitle, Süreç), 📌 üste sabitleme, arama ve kategori filtresi',
    ],
    '6.8' => [
        'Rapor maili birden fazla alıcıya gidebiliyor — adresleri virgülle yazın; kısmi başarı durumunda kimlere gittiği/gitmediği açıkça bildirilir',
        'Mailin tüm metinleri düzenlenebilir: ana başlık, selamlama, bölüm başlıkları, istatistik giriş cümlesi, kapanış ve teşekkür — boş bırakılan varsayılanı kullanır',
        'Tipografi rafine edildi: serif başlık hiyerarşisi, üstte marka şeridi, köşeli vurgu işaretli bölüm bantları, çerçeveli istatistik kartları',
    ],
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
const APPOINTMENT_STATUSES = ['pending' => 'Bekliyor', 'approved' => 'Onaylandı', 'alternative' => 'Farklı Saat Önerildi', 'rejected' => 'Reddedildi'];

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
    'other'     => 'M12 8v8m-4-4h8M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    // Equipment categories
    'camera'    => 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z',
    'lens'      => 'M12 19a7 7 0 100-14 7 7 0 000 14zm0-3.5a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM19 5l1.5-1.5',
    'sd_card'   => 'M8 3h9a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V7l3-4zM9 7v3m3-3v3m3-3v3',
    'tripod'    => 'M9 4h6v4H9zM12 8v4m0 0l-5 8m5-8l5 8m-5-8v8',
    'light'      => 'M9 18h6M10 21h4M12 3a6 6 0 00-4 10.5c.7.6 1 1.5 1 2.5h6c0-1 .3-1.9 1-2.5A6 6 0 0012 3z',
    'audio'       => 'M12 15a3 3 0 003-3V6a3 3 0 10-6 0v6a3 3 0 003 3zm-7-3a7 7 0 0014 0M12 19v3',
    'drone'     => 'M4 6a2 2 0 104 0 2 2 0 10-4 0zm12 0a2 2 0 104 0 2 2 0 10-4 0zM4 18a2 2 0 104 0 2 2 0 10-4 0zm12 0a2 2 0 104 0 2 2 0 10-4 0zM7.5 7.5l3 3m6-3l-3 3m-6 6l3-3m6 3l-3-3m-3 3v-3a3 3 0 013-3',
    'accessory'  => 'M6 7h12l1 4H5l1-4zm-1 4v8a1 1 0 001 1h12a1 1 0 001-1v-8M12 7V4',
    // General UI
    'archive'     => 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
    'megaphone'   => 'M11 5.88V19.24a1.76 1.76 0 01-3.42.6L5.44 14M18.7 4a9 9 0 01.3 13.3M5.44 14A2 2 0 015 10h1a8 8 0 005-2l3-2v12l-3-2a8 8 0 00-5-2H5.44z',
    'pin'       => 'M12 21s-7-5.5-7-11a7 7 0 1114 0c0 5.5-7 11-7 11zm0-8.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
    'calendar'    => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    'time'      => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    'lock'     => 'M7 11V7a5 5 0 0110 0v4M5 11h14v9a1 1 0 01-1 1H6a1 1 0 01-1-1v-9z',
    'lock-open' => 'M7 11V7a5 5 0 019.5-2M5 11h14v9a1 1 0 01-1 1H6a1 1 0 01-1-1v-9z',
    'repeat'    => 'M17 2l4 4-4 4M3 11V9a4 4 0 014-4h14M7 22l-4-4 4-4m14-3v2a4 4 0 01-4 4H3',
    'paperclip'      => 'M21.4 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.2-9.19a4 4 0 015.65 5.66l-9.2 9.19a2 2 0 01-2.82-2.83l8.49-8.48',
    'folder'    => 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
    'video'     => 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z',
    'person'      => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    'people'   => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3',
    'chat'    => 'M8 12h8m-8-4h8m-9 8l-4 4V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H7z',
    'handshake' => 'M11 17l-1.5 1.5a2 2 0 01-3-3L8 14m3 3l2 2a2 2 0 003-3l-.5-.5M11 17l3-3m-6 0L5.5 11.5a2 2 0 010-3L8 6l4 1 3.5-1.5a2 2 0 012.5.5L21 9l-3 5.5M8 14l3-3',
    'item'     => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z',
    'cop'       => 'M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3',
    'box'      => 'M21 8l-9-5-9 5m18 0l-9 5m9-5v8l-9 5m0-8L3 8m9 5v8m-9-13v8l9 5',
    'approval'      => 'M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    'chart'    => 'M9 19v-6M15 19v-2M12 19v-9M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z',
    'star'    => 'M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2z',
    'sun'     => 'M12 17a5 5 0 100-10 5 5 0 000 10zm0-15v2m0 16v2M4.2 4.2l1.4 1.4m12.8 12.8l1.4 1.4M2 12h2m16 0h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
    'rocket'     => 'M4.5 16.5c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2 0-2.8-.8-.7-2-.7-3 0zM12 15l-3-3a22 22 0 012-4c3.2-3.2 7-4.5 10-4 .5 3-1 6.8-4 10a22 22 0 01-4 2l-1-1zM9 12H4s.5-3.5 2-5c1.7-1.7 5 0 5 0m1 8v5s3.5-.5 5-2c1.7-1.7 0-5 0-5M15 9h.01',
    'warning'     => 'M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z',
    'money'      => 'M12 8c-2.21 0-4 .9-4 2s1.79 2 4 2 4 .9 4 2-1.79 2-4 2m0-8c1.66 0 3.07.5 3.6 1.2M12 8V6m0 12v-2m0 2c-1.66 0-3.07-.5-3.6-1.2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    'document'     => 'M9 17h6M9 13h6M9 9h1m4 12H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6a1 1 0 01.7.3l5.4 5.4a1 1 0 01.3.7V19a2 2 0 01-2 2z',
];

/** Renders a monochrome line SVG icon (currentColor — inherits the surrounding text color) */
function icon(string $name, int $size = 16, string $style = ''): string {
    $path = ICONS[$name] ?? ICONS['other'];
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"' . ($style ? ' style="' . $style . '"' : '') . '><path d="' . $path . '"/></svg>';
}

/** Converts CSV-stored multi-platform values into icon badges */
function platform_badges(?string $csv, bool $onlyIcon = false): string {
    if (!$csv) return '';
    $h = '';
    foreach (array_filter(array_map('trim', explode(',', $csv))) as $pl) {
        $label = PLATFORMS[$pl] ?? $pl;
        $svg = icon(isset(ICONS[$pl]) ? $pl : 'other', $onlyIcon ? 13 : 13);
        $h .= $onlyIcon
            ? '<span class="p-icon" title="' . e($label) . '">' . $svg . '</span>'
            : '<span class="badge" style="padding:2px 8px;gap:5px">' . $svg . ' ' . e($label) . '</span> ';
    }
    return $h;
}

/** Renders a 1-5 star visual */
function stars(float $rating, int $size = 14): string {
    $h = '<span class="stars" style="font-size:' . $size . 'px">';
    for ($i = 1; $i <= 5; $i++) $h .= '<span style="opacity:' . ($i <= round($rating) ? '1' : '.25') . '">★</span>';
    return $h . '</span>';
}

/* Equipment module constants */
const EQUIPMENT_CATEGORIES = ['camera' => 'Kamera', 'lens' => 'Lens', 'sd_card' => 'SD Kart', 'tripod' => 'Tripod', 'light' => 'Işık', 'audio' => 'Ses', 'drone' => 'Drone', 'accessory' => 'Aksesuar', 'other' => 'Diğer'];
const EQUIPMENT_STATUSES = ['in_studio' => 'Stüdyoda', 'checked_out' => 'Zimmette', 'on_shoot' => 'Çekimde', 'faulty' => 'Arızalı', 'in_maintenance' => 'Bakımda'];
const SD_STATUSES = ['empty' => 'Boş / Hazır', 'full' => 'Dolu', 'transferred' => "Drive'a Aktarıldı"];
const EQUIPMENT_LOG_TYPES = [
    'added' => 'envantere eklendi', 'custody' => 'zimmet verildi', 'return' => 'iade edildi',
    'shoot_out' => 'çekime çıktı', 'shoot_return' => 'çekimden döndü',
    'sd_full' => 'dolu işaretlendi', 'sd_transferred' => "Drive'a aktarıldı", 'sd_emptied' => 'boşaltıldı',
    'fault' => 'arızalı işaretlendi', 'maintenance' => 'bakıma alındı', 'fixed' => 'kullanıma döndü',
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
    return isset(THEMES[$theme]) ? $theme : setting('default_theme', 'lime');
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
    'night'         => ['Gece', '#f8f2cb', true],
    'classic-dark'         => ['Klasik Koyu', '#60a5fa', true],
    'classic-light' => ['Klasik Açık', '#2563eb', false],
    // v5.0 airy styles: liquid glass / glassmorphism / claymorphism
    'liquid-glass'       => ['Liquid Glass', '#8ad8ff', true],
    'liquid-glass-light' => ['Liquid Glass Aydınlık', '#0284c7', false],
    'glass'              => ['Glassmorphism', '#c4b5fd', true],
    'glass-light'        => ['Glassmorphism Aydınlık', '#7c3aed', false],
    'clay'               => ['Claymorphism', '#2563eb', false],
];

/* Notification categories (subject to user preference) */
const NOTIFICATION_CATEGORIES = [
    'task' => 'İş atama ve durum değişiklikleri',
    'approval' => 'Onay talepleri ve yanıtları',
    'request' => 'Yeni talepler',
    'message' => 'Mesajlar',
];

function notification_pref(array $recipient, string $category): array {
    // Returns: [panel_notification_on, email_on]
    $t = json_decode($recipient['notification_preferences'] ?? '', true);
    if (!is_array($t)) return [true, true]; // default: everything on
    $panel = !isset($t[$category]) || (bool)$t[$category];
    $email = !isset($t['email']) || (bool)$t['email'];
    return [$panel, $email];
}

function badge(string $value, array $dictionary, string $classPrefix = ''): string {
    $label = $dictionary[$value] ?? $value;
    return '<span class="badge r-' . ($classPrefix ? $classPrefix . '-' : '') . e($value) . '">' . e($label) . '</span>';
}

/* ---------------- Notifications & Activity ---------------- */

function notify(int $userId, string $title, string $message = '', string $link = '', string $category = 'task', bool $email = true): void {
    // No self-notifications for a user's own actions — but the scheduled jobs are
    // the system acting, so the user whose page load triggered them still gets theirs
    if (empty($GLOBALS['sada_system_actor']) && $userId === (int)(user()['id'] ?? 0)) return;
    $recipient = row("SELECT * FROM users WHERE id=? AND is_active=1", [$userId]);
    if (!$recipient) return;
    [$panelOpen, $emailOpen] = notification_pref($recipient, $category);
    if (!$panelOpen) return; // the user has turned this category off
    insert('notifications', [
        'user_id' => $userId, 'title' => $title, 'message' => $message,
        'link' => $link, 'is_read' => 0, 'created' => date('Y-m-d H:i:s'),
    ]);
    if ($email && $emailOpen && setting('email_notifications') === '1' && setting('smtp_enabled') === '1') {
        // The mail leaves after the response: an announcement to 15 people used to be
        // 15 SMTP transactions the clicking user waited on (minutes when the MX is slow)
        $to = $recipient['email']; $text = $message . ($link ? "\n\nGörüntüle: " . full_url($link) : '');
        after_response(function () use ($to, $title, $text) {
            if (sada_deadline_passed()) return;
            require_once __DIR__ . '/mailer.php';
            send_email($to, $title, $text);
        });
    }
}

function full_url(string $path): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . '/' . ltrim($path, '/');
}

/**
 * The most recent shoot (last 30 days) an SD card was checked out to whose
 * footage has NOT been confirmed in Drive yet — the hook for the SD warnings.
 */
function sd_last_shoot(int $equipmentId): ?array {
    $r = row("SELECT e.id, e.title, e.created_by, c.manager_id
        FROM event_equipment ee JOIN events e ON e.id=ee.event_id
        LEFT JOIN clients c ON c.id = COALESCE(e.client_id, (SELECT client_id FROM projects WHERE id=e.project_id))
        WHERE ee.equipment_id=? AND e.type='shoot' AND e.drive_status='pending'
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
    $targetFolder = ROOT . '/uploads/' . date('Ym');
    if (!is_dir($targetFolder)) mkdir($targetFolder, 0755, true);
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

/** A month created along the way (calendar, recurring work, carrying work over) starts in production when it has
 *  begun, in planning when it lies ahead; opening a month by hand passes 'planning' */
function get_or_create_period(int $projectId, int $year, int $month, ?string $phase = null): int {
    $d = row("SELECT id FROM periods WHERE project_id=? AND year=? AND month=?", [$projectId, $year, $month]);
    if ($d) return (int)$d['id'];
    $phase ??= $year * 12 + $month > (int)date('Y') * 12 + (int)date('n') ? 'planning' : 'production';
    return insert('periods', ['project_id' => $projectId, 'year' => $year, 'month' => $month, 'status' => 'open', 'phase' => $phase, 'created' => date('Y-m-d H:i:s')]);
}

/** Active customer accounts that see a client file (primary file or an extra file assignment) */
function client_customer_ids(int $clientId): array {
    return array_map('intval', array_column(rows("SELECT DISTINCT us.id FROM users us LEFT JOIN customer_clients md ON md.user_id=us.id
        WHERE us.role='customer' AND us.is_active=1 AND (us.client_id=? OR md.client_id=?)", [$clientId, $clientId]), 'id'));
}

/* ---------------- Mentions (@mention) & task tags ---------------- */

/** Highlights @First Last mentions in text (matched against active user names, longest name first) */
function highlight_mentions(string $escapedText): string {
    static $names = null;
    if ($names === null) {
        $names = array_column(rows("SELECT name FROM users WHERE is_active=1 ORDER BY CHAR_LENGTH(name) DESC"), 'name');
    }
    foreach ($names as $name) {
        $escaped = e($name); // the text is already escaped with e()
        $escapedText = str_ireplace('@' . $escaped, '<span class="mention">@' . $escaped . '</span>', $escapedText);
    }
    return $escapedText;
}

/** Converts comma-separated task tags into colored chips */
function tag_chips(?string $tags, string $extraClass = ''): string {
    if (!$tags) return '';
    $h = '';
    foreach (array_filter(array_map('trim', explode(',', $tags))) as $tag) {
        $hue = crc32(mb_strtolower($tag)) % 360; // stable color per tag
        $h .= '<span class="label-chip ' . $extraClass . '" style="--chip-tone:' . $hue . '">' . e($tag) . '</span>';
    }
    return $h;
}

/** Notifies mentioned user ids (expects a JSON array) */
function notify_mentions(string $tagsJson, string $title, string $message, string $link): void {
    $ids = json_decode($tagsJson, true);
    if (!is_array($ids)) return;
    foreach (array_unique(array_map('intval', $ids)) as $uid) {
        if ($uid > 0) notify($uid, $title, $message, $link, 'message');
    }
}

/* ---------------- Recurring task automation ----------------
 * Requires no cron setup on the server: triggered hourly during page loads.
 * Optionally, /cron.php can also be wired to a real cron job.
 */
/**
 * Atomic once-per-value claim (e.g. once per day): exactly one caller gets true.
 * The old "read, compare, then write" pairs let two simultaneous requests both
 * pass the check and send every reminder twice.
 */
function claim_once(string $key, string $value): bool {
    if (val("SELECT setting_value FROM settings WHERE setting_key=?", [$key]) === $value) return false; // cheap read first
    $st = q("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = VALUES(setting_value), setting_value, VALUES(setting_value))", [$key, $value]);
    return $st->rowCount() > 0; // 1 = inserted, 2 = changed, 0 = already claimed
}

/** Atomic interval claim: true for the single caller that moves the timestamp forward. */
function claim_interval(string $key, int $seconds): bool {
    $now = time();
    $last = val("SELECT setting_value FROM settings WHERE setting_key=?", [$key]);
    if ($last !== false && (int)$last > $now - $seconds) return false; // cheap read first: no writes on ordinary page loads
    if ($last === false) q("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, '0')", [$key]);
    // a non-numeric value (e.g. a date written by hand) would make the CAST below fail on every run
    elseif (!ctype_digit((string)$last)) q("UPDATE settings SET setting_value='0' WHERE setting_key=? AND setting_value=?", [$key, $last]);
    $st = q("UPDATE settings SET setting_value=? WHERE setting_key=? AND CAST(setting_value AS UNSIGNED) <= ?", [(string)$now, $key, $now - $seconds]);
    return $st->rowCount() > 0;
}

function run_recurring_jobs(bool $force = false): int {
    if ($force) q("INSERT INTO settings (setting_key, setting_value) VALUES ('last_repeat_check', ?) ON DUPLICATE KEY UPDATE setting_value=?", [time(), time()]);
    elseif (!claim_interval('last_repeat_check', 3600)) return 0;
    $GLOBALS['sada_system_actor'] = true;

    $count = 0;
    // Housekeeping: the login log only matters for the 15-minute lockout window
    q("DELETE FROM login_attempts WHERE created < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    foreach (rows("SELECT * FROM tasks WHERE `repeat`!='none' AND status!='cancelled'") as $g) {
        $periodKey = $g['repeat'] === 'weekly' ? date('o-W') : date('Y-m');
        if ($g['last_repeat'] === $periodKey) continue;
        if ($g['last_repeat'] === null) {
            // First period: the task itself is already this period's work — just stamp it
            update_row('tasks', ['last_repeat' => $periodKey], 'id=?', [$g['id']]);
            continue;
        }
        // A new period has started: create a fresh copy from the template task
        $newLastDate = $g['repeat'] === 'weekly' ? date('Y-m-d', strtotime('sunday this week')) : date('Y-m-t');
        $periodId = null;
        $projectType = val("SELECT type FROM projects WHERE id=?", [$g['project_id']]);
        if ($projectType === 'monthly') $periodId = get_or_create_period((int)$g['project_id'], (int)date('Y'), (int)date('n'));
        $newId = insert('tasks', [
            'project_id' => $g['project_id'], 'period_id' => $periodId,
            'title' => $g['title'],
            'description' => $g['description'],
            'assignee_id' => $g['assignee_id'], 'created_by' => $g['created_by'],
            'priority' => $g['priority'], 'status' => 'todo', 'kind' => $g['kind'], 'platforms' => $g['platforms'], 'type_id' => $g['type_id'], 'lane' => $g['lane'],
            'due_date' => $newLastDate, 'repeat' => 'none',
            'created' => date('Y-m-d H:i:s'),
        ]);
        // Copy the workflow steps in a reset state
        $steps = rows("SELECT * FROM task_steps WHERE task_id=? ORDER BY sort_order", [$g['id']]);
        foreach ($steps as $i => $a) {
            insert('task_steps', [
                'task_id' => $newId, 'sort_order' => $a['sort_order'], 'name' => $a['name'], 'skill_id' => $a['skill_id'], 'kind' => $a['kind'],
                'owner_id' => $a['owner_id'], 'status' => $i === 0 ? 'active' : 'pending',
            ]);
        }
        if ($steps) { if ($first = task_active_step($newId)) step_announce($first); task_sync_from_steps($newId); }
        // Copy the checklist in a reset state
        foreach (rows("SELECT * FROM task_checklist WHERE task_id=? ORDER BY sort_order", [$g['id']]) as $k) {
            insert('task_checklist', ['task_id' => $newId, 'name' => $k['name'], 'is_done' => 0, 'sort_order' => $k['sort_order']]);
        }
        update_row('tasks', ['last_repeat' => $periodKey], 'id=?', [$g['id']]);
        if ($g['assignee_id']) notify((int)$g['assignee_id'], 'Tekrarlayan iş oluşturuldu', $g['title'], 'task.php?id=' . $newId, 'task');
        $count++;
    }

    /* --- Monthly salary expenses: auto-created at the start of each month --- */
    $thisMonth = date('Y-m');
    foreach (rows("SELECT id, name, salary FROM users WHERE salary>0 AND is_active=1") as $person) {
        $var = val("SELECT COUNT(*) FROM expenses WHERE type='salary' AND user_id=? AND last_repeat=?", [$person['id'], $thisMonth]);
        if (!$var) {
            insert('expenses', [
                'type' => 'salary', 'title' => $person['name'] . ' — ' . MONTHS[(int)date('n')] . ' maaşı',
                'amount' => $person['salary'], 'date' => date('Y-m-01'), 'status' => 'pending',
                'repeat' => 'none', 'last_repeat' => $thisMonth, 'user_id' => $person['id'], 'created' => date('Y-m-d H:i:s'),
            ]);
        }
    }
    /* --- Monthly recurring expenses (rent, subscriptions, etc.) --- */
    foreach (rows("SELECT * FROM expenses WHERE `repeat`='monthly'") as $gd) {
        if ($gd['last_repeat'] === $thisMonth) continue;
        if ($gd['last_repeat'] === null) { update_row('expenses', ['last_repeat' => $thisMonth], 'id=?', [$gd['id']]); continue; }
        insert('expenses', [
            'type' => $gd['type'], 'title' => $gd['title'], 'amount' => $gd['amount'],
            'date' => date('Y-m-01'), 'status' => 'pending', 'repeat' => 'none',
            'last_repeat' => $thisMonth, 'user_id' => $gd['user_id'], 'description' => $gd['description'],
            'created' => date('Y-m-d H:i:s'),
        ]);
        update_row('expenses', ['last_repeat' => $thisMonth], 'id=?', [$gd['id']]);
    }

    /* --- Meeting reminder: notify participants ~1 hour ahead --- */
    $upcomingMeetings = rows("SELECT * FROM events WHERE type='meeting' AND is_reminded=0
        AND start BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 75 MINUTE)");
    foreach ($upcomingMeetings as $top) {
        $time = date('H:i', strtotime($top['start']));
        $messageText = $time . ' — ' . ($top['place'] ?: '') . ($top['online_link'] ? ' (online)' : '');
        $recipients = array_column(rows("SELECT user_id FROM event_participants WHERE event_id=?", [$top['id']]), 'user_id');
        $recipients[] = (int)$top['created_by'];
        foreach (array_unique($recipients) as $aid) {
            notify((int)$aid, '⏰ Toplantı yaklaşıyor: ' . $top['title'], $messageText, 'meetings.php', 'task');
        }
        update_row('events', ['is_reminded' => 1], 'id=?', [$top['id']]);
    }

    /* --- Daily digest: once a day per user — "what awaits you today" --- */
    if (claim_once('last_user_daily_digest', date('Y-m-d'))) {
        $today = date('Y-m-d');
        foreach (rows("SELECT id FROM users WHERE is_active=1 AND role!='customer'") as $person) {
            $kid = (int)$person['id'];
            $parts = [];
            $taskCount = (int)val("SELECT COUNT(*) FROM tasks g WHERE g.is_archived=0 AND " . task_open_sql('g') . " AND g.due_date=?
                AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees ga WHERE ga.task_id=g.id AND ga.user_id=?))", [$today, $kid, $kid]);
            if ($taskCount) $parts[] = $taskCount . ' iş teslimi';
            $topCount = (int)val("SELECT COUNT(*) FROM events e WHERE e.type='meeting' AND DATE(e.start)=?
                AND (e.created_by=? OR EXISTS(SELECT 1 FROM event_participants ek WHERE ek.event_id=e.id AND ek.user_id=?))", [$today, $kid, $kid]);
            if ($topCount) $parts[] = $topCount . ' toplantı';
            $shootCount = (int)val("SELECT COUNT(*) FROM events WHERE type!='meeting' AND DATE(start)<=? AND DATE(COALESCE(`end`,start))>=?", [$today, $today]);
            if ($shootCount) $parts[] = $shootCount . ' etkinlik';
            $contentCount = (int)val("SELECT COUNT(*) FROM tasks WHERE is_archived=0 AND publish_date=? AND " . task_open_sql(), [$today]);
            if ($contentCount) $parts[] = $contentCount . ' içerik yayını';
            if ($parts) {
                notify($kid, '🌅 Bugün seni bekleyenler', implode(' · ', $parts), 'index.php', 'task', false);
            }
        }
    }

    /* --- Weekly manager digest: once every Monday --- */
    $buWeek = date('o-W');
    if (date('N') == 1 && claim_once('last_user_weekly_digest', $buWeek)) {
        $hb = date('Y-m-d', strtotime('-7 days'));
        $summary = [];
        $t1 = (int)val("SELECT COUNT(*) FROM tasks WHERE " . task_done_sql() . " AND completion>=?", [$hb]);
        if ($t1) $summary[] = $t1 . ' iş tamamlandı';
        $t2 = (int)val("SELECT COUNT(*) FROM tasks WHERE is_archived=0 AND " . task_open_sql() . " AND due_date<CURDATE()");
        if ($t2) $summary[] = $t2 . ' iş gecikmede';
        $t3 = (int)val("SELECT COUNT(*) FROM requests WHERE created>=?", [$hb]);
        if ($t3) $summary[] = $t3 . ' yeni talep';
        $t4 = val("SELECT ROUND(AVG(rating),1) FROM ratings WHERE created>=?", [$hb]);
        if ($t4) $summary[] = 'ort. puan ' . $t4 . '★';
        $t5 = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid' AND date>=?", [$hb]);
        $t6 = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='paid' AND date>=?", [$hb]);
        if ($t5 || $t6) $summary[] = 'gelir ' . money($t5) . ' / gider ' . money($t6);
        if ($summary) {
            foreach (rows("SELECT id FROM users WHERE role IN ('admin','pm') AND is_active=1") as $yo) {
                notify((int)$yo['id'], '📅 Haftalık özet', implode(' · ', $summary), 'reports.php', 'task');
            }
        }
    }

    /* --- Contract expiry reminder (30 days ahead, once) --- */
    foreach (rows("SELECT s.*, d.name client_name FROM contracts s JOIN clients d ON d.id=s.client_id WHERE s.is_reminded=0 AND s.end IS NOT NULL AND s.end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND s.end >= CURDATE()") as $sz) {
        foreach (rows("SELECT id FROM users WHERE role IN ('admin','pm') AND is_active=1") as $ya) {
            notify((int)$ya['id'], '⏰ Sözleşme bitiyor: ' . $sz['client_name'], '"' . $sz['title'] . '" sözleşmesi ' . format_date($sz['end']) . ' tarihinde sona eriyor.', 'client.php?id=' . $sz['client_id'], 'task');
        }
        update_row('contracts', ['is_reminded' => 1], 'id=?', [$sz['id']]);
    }

    /* --- Month rhythm: once a month remind the manager of a monthly project whose month is not open yet,
     *     and from the 5th on, of last month still not closed --- */
    if (claim_once('last_month_open_check', date('Y-m'))) {
        foreach (rows("SELECT p.id, p.name, p.pm_id, c.name client_name FROM projects p JOIN clients c ON c.id=p.client_id
            WHERE p.type='monthly' AND p.status='active' AND NOT EXISTS (SELECT 1 FROM periods d WHERE d.project_id=p.id AND d.year=? AND d.month=?)", [(int)date('Y'), (int)date('n')]) as $mp) {
            $who = $mp['pm_id'] ? [(int)$mp['pm_id']] : array_map('intval', array_column(rows("SELECT id FROM users WHERE role='admin' AND is_active=1"), 'id'));
            foreach ($who as $uid) notify($uid, '📅 ' . MONTHS[(int)date('n')] . ' ayı açılmadı: ' . $mp['client_name'], $mp['name'] . ' projesinde bu ayın planı henüz yok.', 'project.php?id=' . $mp['id'] . '#periods', 'task');
        }
    }
    if ((int)date('j') >= 5 && claim_once('last_month_close_check', date('Y-m'))) {
        $last = strtotime('first day of last month');
        foreach (rows("SELECT d.id, d.year, d.month, p.name, p.pm_id FROM periods d JOIN projects p ON p.id=d.project_id
            WHERE p.status='active' AND d.year=? AND d.month=? AND d.phase!='closed'", [(int)date('Y', $last), (int)date('n', $last)]) as $lm) {
            $who = $lm['pm_id'] ? [(int)$lm['pm_id']] : array_map('intval', array_column(rows("SELECT id FROM users WHERE role='admin' AND is_active=1"), 'id'));
            foreach ($who as $uid) notify($uid, '🗂 ' . period_name($lm) . ' kapanmadı', $lm['name'] . ': açık işleri taşıyıp raporu yazdıktan sonra ayı kapatın.', 'month.php?id=' . $lm['id'], 'task');
        }
    }

    require_once __DIR__ . '/mailer.php'; // due/digest/drive mails below need it

    /* --- Task due-date chain: notification (+ e-mail via notify's preferences), max once per day --- */
    if (claim_once('last_due_check', date('Y-m-d'))) {
        $dueTasks = rows("SELECT g.id, g.title, g.due_date, g.assignee_id,
            (SELECT GROUP_CONCAT(ga.user_id) FROM task_assignees ga WHERE ga.task_id=g.id) assignee_ids
            FROM tasks g WHERE g.is_archived=0 AND " . task_open_sql('g') . " AND g.due_date IS NOT NULL
            AND g.due_date <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)");
        foreach ($dueTasks as $dt) {
            if (sada_deadline_passed()) break;
            $who = array_filter(array_unique(array_merge([(int)$dt['assignee_id']], array_map('intval', explode(',', (string)$dt['assignee_ids'])))));
            $overdue = $dt['due_date'] < date('Y-m-d');
            foreach ($who as $uid) {
                $title = $overdue ? '🔴 İş gecikti: ' . $dt['title'] : '⏳ Son gün yarın: ' . $dt['title'];
                $body = $overdue
                    ? 'Son tarihi ' . format_date($dt['due_date']) . ' olan iş hâlâ tamamlanmadı.'
                    : 'İşin son tarihi yarın (' . format_date($dt['due_date']) . ').';
                // notify() mails too (when e-mail notifications are on) — a second explicit
                // send here used to double every reminder and double the SMTP time
                notify($uid, $title, $body, 'task.php?id=' . $dt['id'], 'task');
            }
        }
    }

    /* --- Daily manager digest e-mail (first run of the day after 07:00) --- */
    if ((int)date('G') >= 7 && claim_once('last_daily_digest', date('Y-m-d'))) {
        $overdueList = rows("SELECT g.title, g.due_date, u.name FROM tasks g LEFT JOIN users u ON u.id=g.assignee_id WHERE g.is_archived=0 AND " . task_open_sql('g') . " AND g.due_date < CURDATE() ORDER BY g.due_date LIMIT 15");
        $todayShoots = rows("SELECT title, start FROM events WHERE type='shoot' AND DATE(start)=CURDATE()");
        $pendingApprovals = (int)val("SELECT COUNT(*) FROM approvals WHERE status='pending'");
        $missingDrive = (int)val("SELECT COUNT(*) FROM events WHERE type='shoot' AND drive_status='pending' AND COALESCE(`end`, start) < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND start > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        if ($overdueList || $todayShoots || $pendingApprovals || $missingDrive) {
            $summaryText = "Günaydın! " . date('d.m.Y') . " özeti:\n\n";
            if ($overdueList) { $summaryText .= "GECİKEN GÖREVLER (" . count($overdueList) . "):\n"; foreach ($overdueList as $o2) $summaryText .= "- " . $o2['title'] . ' (' . ($o2['name'] ?: 'atanmamış') . ', son: ' . format_date($o2['due_date']) . ")\n"; $summaryText .= "\n"; }
            if ($todayShoots) { $summaryText .= "BUGÜNÜN ÇEKİMLERİ:\n"; foreach ($todayShoots as $s2) $summaryText .= "- " . $s2['title'] . ' (' . substr($s2['start'], 11, 5) . ")\n"; $summaryText .= "\n"; }
            if ($pendingApprovals) $summaryText .= "Bekleyen onay: $pendingApprovals\n";
            if ($missingDrive) $summaryText .= "Drive'a aktarılmamış çekim: $missingDrive\n";
            $summaryText .= "\nPanel: " . full_url('index.php');
            foreach (rows("SELECT email FROM users WHERE role IN ('admin','pm') AND is_active=1") as $yd) {
                if (sada_deadline_passed()) break;
                if ($yd['email']) send_email($yd['email'], '📋 SADA One günlük özet — ' . date('d.m.Y'), $summaryText);
            }
        }
    }

    /* --- Drive transfer tracking (max once per day) ---
     * Semi-automatic: a finished shoot without a Drive link/mark → warn the crew.
     * Fully automatic (if the service account is configured): look into the shoot's
     * (or client's) Drive folder; files created after the shoot start → auto-mark. */
    if (claim_once('last_drive_check', date('Y-m-d'))) {
        require_once __DIR__ . '/google-drive.php';
        $driveOn = drive_configured();
        $driveToken = $driveOn ? drive_token() : null;
        $pendingShoots = rows("SELECT e.id, e.title, e.start, e.created_by, e.drive_folder_id, e.drive_link, e.drive_files_seen,
            c.drive_folder_id client_folder, c.manager_id,
            (SELECT GROUP_CONCAT(ep.user_id) FROM event_participants ep WHERE ep.event_id=e.id) participant_ids
            FROM events e LEFT JOIN clients c ON c.id = COALESCE(e.client_id, (SELECT client_id FROM projects WHERE id=e.project_id))
            WHERE e.type='shoot' AND e.drive_status='pending'
            AND COALESCE(e.`end`, e.start) < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND e.start > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        foreach ($pendingShoots as $sh) {
            if (sada_deadline_passed()) break; // the rest is picked up tomorrow
            $folder = $sh['drive_folder_id'] ?: $sh['client_folder'];
            $who = array_filter(array_unique(array_merge(
                array_map('intval', explode(',', (string)$sh['participant_ids'])),
                [(int)$sh['created_by'], (int)$sh['manager_id']])));
            $warn = function (string $title, string $text) use ($who) {
                foreach ($who as $uid) notify($uid, $title, $text, 'shoot-list.php', 'task'); // mails per preference
            };
            // A link counts as human confirmation only when someone actually ADDED it —
            // auto-created folders store their own URL in drive_link, that must not count
            $autoLink = $sh['drive_folder_id'] ? 'https://drive.google.com/drive/folders/' . $sh['drive_folder_id'] : null;
            if ($sh['drive_link'] && $sh['drive_link'] !== $autoLink) {
                update_row('events', ['drive_status' => 'transferred'], 'id=?', [$sh['id']]);
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
                        $warn('📁 Klasörde dosyalar görüldü: ' . $sh['title'],
                            '"' . $sh['title'] . '" çekiminin klasöründe ' . $r['count'] . '+ dosya var. Yüklenmesi gereken HER ŞEY yüklendiyse çekim listesinden "Tümü yüklendi" olarak işaretleyin.');
                    } else {
                        $warn('⏳ Yükleme onayı bekleniyor: ' . $sh['title'],
                            'Klasörde dosyalar var ama "tümü yüklendi" onayı verilmedi. Eksik kalmadıysa çekim listesinden işaretleyin.');
                    }
                    continue;
                }
            }
            // No files at all → strong warning
            $warn('📁 Drive aktarımı bekleniyor: ' . $sh['title'],
                format_date($sh['start'], true) . ' tarihli çekimin görüntüleri henüz Drive\'a aktarılmadı. Aktardıysanız çekim listesinden işaretleyin.');
        }
    }

    /* --- Monthly-report reminders (max once per day) ---
     * Window 1: last 3 days of the month  → remind the client manager about the CURRENT month
     * Window 2: first 3 days of the month → remind about the PREVIOUS month
     * Day 4:    still empty               → escalate to admins/PMs as overdue */
    $today = date('Y-m-d');
    if (claim_once('last_report_reminder', $today)) {
        $day = (int)date('j');
        $lastDay = (int)date('t');
        $missing = function (string $period): array {
            return rows("SELECT c.id, c.name, c.manager_id, r.status
                FROM clients c LEFT JOIN monthly_reports r ON r.client_id=c.id AND r.period=?
                WHERE c.status='active' AND c.manager_id IS NOT NULL AND (r.id IS NULL OR r.status='draft')", [$period]);
        };
        if ($day >= $lastDay - 2) {
            $period = date('Y-m');
            foreach ($missing($period) as $cl) {
                notify((int)$cl['manager_id'], '📊 Aylık rapor zamanı: ' . $cl['name'],
                    ($cl['status'] === 'draft' ? 'Taslak raporu tamamlayıp' : 'Bu ayın raporunu doldurup') . ' "Tamamlandı" olarak kaydedin.',
                    'monthly-reports.php?client=' . $cl['id'] . '&period=' . $period, 'task');
            }
        }
        if ($day <= 3) {
            $period = date('Y-m', strtotime('first day of last month'));
            foreach ($missing($period) as $cl) {
                notify((int)$cl['manager_id'], '📊 Geçen ayın raporu bekliyor: ' . $cl['name'],
                    'Önceki ayın (' . $period . ') raporu henüz tamamlanmadı.',
                    'monthly-reports.php?client=' . $cl['id'] . '&period=' . $period, 'task');
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
                        'monthly-reports.php?client=' . $cl['id'] . '&period=' . $period, 'task');
                }
                foreach (rows("SELECT id FROM users WHERE role IN ('admin','pm') AND is_active=1") as $ya) {
                    notify((int)$ya['id'], '🔴 Geciken aylık raporlar', $period . ' dönemi için eksik: ' . mb_substr($names, 0, 180), 'monthly-reports.php', 'task');
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
    $g = row("SELECT status, lock_bypassed, depends_on_id, assignee_id, is_archived, title, due_date FROM tasks WHERE id=?", [$id]);
    $steps = val("SELECT GROUP_CONCAT(CONCAT(id,':',status) ORDER BY sort_order) FROM task_steps WHERE task_id=?", [$id]);
    $check = val("SELECT GROUP_CONCAT(CONCAT(id,':',is_done) ORDER BY sort_order) FROM task_checklist WHERE task_id=?", [$id]);
    $comment = val("SELECT CONCAT(COUNT(*),':',COALESCE(MAX(id),0),':',SUM(is_edited)) FROM comments WHERE ref_type='task' AND ref_id=?", [$id]);
    $reaction = val("SELECT COUNT(*) FROM comment_reactions t JOIN comments y ON y.id=t.comment_id WHERE y.ref_type='task' AND y.ref_id=?", [$id]);
    $ek = val("SELECT COUNT(*) FROM archive WHERE task_id=?", [$id]);
    $watcher = val("SELECT COUNT(*) FROM task_watchers WHERE task_id=?", [$id]);
    // The dependency task's status also affects the lock
    $dependentStatus = $g && $g['depends_on_id'] ? val("SELECT status FROM tasks WHERE id=?", [$g['depends_on_id']]) : '';
    return md5(json_encode([$g, $steps, $check, $comment, $reaction, $ek, $watcher, $dependentStatus]));
}

function live_hash_list(): string {
    // Aggregates instead of GROUP_CONCAT: that one silently truncates at
    // group_concat_max_len (1 KB ≈ 60 tasks), after which the hash stopped changing
    return md5(json_encode(row("SELECT COUNT(*) c, COALESCE(MAX(id),0) m,
        COALESCE(SUM(CRC32(CONCAT_WS(':',id,status,sort_order,is_archived,COALESCE(assignee_id,0)))),0) s FROM tasks")));
}

/** Has the user enabled the 'only steps I am responsible for' preference? */
function only_own_steps(): bool {
    $t = json_decode(user()['notification_preferences'] ?? '', true);
    return is_array($t) && !empty($t['only_own_steps']);
}

/* ---------------- Task (İş) status ---------------- */

/** Is the task still open? Completed, published and cancelled work is closed. */
function task_is_open(string $status): bool { return !in_array($status, TASK_CLOSED, true); }

/** SQL condition for open tasks, e.g. task_open_sql('g') → g.status NOT IN (…) */
function task_open_sql(string $alias = ''): string {
    return ($alias !== '' ? "$alias." : '') . "status NOT IN ('" . implode("','", TASK_CLOSED) . "')";
}

/** SQL condition for delivered tasks (completed or published; cancelled work is not delivered) */
function task_done_sql(string $alias = ''): string {
    return ($alias !== '' ? "$alias." : '') . "status IN ('completed','published')";
}

/**
 * Moves a task to a new status: lock rules, completion stamp, activity log and notifications to the
 * assignee and watchers. Every status change (page, board, table, approvals, steps) goes through here.
 * Returns an error message, or null when done.
 */
function task_set_status(array $task, string $status, bool $checkLock = true): ?string {
    if (!isset(TASK_STATUSES[$status])) return 'Geçersiz durum.';
    if ($task['status'] === $status) return null;
    if ($checkLock && ($block = task_lock_reason($task, $status))) return '🔒 ' . $block;
    $data = ['status' => $status];
    if (in_array($status, ['completed', 'published'], true)) {
        if (!in_array($task['status'], ['completed', 'published'], true)) $data['completion'] = date('Y-m-d H:i:s');
    } else {
        $data['completion'] = null;
    }
    update_row('tasks', $data, 'id=?', [$task['id']]);
    log_activity('"' . $task['title'] . '" işini ' . TASK_STATUSES[$status] . ' durumuna aldı', 'task', (int)$task['id']);
    $recipients = array_column(rows("SELECT user_id FROM task_watchers WHERE task_id=?", [$task['id']]), 'user_id');
    if (!empty($task['assignee_id'])) $recipients[] = (int)$task['assignee_id'];
    foreach (array_unique(array_map('intval', $recipients)) as $aid)
        notify($aid, 'İşin durumu değişti', $task['title'] . ' → ' . TASK_STATUSES[$status], 'task.php?id=' . $task['id'], 'task');
    return null;
}

/* ---------------- Step engine (7.2) ----------------
 * A task built from a task type moves through its steps; its status follows the active step
 * (work → Devam Ediyor, review → İç Onayda, client approval → Müşteride, publish → Tamamlandı ·
 * yayın bekliyor; all done → Tamamlandı / Yayınlandı). An active step without an owner that has a
 * skill sits in that skill's pool: anyone with the skill can take it.
 */
require_once __DIR__ . '/migration-steps.php'; // step_status_for(), shared with the migration

const STEP_KINDS = ['work' => 'Üretim', 'review' => 'İç kontrol', 'client_approval' => 'Müşteri onayı', 'publish' => 'Yayın'];

function skills_all(): array { return rows("SELECT id, name FROM skills ORDER BY sort_order, name"); }
function user_skill_ids(int $userId): array { return array_map('intval', array_column(rows("SELECT skill_id FROM user_skills WHERE user_id=?", [$userId]), 'skill_id')); }
function task_has_steps(int $taskId): bool { return (bool)val("SELECT COUNT(*) FROM task_steps WHERE task_id=?", [$taskId]); }

/** Creates a task's steps from its task type; $owners maps a type-step id (or its position) to a user id, 0 = pool */
function task_steps_setup(int $taskId, int $typeId, array $owners = []): void {
    $task = row("SELECT t.id, p.pm_id, c.no_approval_types FROM tasks t JOIN projects p ON p.id=t.project_id JOIN clients c ON c.id=p.client_id WHERE t.id=?", [$taskId]);
    $coordination = (int)val("SELECT id FROM skills WHERE name='Koordinasyon'");
    // The client file may skip client approval for this type of work (e.g. story, daily post)
    $skipApproval = $task && in_array($typeId, array_map('intval', explode(',', (string)$task['no_approval_types'])), true);
    $placed = 0;
    foreach (rows("SELECT * FROM task_type_steps WHERE type_id=? ORDER BY sort_order, id", [$typeId]) as $i => $st) {
        if ($skipApproval && $st['kind'] === 'client_approval') continue;
        $owner = array_key_exists($st['id'], $owners) ? $owners[$st['id']] : ($owners[$i] ?? null);
        // No choice made: the type's default person, else the project manager for coordination steps
        if ($owner === null) $owner = $st['owner_id'] ?: ((int)$st['skill_id'] === $coordination && $task ? $task['pm_id'] : null);
        insert('task_steps', [
            'task_id' => $taskId, 'sort_order' => $st['sort_order'], 'name' => $st['name'],
            'skill_id' => $st['skill_id'], 'kind' => $st['kind'], 'owner_id' => $owner ? (int)$owner : null,
            'status' => $placed++ === 0 ? 'active' : 'pending',
        ]);
        if ($owner) q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$taskId, (int)$owner]);
    }
    update_row('tasks', ['type_id' => $typeId], 'id=?', [$taskId]);
    if ($first = row("SELECT * FROM task_steps WHERE task_id=? AND status='active' LIMIT 1", [$taskId])) step_announce($first);
    task_sync_from_steps($taskId);
}

/** Recomputes a task's status (and who holds it) from its steps. Cancelled work stays cancelled. */
function task_sync_from_steps(int $taskId): void {
    $task = row("SELECT * FROM tasks WHERE id=?", [$taskId]);
    if (!$task || $task['status'] === 'cancelled') return;
    $steps = rows("SELECT status, kind, owner_id FROM task_steps WHERE task_id=? ORDER BY sort_order, id", [$taskId]);
    if (!$steps) return;
    $status = step_status_for($steps);
    if ($status && $status !== $task['status']) task_set_status($task, $status, false);
    // The person holding the work right now: the active step's owner
    foreach ($steps as $s) if ($s['status'] === 'active') {
        if ($s['owner_id'] && (int)$s['owner_id'] !== (int)$task['assignee_id']) update_row('tasks', ['assignee_id' => (int)$s['owner_id']], 'id=?', [$taskId]);
        break;
    }
}

/** Tells the owner it is their turn, or the skill's pool that work is waiting. */
function step_announce(array $step): void {
    $title = (string)val("SELECT title FROM tasks WHERE id=?", [$step['task_id']]);
    if ($step['owner_id']) { notify((int)$step['owner_id'], 'Sıra sende: ' . $step['name'], $title, 'task.php?id=' . $step['task_id'], 'task'); return; }
    if (!$step['skill_id']) return;
    $skill = (string)val("SELECT name FROM skills WHERE id=?", [$step['skill_id']]);
    foreach (rows("SELECT u.id, u.notification_preferences FROM user_skills us JOIN users u ON u.id=us.user_id WHERE us.skill_id=? AND u.is_active=1 AND u.role!='customer'", [$step['skill_id']]) as $person) {
        $pref = json_decode((string)$person['notification_preferences'], true);
        if (is_array($pref) && !empty($pref['only_own_steps'])) continue; // "only my steps" opts out of pool alerts
        notify((int)$person['id'], $skill . ' havuzuna iş düştü', $title . ' → ' . $step['name'], 'task.php?id=' . $step['task_id'], 'task');
    }
}

/** May this user act on (finish / send back) the step? Owner, managers, or anyone with the skill for a pool step. */
function step_can_act(array $step, array $user): bool {
    if (in_array($user['role'], ['admin', 'pm'], true)) return true;
    if ($step['owner_id']) return (int)$step['owner_id'] === (int)$user['id'];
    return !$step['skill_id'] || in_array((int)$step['skill_id'], user_skill_ids((int)$user['id']), true);
}

/** Finishes the active step and hands the work to the next one. Returns an error or null. */
function step_finish(array $step, int $userId): ?string {
    if ($step['status'] !== 'active') return 'Yalnızca sıradaki adım bitirilebilir.';
    update_row('task_steps', ['status' => 'done', 'done_date' => date('Y-m-d H:i:s'), 'done_by' => $userId, 'owner_id' => $step['owner_id'] ?: $userId], 'id=?', [$step['id']]);
    $next = row("SELECT * FROM task_steps WHERE task_id=? AND status!='done' AND (sort_order>? OR (sort_order=? AND id>?)) ORDER BY sort_order, id LIMIT 1", [$step['task_id'], $step['sort_order'], $step['sort_order'], $step['id']]);
    if ($next) { update_row('task_steps', ['status' => 'active'], 'id=?', [$next['id']]); $next['status'] = 'active'; step_announce($next); }
    task_sync_from_steps((int)$step['task_id']);
    return null;
}

/** Sends the work back from a review / client-approval step to the last finished production step. */
function step_send_back(array $step, string $note, int $userId, string $who = ''): ?string {
    if ($step['status'] !== 'active') return 'Yalnızca sıradaki adım geri gönderilebilir.';
    $target = row("SELECT * FROM task_steps WHERE task_id=? AND kind='work' AND status='done' AND sort_order<=? AND id!=? ORDER BY sort_order DESC, id DESC LIMIT 1", [$step['task_id'], $step['sort_order'], $step['id']])
        ?: row("SELECT * FROM task_steps WHERE task_id=? ORDER BY sort_order, id LIMIT 1", [$step['task_id']]);
    if (!$target || (int)$target['id'] === (int)$step['id']) return 'Geri gönderilecek bir üretim adımı yok.';
    q("UPDATE task_steps SET status='pending', done_date=NULL, done_by=NULL WHERE task_id=? AND sort_order>? AND sort_order<=?", [$step['task_id'], $target['sort_order'], $step['sort_order']]);
    update_row('task_steps', ['status' => 'active', 'done_date' => null, 'done_by' => null], 'id=?', [$target['id']]);
    // The reason lands in the task's discussion, so the maker sees it next to the work
    insert('comments', ['ref_type' => 'task', 'ref_id' => $step['task_id'], 'user_id' => $userId, 'created' => date('Y-m-d H:i:s'),
        'message' => '↩ ' . ($who !== '' ? $who . ' — ' : '') . $step['name'] . ' adımından "' . $target['name'] . '" adımına geri gönderildi' . (trim($note) !== '' ? ': ' . trim($note) : '')]);
    $target['status'] = 'active';
    step_announce($target);
    task_sync_from_steps((int)$step['task_id']);
    return null;
}

/** Reopens a finished step (undo); the steps after it wait again. */
function step_reopen(array $step): ?string {
    if ($step['status'] !== 'done') return 'Bu adım zaten açık.';
    q("UPDATE task_steps SET status='pending', done_date=NULL, done_by=NULL WHERE task_id=? AND status IN ('done','active') AND (sort_order>? OR (sort_order=? AND id>?))", [$step['task_id'], $step['sort_order'], $step['sort_order'], $step['id']]);
    update_row('task_steps', ['status' => 'active', 'done_date' => null, 'done_by' => null], 'id=?', [$step['id']]);
    $task = row("SELECT * FROM tasks WHERE id=?", [$step['task_id']]);
    if ($task && in_array($task['status'], ['completed', 'published'], true)) task_set_status($task, 'in_progress', false);
    task_sync_from_steps((int)$step['task_id']);
    return null;
}

/** The active step of a task, if any */
function task_active_step(int $taskId): ?array { return row("SELECT * FROM task_steps WHERE task_id=? AND status='active' ORDER BY sort_order, id LIMIT 1", [$taskId]); }

/* ---------------- Task lock checks ---------------- */

/** Returns the reason blocking the task from progressing; null if there is none. */
function task_lock_reason(array $task, string $targetStatus): ?string {
    if (!empty($task['lock_bypassed'])) return null; // an admin has bypassed the lock
    // Dependency: cannot move past 'todo' until the linked task is closed (cancelling is always allowed)
    if ($task['depends_on_id'] && !in_array($targetStatus, ['todo', 'cancelled'], true)) {
        $dependent = row("SELECT title, status FROM tasks WHERE id=?", [$task['depends_on_id']]);
        if ($dependent && task_is_open($dependent['status'])) {
            return '"' . $dependent['title'] . '" işi tamamlanmadan bu iş ilerleyemez.';
        }
    }
    // Status lock: cannot be marked completed or published until the workflow steps are done
    if (in_array($targetStatus, ['completed', 'published'], true)) {
        $missing = (int)val("SELECT COUNT(*) FROM task_steps WHERE task_id=? AND status!='done'", [$task['id']]);
        if ($missing > 0) return "Akışta $missing tamamlanmamış adım var. Önce adımları bitirin.";
    }
    return null;
}

function project_channel(int $projectId, string $type = 'project'): int {
    $k = row("SELECT id FROM channels WHERE project_id=? AND type=?", [$projectId, $type]);
    if ($k) return (int)$k['id'];
    $project = row("SELECT name FROM projects WHERE id=?", [$projectId]);
    $name = $project['name'] ?? 'Proje';
    $channelId = insert('channels', ['name' => $name, 'type' => $type, 'project_id' => $projectId, 'created' => date('Y-m-d H:i:s')]);
    // Auto-add team members
    foreach (rows("SELECT id FROM users WHERE role IN ('admin','pm','team') AND is_active=1") as $u) {
        q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channelId, $u['id']]);
    }
    if ($type === 'customer') {
        $clientId = val("SELECT client_id FROM projects WHERE id=?", [$projectId]);
        foreach (rows("SELECT id FROM users WHERE role='customer' AND client_id=? AND is_active=1", [$clientId]) as $u) {
            q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channelId, $u['id']]);
        }
    }
    return $channelId;
}

// Runs after all helpers are defined: one-time legacy schema localization
legacy_schema_check();

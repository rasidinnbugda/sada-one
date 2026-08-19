<?php
/**
 * SADA One — Çekirdek başlatma dosyası
 * Oturum, veritabanı bağlantısı, yetki kontrolü ve yardımcı fonksiyonlar.
 */
// Oturum güvenliği: çerez sertleştirme (XSS ile çerez çalınması ve CSRF yüzeyini daraltır)
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
session_start();
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Istanbul');

define('ROOT', dirname(__DIR__));
define('BASE_URL', rtrim(dirname($_SERVER['SCRIPT_NAME']) === '/' ? '' : str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/'));

// Kurulum kontrolü
if (!file_exists(ROOT . '/config.php')) {
    header('Location: install/index.php');
    exit;
}
$GLOBALS['config'] = include ROOT . '/config.php';

/* ---------------- Veritabanı ---------------- */

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

function insert(string $tablo, array $veri): int {
    $kolonlar = implode(',', array_keys($veri));
    $yer = implode(',', array_fill(0, count($veri), '?'));
    q("INSERT INTO $tablo ($kolonlar) VALUES ($yer)", array_values($veri));
    return (int)db()->lastInsertId();
}

function guncelle(string $tablo, array $veri, string $kosul, array $kosulP = []): void {
    $set = implode(',', array_map(fn($k) => "$k=?", array_keys($veri)));
    q("UPDATE $tablo SET $set WHERE $kosul", array_merge(array_values($veri), $kosulP));
}

/* ---------------- Ayarlar ---------------- */

function ayar(string $anahtar, $varsayilan = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (rows("SELECT anahtar, deger FROM settings") as $r) $cache[$r['anahtar']] = $r['deger'];
    }
    return $cache[$anahtar] ?? $varsayilan;
}

/* ---------------- Oturum & Yetki ---------------- */

function user(): ?array {
    static $u = false;
    if ($u === false) {
        $u = empty($_SESSION['uid']) ? null : row("SELECT * FROM users WHERE id=? AND aktif=1", [$_SESSION['uid']]);
    }
    return $u;
}

function require_login(): array {
    $u = user();
    if (!$u) { header('Location: login.php'); exit; }
    return $u;
}

function is_admin(): bool { return (user()['rol'] ?? '') === 'yonetici'; }
function is_pm(): bool { return in_array(user()['rol'] ?? '', ['yonetici', 'pm']); }
function is_staff(): bool { return in_array(user()['rol'] ?? '', ['yonetici', 'pm', 'ekip', 'finans', 'stajyer']); }
function is_finans(): bool { return (user()['rol'] ?? '') === 'finans'; }
function is_stajyer(): bool { return (user()['rol'] ?? '') === 'stajyer'; }
function is_musteri(): bool { return (user()['rol'] ?? '') === 'musteri'; }

/** Yetkisiz erişimde: AJAX isteğiyse JSON 403, normal sayfa ise yönlendirme */
function yetkisiz(): void {
    if (defined('AJAX_ISTEK')) {
        json_out(['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.'], 403);
    }
    header('Location: index.php');
    exit;
}

function require_staff(): array {
    $u = require_login();
    if (!is_staff()) yetkisiz();
    return $u;
}
function require_pm(): array {
    $u = require_login();
    if (!is_pm()) yetkisiz();
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if (!is_admin()) yetkisiz();
    return $u;
}

/* ---------------- Kullanıcı bazlı izinler ----------------
 * Rol varsayılanları + kullanıcıya özel geçersiz kılmalar (users.izinler JSON).
 * Anahtarlar: finans, rapor, dosya_yonet, gorev_sil, icerik_yonet, kapasite
 */
const IZIN_ANAHTARLARI = [
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
];

function yetki(string $anahtar): bool {
    $u = user();
    if (!$u) return false;
    if ($u['rol'] === 'yonetici') return true;
    if ($u['rol'] === 'musteri') return false;
    // Kullanıcıya özel geçersiz kılma
    $ozel = json_decode($u['izinler'] ?? '', true);
    if (is_array($ozel) && array_key_exists($anahtar, $ozel)) return (bool)$ozel[$anahtar];
    // Rol varsayılanları
    $varsayilan = [
        'pm'      => ['finans' => 1, 'rapor' => 1, 'kapasite' => 1, 'dosya_yonet' => 1, 'gorev_olustur' => 1, 'gorev_sil' => 1, 'icerik_yonet' => 1, 'ekipman_yonet' => 1, 'onay_gonder' => 1, 'duyuru_yayinla' => 1, 'takvim_yonet' => 1, 'kanal_kur' => 1, 'belge_olustur' => 1, 'arsiv_sil' => 1, 'talep_yonet' => 1],
        'ekip'    => ['finans' => 0, 'rapor' => 0, 'kapasite' => 0, 'dosya_yonet' => 0, 'gorev_olustur' => 1, 'gorev_sil' => 0, 'icerik_yonet' => 1, 'ekipman_yonet' => 0, 'onay_gonder' => 1, 'duyuru_yayinla' => 0, 'takvim_yonet' => 1, 'kanal_kur' => 1, 'belge_olustur' => 0, 'arsiv_sil' => 0, 'talep_yonet' => 0],
        'finans'  => ['finans' => 1, 'rapor' => 1, 'kapasite' => 1, 'dosya_yonet' => 0, 'gorev_olustur' => 0, 'gorev_sil' => 0, 'icerik_yonet' => 0, 'ekipman_yonet' => 0, 'onay_gonder' => 0, 'duyuru_yayinla' => 0, 'takvim_yonet' => 0, 'kanal_kur' => 1, 'belge_olustur' => 1, 'arsiv_sil' => 0, 'talep_yonet' => 0],
        'stajyer' => ['finans' => 0, 'rapor' => 0, 'kapasite' => 0, 'dosya_yonet' => 0, 'gorev_olustur' => 0, 'gorev_sil' => 0, 'icerik_yonet' => 0, 'ekipman_yonet' => 0, 'onay_gonder' => 0, 'duyuru_yayinla' => 0, 'takvim_yonet' => 0, 'kanal_kur' => 0, 'belge_olustur' => 0, 'arsiv_sil' => 0, 'talep_yonet' => 0],
    ];
    return (bool)($varsayilan[$u['rol']][$anahtar] ?? 0);
}

function require_yetki(string $anahtar): array {
    $u = require_login();
    if (!yetki($anahtar)) yetkisiz();
    return $u;
}

/** Müşterinin erişebildiği dosya id'leri (birincil dosya + ek atamalar) */
function musteri_dosya_idler(?int $userId = null): array {
    static $cache = [];
    $u = user();
    $userId = $userId ?? (int)($u['id'] ?? 0);
    if (isset($cache[$userId])) return $cache[$userId];
    $idler = array_map('intval', array_column(rows("SELECT dosya_id FROM musteri_dosyalari WHERE user_id=?", [$userId]), 'dosya_id'));
    $birincil = (int)val("SELECT dosya_id FROM users WHERE id=?", [$userId]);
    if ($birincil && !in_array($birincil, $idler)) $idler[] = $birincil;
    return $cache[$userId] = $idler;
}

/** IN (...) sorguları için yer tutucu üretir; boş listede imkânsız koşul döner */
function in_sorgu(array $idler): array {
    if (!$idler) return ['(SELECT 0 WHERE 1=0)', []]; // hiç dosyası yoksa boş sonuç
    return ['(' . implode(',', array_fill(0, count($idler), '?')) . ')', $idler];
}

/** Müşteri kullanıcının erişebileceği proje mi? */
function proje_erisim(int $projeId): bool {
    $u = user();
    if (!$u) return false;
    if (is_staff()) return true;
    $idler = musteri_dosya_idler();
    if (!$idler) return false;
    [$in, $p] = in_sorgu($idler);
    return (bool)val("SELECT COUNT(*) FROM projeler WHERE id=? AND dosya_id IN $in", array_merge([$projeId], $p));
}

/** Müşteri bu dosyaya erişebilir mi? */
function dosya_erisim(int $dosyaId): bool {
    if (is_staff()) return true;
    return in_array($dosyaId, musteri_dosya_idler());
}

/* ---------------- CSRF ---------------- */

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(20));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $t = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
        json_out(['ok' => false, 'hata' => 'Oturum doğrulaması başarısız. Sayfayı yenileyin.'], 403);
    }
}

/* ---------------- Yardımcılar ---------------- */

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function json_out($veri, int $kod = 200): void {
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    exit;
}

const AYLAR = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
const GUNLER = ['Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar'];

function tarih(?string $dt, bool $saatli = false): string {
    if (!$dt || $dt === '0000-00-00') return '—';
    $ts = strtotime($dt);
    $s = date('j', $ts) . ' ' . AYLAR[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    if ($saatli) $s .= ' ' . date('H:i', $ts);
    return $s;
}

function zaman_once(?string $dt): string {
    if (!$dt) return '—';
    $fark = time() - strtotime($dt);
    if ($fark < 60) return 'az önce';
    if ($fark < 3600) return floor($fark / 60) . ' dk önce';
    if ($fark < 86400) return floor($fark / 3600) . ' saat önce';
    if ($fark < 604800) return floor($fark / 86400) . ' gün önce';
    return tarih($dt);
}

function dakika_format(int $dk): string {
    if ($dk < 60) return $dk . ' dk';
    $s = intdiv($dk, 60); $k = $dk % 60;
    return $s . 'sa' . ($k ? ' ' . $k . 'dk' : '');
}

function para(?float $t): string { return number_format((float)$t, 2, ',', '.') . ' ₺'; }

function bas_harf(string $ad): string {
    $parcalar = preg_split('/\s+/', trim($ad));
    $h = mb_substr($parcalar[0], 0, 1);
    if (count($parcalar) > 1) $h .= mb_substr(end($parcalar), 0, 1);
    return mb_strtoupper($h);
}

function avatar(?array $u, int $boyut = 34): string {
    if (!$u) return '<span class="avatar" style="width:' . $boyut . 'px;height:' . $boyut . 'px;background:var(--surface-2)">?</span>';
    if (!empty($u['avatar'])) {
        return '<span class="avatar" title="' . e($u['ad']) . '" style="width:' . $boyut . 'px;height:' . $boyut . 'px;background-image:url(\'uploads/' . e($u['avatar']) . '\');background-size:cover;background-position:center"></span>';
    }
    $renk = e($u['renk'] ?? '#182f5d');
    return '<span class="avatar" title="' . e($u['ad']) . '" style="width:' . $boyut . 'px;height:' . $boyut . 'px;background:' . $renk . '22;color:' . $renk . ';border:1.5px solid ' . $renk . '55">' . e(bas_harf($u['ad'])) . '</span>';
}

/** Dosya logosu ya da renkli baş harf kutusu */
function dosya_logo(array $d, int $boyut = 40, int $fontPx = 15): string {
    if (!empty($d['logo'])) {
        return '<span class="dosya-avatar" style="width:' . $boyut . 'px;height:' . $boyut . 'px;background-image:url(\'uploads/' . e($d['logo']) . '\');background-size:cover;background-position:center"></span>';
    }
    $renk = e($d['renk'] ?? '#182f5d');
    return '<span class="dosya-avatar" style="width:' . $boyut . 'px;height:' . $boyut . 'px;font-size:' . $fontPx . 'px;background:' . $renk . '22;color:' . $renk . '">' . e(bas_harf($d['ad'])) . '</span>';
}

/* ---------------- Etiket sözlükleri ---------------- */

const PROJE_TURLERI = ['aylik' => 'Aylık Düzenli', 'donemsel' => 'Dönemsel', 'tek' => 'Tek Seferlik'];
const DOSYA_TURLERI = ['marka' => 'Marka', 'sirket' => 'Şirket', 'stk' => 'STK'];
const GOREV_DURUMLARI = ['yapilacak' => 'Yapılacak', 'devam' => 'Devam Ediyor', 'incelemede' => 'İncelemede', 'onayda' => 'Onayda', 'tamamlandi' => 'Tamamlandı'];
const ONCELIKLER = ['dusuk' => 'Düşük', 'normal' => 'Normal', 'yuksek' => 'Yüksek', 'acil' => 'Acil'];
const PROJE_DURUMLARI = ['aktif' => 'Aktif', 'beklemede' => 'Beklemede', 'tamamlandi' => 'Tamamlandı', 'iptal' => 'İptal'];
const ICERIK_DURUMLARI = ['taslak' => 'Taslak', 'ic_onay' => 'İç Onayda', 'musteri_onay' => 'Müşteri Onayında', 'revize' => 'Revize', 'onaylandi' => 'Onaylandı', 'yayinlandi' => 'Yayınlandı'];
const ONAY_DURUMLARI = ['bekliyor' => 'Bekliyor', 'onaylandi' => 'Onaylandı', 'revize' => 'Revize İstendi', 'reddedildi' => 'Reddedildi'];
const TALEP_DURUMLARI = ['yeni' => 'Yeni', 'inceleniyor' => 'İnceleniyor', 'gorev_olusturuldu' => 'Göreve Dönüştürüldü', 'tamamlandi' => 'Tamamlandı', 'reddedildi' => 'Reddedildi'];
const PLATFORMLAR = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'x' => 'X (Twitter)', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'web' => 'Web Sitesi', 'diger' => 'Diğer'];
const ETKINLIK_TURLERI = ['cekim' => 'Çekim', 'toplanti' => 'Toplantı', 'teslim' => 'Teslim', 'diger' => 'Diğer'];
const ROLLER = ['yonetici' => 'Yönetici', 'pm' => 'Proje Yöneticisi', 'ekip' => 'Ekip Üyesi', 'finans' => 'Finans', 'stajyer' => 'Stajyer', 'musteri' => 'Müşteri'];
const TEKRARLAR = ['yok' => 'Tekrarlamaz', 'haftalik' => 'Her Hafta', 'aylik' => 'Her Ay'];
const GIDER_TURLERI = ['maas' => 'Maaş', 'kira' => 'Kira', 'abonelik' => 'Abonelik', 'ekipman' => 'Ekipman', 'vergi' => 'Vergi', 'diger' => 'Diğer'];

/* ---------------- Sürüm & güncelleme notları ---------------- */
const SURUM = '4.0';
const SURUM_NOTLARI = [
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
const RANDEVU_DURUMLARI = ['bekliyor' => 'Bekliyor', 'onaylandi' => 'Onaylandı', 'alternatif' => 'Farklı Saat Önerildi', 'reddedildi' => 'Reddedildi'];

/* ---------------- Merkezi SVG ikon kütüphanesi (monokrom çizgi) ---------------- */
const IKONLAR = [
    // Sosyal platformlar
    'instagram' => 'M7 3h10a4 4 0 014 4v10a4 4 0 01-4 4H7a4 4 0 01-4-4V7a4 4 0 014-4zm5 5.5a3.5 3.5 0 100 7 3.5 3.5 0 000-7zM17.2 6.8h.01',
    'facebook'  => 'M15 3h-2.5A3.5 3.5 0 009 6.5V9H6v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3V3z',
    'x'         => 'M4 4l7.2 9.4L4.4 20h2.4l5.5-5.5L16.8 20H20l-7.5-9.8L18.9 4h-2.4l-4.9 5L8 4H4z',
    'linkedin'  => 'M6.5 9v11M6.5 4.5v.01M11 20v-6a3 3 0 016 0v6M11 9v11',
    'youtube'   => 'M21 8a3 3 0 00-2-2c-2-.5-7-.5-7-.5s-5 0-7 .5a3 3 0 00-2 2 30 30 0 000 8 3 3 0 002 2c2 .5 7 .5 7 .5s5 0 7-.5a3 3 0 002-2 30 30 0 000-8zM10 9.5l5 2.5-5 2.5v-5z',
    'tiktok'    => 'M14 4v9.5a3.5 3.5 0 11-3.5-3.5M14 4a5 5 0 005 5',
    'web'       => 'M12 21a9 9 0 100-18 9 9 0 000 18zM3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z',
    'diger'     => 'M12 8v8m-4-4h8M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    // Ekipman kategorileri
    'kamera'    => 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z',
    'lens'      => 'M12 19a7 7 0 100-14 7 7 0 000 14zm0-3.5a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM19 5l1.5-1.5',
    'sd_kart'   => 'M8 3h9a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V7l3-4zM9 7v3m3-3v3m3-3v3',
    'tripod'    => 'M9 4h6v4H9zM12 8v4m0 0l-5 8m5-8l5 8m-5-8v8',
    'isik'      => 'M9 18h6M10 21h4M12 3a6 6 0 00-4 10.5c.7.6 1 1.5 1 2.5h6c0-1 .3-1.9 1-2.5A6 6 0 0012 3z',
    'ses'       => 'M12 15a3 3 0 003-3V6a3 3 0 10-6 0v6a3 3 0 003 3zm-7-3a7 7 0 0014 0M12 19v3',
    'drone'     => 'M4 6a2 2 0 104 0 2 2 0 10-4 0zm12 0a2 2 0 104 0 2 2 0 10-4 0zM4 18a2 2 0 104 0 2 2 0 10-4 0zm12 0a2 2 0 104 0 2 2 0 10-4 0zM7.5 7.5l3 3m6-3l-3 3m-6 6l3-3m6 3l-3-3m-3 3v-3a3 3 0 013-3',
    'aksesuar'  => 'M6 7h12l1 4H5l1-4zm-1 4v8a1 1 0 001 1h12a1 1 0 001-1v-8M12 7V4',
    // Genel arayüz
    'arsiv'     => 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
    'megafon'   => 'M11 5.88V19.24a1.76 1.76 0 01-3.42.6L5.44 14M18.7 4a9 9 0 01.3 13.3M5.44 14A2 2 0 015 10h1a8 8 0 005-2l3-2v12l-3-2a8 8 0 00-5-2H5.44z',
    'pin'       => 'M12 21s-7-5.5-7-11a7 7 0 1114 0c0 5.5-7 11-7 11zm0-8.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
    'takvim'    => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    'saat'      => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    'kilit'     => 'M7 11V7a5 5 0 0110 0v4M5 11h14v9a1 1 0 01-1 1H6a1 1 0 01-1-1v-9z',
    'kilit-acik' => 'M7 11V7a5 5 0 019.5-2M5 11h14v9a1 1 0 01-1 1H6a1 1 0 01-1-1v-9z',
    'tekrar'    => 'M17 2l4 4-4 4M3 11V9a4 4 0 014-4h14M7 22l-4-4 4-4m14-3v2a4 4 0 01-4 4H3',
    'atac'      => 'M21.4 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.2-9.19a4 4 0 015.65 5.66l-9.2 9.19a2 2 0 01-2.82-2.83l8.49-8.48',
    'klasor'    => 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
    'video'     => 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z',
    'kisi'      => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    'kisiler'   => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3',
    'sohbet'    => 'M8 12h8m-8-4h8m-9 8l-4 4V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H7z',
    'el-sikisma' => 'M11 17l-1.5 1.5a2 2 0 01-3-3L8 14m3 3l2 2a2 2 0 003-3l-.5-.5M11 17l3-3m-6 0L5.5 11.5a2 2 0 010-3L8 6l4 1 3.5-1.5a2 2 0 012.5.5L21 9l-3 5.5M8 14l3-3',
    'kalem'     => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z',
    'cop'       => 'M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3',
    'kutu'      => 'M21 8l-9-5-9 5m18 0l-9 5m9-5v8l-9 5m0-8L3 8m9 5v8m-9-13v8l9 5',
    'onay'      => 'M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    'grafik'    => 'M9 19v-6M15 19v-2M12 19v-9M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z',
    'yildiz'    => 'M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2z',
    'gunes'     => 'M12 17a5 5 0 100-10 5 5 0 000 10zm0-15v2m0 16v2M4.2 4.2l1.4 1.4m12.8 12.8l1.4 1.4M2 12h2m16 0h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
    'roket'     => 'M4.5 16.5c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2 0-2.8-.8-.7-2-.7-3 0zM12 15l-3-3a22 22 0 012-4c3.2-3.2 7-4.5 10-4 .5 3-1 6.8-4 10a22 22 0 01-4 2l-1-1zM9 12H4s.5-3.5 2-5c1.7-1.7 5 0 5 0m1 8v5s3.5-.5 5-2c1.7-1.7 0-5 0-5M15 9h.01',
    'uyari'     => 'M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z',
    'para'      => 'M12 8c-2.21 0-4 .9-4 2s1.79 2 4 2 4 .9 4 2-1.79 2-4 2m0-8c1.66 0 3.07.5 3.6 1.2M12 8V6m0 12v-2m0 2c-1.66 0-3.07-.5-3.6-1.2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    'belge'     => 'M9 17h6M9 13h6M9 9h1m4 12H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6a1 1 0 01.7.3l5.4 5.4a1 1 0 01.3.7V19a2 2 0 01-2 2z',
];

/** Monokrom çizgi SVG ikon üretir (currentColor — bulunduğu metnin rengini alır) */
function ikon(string $ad, int $boyut = 16, string $stil = ''): string {
    $yol = IKONLAR[$ad] ?? IKONLAR['diger'];
    return '<svg class="ikon" width="' . $boyut . '" height="' . $boyut . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"' . ($stil ? ' style="' . $stil . '"' : '') . '><path d="' . $yol . '"/></svg>';
}

/** CSV saklanan çoklu platformları ikonlu rozetlere çevirir */
function platform_rozetleri(?string $csv, bool $sadeceIkon = false): string {
    if (!$csv) return '';
    $h = '';
    foreach (array_filter(array_map('trim', explode(',', $csv))) as $pl) {
        $etiket = PLATFORMLAR[$pl] ?? $pl;
        $svg = ikon(isset(IKONLAR[$pl]) ? $pl : 'diger', $sadeceIkon ? 13 : 13);
        $h .= $sadeceIkon
            ? '<span class="p-ikon" title="' . e($etiket) . '">' . $svg . '</span>'
            : '<span class="rozet" style="padding:2px 8px;gap:5px">' . $svg . ' ' . e($etiket) . '</span> ';
    }
    return $h;
}

/** 1-5 yıldız görseli üretir */
function yildizlar(float $puan, int $boyut = 14): string {
    $h = '<span class="yildizlar" style="font-size:' . $boyut . 'px">';
    for ($i = 1; $i <= 5; $i++) $h .= '<span style="opacity:' . ($i <= round($puan) ? '1' : '.25') . '">★</span>';
    return $h . '</span>';
}

/* Ekipman modülü sabitleri */
const EKIPMAN_KATEGORILERI = ['kamera' => 'Kamera', 'lens' => 'Lens', 'sd_kart' => 'SD Kart', 'tripod' => 'Tripod', 'isik' => 'Işık', 'ses' => 'Ses', 'drone' => 'Drone', 'aksesuar' => 'Aksesuar', 'diger' => 'Diğer'];
const EKIPMAN_DURUMLARI = ['studyoda' => 'Stüdyoda', 'zimmette' => 'Zimmette', 'cekimde' => 'Çekimde', 'arizali' => 'Arızalı', 'bakimda' => 'Bakımda'];
const SD_DURUMLARI = ['bos' => 'Boş / Hazır', 'dolu' => 'Dolu', 'aktarildi' => "Drive'a Aktarıldı"];
const EKIPMAN_HAREKET_TURLERI = [
    'eklendi' => 'envantere eklendi', 'zimmet' => 'zimmet verildi', 'iade' => 'iade edildi',
    'cekime_cikti' => 'çekime çıktı', 'cekimden_dondu' => 'çekimden döndü',
    'sd_dolu' => 'dolu işaretlendi', 'sd_aktarildi' => "Drive'a aktarıldı", 'sd_bosaltildi' => 'boşaltıldı',
    'ariza' => 'arızalı işaretlendi', 'bakim' => 'bakıma alındı', 'duzeltildi' => 'kullanıma döndü',
];

/** Ekipman hareket kaydı düşer */
function ekipman_logla(int $ekipmanId, string $tur, string $aciklama = '', ?int $hedefUserId = null, ?int $etkinlikId = null): void {
    insert('ekipman_hareketleri', [
        'ekipman_id' => $ekipmanId, 'user_id' => (int)(user()['id'] ?? 0),
        'hedef_user_id' => $hedefUserId, 'etkinlik_id' => $etkinlikId,
        'tur' => $tur, 'aciklama' => $aciklama, 'created' => date('Y-m-d H:i:s'),
    ]);
}

/* Temalar: anahtar => [Etiket, vurgu rengi, koyu mu] */
const TEMALAR = [
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
];

/* Bildirim kategorileri (kullanıcı tercihine tabi) */
const BILDIRIM_KATEGORILERI = [
    'gorev' => 'Görev atama ve durum değişiklikleri',
    'onay' => 'Onay talepleri ve yanıtları',
    'talep' => 'Yeni talepler',
    'mesaj' => 'Mesajlar',
];

function bildirim_tercihi(array $alici, string $kategori): array {
    // Dönen: [panel_bildirimi_acik, eposta_acik]
    $t = json_decode($alici['bildirim_tercihleri'] ?? '', true);
    if (!is_array($t)) return [true, true]; // varsayılan: hepsi açık
    $panel = !isset($t[$kategori]) || (bool)$t[$kategori];
    $eposta = !isset($t['eposta']) || (bool)$t['eposta'];
    return [$panel, $eposta];
}

function rozet(string $deger, array $sozluk, string $sinifOn = ''): string {
    $etiket = $sozluk[$deger] ?? $deger;
    return '<span class="rozet r-' . ($sinifOn ? $sinifOn . '-' : '') . e($deger) . '">' . e($etiket) . '</span>';
}

/* ---------------- Bildirim & Aktivite ---------------- */

function bildir(int $userId, string $baslik, string $mesaj = '', string $link = '', string $kategori = 'gorev', bool $eposta = true): void {
    if ($userId === (int)(user()['id'] ?? 0)) return; // kendine bildirim yok
    $alici = row("SELECT * FROM users WHERE id=? AND aktif=1", [$userId]);
    if (!$alici) return;
    [$panelAcik, $epostaAcik] = bildirim_tercihi($alici, $kategori);
    if (!$panelAcik) return; // kullanıcı bu kategoriyi kapatmış
    insert('bildirimler', [
        'user_id' => $userId, 'baslik' => $baslik, 'mesaj' => $mesaj,
        'link' => $link, 'okundu' => 0, 'created' => date('Y-m-d H:i:s'),
    ]);
    if ($eposta && $epostaAcik && ayar('eposta_bildirim') === '1' && ayar('smtp_aktif') === '1') {
        require_once __DIR__ . '/mailer.php';
        eposta_gonder($alici['eposta'], $baslik, $mesaj . ($link ? "\n\nGörüntüle: " . tam_url($link) : ''));
    }
}

function tam_url(string $yol): string {
    $protokol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $protokol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . '/' . ltrim($yol, '/');
}

function log_aktivite(string $aciklama, ?string $refTur = null, ?int $refId = null): void {
    insert('aktiviteler', [
        'user_id' => (int)(user()['id'] ?? 0), 'ref_tur' => $refTur, 'ref_id' => $refId,
        'aciklama' => $aciklama, 'created' => date('Y-m-d H:i:s'),
    ]);
}

/* ---------------- Dosya yükleme ---------------- */

function dosya_yukle(string $alan): ?array {
    if (empty($_FILES[$alan]) || $_FILES[$alan]['error'] !== UPLOAD_ERR_OK) return null;
    $f = $_FILES[$alan];
    if ($f['size'] > 50 * 1024 * 1024) return null; // 50 MB sınır
    $uzanti = mb_strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    // Güvenlik: yalnızca bilinen güvenli türlere izin ver (beyaz liste)
    $izinli = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'zip', 'rar', '7z', 'mp4', 'mov', 'avi', 'mp3', 'wav', 'aac', 'psd', 'ai', 'indd', 'srt', 'otf', 'ttf'];
    if (!in_array($uzanti, $izinli)) return null;
    $yeniAd = date('Ym') . '/' . bin2hex(random_bytes(8)) . '.' . $uzanti;
    $hedefKlasor = ROOT . '/uploads/' . date('Ym');
    if (!is_dir($hedefKlasor)) mkdir($hedefKlasor, 0755, true);
    if (!move_uploaded_file($f['tmp_name'], ROOT . '/uploads/' . $yeniAd)) return null;
    return ['yol' => $yeniAd, 'ad' => $f['name'], 'boyut' => $f['size'], 'uzanti' => $uzanti];
}

function boyut_format(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}

/* ---------------- Dönem yardımcıları ---------------- */

function donem_ad(array $d): string { return AYLAR[(int)$d['ay']] . ' ' . $d['yil']; }

function donem_getir_veya_olustur(int $projeId, int $yil, int $ay): int {
    $d = row("SELECT id FROM donemler WHERE proje_id=? AND yil=? AND ay=?", [$projeId, $yil, $ay]);
    if ($d) return (int)$d['id'];
    return insert('donemler', ['proje_id' => $projeId, 'yil' => $yil, 'ay' => $ay, 'durum' => 'acik', 'created' => date('Y-m-d H:i:s')]);
}

/* ---------------- Etiketleme (@mention) & görev etiketleri ---------------- */

/** Metindeki @Ad Soyad ifadelerini vurgular (aktif kullanıcı adlarına göre, uzun ad önce) */
function mention_vurgula(string $kacisliMetin): string {
    static $adlar = null;
    if ($adlar === null) {
        $adlar = array_column(rows("SELECT ad FROM users WHERE aktif=1 ORDER BY CHAR_LENGTH(ad) DESC"), 'ad');
    }
    foreach ($adlar as $ad) {
        $kacisli = e($ad); // metin zaten e() ile kaçışlı
        $kacisliMetin = str_ireplace('@' . $kacisli, '<span class="mention">@' . $kacisli . '</span>', $kacisliMetin);
    }
    return $kacisliMetin;
}

/** Virgülle ayrılmış görev etiketlerini renkli çiplere dönüştürür */
function etiket_cipleri(?string $etiketler, string $ekSinif = ''): string {
    if (!$etiketler) return '';
    $h = '';
    foreach (array_filter(array_map('trim', explode(',', $etiketler))) as $et) {
        $ton = crc32(mb_strtolower($et)) % 360; // etikete sabit renk
        $h .= '<span class="etiket-cip ' . $ekSinif . '" style="--cip-ton:' . $ton . '">' . e($et) . '</span>';
    }
    return $h;
}

/** Mention edilen kullanıcı id'lerini bildirir (JSON dizi bekler) */
function mentionlari_bildir(string $etiketlerJson, string $baslik, string $mesaj, string $link): void {
    $idler = json_decode($etiketlerJson, true);
    if (!is_array($idler)) return;
    foreach (array_unique(array_map('intval', $idler)) as $uid) {
        if ($uid > 0) bildir($uid, $baslik, $mesaj, $link, 'mesaj');
    }
}

/* ---------------- Tekrarlayan görev otomasyonu ----------------
 * Sunucuda cron kurulumu gerektirmez: sayfa yüklenirken saatte bir tetiklenir.
 * İsteyen /cron.php adresini gerçek cron'a da bağlayabilir.
 */
function tekrar_kontrol(bool $zorla = false): int {
    $son = (int)val("SELECT deger FROM settings WHERE anahtar='son_tekrar_kontrol'");
    if (!$zorla && time() - $son < 3600) return 0;
    q("INSERT INTO settings (anahtar, deger) VALUES ('son_tekrar_kontrol', ?) ON DUPLICATE KEY UPDATE deger=?", [time(), time()]);

    $sayi = 0;
    foreach (rows("SELECT * FROM gorevler WHERE tekrar!='yok'") as $g) {
        $donemAnahtar = $g['tekrar'] === 'haftalik' ? date('o-W') : date('Y-m');
        if ($g['son_tekrar'] === $donemAnahtar) continue;
        if ($g['son_tekrar'] === null) {
            // İlk dönem: görevin kendisi zaten bu dönemin işi — sadece damgala
            guncelle('gorevler', ['son_tekrar' => $donemAnahtar], 'id=?', [$g['id']]);
            continue;
        }
        // Yeni dönem başladı: şablon görevden taze bir kopya üret
        $yeniSonTarih = $g['tekrar'] === 'haftalik' ? date('Y-m-d', strtotime('sunday this week')) : date('Y-m-t');
        $donemId = null;
        $projeTur = val("SELECT tur FROM projeler WHERE id=?", [$g['proje_id']]);
        if ($projeTur === 'aylik') $donemId = donem_getir_veya_olustur((int)$g['proje_id'], (int)date('Y'), (int)date('n'));
        $yeniId = insert('gorevler', [
            'proje_id' => $g['proje_id'], 'donem_id' => $donemId,
            'baslik' => $g['baslik'],
            'aciklama' => $g['aciklama'],
            'atanan_id' => $g['atanan_id'], 'olusturan_id' => $g['olusturan_id'],
            'oncelik' => $g['oncelik'], 'durum' => 'yapilacak',
            'son_tarih' => $yeniSonTarih, 'tekrar' => 'yok',
            'created' => date('Y-m-d H:i:s'),
        ]);
        // Akış adımlarını sıfırlanmış olarak kopyala
        $adimlar = rows("SELECT * FROM gorev_adimlari WHERE gorev_id=? ORDER BY sira", [$g['id']]);
        foreach ($adimlar as $i => $a) {
            insert('gorev_adimlari', [
                'gorev_id' => $yeniId, 'sira' => $a['sira'], 'ad' => $a['ad'],
                'sorumlu_id' => $a['sorumlu_id'], 'durum' => $i === 0 ? 'aktif' : 'bekliyor',
            ]);
        }
        // Kontrol listesini sıfırlanmış kopyala
        foreach (rows("SELECT * FROM gorev_kontrol WHERE gorev_id=? ORDER BY sira", [$g['id']]) as $k) {
            insert('gorev_kontrol', ['gorev_id' => $yeniId, 'ad' => $k['ad'], 'tamam' => 0, 'sira' => $k['sira']]);
        }
        guncelle('gorevler', ['son_tekrar' => $donemAnahtar], 'id=?', [$g['id']]);
        if ($g['atanan_id']) bildir((int)$g['atanan_id'], 'Tekrarlayan görev oluşturuldu', $g['baslik'], 'gorev.php?id=' . $yeniId, 'gorev');
        $sayi++;
    }

    /* --- Aylık maaş giderleri: her ay başında otomatik oluştur --- */
    $buAy = date('Y-m');
    foreach (rows("SELECT id, ad, maas FROM users WHERE maas>0 AND aktif=1") as $kisi) {
        $var = val("SELECT COUNT(*) FROM giderler WHERE tur='maas' AND user_id=? AND son_tekrar=?", [$kisi['id'], $buAy]);
        if (!$var) {
            insert('giderler', [
                'tur' => 'maas', 'baslik' => $kisi['ad'] . ' — ' . AYLAR[(int)date('n')] . ' maaşı',
                'tutar' => $kisi['maas'], 'tarih' => date('Y-m-01'), 'durum' => 'bekliyor',
                'tekrar' => 'yok', 'son_tekrar' => $buAy, 'user_id' => $kisi['id'], 'created' => date('Y-m-d H:i:s'),
            ]);
        }
    }
    /* --- Aylık tekrarlayan giderler (kira, abonelik vb.) --- */
    foreach (rows("SELECT * FROM giderler WHERE tekrar='aylik'") as $gd) {
        if ($gd['son_tekrar'] === $buAy) continue;
        if ($gd['son_tekrar'] === null) { guncelle('giderler', ['son_tekrar' => $buAy], 'id=?', [$gd['id']]); continue; }
        insert('giderler', [
            'tur' => $gd['tur'], 'baslik' => $gd['baslik'], 'tutar' => $gd['tutar'],
            'tarih' => date('Y-m-01'), 'durum' => 'bekliyor', 'tekrar' => 'yok',
            'son_tekrar' => $buAy, 'user_id' => $gd['user_id'], 'aciklama' => $gd['aciklama'],
            'created' => date('Y-m-d H:i:s'),
        ]);
        guncelle('giderler', ['son_tekrar' => $buAy], 'id=?', [$gd['id']]);
    }

    /* --- Toplantı hatırlatması: ~1 saat kala katılımcılara bildir --- */
    $yaklasanToplantilar = rows("SELECT * FROM etkinlikler WHERE tur='toplanti' AND hatirlatildi=0
        AND baslangic BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 75 MINUTE)");
    foreach ($yaklasanToplantilar as $top) {
        $saat = date('H:i', strtotime($top['baslangic']));
        $mesajMetni = $saat . ' — ' . ($top['yer'] ?: '') . ($top['online_link'] ? ' (online)' : '');
        $alicilar = array_column(rows("SELECT user_id FROM etkinlik_katilimcilari WHERE etkinlik_id=?", [$top['id']]), 'user_id');
        $alicilar[] = (int)$top['olusturan_id'];
        foreach (array_unique($alicilar) as $aid) {
            bildir((int)$aid, '⏰ Toplantı yaklaşıyor: ' . $top['baslik'], $mesajMetni, 'toplantilar.php', 'gorev');
        }
        guncelle('etkinlikler', ['hatirlatildi' => 1], 'id=?', [$top['id']]);
    }

    /* --- Günlük özet: her kullanıcıya günde bir kez "bugün seni bekleyenler" --- */
    $sonOzet = val("SELECT deger FROM settings WHERE anahtar='son_gunluk_ozet'");
    if ($sonOzet !== date('Y-m-d')) {
        q("INSERT INTO settings (anahtar, deger) VALUES ('son_gunluk_ozet', ?) ON DUPLICATE KEY UPDATE deger=?", [date('Y-m-d'), date('Y-m-d')]);
        $bugun = date('Y-m-d');
        foreach (rows("SELECT id FROM users WHERE aktif=1 AND rol!='musteri'") as $kisi) {
            $kid = (int)$kisi['id'];
            $parcalar = [];
            $gorevSayi = (int)val("SELECT COUNT(*) FROM gorevler g WHERE g.arsivlendi=0 AND g.durum!='tamamlandi' AND g.son_tarih=?
                AND (g.atanan_id=? OR EXISTS(SELECT 1 FROM gorev_atananlar ga WHERE ga.gorev_id=g.id AND ga.user_id=?))", [$bugun, $kid, $kid]);
            if ($gorevSayi) $parcalar[] = $gorevSayi . ' görev teslimi';
            $topSayi = (int)val("SELECT COUNT(*) FROM etkinlikler e WHERE e.tur='toplanti' AND DATE(e.baslangic)=?
                AND (e.olusturan_id=? OR EXISTS(SELECT 1 FROM etkinlik_katilimcilari ek WHERE ek.etkinlik_id=e.id AND ek.user_id=?))", [$bugun, $kid, $kid]);
            if ($topSayi) $parcalar[] = $topSayi . ' toplantı';
            $cekimSayi = (int)val("SELECT COUNT(*) FROM etkinlikler WHERE tur!='toplanti' AND DATE(baslangic)<=? AND DATE(COALESCE(bitis,baslangic))>=?", [$bugun, $bugun]);
            if ($cekimSayi) $parcalar[] = $cekimSayi . ' etkinlik';
            $icerikSayi = (int)val("SELECT COUNT(*) FROM icerikler WHERE tarih=? AND durum NOT IN ('yayinlandi')", [$bugun]);
            if ($icerikSayi) $parcalar[] = $icerikSayi . ' içerik yayını';
            if ($parcalar) {
                bildir($kid, '🌅 Bugün seni bekleyenler', implode(' · ', $parcalar), 'index.php', 'gorev', false);
            }
        }
    }

    /* --- Haftalık yönetici özeti: her pazartesi bir kez --- */
    $buHafta = date('o-W');
    if (date('N') == 1 && val("SELECT deger FROM settings WHERE anahtar='son_haftalik_ozet'") !== $buHafta) {
        q("INSERT INTO settings (anahtar, deger) VALUES ('son_haftalik_ozet', ?) ON DUPLICATE KEY UPDATE deger=?", [$buHafta, $buHafta]);
        $hb = date('Y-m-d', strtotime('-7 days'));
        $ozet = [];
        $t1 = (int)val("SELECT COUNT(*) FROM gorevler WHERE durum='tamamlandi' AND tamamlanma>=?", [$hb]);
        if ($t1) $ozet[] = $t1 . ' görev tamamlandı';
        $t2 = (int)val("SELECT COUNT(*) FROM gorevler WHERE arsivlendi=0 AND durum!='tamamlandi' AND son_tarih<CURDATE()");
        if ($t2) $ozet[] = $t2 . ' görev gecikmede';
        $t3 = (int)val("SELECT COUNT(*) FROM talepler WHERE created>=?", [$hb]);
        if ($t3) $ozet[] = $t3 . ' yeni talep';
        $t4 = val("SELECT ROUND(AVG(puan),1) FROM puanlar WHERE created>=?", [$hb]);
        if ($t4) $ozet[] = 'ort. puan ' . $t4 . '★';
        $t5 = (float)val("SELECT COALESCE(SUM(tutar),0) FROM odemeler WHERE durum='odendi' AND tarih>=?", [$hb]);
        $t6 = (float)val("SELECT COALESCE(SUM(tutar),0) FROM giderler WHERE durum='odendi' AND tarih>=?", [$hb]);
        if ($t5 || $t6) $ozet[] = 'gelir ' . para($t5) . ' / gider ' . para($t6);
        if ($ozet) {
            foreach (rows("SELECT id FROM users WHERE rol IN ('yonetici','pm') AND aktif=1") as $yo) {
                bildir((int)$yo['id'], '📅 Haftalık özet', implode(' · ', $ozet), 'raporlar.php', 'gorev');
            }
        }
    }

    /* --- Sözleşme bitiş hatırlatması (30 gün kala, bir kez) --- */
    foreach (rows("SELECT s.*, d.ad dosya_ad FROM sozlesmeler s JOIN dosyalar d ON d.id=s.dosya_id WHERE s.hatirlatildi=0 AND s.bitis IS NOT NULL AND s.bitis <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND s.bitis >= CURDATE()") as $sz) {
        foreach (rows("SELECT id FROM users WHERE rol IN ('yonetici','pm') AND aktif=1") as $ya) {
            bildir((int)$ya['id'], '⏰ Sözleşme bitiyor: ' . $sz['dosya_ad'], '"' . $sz['baslik'] . '" sözleşmesi ' . tarih($sz['bitis']) . ' tarihinde sona eriyor.', 'dosya.php?id=' . $sz['dosya_id'], 'gorev');
        }
        guncelle('sozlesmeler', ['hatirlatildi' => 1], 'id=?', [$sz['id']]);
    }

    return $sayi;
}

/* ---------------- Canlı senkron: durum özetleri ----------------
 * Açık sayfalar 10 sn'de bir bu hash'i kontrol eder; değiştiyse sayfa tazelenir.
 */
function canli_hash_gorev(int $id): string {
    $g = row("SELECT durum, kilit_acik, bagimli_id, atanan_id, arsivlendi, baslik, son_tarih FROM gorevler WHERE id=?", [$id]);
    $adimlar = val("SELECT GROUP_CONCAT(CONCAT(id,':',durum) ORDER BY sira) FROM gorev_adimlari WHERE gorev_id=?", [$id]);
    $kontrol = val("SELECT GROUP_CONCAT(CONCAT(id,':',tamam) ORDER BY sira) FROM gorev_kontrol WHERE gorev_id=?", [$id]);
    $yorum = val("SELECT CONCAT(COUNT(*),':',COALESCE(MAX(id),0),':',SUM(duzenlendi)) FROM yorumlar WHERE ref_tur='gorev' AND ref_id=?", [$id]);
    $tepki = val("SELECT COUNT(*) FROM yorum_tepkiler t JOIN yorumlar y ON y.id=t.yorum_id WHERE y.ref_tur='gorev' AND y.ref_id=?", [$id]);
    $ek = val("SELECT COUNT(*) FROM arsiv WHERE gorev_id=?", [$id]);
    $izleyici = val("SELECT COUNT(*) FROM gorev_izleyiciler WHERE gorev_id=?", [$id]);
    // Bağımlı görevin durumu da kilidi etkiler
    $bagimliDurum = $g && $g['bagimli_id'] ? val("SELECT durum FROM gorevler WHERE id=?", [$g['bagimli_id']]) : '';
    return md5(json_encode([$g, $adimlar, $kontrol, $yorum, $tepki, $ek, $izleyici, $bagimliDurum]));
}

function canli_hash_liste(): string {
    return md5((string)val("SELECT GROUP_CONCAT(CONCAT_WS(':',id,durum,sira,arsivlendi,COALESCE(atanan_id,0)) ORDER BY id) FROM gorevler"));
}

/** Kullanıcı 'yalnızca sorumlusu olduğum adımlar' tercihini açmış mı? */
function sadece_kendi_adimlarim(): bool {
    $t = json_decode(user()['bildirim_tercihleri'] ?? '', true);
    return is_array($t) && !empty($t['sadece_kendi_adimlarim']);
}

/** Görev tamamlanınca bağlı içeriği 'onaylandı' durumuna taşır (yayınlanmadıysa) */
function gorev_icerik_senkron(int $gorevId): void {
    $icerikId = (int)val("SELECT icerik_id FROM gorevler WHERE id=?", [$gorevId]);
    if ($icerikId) q("UPDATE icerikler SET durum='onaylandi' WHERE id=? AND durum NOT IN ('yayinlandi','onaylandi')", [$icerikId]);
}

/* ---------------- Görev kilit kontrolleri ---------------- */

/** Görevin ilerlemesini engelleyen neden döner; engel yoksa null. */
function gorev_kilit_nedeni(array $gorev, string $hedefDurum): ?string {
    if (!empty($gorev['kilit_acik'])) return null; // yönetici kilidi devre dışı bırakmış
    // Bağımlılık: bağlı görev bitmeden yapilacak'tan ileri gidemez
    if ($gorev['bagimli_id'] && $hedefDurum !== 'yapilacak') {
        $bagimli = row("SELECT baslik, durum FROM gorevler WHERE id=?", [$gorev['bagimli_id']]);
        if ($bagimli && $bagimli['durum'] !== 'tamamlandi') {
            return '"' . $bagimli['baslik'] . '" görevi tamamlanmadan bu görev ilerleyemez.';
        }
    }
    // Durum kilidi: akış adımları bitmeden tamamlandı yapılamaz
    if ($hedefDurum === 'tamamlandi') {
        $eksik = (int)val("SELECT COUNT(*) FROM gorev_adimlari WHERE gorev_id=? AND durum!='tamam'", [$gorev['id']]);
        if ($eksik > 0) return "Akışta $eksik tamamlanmamış adım var. Önce adımları bitirin.";
    }
    return null;
}

function proje_kanali(int $projeId, string $tur = 'proje'): int {
    $k = row("SELECT id FROM kanallar WHERE proje_id=? AND tur=?", [$projeId, $tur]);
    if ($k) return (int)$k['id'];
    $proje = row("SELECT ad FROM projeler WHERE id=?", [$projeId]);
    $ad = $proje['ad'] ?? 'Proje';
    $kanalId = insert('kanallar', ['ad' => $ad, 'tur' => $tur, 'proje_id' => $projeId, 'created' => date('Y-m-d H:i:s')]);
    // Ekip üyelerini otomatik ekle
    foreach (rows("SELECT id FROM users WHERE rol IN ('yonetici','pm','ekip') AND aktif=1") as $u) {
        q("INSERT IGNORE INTO kanal_uyeleri (kanal_id, user_id) VALUES (?,?)", [$kanalId, $u['id']]);
    }
    if ($tur === 'musteri') {
        $dosyaId = val("SELECT dosya_id FROM projeler WHERE id=?", [$projeId]);
        foreach (rows("SELECT id FROM users WHERE rol='musteri' AND dosya_id=? AND aktif=1", [$dosyaId]) as $u) {
            q("INSERT IGNORE INTO kanal_uyeleri (kanal_id, user_id) VALUES (?,?)", [$kanalId, $u['id']]);
        }
    }
    return $kanalId;
}

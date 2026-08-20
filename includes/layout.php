<?php
/**
 * SADA One — Page layout (sidebar + top bar)
 */

function page_start(string $title, string $activePage = ''): void {
    $u = user();
    if ($u && is_staff()) { try { run_recurring_jobs(); } catch (Throwable $e) { /* silent */ } }
    $theme = isset(THEMES[$u['theme'] ?? '']) ? $u['theme'] : setting('varsayilan_tema', 'lime');
    $siteName = setting('site_adi', 'SADA One');
    $notificationCount = $u ? (int)val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0", [$u['id']]) : 0;

    $nav = [];
    $navGruplar = [];
    if (is_staff()) {
        $nav = [
            ['index.php', 'panel', 'Panel', 'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1V10'],
            ['my-space.php', 'my_space', 'Alanım', 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z'],
            ['clients.php', 'clients', 'Dosyalar', 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z'],
            ['projects.php', 'projects', 'Projeler', 'M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z'],
            ['tasks.php', 'tasks', 'Görevler', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
        ];
        // Groups: [key, label, icon, items]
        $navGruplar[] = ['calendars', 'Takvimler', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', [
            ['calendar.php', 'calendar', 'Prodüksiyon', 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z'],
            ['content-calendar.php', 'content', 'İçerik', 'M7 4v16M17 4v16M3 8h18M3 16h18M3 4h18v16H3z'],
            ['meetings.php', 'meetings', 'Toplantılar', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z'],
            ['appointments.php', 'appointments', 'Randevular', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm7-6l2 2 4-4'],
            ['timeline.php', 'cizelge', 'Zaman Çizelgesi', 'M4 6h6m-6 6h10M4 18h14M20 6v12'],
        ]];
        $contactOgeler = [
            ['messages.php', 'messages', 'Mesajlar', 'M8 12h8m-8-4h8m-9 8l-4 4V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H7z'],
            ['announcements.php', 'duyurular', 'Duyurular', 'M11 5.88V19.24a1.76 1.76 0 01-3.42.6L5.44 14M18.7 4a9 9 0 01.3 13.3M5.44 14A2 2 0 015 10h1a8 8 0 005-2l3-2v12l-3-2a8 8 0 00-5-2H5.44z'],
        ];
        if (!is_intern()) {
            $contactOgeler[] = ['approvals.php', 'approvals', 'Onaylar', 'M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z'];
            $contactOgeler[] = ['requests.php', 'requests', 'Talepler', 'M8 10h8m-8 4h4m9-2a9 9 0 11-18 0 9 9 0 0118 0zM12 3v1m0 16v1'];
        }
        $navGruplar[] = ['contact', 'İletişim', 'M8 12h8m-8-4h8m-9 8l-4 4V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H7z', $contactOgeler];
        $navGruplar[] = ['studyo', 'Stüdyo', 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z', [
            ['equipment.php', 'ekipman', 'Ekipman', 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z'],
            ['team.php', 'team', 'Ekip', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3'],
        ]];
        $analizOgeler = [];
        if (permission('finans')) $analizOgeler[] = ['finance.php', 'finance', 'Finans', 'M12 8c-2.21 0-4 .9-4 2s1.79 2 4 2 4 .9 4 2-1.79 2-4 2m0-8c1.66 0 3.07.5 3.6 1.2M12 8V6m0 12v-2m0 2c-1.66 0-3.07-.5-3.6-1.2M21 12a9 9 0 11-18 0 9 9 0 0118 0z'];
        if (permission('rapor')) $analizOgeler[] = ['reports.php', 'reports', 'Raporlar', 'M9 19v-6M15 19v-2M12 19v-9M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z'];
        if ($analizOgeler) $navGruplar[] = ['analiz', 'Analiz', 'M9 19v-6M15 19v-2M12 19v-9M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z', $analizOgeler];
        // Operations: SOP modules (v14)
        $opOgeler = [
            ['shoot-list.php', 'shoots', 'Çekim Listesi', 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z'],
            ['growth.php', 'growth', 'Gelişim & Mentörlük', 'M12 14l9-5-9-5-9 5 9 5zm0 0v6m-6-3.5V12m12 4.5V12'],
            ['ideas.php', 'ideas', 'Fikir Panosu', 'M9.66 18h4.68M10 21h4m-2-18a7 7 0 00-4 12.7c.6.5 1 1.2 1 2v.3h6v-.3c0-.8.4-1.5 1-2A7 7 0 0012 3z'],
        ];
        if (!is_intern()) {
            $opOgeler[] = ['talent-pool.php', 'pool', 'Çalışan Havuzu', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z'];
            $opOgeler[] = ['monthly-reports.php', 'mreports', 'Aylık Raporlar', 'M8 7V3m8 4V3M5 11h14M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'];
        }
        if (is_admin() || ($u && $u['role'] === 'pm')) $opOgeler[] = ['manager-tracking.php', 'ytakip', 'Yönetici Takip', 'M9 12l2 2 4-4M7.8 21L12 17l4.2 4V5a2 2 0 00-2-2H9.8a2 2 0 00-2 2v16z'];
        $navGruplar[] = ['operasyon', 'Operasyon', 'M4 6h16M4 12h16M4 18h10', $opOgeler];
    } else {
        $nav = [
            ['index.php', 'panel', 'Panel', 'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1V10'],
            ['clients.php', 'clients', 'Dosyalarım', 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z'],
            ['projects.php', 'projects', 'Projelerim', 'M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z'],
            ['appointments.php', 'appointments', 'Randevu', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm7-6l2 2 4-4'],
            ['content-calendar.php', 'content', 'İçerik Takvimi', 'M7 4v16M17 4v16M3 8h18M3 16h18M3 4h18v16H3z'],
            ['approvals.php', 'approvals', 'Onaylar', 'M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['requests.php', 'requests', 'Taleplerim', 'M8 10h8m-8 4h4m9-2a9 9 0 11-18 0 9 9 0 0118 0zM12 3v1m0 16v1'],
            ['messages.php', 'messages', 'Mesajlar', 'M8 12h8m-8-4h8m-9 8l-4 4V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H7z'],
            ['archive.php', 'archive', 'Dosya Arşivi', 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4'],
        ];
    }
    $yonetimNav = [];
    if (is_admin()) {
        $yonetimNav = [
            ['users.php', 'users', 'Kullanıcılar', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3'],
            ['workflows.php', 'workflows', 'Akış Şablonları', 'M13 10V3L4 14h7v7l9-11h-7z'],
            ['project-templates.php', 'ptemplates', 'Proje Şablonları', 'M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z'],
            ['form-templates.php', 'forms', 'Form Şablonları', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            ['settings.php', 'settings', 'Ayarlar', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
            ['updater.php', 'update', 'Güncelleme', 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
        ];
    } elseif (is_staff()) {
        $yonetimNav = [['archive.php', 'archive', 'Dosya Arşivi', 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4']];
    }
?>
<!DOCTYPE html>
<html lang="tr" data-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf" content="<?= csrf_token() ?>">
<title><?= e($title) ?> — <?= e($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
<script type="speculationrules">
{"prerender": [{"where": {"and": [
    {"href_matches": "/*"},
    {"not": {"href_matches": "/*logout*"}},
    {"not": {"href_matches": "/*export*"}},
    {"not": {"href_matches": "/*update_row*"}},
    {"not": {"href_matches": "/*install*"}}
]}, "eagerness": "moderate"}]}
</script>
<?php if (theme_favicon()): ?><link rel="icon" href="uploads/<?= e(theme_favicon()) ?>"><?php endif; ?>
</head>
<body>
<div class="sayfa-cubugu" id="pageCubugu"></div>
<div class="uygulama">
    <aside class="kenar" id="sidebar">
        <div class="kenar-logo">
            <?php if (theme_logo()): ?>
            <a href="index.php" style="display:flex;align-items:center"><img src="uploads/<?= e(theme_logo()) ?>" alt="<?= e($siteName) ?>" style="max-height:40px;max-width:170px;object-fit:contain"></a>
            <?php else: ?>
            <a href="index.php" class="logotip">SADA<span>.</span></a>
            <?php endif; ?>
            <button class="kenar-kapat" data-kenar-kapat aria-label="Menüyü kapat">✕</button>
        </div>
        <nav class="kenar-nav">
            <?php
            // Renders a single nav item (with the messages badge)
            $navOgeWrite = function (array $n, bool $bottomOge = false) use ($activePage, $u) {
                echo '<a href="' . $n[0] . '" class="nav-oge ' . ($bottomOge ? 'nav-alt-oge ' : '') . ($activePage === $n[1] ? 'aktif' : '') . '">';
                echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="' . $n[3] . '"/></svg>';
                echo '<span>' . $n[2] . '</span>';
                if ($n[1] === 'messages' && $u) {
                    $okunmamis = (int)val("SELECT COUNT(*) FROM messages m JOIN channel_members ku ON ku.channel_id=m.channel_id AND ku.user_id=? WHERE m.user_id!=? AND ku.archive=0 AND (ku.last_read IS NULL OR m.created>ku.last_read)", [$u['id'], $u['id']]);
                    if ($okunmamis) echo '<span class="nav-sayac">' . ($okunmamis > 99 ? '99+' : $okunmamis) . '</span>';
                }
                echo '</a>';
            };
            foreach ($nav as $n) $navOgeWrite($n);

            // Collapsible groups
            foreach ($navGruplar as $grup):
                [$gAnahtar, $gEtiket, $gIkon, $gOgeler] = $grup;
                $grupActive = in_array($activePage, array_column($gOgeler, 1)); ?>
            <div class="nav-grup <?= $grupActive ? 'acik aktif-grup' : '' ?>" data-nav-grup="<?= $gAnahtar ?>">
                <button class="nav-oge nav-grup-baslik" data-grup-btn>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $gIkon ?>"/></svg>
                    <span><?= $gEtiket ?></span>
                    <svg class="grup-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="nav-grup-icerik">
                    <?php foreach ($gOgeler as $n) $navOgeWrite($n, true); ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($yonetimNav): ?>
            <div class="nav-bolum"><?= is_admin() ? 'Yönetim' : 'Araçlar' ?></div>
            <?php foreach ($yonetimNav as $n) $navOgeWrite($n); endif; ?>
        </nav>
        <div class="kenar-alt">
            <?php if (is_staff()): ?>
            <div class="acilir" data-acilir style="width:100%">
                <button class="btn btn-marka btn-blok" data-acilir-btn>
                    <svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" width="16"><path d="M12 5v14M5 12h14"/></svg> Hızlı Oluştur
                </button>
                <div class="acilir-panel hizli-olustur-panel">
                    <?php if (permission('gorev_olustur')): ?><a class="acilir-oge" href="tasks.php?create=1">Görev</a><?php endif; ?>
                    <?php if (permission('takvim_yonet')): ?>
                    <a class="acilir-oge" href="calendar.php?create=1">Etkinlik / Çekim</a>
                    <a class="acilir-oge" href="meetings.php?create=1">Toplantı</a>
                    <?php endif; ?>
                    <?php if (permission('icerik_yonet')): ?><a class="acilir-oge" href="content-calendar.php?create=1">İçerik</a><?php endif; ?>
                    <a class="acilir-oge" href="my-space.php?create=1">Kişisel Not</a>
                    <?php if (permission('duyuru_yayinla')): ?><a class="acilir-oge" href="announcements.php?create=1">Duyuru</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </aside>

    <div class="icerik-alani">
        <header class="ustbar">
            <button class="menu-btn" data-kenar-ac aria-label="Menüyü aç"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
            <div class="ustbar-baslik"><?= e($title) ?></div>
            <div class="arama-global">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                <input class="girdi" id="globalSearch" placeholder="Ara: dosya, proje, görev..." autocomplete="off">
                <div class="arama-sonuc" id="searchResult"></div>
            </div>
            <div class="ustbar-sag">
                <div class="acilir" data-acilir>
                    <button class="ikon-btn" data-acilir-btn aria-label="Bildirimler">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <?php if ($notificationCount): ?><span class="ikon-sayac" id="notificationCounter"><?= $notificationCount > 99 ? '99+' : $notificationCount ?></span><?php endif; ?>
                    </button>
                    <div class="acilir-panel bildirim-panel">
                        <div class="acilir-baslik">Bildirimler
                            <span class="satir-esnek" style="gap:10px">
                                <?php if ($notificationCount): ?><button class="mini-btn" data-tumunu-oku>Okundu işaretle</button><?php endif; ?>
                                <button class="mini-btn" style="color:var(--tehlike)" data-action="notification_clear" data-approval="Tüm bildirimler silinsin mi?">Tümünü sil</button>
                            </span>
                        </div>
                        <div class="bildirim-liste">
                            <?php $notifications = $u ? rows("SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 12", [$u['id']]) : [];
                            if (!$notifications): ?><div class="bos-mini">Henüz bildirim yok</div>
                            <?php else: foreach ($notifications as $b): ?>
                            <a href="<?= e($b['link'] ?: '#') ?>" class="bildirim-oge <?= $b['is_read'] ? '' : 'yeni' ?>" data-notification="<?= $b['id'] ?>">
                                <div class="bildirim-baslik"><?= e($b['title']) ?></div>
                                <?php if ($b['message']): ?><div class="bildirim-metin"><?= e(mb_substr($b['message'], 0, 90)) ?></div><?php endif; ?>
                                <div class="bildirim-zaman"><?= time_ago($b['created']) ?></div>
                                <span class="bildirim-sil-x" data-bildirim-sil title="Sil">✕</span>
                            </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <div class="acilir" data-acilir>
                    <button class="kullanici-btn" data-acilir-btn>
                        <?= avatar($u, 34) ?>
                        <span class="kullanici-ad"><?= e($u['name'] ?? '') ?></span>
                        <svg viewBox="0 0 24 24" width="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div class="acilir-panel">
                        <div class="acilir-baslik"><?= e(ROLES[$u['role'] ?? 'team'] ?? '') ?></div>
                        <a href="profile.php" class="acilir-oge">Profil & Tema</a>
                        <a href="logout.php" class="acilir-oge tehlike">Çıkış Yap</a>
                    </div>
                </div>
            </div>
        </header>
        <main class="ana">
<?php
}

function page_end(): void {
    $u = user();
?>
        </main>
    </div>
</div>
<?php if ($u && is_staff()):
    $dockTodos = rows("SELECT * FROM personal_todos WHERE user_id=? AND is_done=0 ORDER BY sort_order LIMIT 8", [$u['id']]);
    $dockNotes = rows("SELECT id, title, text FROM personal_notes WHERE user_id=? ORDER BY COALESCE(`update`, created) DESC LIMIT 5", [$u['id']]);
    $dockLinks = rows("SELECT * FROM personal_links WHERE user_id=? ORDER BY name LIMIT 8", [$u['id']]); ?>
<!-- Personal dock -->
<button class="dock-btn" id="dockBtn" title="Kişisel alan" aria-label="Kişisel alan">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="22"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg>
</button>
<div class="dock-panel" id="dockPanel">
    <div class="dock-sekmeler">
        <button class="dock-sekme aktif" data-dock="todos">Yapılacaklar</button>
        <button class="dock-sekme" data-dock="scratchpad">Karalama</button>
        <button class="dock-sekme" data-dock="notes">Notlar</button>
        <button class="dock-sekme" data-dock="links">Linkler</button>
    </div>
    <div class="dock-icerik aktif" id="dock-isler">
        <div class="dikey" style="gap:2px" id="dockIsList">
            <?php foreach ($dockTodos as $di): ?>
            <div class="kontrol-oge"><input type="checkbox" onchange="dockIsToggle(<?= $di['id'] ?>, this)"><span class="kontrol-metin kucuk"><?= e($di['name']) ?></span></div>
            <?php endforeach; ?>
            <?php if (!$dockTodos): ?><div class="metin-muted kucuk">Açık madde yok 🎉</div><?php endif; ?>
        </div>
        <form class="satir-esnek mt-2" style="gap:6px" onsubmit="return dockIsAdd(event)">
            <input class="girdi" id="dockIsNew" placeholder="Yeni madde..." style="font-size:13px">
            <button type="submit" class="btn btn-sm">+</button>
        </form>
    </div>
    <div class="dock-icerik" id="dock-karalama">
        <textarea class="metin-alani" id="dockScratchpad" style="min-height:200px;font-size:13px" placeholder="Karalama — otomatik kaydedilir"><?= e($u['scratchpad'] ?? '') ?></textarea>
        <div class="hucre-alt mt-1" id="dockScratchpadStatus">otomatik kaydedilir</div>
    </div>
    <div class="dock-icerik" id="dock-notlar">
        <?php foreach ($dockNotes as $dn): ?>
        <div style="padding:9px 11px;background:var(--surface-2);border-radius:10px;margin-bottom:6px">
            <?php if ($dn['title']): ?><div class="kucuk kalin"><?= e($dn['title']) ?></div><?php endif; ?>
            <div class="hucre-alt" style="white-space:pre-wrap"><?= e(mb_substr($dn['text'], 0, 120)) ?><?= mb_strlen($dn['text']) > 120 ? '…' : '' ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (!$dockNotes): ?><div class="metin-muted kucuk">Henüz not yok.</div><?php endif; ?>
        <a href="my-space.php" class="mini-btn">Alanım'da düzenle →</a>
    </div>
    <div class="dock-icerik" id="dock-linkler">
        <?php foreach ($dockLinks as $dl): ?>
        <a href="<?= e($dl['url']) ?>" target="_blank" class="satir-esnek kucuk kalin" style="gap:8px;padding:8px 10px;background:var(--surface-2);border-radius:9px;margin-bottom:5px;color:var(--marka)"><?= e($dl['name']) ?></a>
        <?php endforeach; ?>
        <?php if (!$dockLinks): ?><div class="metin-muted kucuk">Yer imi yok.</div><?php endif; ?>
        <a href="my-space.php" class="mini-btn">Alanım'da yönet →</a>
    </div>
</div>
<script>
document.getElementById('dockBtn').addEventListener('click', () => document.getElementById('dockPanel').classList.toggle('acik'));
document.querySelectorAll('.dock-sekme').forEach(s => s.addEventListener('click', () => {
    document.querySelectorAll('.dock-sekme').forEach(x => x.classList.remove('aktif'));
    document.querySelectorAll('.dock-content').forEach(x => x.classList.remove('aktif'));
    s.classList.add('aktif');
    document.getElementById('dock-' + s.dataset.dock).classList.add('aktif');
}));
async function dockIsToggle(id, box) { await api('personal_is_toggle', { id }); box.closest('.check-oge').style.opacity = '.4'; }
async function dockIsAdd(e) {
    e.preventDefault();
    const g = document.getElementById('dockIsNew'); const name = g.value.trim(); if (!name) return false;
    const j = await api('personal_is_add', { name });
    if (j.ok) {
        g.value = '';
        const div = document.createElement('div');
        div.className = 'kontrol-oge';
        div.innerHTML = `<input type="checkbox" onchange="dockIsToggle(${j.id}, this)"><span class="kontrol-metin kucuk"></span>`;
        div.querySelector('.check-text').textContent = j.name;
        document.getElementById('dockIsList').appendChild(div);
    }
    return false;
}
let dockKZ = null;
document.getElementById('dockScratchpad').addEventListener('input', function () {
    document.getElementById('dockScratchpadStatus').textContent = 'yazılıyor...';
    clearTimeout(dockKZ);
    dockKZ = setTimeout(async () => {
        const j = await api('scratchpad_save', { text: this.value });
        document.getElementById('dockScratchpadStatus').textContent = j.ok ? '✓ kaydedildi' : 'kaydedilemedi';
    }, 1200);
});
// ?create=1 → open the page's create modal
if (new URLSearchParams(location.search).get('create') === '1') {
    const target = ['modalTask', 'modalEvent', 'modalMeeting', 'modalContent', 'modalNot', 'modalAnnouncement'].find(m => document.getElementById(m));
    if (target) setTimeout(() => { if (target === 'modalNot' && typeof notSifirla === 'function') notSifirla(); modalOpen(target); }, 250);
}
</script>
<?php endif; ?>
<div class="karartma" data-karartma></div>
<div class="toast-alan" id="toastField"></div>
<script src="assets/js/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
<?php
}

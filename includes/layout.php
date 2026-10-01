<?php
/**
 * SADA One — Page layout (sidebar + top bar)
 */

function page_start(string $title, string $activePage = ''): void {
    $u = user();
    // Scheduled housekeeping runs after the page has been sent — never inside the page wait
    if ($u && is_staff()) after_response(fn() => run_recurring_jobs());
    $theme = isset(THEMES[$u['theme'] ?? '']) ? $u['theme'] : setting('default_theme', 'studio');
    $siteName = setting('site_name', 'SADA One');
    $notificationCount = $u ? (int)val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0", [$u['id']]) : 0;

    $nav = [];
    $navGroups = [];
    if (is_staff()) {
        $nav = [
            ['index.php', 'panel', 'Panel', 'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1V10'],
            ['today.php', 'today', 'Bugün', 'M12 3v2m0 14v2m9-9h-2M5 12H3m15.4-6.4L17 7M7 17l-1.4 1.4m12.8 0L17 17M7 7L5.6 5.6M16 12a4 4 0 11-8 0 4 4 0 018 0z'],
            ['tasks.php', 'tasks', 'İşler', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
            ['calendar.php', 'calendar', 'Takvim', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['clients.php', 'clients', 'Dosyalar', 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z'],
            ['projects.php', 'projects', 'Projeler', 'M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z'],
            ['messages.php', 'messages', 'Mesajlar', 'M8 12h8m-8-4h8m-9 8l-4 4V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H7z'],
        ];
        // Groups: [key, label, icon, items]
        $navGroups[] = ['studio', 'Stüdyo', 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z', [
            ['shoot-list.php', 'shoots', 'Çekim Listesi', 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z'],
            ['equipment.php', 'equipment', 'Ekipman', 'M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z'],
            ['team.php', 'team', 'Ekip', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3'],
        ]];
        $analyticsItems = [];
        if (permission('finance')) $analyticsItems[] = ['finance.php', 'finance', 'Finans', 'M12 8c-2.21 0-4 .9-4 2s1.79 2 4 2 4 .9 4 2-1.79 2-4 2m0-8c1.66 0 3.07.5 3.6 1.2M12 8V6m0 12v-2m0 2c-1.66 0-3.07-.5-3.6-1.2M21 12a9 9 0 11-18 0 9 9 0 0118 0z'];
        if (permission('report')) $analyticsItems[] = ['reports.php', 'reports', 'Raporlar', 'M9 19v-6M15 19v-2M12 19v-9M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z'];
        if (!is_intern()) $analyticsItems[] = ['monthly-reports.php', 'mreports', 'Aylık Raporlar', 'M8 7V3m8 4V3M5 11h14M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'];
        if (is_pm()) $analyticsItems[] = ['tower.php', 'tower', 'Kule', 'M2.5 12s3.5-7 9.5-7 9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7zM12 15a3 3 0 100-6 3 3 0 000 6z'];
        if ($analyticsItems) $navGroups[] = ['analytics', 'Analiz', 'M9 19v-6M15 19v-2M12 19v-9M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z', $analyticsItems];
        // The team's own things: announcements, requests, ideas, growth, personal space
        $teamItems = [['announcements.php', 'announcements', 'Duyurular', 'M11 5.88V19.24a1.76 1.76 0 01-3.42.6L5.44 14M18.7 4a9 9 0 01.3 13.3M5.44 14A2 2 0 015 10h1a8 8 0 005-2l3-2v12l-3-2a8 8 0 00-5-2H5.44z']];
        if (!is_intern()) $teamItems[] = ['requests.php', 'requests', 'Talepler', 'M8 10h8m-8 4h4m9-2a9 9 0 11-18 0 9 9 0 0118 0zM12 3v1m0 16v1'];
        $teamItems[] = ['office.php', 'office', 'Ofis Günleri', 'M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1M9 13h1m4 0h1M9 17h1m4 0h1'];
        $teamItems[] = ['ideas.php', 'ideas', 'Fikir Panosu', 'M9.66 18h4.68M10 21h4m-2-18a7 7 0 00-4 12.7c.6.5 1 1.2 1 2v.3h6v-.3c0-.8.4-1.5 1-2A7 7 0 0012 3z'];
        $teamItems[] = ['growth.php', 'growth', 'Gelişim & Mentörlük', 'M12 14l9-5-9-5-9 5 9 5zm0 0v6m-6-3.5V12m12 4.5V12'];
        if (!is_intern()) $teamItems[] = ['talent-pool.php', 'pool', 'Çalışan Havuzu', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z'];
        $teamItems[] = ['my-space.php', 'my_space', 'Alanım', 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z'];
        $navGroups[] = ['team', 'Ekip & Fikir', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z', $teamItems];
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
    $adminNav = [];
    if (is_admin()) {
        $adminNav = [
            ['users.php', 'users', 'Kullanıcılar', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3'],
            ['task-types.php', 'task_types', 'İş Türleri', 'M13 10V3L4 14h7v7l9-11h-7z'],
            ['project-templates.php', 'ptemplates', 'Proje Şablonları', 'M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z'],
            ['form-templates.php', 'forms', 'Form Şablonları', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            ['settings.php', 'settings', 'Ayarlar', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
            ['updater.php', 'update', 'Güncelleme', 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
        ];
    } elseif (is_staff()) {
        $adminNav = [['archive.php', 'archive', 'Dosya Arşivi', 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4']];
    }
?>
<!DOCTYPE html>
<html lang="tr" data-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf" content="<?= csrf_token() ?>">
<title><?= e($title) ?> — <?= e($siteName) ?></title>
<link rel="stylesheet" href="assets/css/fonts.css?v=<?= APP_VERSION ?>">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="<?= e(THEMES[$theme][1]) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="assets/img/icon-192.png">
<?php /* conservative = prerender on pointer-down only. "moderate" prerendered every
         hovered link: each one a full hidden page render (queries + live-sync timers)
         multiplying the PHP processes a single user occupied on shared hosting. */ ?>
<script type="speculationrules">
{"prerender": [{"where": {"and": [
    {"href_matches": "/*"},
    {"not": {"href_matches": "/*logout*"}},
    {"not": {"href_matches": "/*export*"}},
    {"not": {"href_matches": "/*update_row*"}},
    {"not": {"href_matches": "/*install*"}}
]}, "eagerness": "conservative"}]}
</script>
<?php if (theme_favicon()): ?><link rel="icon" href="uploads/<?= e(theme_favicon()) ?>"><?php endif; ?>
</head>
<body>
<div class="page-bar" id="pageBar"></div>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <?php if (theme_logo()): ?>
            <a href="index.php" style="display:flex;align-items:center"><img src="uploads/<?= e(theme_logo()) ?>" alt="<?= e($siteName) ?>" style="max-height:40px;max-width:170px;object-fit:contain"></a>
            <?php else: ?>
            <a href="index.php" class="wordmark">SADA<span>.</span></a>
            <?php endif; ?>
            <button class="sidebar-close" data-sidebar-close aria-label="Menüyü kapat">✕</button>
        </div>
        <nav class="sidebar-nav">
            <?php
            // Renders a single nav item (with the messages badge)
            $navItemWrite = function (array $n, bool $subItem = false) use ($activePage, $u) {
                $isActive = $activePage === $n[1] || ($n[1] === 'calendar' && is_staff() && isset(CALENDAR_VIEWS[$activePage]));
                echo '<a href="' . $n[0] . '" class="nav-item ' . ($subItem ? 'nav-sub-item ' : '') . ($isActive ? 'active' : '') . '">';
                echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="' . $n[3] . '"/></svg>';
                echo '<span>' . $n[2] . '</span>';
                if ($n[1] === 'messages' && $u) {
                    $unread = (int)val("SELECT COUNT(*) FROM messages m JOIN channel_members ku ON ku.channel_id=m.channel_id AND ku.user_id=? WHERE m.user_id!=? AND ku.archive=0 AND (ku.last_read IS NULL OR m.created>ku.last_read)", [$u['id'], $u['id']]);
                    if ($unread) echo '<span class="nav-counter">' . ($unread > 99 ? '99+' : $unread) . '</span>';
                }
                if ($n[1] === 'mreports' && $u) {
                    // Missing monthly reports of the current window, for the clients this user manages
                    $day = (int)date('j'); $lastDay = (int)date('t');
                    $wPeriod = $day >= $lastDay - 2 ? date('Y-m') : ($day <= 4 ? date('Y-m', strtotime('first day of last month')) : null);
                    if ($wPeriod) {
                        $missingReport = (int)val("SELECT COUNT(*) FROM clients c LEFT JOIN monthly_reports r ON r.client_id=c.id AND r.period=?
                            WHERE c.status='active' AND c.manager_id=? AND (r.id IS NULL OR r.status='draft')", [$wPeriod, $u['id']]);
                        if ($missingReport) echo '<span class="nav-counter">' . $missingReport . '</span>';
                    }
                }
                echo '</a>';
            };
            foreach ($nav as $n) $navItemWrite($n);

            // Collapsible groups
            foreach ($navGroups as $group):
                [$gKey, $gLabel, $gIcon, $gItems] = $group;
                $groupActive = in_array($activePage, array_column($gItems, 1)); ?>
            <div class="nav-group <?= $groupActive ? 'open active-group' : '' ?>" data-nav-group="<?= $gKey ?>">
                <button class="nav-item nav-group-title" data-group-btn>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $gIcon ?>"/></svg>
                    <span><?= $gLabel ?></span>
                    <svg class="group-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <div class="nav-group-content">
                    <?php foreach ($gItems as $n) $navItemWrite($n, true); ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($adminNav): ?>
            <div class="nav-section"><?= is_admin() ? 'Yönetim' : 'Araçlar' ?></div>
            <?php foreach ($adminNav as $n) $navItemWrite($n); endif; ?>
        </nav>
        <div class="sidebar-bottom">
            <?php if (is_staff()): ?>
            <div class="dropdown" data-dropdown style="width:100%">
                <button class="btn btn-brand btn-block" data-dropdown-btn>
                    <svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" width="16"><path d="M12 5v14M5 12h14"/></svg> Hızlı Oluştur
                </button>
                <div class="dropdown-panel quick-create-panel">
                    <?php if (permission('task_create')): ?><a class="dropdown-item" href="tasks.php?create=1">İş</a><?php endif; ?>
                    <?php if (permission('calendar_manage')): ?>
                    <a class="dropdown-item" href="calendar.php?create=1">Etkinlik / Çekim</a>
                    <a class="dropdown-item" href="meetings.php?create=1">Toplantı</a>
                    <?php endif; ?>
                    <?php if (permission('content_manage')): ?><a class="dropdown-item" href="content-calendar.php?create=1">Yayın planı</a><?php endif; ?>
                    <a class="dropdown-item" href="my-space.php?create=1">Kişisel Not</a>
                    <?php if (permission('announcement_publish')): ?><a class="dropdown-item" href="announcements.php?create=1">Duyuru</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </aside>

    <div class="content-area">
        <header class="topbar">
            <button class="menu-btn" data-sidebar-open aria-label="Menüyü aç"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
            <div class="topbar-title"><?= e($title) ?></div>
            <div class="search-global">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                <input class="input" id="globalSearch" placeholder="Ara: dosya, proje, iş..." autocomplete="off">
                <div class="search-result" id="searchResult"></div>
            </div>
            <div class="topbar-right">
                <div class="dropdown" data-dropdown>
                    <button class="icon-btn" data-dropdown-btn aria-label="Bildirimler">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <span class="icon-counter" data-notification-badge<?= $notificationCount ? '' : ' style="display:none"' ?>><?= $notificationCount > 99 ? '99+' : (int)$notificationCount ?></span>
                    </button>
                    <div class="dropdown-panel notification-panel">
                        <div class="dropdown-title">Bildirimler
                            <span class="row-flex" style="gap:10px">
                                <?php if ($notificationCount): ?><button class="mini-btn" data-all-read>Okundu işaretle</button><?php endif; ?>
                                <button class="mini-btn" style="color:var(--danger)" data-action="notification_clear" data-confirm="Tüm bildirimler silinsin mi?">Tümünü sil</button>
                            </span>
                        </div>
                        <div class="notification-list">
                            <?php $notifications = $u ? rows("SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 12", [$u['id']]) : [];
                            if (!$notifications): ?><div class="empty-mini">Henüz bildirim yok</div>
                            <?php else: foreach ($notifications as $b): ?>
                            <a href="<?= e($b['link'] ?: '#') ?>" class="notification-item <?= $b['is_read'] ? '' : 'new' ?>" data-notification="<?= $b['id'] ?>">
                                <div class="notification-title"><?= e($b['title']) ?></div>
                                <?php if ($b['message']): ?><div class="notification-text"><?= e(mb_substr($b['message'], 0, 90)) ?></div><?php endif; ?>
                                <div class="notification-time"><?= time_ago($b['created']) ?></div>
                                <span class="notification-delete-x" data-notification-delete title="Sil">✕</span>
                            </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <div class="dropdown" data-dropdown>
                    <button class="user-btn" data-dropdown-btn>
                        <?= avatar($u, 34) ?>
                        <span class="user-name"><?= e($u['name'] ?? '') ?></span>
                        <svg viewBox="0 0 24 24" width="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div class="dropdown-panel">
                        <div class="dropdown-title"><?= e(ROLES[$u['role'] ?? 'team'] ?? '') ?></div>
                        <a href="profile.php" class="dropdown-item">Profil & Tema</a>
                        <a href="logout.php" class="dropdown-item danger">Çıkış Yap</a>
                    </div>
                </div>
            </div>
        </header>
        <main class="main">
<?php
    if (is_staff() && isset(CALENDAR_VIEWS[$activePage])): ?>
            <nav class="view-bar" aria-label="Takvim görünümleri">
                <span class="view-bar-label">Takvim</span>
                <?php foreach (CALENDAR_VIEWS as $viewKey => [$viewHref, $viewLabel]): ?><a href="<?= $viewHref ?>" class="view-tab<?= $viewKey === $activePage ? ' active' : '' ?>"><?= $viewLabel ?></a><?php endforeach; ?>
            </nav>
<?php endif;
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
    <div class="dock-tabs">
        <button class="dock-tab active" data-dock="todos">Yapılacaklar</button>
        <button class="dock-tab" data-dock="scratchpad">Karalama</button>
        <button class="dock-tab" data-dock="notes">Notlar</button>
        <button class="dock-tab" data-dock="links">Linkler</button>
    </div>
    <div class="dock-content active" id="dock-todos">
        <div class="vertical" style="gap:2px" id="dockIsList">
            <?php foreach ($dockTodos as $di): ?>
            <div class="check-item"><input type="checkbox" onchange="dockIsToggle(<?= $di['id'] ?>, this)"><span class="check-text small"><?= e($di['name']) ?></span></div>
            <?php endforeach; ?>
            <?php if (!$dockTodos): ?><div class="text-muted small">Açık madde yok 🎉</div><?php endif; ?>
        </div>
        <form class="row-flex mt-2" style="gap:6px" onsubmit="return dockIsAdd(event)">
            <input class="input" id="dockIsNew" placeholder="Yeni madde..." style="font-size:13px">
            <button type="submit" class="btn btn-sm">+</button>
        </form>
    </div>
    <div class="dock-content" id="dock-scratchpad">
        <textarea class="text-area" id="dockScratchpad" style="min-height:200px;font-size:13px" placeholder="Karalama — otomatik kaydedilir"><?= e($u['scratchpad'] ?? '') ?></textarea>
        <div class="cell-bottom mt-1" id="dockScratchpadStatus">otomatik kaydedilir</div>
    </div>
    <div class="dock-content" id="dock-notes">
        <?php foreach ($dockNotes as $dn): ?>
        <div style="padding:9px 11px;background:var(--surface-2);border-radius:10px;margin-bottom:6px">
            <?php if ($dn['title']): ?><div class="small bold"><?= e($dn['title']) ?></div><?php endif; ?>
            <div class="cell-bottom" style="white-space:pre-wrap"><?= e(mb_substr($dn['text'], 0, 120)) ?><?= mb_strlen($dn['text']) > 120 ? '…' : '' ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (!$dockNotes): ?><div class="text-muted small">Henüz not yok.</div><?php endif; ?>
        <a href="my-space.php" class="mini-btn">Alanım'da düzenle →</a>
    </div>
    <div class="dock-content" id="dock-links">
        <?php foreach ($dockLinks as $dl): ?>
        <a href="<?= e($dl['url']) ?>" target="_blank" class="row-flex small bold" style="gap:8px;padding:8px 10px;background:var(--surface-2);border-radius:9px;margin-bottom:5px;color:var(--brand)"><?= e($dl['name']) ?></a>
        <?php endforeach; ?>
        <?php if (!$dockLinks): ?><div class="text-muted small">Yer imi yok.</div><?php endif; ?>
        <a href="my-space.php" class="mini-btn">Alanım'da yönet →</a>
    </div>
</div>
<script>
document.getElementById('dockBtn').addEventListener('click', () => document.getElementById('dockPanel').classList.toggle('open'));
document.querySelectorAll('.dock-tab').forEach(s => s.addEventListener('click', () => {
    document.querySelectorAll('.dock-tab').forEach(x => x.classList.remove('active'));
    document.querySelectorAll('.dock-content').forEach(x => x.classList.remove('active'));
    s.classList.add('active');
    document.getElementById('dock-' + s.dataset.dock).classList.add('active');
}));
async function dockIsToggle(id, box) { await api('personal_todo_toggle', { id }); box.closest('.check-item').style.opacity = '.4'; }
async function dockIsAdd(e) {
    e.preventDefault();
    const g = document.getElementById('dockIsNew'); const name = g.value.trim(); if (!name) return false;
    const j = await api('personal_todo_add', { name });
    if (j.ok) {
        g.value = '';
        const div = document.createElement('div');
        div.className = 'check-item';
        div.innerHTML = `<input type="checkbox" onchange="dockIsToggle(${j.id}, this)"><span class="check-text small"></span>`;
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
        document.getElementById('dockScratchpadStatus').textContent = j.ok ? '✓ kaydedildi' : 'save_failed';
    }, 1200);
});
// ?create=1 → open the page's create modal
if (new URLSearchParams(location.search).get('create') === '1') {
    const target = ['modalTask', 'modalEvent', 'modalMeeting', 'modalPlan', 'modalNote', 'modalAnnouncement'].find(m => document.getElementById(m));
    if (target) setTimeout(() => { if (target === 'modalNote' && typeof noteReset === 'function') noteReset(); modalOpen(target); }, 250);
}
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/mikasa.php';
if (mikasa_on($u)): $mkName = setting('mikasa_name', 'Mikasa') ?: 'Mikasa'; $mkAvatar = setting('mikasa_avatar'); ?>
<!-- Mikasa: the team's assistant (never shown to customers) -->
<div class="mikasa" id="mikasa" data-name="<?= e($mkName) ?>" hidden>
    <div class="mk-bubble" role="status" aria-live="polite" hidden><span class="mk-text"></span> <a class="mk-link" hidden>Aç →</a></div>
    <div class="mk-menu" hidden><button type="button" data-mk="next">Başka ne var?</button><button type="button" data-mk="mute">Bugün sus</button><button type="button" data-mk="off">Kapat</button></div>
    <button type="button" class="mk-face" aria-label="<?= e($mkName) ?>" title="<?= e($mkName) ?>">
        <?php if ($mkAvatar): ?><img src="uploads/<?= e($mkAvatar) ?>" alt="" class="mk-img">
        <?php else: ?>
        <svg viewBox="0 0 64 64" aria-hidden="true">
            <circle cx="32" cy="35" r="24" class="mk-head"/>
            <ellipse cx="24" cy="24" rx="8" ry="4.5" fill="#fff" opacity=".22"/>
            <path d="M42 15.5l4-7" class="mk-line"/><circle cx="47.5" cy="7.5" r="4.5" class="mk-head"/><circle cx="47.5" cy="7.5" r="1.8" class="mk-ink"/>
            <g class="mk-eyes"><ellipse cx="24.5" cy="35" rx="3.3" ry="4.3" class="mk-ink"/><ellipse cx="39.5" cy="35" rx="3.3" ry="4.3" class="mk-ink"/><circle cx="25.6" cy="33.4" r="1.1" fill="#fff"/><circle cx="40.6" cy="33.4" r="1.1" fill="#fff"/></g>
            <g class="mk-eye-closed"><path d="M20.5 36q4 3.2 8 0" class="mk-line"/><path d="M35.5 36q4 3.2 8 0" class="mk-line"/></g>
            <circle cx="18" cy="43" r="3" fill="#ff7a8a" opacity=".35"/><circle cx="46" cy="43" r="3" fill="#ff7a8a" opacity=".35"/>
            <path d="M29 44.5q3 2.6 6 0" class="mk-line"/>
        </svg>
        <?php endif; ?>
        <span class="mk-zzz" aria-hidden="true">z</span>
    </button>
</div>
<?php endif; ?>
<div class="backdrop" data-backdrop></div>
<div class="toast-area" id="toastField"></div>
<script src="assets/js/app.js?v=<?= APP_VERSION ?>"></script>
<?php if (mikasa_on($u)): ?><script src="assets/js/mikasa.js?v=<?= APP_VERSION ?>"></script><?php endif; ?>
</body>
</html>
<?php
}

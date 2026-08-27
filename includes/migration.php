<?php
/**
 * SADA One — Central Schema Migration
 * The install wizard, guncelle.php, and the in-panel updater all use the same list.
 * Every command is idempotent: "Duplicate/exists" errors are skipped.
 */

function migration_commands(): array {
    return [

    // users
    "ALTER TABLE users MODIFY role ENUM('yonetici','pm','ekip','finans','musteri') NOT NULL DEFAULT 'ekip'",
    "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN weekly_capacity SMALLINT NOT NULL DEFAULT 45",
    "ALTER TABLE users ADD COLUMN permissions TEXT",
    "ALTER TABLE users ADD COLUMN notification_preferences TEXT",
    "ALTER TABLE users ADD COLUMN widgets TEXT",
    // client files
    "ALTER TABLE clients ADD COLUMN logo VARCHAR(255) DEFAULT NULL",
    // tasks
    "ALTER TABLE tasks ADD COLUMN sort_order INT NOT NULL DEFAULT 0",
    "ALTER TABLE tasks ADD COLUMN bagimli_id INT DEFAULT NULL",
    "ALTER TABLE tasks ADD COLUMN lock_bypassed TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE tasks ADD COLUMN `repeat` ENUM('yok','haftalik','aylik') NOT NULL DEFAULT 'yok'",
    "ALTER TABLE tasks ADD COLUMN last_repeat VARCHAR(10) DEFAULT NULL",
    // new tables
    "CREATE TABLE IF NOT EXISTS project_members (project_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (project_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS client_members (client_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (client_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS task_checklist (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, name VARCHAR(200) NOT NULL, is_done TINYINT(1) NOT NULL DEFAULT 0, sort_order TINYINT NOT NULL DEFAULT 1, INDEX(task_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v3 ----
    "ALTER TABLE users ADD COLUMN task_view VARCHAR(10) NOT NULL DEFAULT 'kanban'",
    "ALTER TABLE tasks ADD COLUMN tags VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE tasks ADD COLUMN estimated_minutes INT NOT NULL DEFAULT 0",
    "ALTER TABLE tasks ADD COLUMN start_date DATE DEFAULT NULL",
    "ALTER TABLE comments ADD COLUMN parent_id INT DEFAULT NULL",
    "ALTER TABLE comments ADD COLUMN archive_id INT DEFAULT NULL",
    "ALTER TABLE comments ADD COLUMN is_edited TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS comment_box_reactions (comment_box_id INT NOT NULL, user_id INT NOT NULL, emoji VARCHAR(8) NOT NULL, PRIMARY KEY (comment_box_id, user_id, emoji)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS task_watchers (task_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (task_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v4 ----
    "ALTER TABLE tasks ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN salary DECIMAL(12,2) NOT NULL DEFAULT 0",
    "ALTER TABLE channels ADD COLUMN icon VARCHAR(8) DEFAULT NULL",
    "ALTER TABLE channel_members ADD COLUMN archive TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS task_assignees (task_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (task_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS expenses (id INT AUTO_INCREMENT PRIMARY KEY, type ENUM('maas','kira','abonelik','ekipman','vergi','diger') NOT NULL DEFAULT 'diger', title VARCHAR(200) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, date DATE NOT NULL, status ENUM('bekliyor','odendi') NOT NULL DEFAULT 'bekliyor', `repeat` ENUM('yok','aylik') NOT NULL DEFAULT 'yok', last_repeat VARCHAR(10) DEFAULT NULL, user_id INT DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS announcements (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, text TEXT, is_important TINYINT(1) NOT NULL DEFAULT 0, created_by INT NOT NULL, created DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS announcement_readers (announcement_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (announcement_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(150) NOT NULL, ip VARCHAR(45) DEFAULT NULL, is_success TINYINT(1) NOT NULL DEFAULT 0, created DATETIME NOT NULL, INDEX(email, created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS contracts (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, title VARCHAR(200) NOT NULL, start DATE DEFAULT NULL, `end` DATE DEFAULT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, archive_id INT DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, is_reminded TINYINT(1) NOT NULL DEFAULT 0, created DATETIME NOT NULL, INDEX(client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v5 ----
    "CREATE TABLE IF NOT EXISTS equipment (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) DEFAULT NULL, name VARCHAR(150) NOT NULL, category ENUM('kamera','lens','sd_kart','tripod','isik','ses','drone','aksesuar','diger') NOT NULL DEFAULT 'diger', photo VARCHAR(255) DEFAULT NULL, status ENUM('studyoda','zimmette','cekimde','arizali','bakimda') NOT NULL DEFAULT 'studyoda', custody_user_id INT DEFAULT NULL, custody_event_id INT DEFAULT NULL, fault_note VARCHAR(255) DEFAULT NULL, purchase_date DATE DEFAULT NULL, price DECIMAL(12,2) NOT NULL DEFAULT 0, sd_status ENUM('bos','dolu','aktarildi') DEFAULT NULL, sd_content VARCHAR(255) DEFAULT NULL, sd_drive_link VARCHAR(255) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(category), INDEX(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS equipment_logs (id INT AUTO_INCREMENT PRIMARY KEY, equipment_id INT NOT NULL, user_id INT NOT NULL, target_user_id INT DEFAULT NULL, event_id INT DEFAULT NULL, type VARCHAR(20) NOT NULL, description VARCHAR(500) DEFAULT NULL, created DATETIME NOT NULL, INDEX(equipment_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS event_equipment (event_id INT NOT NULL, equipment_id INT NOT NULL, PRIMARY KEY (event_id, equipment_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v6 ----
    "ALTER TABLE users MODIFY role ENUM('yonetici','pm','ekip','finans','stajyer','musteri') NOT NULL DEFAULT 'ekip'",
    "ALTER TABLE users ADD COLUMN scratchpad MEDIUMTEXT",
    "ALTER TABLE events ADD COLUMN online_link VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE events ADD COLUMN is_reminded TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS event_participants (event_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (event_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS personal_notes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(150) DEFAULT NULL, text TEXT, color VARCHAR(20) NOT NULL DEFAULT 'varsayilan', created DATETIME NOT NULL, `update` DATETIME DEFAULT NULL, INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS personal_todos (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(255) NOT NULL, is_done TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS personal_links (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(150) NOT NULL, url VARCHAR(500) NOT NULL, INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v7 ----
    "ALTER TABLE users ADD COLUMN seen_version VARCHAR(10) DEFAULT NULL",
    "CREATE TABLE IF NOT EXISTS customer_clients (user_id INT NOT NULL, client_id INT NOT NULL, PRIMARY KEY (user_id, client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "INSERT IGNORE INTO customer_clients (user_id, client_id) SELECT id, client_id FROM users WHERE role='musteri' AND client_id IS NOT NULL",
    "CREATE TABLE IF NOT EXISTS ratings (id INT AUTO_INCREMENT PRIMARY KEY, ref_type ENUM('gorev','onay') NOT NULL, ref_id INT NOT NULL, project_id INT NOT NULL, user_id INT NOT NULL, rating TINYINT NOT NULL, comment_box VARCHAR(500) DEFAULT NULL, created DATETIME NOT NULL, UNIQUE KEY uniq_rating (ref_type, ref_id, user_id), INDEX(project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS appointments (id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, client_id INT DEFAULT NULL, topic VARCHAR(200) NOT NULL, date DATETIME NOT NULL, online_request TINYINT(1) NOT NULL DEFAULT 0, notes VARCHAR(500) DEFAULT NULL, status ENUM('bekliyor','onaylandi','alternatif','reddedildi') NOT NULL DEFAULT 'bekliyor', alternative_date DATETIME DEFAULT NULL, online_link VARCHAR(255) DEFAULT NULL, event_id INT DEFAULT NULL, reply_note VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(customer_id), INDEX(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v8 ----
    "ALTER TABLE contents ADD COLUMN client_id INT DEFAULT NULL AFTER id",
    "ALTER TABLE contents MODIFY project_id INT DEFAULT NULL",
    "ALTER TABLE contents MODIFY platform VARCHAR(120) NOT NULL DEFAULT 'instagram'",
    "UPDATE contents i JOIN projects p ON p.id=i.project_id SET i.client_id=p.client_id WHERE i.client_id IS NULL",
    "CREATE TABLE IF NOT EXISTS social_accounts (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, platform VARCHAR(20) NOT NULL DEFAULT 'instagram', username VARCHAR(100) NOT NULL, url VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS social_metrics (id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, date DATE NOT NULL, followers INT NOT NULL DEFAULT 0, post INT DEFAULT NULL, engagement INT DEFAULT NULL, entered_by INT DEFAULT NULL, created DATETIME NOT NULL, UNIQUE KEY uniq_metric (account_id, date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v9 ----
    "CREATE TABLE IF NOT EXISTS documents (id INT AUTO_INCREMENT PRIMARY KEY, type ENUM('teklif','fatura') NOT NULL DEFAULT 'teklif', doc_no VARCHAR(20) NOT NULL, client_id INT DEFAULT NULL, title VARCHAR(200) NOT NULL, items TEXT, vat_rate TINYINT NOT NULL DEFAULT 20, status ENUM('taslak','gonderildi','onaylandi','reddedildi') NOT NULL DEFAULT 'taslak', valid_until DATE DEFAULT NULL, notes VARCHAR(500) DEFAULT NULL, created_by INT NOT NULL, created DATETIME NOT NULL, INDEX(client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v11 ----
    "ALTER TABLE archive ADD COLUMN url VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE approvals ADD COLUMN drive_link VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE form_fields MODIFY type ENUM('metin','uzun_metin','secim','tarih','sayi','dosya') NOT NULL DEFAULT 'metin'",
    // ---- v10 ----
    "ALTER TABLE tasks ADD COLUMN content_id INT DEFAULT NULL",
    "CREATE TABLE IF NOT EXISTS project_templates (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, description VARCHAR(255) DEFAULT NULL, tasks TEXT, created DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS client_notes (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, title VARCHAR(150) NOT NULL, text TEXT, sort_order INT NOT NULL DEFAULT 0, updated_by INT DEFAULT NULL, `update` DATETIME DEFAULT NULL, created DATETIME NOT NULL, INDEX(client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v14 (SADA One 4.0) ----
    "ALTER TABLE projects ADD COLUMN budget DECIMAL(12,2) NOT NULL DEFAULT 0",
    "ALTER TABLE projects ADD COLUMN revision_limit TINYINT NOT NULL DEFAULT 2",
    "ALTER TABLE projects ADD COLUMN handover VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE projects ADD COLUMN team_roles TEXT",
    "ALTER TABLE events ADD COLUMN shopping_list TEXT",
    "ALTER TABLE events ADD COLUMN needs_list TEXT",
    "CREATE TABLE IF NOT EXISTS project_extra_requests (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, title VARCHAR(200) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, out_of_scope TINYINT(1) NOT NULL DEFAULT 0, status ENUM('bekliyor','onaylandi','reddedildi') NOT NULL DEFAULT 'bekliyor', description VARCHAR(500) DEFAULT NULL, created_by INT NOT NULL, created DATETIME NOT NULL, INDEX(project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS project_checklist (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, item VARCHAR(200) NOT NULL, check_note VARCHAR(500) DEFAULT NULL, owner_id INT DEFAULT NULL, is_done TINYINT(1) NOT NULL DEFAULT 0, is_delivered TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, INDEX(project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS project_review (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, type ENUM('ic','dis','case_study') NOT NULL, content TEXT, updated_by INT DEFAULT NULL, updated DATETIME DEFAULT NULL, UNIQUE KEY pd_unique (project_id, type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS mentorship (id INT AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, field VARCHAR(200) NOT NULL, mentor_id INT DEFAULT NULL, project_id INT DEFAULT NULL, practice_arena VARCHAR(255) DEFAULT NULL, output TEXT, status ENUM('planlandi','devam','tamamlandi') NOT NULL DEFAULT 'planlandi', created DATETIME NOT NULL, updated DATETIME DEFAULT NULL, INDEX(member_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS talent_pool (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, skill VARCHAR(255) DEFAULT NULL, worked_before TINYINT(1) NOT NULL DEFAULT 0, contact VARCHAR(255) DEFAULT NULL, cv_archive_id INT DEFAULT NULL, note VARCHAR(500) DEFAULT NULL, added_by INT NOT NULL, created DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS ideas (id INT AUTO_INCREMENT PRIMARY KEY, idea VARCHAR(300) NOT NULL, organization VARCHAR(200) DEFAULT NULL, description TEXT, proposer_id INT NOT NULL, status ENUM('yeni','begenildi','uygulandi') NOT NULL DEFAULT 'yeni', created DATETIME NOT NULL, INDEX(proposer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS monthly_reports (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, period CHAR(7) NOT NULL, summary TEXT, work_done TEXT, metrics TEXT, plan TEXT, author_id INT NOT NULL, status ENUM('taslak','tamamlandi') NOT NULL DEFAULT 'taslak', created DATETIME NOT NULL, updated DATETIME DEFAULT NULL, UNIQUE KEY ar_unique (client_id, period)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS task_manager_notes (task_id INT NOT NULL, user_id INT NOT NULL, note TEXT, updated DATETIME DEFAULT NULL, PRIMARY KEY (task_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v5.1: monthly-report automation + shoot costs ----
    "ALTER TABLE clients ADD COLUMN manager_id INT DEFAULT NULL",
    "ALTER TABLE events ADD COLUMN cost DECIMAL(12,2) NOT NULL DEFAULT 0",
    "ALTER TABLE expenses ADD COLUMN event_id INT DEFAULT NULL",
    // ---- v6.0: Drive tracking + AI groundwork ----
    "ALTER TABLE clients ADD COLUMN drive_folder_id VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE events ADD COLUMN drive_folder_id VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE events ADD COLUMN drive_link VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE events ADD COLUMN drive_status ENUM('bekliyor','aktarildi') NOT NULL DEFAULT 'bekliyor'",
        "ALTER TABLE events ADD COLUMN drive_files_seen TINYINT(1) NOT NULL DEFAULT 0",
    ];
}

/** Runs all migration commands; returns [status, sql] pairs. status: ok|atla|hata */
function run_migrations(PDO $pdo): array {
    $results = [];
    // Legacy Turkish schemas are renamed to English first (no-op on fresh installs)
    require_once __DIR__ . '/legacy-migration.php';
    foreach (legacy_localization($pdo) as $l) $results[] = [str_starts_with($l, 'ERR') ? 'error' : 'ok', 'legacy: ' . $l];
    // Table renames must run BEFORE the CREATE IF NOT EXISTS list: otherwise an empty
    // new-named table gets created first, the rename then collides, and the old data
    // is stranded in the old table. If both exist, keep the one that holds the data.
    foreach ([['project_ek_requests', 'project_extra_requests']] as [$oldT, $newT]) {
        try {
            $hasOld = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($oldT))->fetchColumn();
            $hasNew = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($newT))->fetchColumn();
            if ($hasOld && $hasNew) {
                $newCount = (int)$pdo->query("SELECT COUNT(*) FROM `$newT`")->fetchColumn();
                if ($newCount === 0) { $pdo->exec("DROP TABLE `$newT`"); $hasNew = false; }
            }
            if ($hasOld && !$hasNew) { $pdo->exec("RENAME TABLE `$oldT` TO `$newT`"); $results[] = ['ok', "rename: $oldT → $newT"]; }
        } catch (PDOException $e) { $results[] = ['hata', "rename $oldT — " . $e->getMessage()]; }
    }
    foreach (migration_commands() as $sql) {
        try {
            $pdo->exec($sql);
            $results[] = ['ok', $sql];
        } catch (PDOException $e) {
            $zaten = (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'exists') !== false || strpos($e->getMessage(), "doesn't exist") !== false);
            $results[] = [$zaten ? 'skip' : 'hata', $sql . ($zaten ? '' : ' — ' . $e->getMessage())];
        }
    }
    return $results;
}

<?php
/**
 * SADA One — Central Schema Migration
 * The install wizard, migrate.php, and the in-panel updater all use the same list.
 * Every command is idempotent: "Duplicate/exists" errors are skipped.
 */

function migration_commands(): array {
    return [

    // users
    // (the older role ENUM without 'intern' was removed from here: two MODIFYs on one column
    //  narrowed it on every run and deleted the intern roles — the v6 line is enough)
    "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN weekly_capacity SMALLINT NOT NULL DEFAULT 45",
    "ALTER TABLE users ADD COLUMN permissions TEXT",
    "ALTER TABLE users ADD COLUMN notification_preferences TEXT",
    "ALTER TABLE users ADD COLUMN widgets TEXT",
    // client files
    "ALTER TABLE clients ADD COLUMN logo VARCHAR(255) DEFAULT NULL",
    // tasks
    "ALTER TABLE tasks ADD COLUMN sort_order INT NOT NULL DEFAULT 0",
    "ALTER TABLE tasks ADD COLUMN depends_on_id INT DEFAULT NULL",
    "ALTER TABLE tasks ADD COLUMN lock_bypassed TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE tasks ADD COLUMN `repeat` ENUM('none','weekly','monthly') NOT NULL DEFAULT 'none'",
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
    "CREATE TABLE IF NOT EXISTS comment_reactions (comment_id INT NOT NULL, user_id INT NOT NULL, emoji VARCHAR(8) NOT NULL, PRIMARY KEY (comment_id, user_id, emoji)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS task_watchers (task_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (task_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v4 ----
    "ALTER TABLE tasks ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN salary DECIMAL(12,2) NOT NULL DEFAULT 0",
    "ALTER TABLE channels ADD COLUMN icon VARCHAR(8) DEFAULT NULL",
    "ALTER TABLE channel_members ADD COLUMN archive TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS task_assignees (task_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (task_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS expenses (id INT AUTO_INCREMENT PRIMARY KEY, type ENUM('salary','rent','subscription','equipment','tax','other') NOT NULL DEFAULT 'other', title VARCHAR(200) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, date DATE NOT NULL, status ENUM('pending','paid') NOT NULL DEFAULT 'pending', `repeat` ENUM('none','monthly') NOT NULL DEFAULT 'none', last_repeat VARCHAR(10) DEFAULT NULL, user_id INT DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS announcements (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, text TEXT, is_important TINYINT(1) NOT NULL DEFAULT 0, created_by INT NOT NULL, created DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS announcement_readers (announcement_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (announcement_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(150) NOT NULL, ip VARCHAR(45) DEFAULT NULL, is_success TINYINT(1) NOT NULL DEFAULT 0, created DATETIME NOT NULL, INDEX(email, created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS contracts (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, title VARCHAR(200) NOT NULL, start DATE DEFAULT NULL, `end` DATE DEFAULT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, archive_id INT DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, is_reminded TINYINT(1) NOT NULL DEFAULT 0, created DATETIME NOT NULL, INDEX(client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v5 ----
    "CREATE TABLE IF NOT EXISTS equipment (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) DEFAULT NULL, name VARCHAR(150) NOT NULL, category ENUM('camera','lens','sd_card','tripod','light','audio','drone','accessory','other') NOT NULL DEFAULT 'other', photo VARCHAR(255) DEFAULT NULL, status ENUM('in_studio','checked_out','on_shoot','faulty','in_maintenance') NOT NULL DEFAULT 'in_studio', custody_user_id INT DEFAULT NULL, custody_event_id INT DEFAULT NULL, fault_note VARCHAR(255) DEFAULT NULL, purchase_date DATE DEFAULT NULL, price DECIMAL(12,2) NOT NULL DEFAULT 0, sd_status ENUM('empty','full','transferred') DEFAULT NULL, sd_content VARCHAR(255) DEFAULT NULL, sd_drive_link VARCHAR(255) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(category), INDEX(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS equipment_logs (id INT AUTO_INCREMENT PRIMARY KEY, equipment_id INT NOT NULL, user_id INT NOT NULL, target_user_id INT DEFAULT NULL, event_id INT DEFAULT NULL, type VARCHAR(20) NOT NULL, description VARCHAR(500) DEFAULT NULL, created DATETIME NOT NULL, INDEX(equipment_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS event_equipment (event_id INT NOT NULL, equipment_id INT NOT NULL, PRIMARY KEY (event_id, equipment_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v6 ----
    "ALTER TABLE users MODIFY role ENUM('admin','pm','team','finance','intern','customer') NOT NULL DEFAULT 'team'",
    "ALTER TABLE users ADD COLUMN scratchpad MEDIUMTEXT",
    "ALTER TABLE events ADD COLUMN online_link VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE events ADD COLUMN is_reminded TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS event_participants (event_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (event_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS personal_notes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(150) DEFAULT NULL, text TEXT, color VARCHAR(20) NOT NULL DEFAULT 'default', created DATETIME NOT NULL, `update` DATETIME DEFAULT NULL, INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS personal_todos (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(255) NOT NULL, is_done TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS personal_links (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(150) NOT NULL, url VARCHAR(500) NOT NULL, INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v7 ----
    "ALTER TABLE users ADD COLUMN seen_version VARCHAR(10) DEFAULT NULL",
    "CREATE TABLE IF NOT EXISTS customer_clients (user_id INT NOT NULL, client_id INT NOT NULL, PRIMARY KEY (user_id, client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "INSERT IGNORE INTO customer_clients (user_id, client_id) SELECT id, client_id FROM users WHERE role='customer' AND client_id IS NOT NULL",
    "CREATE TABLE IF NOT EXISTS ratings (id INT AUTO_INCREMENT PRIMARY KEY, ref_type ENUM('task','approval') NOT NULL, ref_id INT NOT NULL, project_id INT NOT NULL, user_id INT NOT NULL, rating TINYINT NOT NULL, comment VARCHAR(500) DEFAULT NULL, created DATETIME NOT NULL, UNIQUE KEY uniq_rating (ref_type, ref_id, user_id), INDEX(project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS appointments (id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, client_id INT DEFAULT NULL, topic VARCHAR(200) NOT NULL, date DATETIME NOT NULL, online_request TINYINT(1) NOT NULL DEFAULT 0, notes VARCHAR(500) DEFAULT NULL, status ENUM('pending','approved','alternative','rejected') NOT NULL DEFAULT 'pending', alternative_date DATETIME DEFAULT NULL, online_link VARCHAR(255) DEFAULT NULL, event_id INT DEFAULT NULL, reply_note VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(customer_id), INDEX(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v8 ----
    "ALTER TABLE contents ADD COLUMN client_id INT DEFAULT NULL AFTER id",
    "ALTER TABLE contents MODIFY project_id INT DEFAULT NULL",
    "ALTER TABLE contents MODIFY platform VARCHAR(120) NOT NULL DEFAULT 'instagram'",
    "UPDATE contents i JOIN projects p ON p.id=i.project_id SET i.client_id=p.client_id WHERE i.client_id IS NULL",
    "CREATE TABLE IF NOT EXISTS social_accounts (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, platform VARCHAR(20) NOT NULL DEFAULT 'instagram', username VARCHAR(100) NOT NULL, url VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS social_metrics (id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, date DATE NOT NULL, followers INT NOT NULL DEFAULT 0, post INT DEFAULT NULL, engagement INT DEFAULT NULL, entered_by INT DEFAULT NULL, created DATETIME NOT NULL, UNIQUE KEY uniq_metric (account_id, date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v9 ----
    "CREATE TABLE IF NOT EXISTS documents (id INT AUTO_INCREMENT PRIMARY KEY, type ENUM('quote','invoice') NOT NULL DEFAULT 'quote', doc_no VARCHAR(20) NOT NULL, client_id INT DEFAULT NULL, title VARCHAR(200) NOT NULL, items TEXT, vat_rate TINYINT NOT NULL DEFAULT 20, status ENUM('draft','sent','approved','rejected') NOT NULL DEFAULT 'draft', valid_until DATE DEFAULT NULL, notes VARCHAR(500) DEFAULT NULL, created_by INT NOT NULL, created DATETIME NOT NULL, INDEX(client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v11 ----
    "ALTER TABLE archive ADD COLUMN url VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE approvals ADD COLUMN drive_link VARCHAR(500) DEFAULT NULL",
    // (the older form_fields.type ENUM was removed from here: every run narrowed the column again and
    //  DELETED the section / multi_select / multi_file types — the v6.10 VARCHAR line is enough)
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
    "CREATE TABLE IF NOT EXISTS project_extra_requests (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, title VARCHAR(200) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, out_of_scope TINYINT(1) NOT NULL DEFAULT 0, status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', description VARCHAR(500) DEFAULT NULL, created_by INT NOT NULL, created DATETIME NOT NULL, INDEX(project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS project_checklist (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, item VARCHAR(200) NOT NULL, check_note VARCHAR(500) DEFAULT NULL, owner_id INT DEFAULT NULL, is_done TINYINT(1) NOT NULL DEFAULT 0, is_delivered TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, INDEX(project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS project_review (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, type ENUM('internal','external','case_study') NOT NULL, content TEXT, updated_by INT DEFAULT NULL, updated DATETIME DEFAULT NULL, UNIQUE KEY pd_unique (project_id, type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS mentorship (id INT AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, field VARCHAR(200) NOT NULL, mentor_id INT DEFAULT NULL, project_id INT DEFAULT NULL, practice_area VARCHAR(255) DEFAULT NULL, output TEXT, status ENUM('planned','in_progress','completed') NOT NULL DEFAULT 'planned', created DATETIME NOT NULL, updated DATETIME DEFAULT NULL, INDEX(member_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS talent_pool (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, skill VARCHAR(255) DEFAULT NULL, worked_before TINYINT(1) NOT NULL DEFAULT 0, contact VARCHAR(255) DEFAULT NULL, cv_archive_id INT DEFAULT NULL, note VARCHAR(500) DEFAULT NULL, added_by INT NOT NULL, created DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS ideas (id INT AUTO_INCREMENT PRIMARY KEY, idea VARCHAR(300) NOT NULL, organization VARCHAR(200) DEFAULT NULL, description TEXT, proposer_id INT NOT NULL, status ENUM('new','liked','implemented') NOT NULL DEFAULT 'new', created DATETIME NOT NULL, INDEX(proposer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS monthly_reports (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, period CHAR(7) NOT NULL, summary TEXT, work_done TEXT, metrics TEXT, plan TEXT, author_id INT NOT NULL, status ENUM('draft','completed') NOT NULL DEFAULT 'draft', created DATETIME NOT NULL, updated DATETIME DEFAULT NULL, UNIQUE KEY ar_unique (client_id, period)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS task_manager_notes (task_id INT NOT NULL, user_id INT NOT NULL, note TEXT, updated DATETIME DEFAULT NULL, PRIMARY KEY (task_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v5.1: monthly-report automation + shoot costs ----
    "ALTER TABLE clients ADD COLUMN manager_id INT DEFAULT NULL",
    "ALTER TABLE events ADD COLUMN cost DECIMAL(12,2) NOT NULL DEFAULT 0",
    "ALTER TABLE expenses ADD COLUMN event_id INT DEFAULT NULL",
    // ---- v6.0: Drive tracking + AI groundwork ----
    "ALTER TABLE clients ADD COLUMN drive_folder_id VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE events ADD COLUMN drive_folder_id VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE events ADD COLUMN drive_link VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE events ADD COLUMN drive_status ENUM('pending','transferred') NOT NULL DEFAULT 'pending'",
        "ALTER TABLE events ADD COLUMN drive_files_seen TINYINT(1) NOT NULL DEFAULT 0",
        // v6.6: the monthly report can be mailed to the client from the panel
        "ALTER TABLE monthly_reports ADD COLUMN sent_at DATETIME DEFAULT NULL",
        "ALTER TABLE monthly_reports ADD COLUMN sent_to VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE monthly_reports ADD COLUMN mail_data TEXT",
        // v6.9: several contact people + knowledge-base categories
        "CREATE TABLE IF NOT EXISTS client_contacts (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, name VARCHAR(120) NOT NULL, title VARCHAR(120) DEFAULT NULL, email VARCHAR(190) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, created DATETIME NOT NULL, INDEX(client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
        "ALTER TABLE client_notes ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'general'",
        "ALTER TABLE client_notes ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE form_fields MODIFY type VARCHAR(20) NOT NULL DEFAULT 'text'",
        // 7.1: a task (İş) is one deliverable — it carries its publish plan; published / cancelled close it
        "ALTER TABLE tasks ADD COLUMN kind ENUM('client','internal') NOT NULL DEFAULT 'client' AFTER project_id",
        "ALTER TABLE tasks ADD COLUMN publish_date DATE DEFAULT NULL",
        "ALTER TABLE tasks ADD COLUMN publish_time TIME DEFAULT NULL",
        "ALTER TABLE tasks ADD COLUMN platforms VARCHAR(120) DEFAULT NULL",
        "ALTER TABLE tasks MODIFY status ENUM('todo','in_progress','in_review','awaiting_approval','completed','published','cancelled') NOT NULL DEFAULT 'todo'",
        "ALTER TABLE tasks ADD INDEX publish_date (publish_date)",
        // 7.2: step engine — task types with step recipes, skills, step kinds and a skill pool
        "CREATE TABLE IF NOT EXISTS skills (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
        "CREATE TABLE IF NOT EXISTS user_skills (user_id INT NOT NULL, skill_id INT NOT NULL, PRIMARY KEY (user_id, skill_id), INDEX(skill_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
        "ALTER TABLE task_types ADD COLUMN kind ENUM('client','internal') NOT NULL DEFAULT 'client' AFTER description",
        "ALTER TABLE task_type_steps ADD COLUMN skill_id INT DEFAULT NULL",
        "ALTER TABLE task_type_steps ADD COLUMN kind ENUM('work','review','client_approval','publish') NOT NULL DEFAULT 'work'",
        "ALTER TABLE task_type_steps ADD COLUMN owner_id INT DEFAULT NULL",
        "ALTER TABLE task_steps ADD COLUMN skill_id INT DEFAULT NULL",
        "ALTER TABLE task_steps ADD COLUMN kind ENUM('work','review','client_approval','publish') NOT NULL DEFAULT 'work'",
        "ALTER TABLE task_steps ADD COLUMN done_by INT DEFAULT NULL",
        "ALTER TABLE task_steps ADD INDEX pool (status, owner_id, skill_id)",
        "ALTER TABLE tasks ADD COLUMN type_id INT DEFAULT NULL",
        // 7.3: a month (period of a monthly project) goes planning → production → closing → closed;
        // work is planned or agenda; clients carry strategy, brand kit and approval rules
        "ALTER TABLE periods ADD COLUMN phase ENUM('planning','production','closing','closed') NOT NULL DEFAULT 'production'",
        "ALTER TABLE periods ADD COLUMN plan_status ENUM('none','pending','approved','revision') NOT NULL DEFAULT 'none'",
        "ALTER TABLE periods ADD COLUMN closed_at DATETIME DEFAULT NULL",
        "UPDATE periods SET phase='closed' WHERE status='closed'",
        "ALTER TABLE tasks ADD COLUMN lane ENUM('planned','agenda') NOT NULL DEFAULT 'planned'",
        "ALTER TABLE clients ADD COLUMN strategy TEXT",
        "ALTER TABLE clients ADD COLUMN brand_kit TEXT",
        "ALTER TABLE clients ADD COLUMN plan_approval TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE clients ADD COLUMN no_approval_types VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE approvals ADD COLUMN period_id INT DEFAULT NULL",
    ];
}

/** Runs all migration commands; returns [status, sql] pairs. status: ok|skip|error */
function run_migrations(PDO $pdo): array {
    $results = [];
    // 7.0: stored Turkish values and names become English. This runs FIRST and must finish:
    // the command list below now defines English ENUMs, which would truncate unconverted rows.
    require_once __DIR__ . '/migration-english.php';
    $englishDone = false;
    try { $englishDone = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='english_values'")->fetchColumn() === '1'; } catch (PDOException $e) {}
    if (!$englishDone) {
        try {
            if (english_values_needed($pdo)) {
                $results[] = ['ok', 'english: backup ' . english_values_backup($pdo)];
                $log = english_values_migration($pdo);
                foreach ($log as $l) $results[] = [str_starts_with($l, 'WARN') ? 'error' : 'ok', 'english: ' . $l];
                if (array_filter($log, fn ($l) => str_starts_with($l, 'WARN'))) return $results;
            }
            $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('english_values', '1') ON DUPLICATE KEY UPDATE setting_value='1'");
        } catch (Throwable $e) {
            $results[] = ['error', 'english: ' . $e->getMessage()];
            return $results;
        }
    }
    // Table renames must run BEFORE the CREATE IF NOT EXISTS list: otherwise an empty
    // new-named table gets created first, the rename then collides, and the old data
    // is stranded in the old table. If both exist, keep the one that holds the data.
    foreach ([['project_ek_requests', 'project_extra_requests'], ['workflow_templates', 'task_types'], ['template_steps', 'task_type_steps']] as [$oldT, $newT]) {
        try {
            $hasOld = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($oldT))->fetchColumn();
            $hasNew = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($newT))->fetchColumn();
            if ($hasOld && $hasNew) {
                $newCount = (int)$pdo->query("SELECT COUNT(*) FROM `$newT`")->fetchColumn();
                if ($newCount === 0) { $pdo->exec("DROP TABLE `$newT`"); $hasNew = false; }
            }
            if ($hasOld && !$hasNew) { $pdo->exec("RENAME TABLE `$oldT` TO `$newT`"); $results[] = ['ok', "rename: $oldT → $newT"]; }
        } catch (PDOException $e) { $results[] = ['error', "rename $oldT — " . $e->getMessage()]; }
    }
    // 7.2: the renamed step table points at its task type
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='task_type_steps' AND column_name='template_id'")->fetchColumn()) {
            $pdo->exec('ALTER TABLE task_type_steps CHANGE template_id type_id INT NOT NULL');
            $results[] = ['ok', 'rename: task_type_steps.template_id → type_id'];
        }
    } catch (PDOException $e) { $results[] = ['error', 'rename task_type_steps.template_id — ' . $e->getMessage()]; }
    // One-time: seed client_contacts from the clients table's single contact fields
    try {
        if ($pdo->query("SHOW TABLES LIKE 'client_contacts'")->fetchColumn()
            && !(int)$pdo->query('SELECT COUNT(*) FROM client_contacts')->fetchColumn()) {
            $n = $pdo->exec("INSERT INTO client_contacts (client_id, name, email, phone, created)
                SELECT id, COALESCE(NULLIF(contact_name,''), 'İletişim'), NULLIF(contact_email,''), NULLIF(contact_phone,''), NOW()
                FROM clients WHERE COALESCE(contact_name,'') != '' OR COALESCE(contact_email,'') != ''");
            if ($n) $results[] = ['ok', 'seed: client_contacts'];
        }
    } catch (PDOException $e) { /* if the table is created in this run, it gets filled on the next one */ }
    // Commands that already succeeded (or were confirmed as "already there") are
    // remembered by hash and never re-executed: a MODIFY/UPDATE without a guard
    // used to rebuild its table on EVERY run, holding metadata locks the whole
    // site waits on. Only genuinely failed commands are retried next time.
    $done = [];
    try {
        $raw = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='migration_done'")->fetchColumn();
        $done = array_fill_keys(json_decode((string)$raw, true) ?: [], true);
    } catch (PDOException $e) { /* fresh install without settings yet */ }
    foreach (migration_commands() as $sql) {
        $h = substr(md5($sql), 0, 16);
        if (isset($done[$h])) { $results[] = ['skip', $sql]; continue; }
        try {
            $pdo->exec($sql);
            $results[] = ['ok', $sql];
            $done[$h] = true;
        } catch (PDOException $e) {
            $already = (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'exists') !== false || strpos($e->getMessage(), "doesn't exist") !== false);
            if ($already) $done[$h] = true;
            $results[] = [$already ? 'skip' : 'error', $sql . ($already ? '' : ' — ' . $e->getMessage())];
        }
    }
    try {
        $st = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('migration_done', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $st->execute([json_encode(array_keys($done))]);
    } catch (PDOException $e) { /* not fatal: the next run simply re-checks */ }
    // 7.1: contents and loose approvals become part of their task. It needs the columns added by
    // the list above, so it runs last; like the English step it is backed up, runs once and a
    // failure keeps the schema version unmarked so it is retried.
    require_once __DIR__ . '/migration-work.php';
    try {
        $workDone = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='unified_work'")->fetchColumn() === '1';
        if (!$workDone) {
            if (!work_items_schema_ready($pdo)) throw new RuntimeException('tasks table is missing the 7.1 columns');
            if (work_items_needed($pdo)) {
                require_once __DIR__ . '/migration-backup.php';
                $results[] = ['ok', 'work: backup ' . migration_db_backup($pdo, 'v7.1-work', '7.1 one-record-per-deliverable migration')];
                foreach (work_items_migration($pdo) as $l) $results[] = ['ok', 'work: ' . $l];
            }
            $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('unified_work', '1') ON DUPLICATE KEY UPDATE setting_value='1'");
        }
    } catch (Throwable $e) {
        $results[] = ['error', 'work: ' . $e->getMessage()];
    }
    // 7.2: step kinds, skills and statuses recomputed from steps (after the work step: it needs 7.1 statuses)
    require_once __DIR__ . '/migration-steps.php';
    try {
        $stepsDone = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='step_engine'")->fetchColumn() === '1';
        if (!$stepsDone && $pdo->query("SELECT setting_value FROM settings WHERE setting_key='unified_work'")->fetchColumn() === '1') {
            if (!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='task_steps' AND column_name='kind'")->fetchColumn())
                throw new RuntimeException('task_steps is missing the 7.2 columns');
            if ((int)$pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn() > 0) { // fresh installs have nothing to back up
                require_once __DIR__ . '/migration-backup.php';
                $results[] = ['ok', 'steps: backup ' . migration_db_backup($pdo, 'v7.2-steps', '7.2 step engine migration')];
            }
            foreach (step_engine_migration($pdo) as $l) $results[] = ['ok', 'steps: ' . $l];
            $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('step_engine', '1') ON DUPLICATE KEY UPDATE setting_value='1'");
        }
    } catch (Throwable $e) {
        $results[] = ['error', 'steps: ' . $e->getMessage()];
    }
    return $results;
}

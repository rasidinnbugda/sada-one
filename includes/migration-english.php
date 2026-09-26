<?php
/**
 * SADA One 7.0 — one-time data migration to English stored values.
 *
 * Until 6.x the database stored Turkish codes ('bekliyor', 'yonetici', 'studyoda' …) and the
 * v5.0 localization pass had renamed only part of the code, so a few screens compared against
 * values that never existed in the data. This step converts every stored code, key and a handful
 * of mistranslated column names to the English names the code now uses.
 *
 * Safety:
 *  - ENUM columns are converted without data loss: widen to old ∪ new values, UPDATE, then narrow.
 *    If a column still holds an unknown value it is left widened and reported, never truncated.
 *  - Every step is idempotent; a finished run is recorded in settings ('english_values' = 1).
 *  - run_migrations() stops before the regular command list if this step reports an error, because
 *    the (now English) ENUM definitions in that list must not meet unconverted Turkish rows.
 */

/** [table, column, [old => new], default for the narrowed ENUM (null = none)] */
function english_enum_map(): array {
    $pending = ['bekliyor' => 'pending'];
    return [
        ['users', 'role', ['yonetici' => 'admin', 'pm' => 'pm', 'ekip' => 'team', 'finans' => 'finance', 'stajyer' => 'intern', 'musteri' => 'customer'], 'team'],
        ['clients', 'type', ['marka' => 'brand', 'sirket' => 'company', 'stk' => 'ngo'], 'brand'],
        ['clients', 'status', ['aktif' => 'active', 'pasif' => 'inactive'], 'active'],
        ['projects', 'type', ['aylik' => 'monthly', 'donemsel' => 'periodic', 'tek' => 'one_off'], 'monthly'],
        ['projects', 'status', ['aktif' => 'active', 'beklemede' => 'on_hold', 'tamamlandi' => 'completed', 'iptal' => 'cancelled'], 'active'],
        ['periods', 'status', ['acik' => 'open', 'kapali' => 'closed'], 'open'],
        ['tasks', 'priority', ['dusuk' => 'low', 'normal' => 'normal', 'yuksek' => 'high', 'acil' => 'urgent'], 'normal'],
        ['tasks', 'status', ['yapilacak' => 'todo', 'devam' => 'in_progress', 'incelemede' => 'in_review', 'onayda' => 'awaiting_approval', 'tamamlandi' => 'completed'], 'todo'],
        ['tasks', 'repeat', ['yok' => 'none', 'haftalik' => 'weekly', 'aylik' => 'monthly'], 'none'],
        ['task_steps', 'status', $pending + ['aktif' => 'active', 'tamam' => 'done'], 'pending'],
        ['expenses', 'type', ['maas' => 'salary', 'kira' => 'rent', 'abonelik' => 'subscription', 'ekipman' => 'equipment', 'vergi' => 'tax', 'diger' => 'other'], 'other'],
        ['expenses', 'status', $pending + ['odendi' => 'paid'], 'pending'],
        ['expenses', 'repeat', ['yok' => 'none', 'aylik' => 'monthly'], 'none'],
        ['equipment', 'category', ['kamera' => 'camera', 'lens' => 'lens', 'sd_kart' => 'sd_card', 'tripod' => 'tripod', 'isik' => 'light', 'ses' => 'audio', 'drone' => 'drone', 'aksesuar' => 'accessory', 'diger' => 'other'], 'other'],
        ['equipment', 'status', ['studyoda' => 'in_studio', 'zimmette' => 'checked_out', 'cekimde' => 'on_shoot', 'arizali' => 'faulty', 'bakimda' => 'in_maintenance'], 'in_studio'],
        ['equipment', 'sd_status', ['bos' => 'empty', 'dolu' => 'full', 'aktarildi' => 'transferred'], null],
        ['contents', 'status', ['taslak' => 'draft', 'ic_onay' => 'internal_approval', 'musteri_onay' => 'customer_approval', 'revize' => 'revision', 'onaylandi' => 'approved', 'yayinlandi' => 'published'], 'draft'],
        ['documents', 'type', ['teklif' => 'quote', 'fatura' => 'invoice'], 'quote'],
        ['documents', 'status', ['taslak' => 'draft', 'gonderildi' => 'sent', 'onaylandi' => 'approved', 'reddedildi' => 'rejected'], 'draft'],
        ['events', 'type', ['cekim' => 'shoot', 'toplanti' => 'meeting', 'teslim' => 'delivery', 'diger' => 'other'], 'shoot'],
        ['events', 'drive_status', $pending + ['aktarildi' => 'transferred'], 'pending'],
        ['ratings', 'ref_type', ['gorev' => 'task', 'onay' => 'approval'], null],
        ['appointments', 'status', $pending + ['onaylandi' => 'approved', 'alternatif' => 'alternative', 'reddedildi' => 'rejected'], 'pending'],
        ['approvals', 'status', $pending + ['onaylandi' => 'approved', 'revize' => 'revision', 'reddedildi' => 'rejected'], 'pending'],
        ['channels', 'type', ['genel' => 'general', 'proje' => 'project', 'ozel' => 'private', 'musteri' => 'customer'], 'general'],
        ['requests', 'status', ['yeni' => 'new', 'inceleniyor' => 'reviewing', 'gorev_olusturuldu' => 'task_created', 'tamamlandi' => 'completed', 'reddedildi' => 'rejected'], 'new'],
        ['payments', 'type', ['fatura' => 'invoice', 'tahsilat' => 'collection'], 'invoice'],
        ['payments', 'status', $pending + ['odendi' => 'paid', 'gecikti' => 'overdue'], 'pending'],
        ['project_extra_requests', 'status', $pending + ['onaylandi' => 'approved', 'reddedildi' => 'rejected'], 'pending'],
        ['project_review', 'type', ['ic' => 'internal', 'dis' => 'external', 'case_study' => 'case_study'], null],
        ['mentorship', 'status', ['planlandi' => 'planned', 'devam' => 'in_progress', 'tamamlandi' => 'completed'], 'planned'],
        ['ideas', 'status', ['yeni' => 'new', 'begenildi' => 'liked', 'uygulandi' => 'implemented'], 'new'],
        ['monthly_reports', 'status', ['taslak' => 'draft', 'tamamlandi' => 'completed'], 'draft'],
    ];
}

/** [table, column, [old => new]] for VARCHAR columns that hold codes */
function english_code_map(): array {
    return [
        ['users', 'theme', ['gece' => 'night', 'koyu' => 'classic-dark', 'acik' => 'classic-light']],
        ['users', 'task_view', ['tablo' => 'table', 'liste' => 'list']],
        ['client_notes', 'category', ['genel' => 'general', 'marka' => 'brand', 'erisim' => 'access', 'kitle' => 'audience', 'surec' => 'process']],
        ['personal_notes', 'color', ['varsayilan' => 'default', 'sari' => 'yellow', 'yesil' => 'green', 'mavi' => 'blue', 'pembe' => 'pink']],
        // 'count' and 'client' were saved for a while by the 6.10 form builder and meant number / file
        ['form_fields', 'type', ['metin' => 'text', 'uzun_metin' => 'long_text', 'secim' => 'select', 'tarih' => 'date', 'sayi' => 'number', 'count' => 'number',
            'dosya' => 'file', 'client' => 'file', 'bolum' => 'section', 'coklu_secim' => 'multi_select', 'coklu_dosya' => 'multi_file']],
        // pre-5.0 names and the half-translated 5.x names, all to one set
        ['equipment_logs', 'type', ['eklendi' => 'added', 'zimmet' => 'custody', 'iade' => 'return', 'cekime_cikti' => 'shoot_out', 'shoot_output' => 'shoot_out',
            'cekimden_dondu' => 'shoot_return', 'sd_dolu' => 'sd_full', 'sd_aktarildi' => 'sd_transferred', 'sd_bosaltildi' => 'sd_emptied', 'ariza' => 'fault',
            'bakim' => 'maintenance', 'duzeltildi' => 'fixed']],
        ['activities', 'ref_type', ['gorev' => 'task', 'proje' => 'project', 'onay' => 'approval', 'talep' => 'request', 'icerik' => 'content', 'ekipman' => 'equipment', 'dosya' => 'client', 'file' => 'client']],
        ['comments', 'ref_type', ['gorev' => 'task', 'proje' => 'project', 'onay' => 'approval']],
        ['social_accounts', 'platform', ['diger' => 'other']],
    ];
}

/** Renamed setting keys (old => new). Values of default_theme are themes too. */
function english_setting_keys(): array {
    return ['site_adi' => 'site_name', 'smtp_aktif' => 'smtp_enabled', 'smtp_gonderen' => 'smtp_sender', 'smtp_kullanici' => 'smtp_user', 'smtp_sifre' => 'smtp_password',
        'varsayilan_tema' => 'default_theme', 'eposta_bildirim' => 'email_notifications', 'mail_takma_adlar' => 'mail_aliases', 'butce_hedef' => 'budget_target',
        'son_gunluk_ozet' => 'last_user_daily_digest', 'son_haftalik_ozet' => 'last_user_weekly_digest', 'son_tekrar_kontrol' => 'last_repeat_check'];
}

function english_permission_keys(): array {
    return ['finans' => 'finance', 'rapor' => 'report', 'kapasite' => 'capacity', 'dosya_yonet' => 'client_manage', 'gorev_olustur' => 'task_create', 'gorev_sil' => 'task_delete',
        'icerik_yonet' => 'content_manage', 'ekipman_yonet' => 'equipment_manage', 'onay_gonder' => 'approval_send', 'duyuru_yayinla' => 'announcement_publish',
        'takvim_yonet' => 'calendar_manage', 'kanal_kur' => 'channel_create', 'belge_olustur' => 'document_create', 'arsiv_sil' => 'archive_delete', 'talep_yonet' => 'request_manage',
        'butce_gor' => 'budget_view', 'finans_yonet' => 'finance_manage', 'randevu_yonet' => 'appointment_manage', 'havuz_yonet' => 'pool_manage',
        'mentorluk_yonet' => 'mentorship_manage', 'ai_kullan' => 'ai_use'];
}

/** True while the database still holds 6.x (Turkish) names or values. Cheap: a few metadata lookups. */
function english_values_needed(PDO $pdo): bool {
    $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=? AND
        ((table_name='users' AND column_name='role' AND column_type LIKE '%yonetici%') OR (table_name='tasks' AND column_name='bagimli_id')
         OR (table_name='request_replies' AND column_name='setting_value') OR (table_name='form_fields' AND column_name='tag'))");
    $s->execute([$db]);
    if ((int) $s->fetchColumn() > 0) return true;
    return (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key IN ('site_adi', 'varsayilan_tema', 'smtp_aktif')")->fetchColumn() > 0;
}

/**
 * Full SQL dump of the database into backups/ before the conversion (the in-panel updater only
 * backs up the code). Returns the file name, or throws — no conversion without a backup.
 */
function english_values_backup(PDO $pdo): string {
    require_once __DIR__ . '/migration-backup.php';
    return migration_db_backup($pdo, 'v7-english', '7.0 English values migration');
}

function english_values_migration(PDO $pdo): array {
    $log = [];
    $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $col = function (string $t, string $c) use ($pdo, $db): ?array {
        $s = $pdo->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?');
        $s->execute([$db, $t, $c]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $hasTable = function (string $t) use ($pdo, $db): bool {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');
        $s->execute([$db, $t]);
        return (bool) $s->fetchColumn();
    };
    $enumSql = fn (array $values) => 'ENUM(' . implode(',', array_map(fn ($v) => $pdo->quote($v), $values)) . ')';
    $definition = function (array $info, string $type, ?string $default) use ($pdo): string {
        $null = $info['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
        if ($default !== null) return "$type $null DEFAULT " . $pdo->quote($default);
        return $type . ' ' . $null . ($info['IS_NULLABLE'] === 'YES' ? ' DEFAULT NULL' : '');
    };

    // 1. Tables and columns whose names were Turkish or mistranslated
    if ($hasTable('comment_box_reactions') && !$hasTable('comment_reactions')) {
        $pdo->exec('RENAME TABLE comment_box_reactions TO comment_reactions');
        $log[] = 'table comment_box_reactions → comment_reactions';
    }
    foreach ([['tasks', 'bagimli_id', 'depends_on_id'], ['comment_reactions', 'comment_box_id', 'comment_id'], ['ratings', 'comment_box', 'comment'],
        ['request_replies', 'setting_value', 'value'], ['form_fields', 'tag', 'label'], ['mentorship', 'practice_arena', 'practice_area']] as [$t, $old, $new]) {
        $info = $col($t, $old);
        if (!$info || $col($t, $new)) continue;
        $default = $info['COLUMN_DEFAULT'];
        $def = $info['COLUMN_TYPE'] . ($info['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL')
            . ($default !== null && !in_array(strtoupper((string) $default), ['NULL'], true) ? ' DEFAULT ' . (is_numeric($default) ? $default : $pdo->quote(trim((string) $default, "'"))) : ($info['IS_NULLABLE'] === 'YES' ? ' DEFAULT NULL' : ''));
        $pdo->exec("ALTER TABLE `$t` CHANGE `$old` `$new` $def");
        $log[] = "column $t.$old → $new";
    }

    // 2. ENUM columns: widen, convert, narrow
    foreach (english_enum_map() as [$t, $c, $map, $default]) {
        $info = $col($t, $c);
        if (!$info || $info['DATA_TYPE'] !== 'enum') continue;
        preg_match_all("~'((?:[^']|'')*)'~", $info['COLUMN_TYPE'], $m);
        $current = array_map(fn ($v) => str_replace("''", "'", $v), $m[1]);
        $target = array_values(array_unique(array_values($map)));
        $needsWork = array_diff($current, $target) || array_diff($target, $current);
        if (!$needsWork) continue;
        $union = array_values(array_unique(array_merge($current, $target)));
        $pdo->exec("ALTER TABLE `$t` MODIFY `$c` " . $definition($info, $enumSql($union), $default));
        $changed = 0;
        foreach ($map as $old => $new) {
            if ($old === $new) continue;
            $st = $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` = ?");
            $st->execute([$new, $old]);
            $changed += $st->rowCount();
        }
        $in = implode(',', array_map(fn ($v) => $pdo->quote($v), $target));
        $unknown = (int) $pdo->query("SELECT COUNT(*) FROM `$t` WHERE `$c` IS NOT NULL AND `$c` NOT IN ($in)")->fetchColumn();
        if ($unknown) {
            $log[] = "WARN $t.$c: $unknown row(s) hold a value outside the new list; column left widened";
            continue;
        }
        $pdo->exec("ALTER TABLE `$t` MODIFY `$c` " . $definition($info, $enumSql($target), $default));
        $log[] = "enum $t.$c: $changed row(s) converted";
    }

    // 3. VARCHAR code columns
    foreach (english_code_map() as [$t, $c, $map]) {
        if (!$col($t, $c)) continue;
        $changed = 0;
        foreach ($map as $old => $new) {
            $st = $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` = ?");
            $st->execute([$new, $old]);
            $changed += $st->rowCount();
        }
        if ($changed) $log[] = "codes $t.$c: $changed row(s)";
    }
    // column defaults that were Turkish
    foreach ([['client_notes', 'category', "VARCHAR(30) NOT NULL DEFAULT 'general'"], ['personal_notes', 'color', "VARCHAR(20) NOT NULL DEFAULT 'default'"],
        ['form_fields', 'type', "VARCHAR(20) NOT NULL DEFAULT 'text'"]] as [$t, $c, $def]) {
        $info = $col($t, $c);
        if ($info && in_array(trim((string) $info['COLUMN_DEFAULT'], "'"), ['genel', 'varsayilan', 'metin'], true)) $pdo->exec("ALTER TABLE `$t` MODIFY `$c` $def");
    }
    // content platforms are a comma list
    $n = $pdo->exec("UPDATE contents SET platform = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', platform, ','), ',diger,', ',other,')) WHERE CONCAT(',', platform, ',') LIKE '%,diger,%'");
    if ($n) $log[] = "codes contents.platform: $n row(s)";

    // 4. JSON columns
    $remapJson = function (string $table, string $column, callable $fn) use ($pdo, $col, &$log) {
        if (!$col($table, $column)) return;
        $rows = $pdo->query("SELECT id, `$column` v FROM `$table` WHERE `$column` IS NOT NULL AND `$column` != ''")->fetchAll(PDO::FETCH_ASSOC);
        $up = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ?");
        $changed = 0;
        foreach ($rows as $r) {
            $data = json_decode($r['v'], true);
            if (!is_array($data)) continue;
            $new = $fn($data);
            $enc = json_encode($new, JSON_UNESCAPED_UNICODE);
            if ($enc !== json_encode($data, JSON_UNESCAPED_UNICODE)) { $up->execute([$enc, $r['id']]); $changed++; }
        }
        if ($changed) $log[] = "json $table.$column: $changed row(s)";
    };
    $renameKeys = function (array $a, array $map): array {
        $o = [];
        foreach ($a as $k => $v) $o[is_string($k) ? ($map[$k] ?? $k) : $k] = $v;
        return $o;
    };
    $perm = english_permission_keys();
    $remapJson('users', 'permissions', fn ($a) => $renameKeys($a, $perm));
    $remapJson('users', 'notification_preferences', fn ($a) => $renameKeys($a, ['gorev' => 'task', 'onay' => 'approval', 'talep' => 'request', 'mesaj' => 'message']));
    $widgets = ['duyurular' => 'announcements', 'yaklasanlar' => 'upcoming', 'istatistik' => 'stats', 'ekip' => 'team', 'gorevlerim' => 'my_tasks', 'hareketler' => 'activity', 'uyarilar' => 'warnings'];
    $remapJson('users', 'widgets', fn ($a) => array_values(array_map(fn ($w) => is_string($w) ? ($widgets[$w] ?? $w) : $w, $a)));
    $remapJson('documents', 'items', fn ($a) => array_map(fn ($it) => is_array($it) ? $renameKeys($it, ['adet' => 'qty']) : $it, $a));
    $priorities = ['dusuk' => 'low', 'yuksek' => 'high', 'acil' => 'urgent'];
    $remapJson('project_templates', 'tasks', fn ($a) => array_map(function ($t) use ($priorities) {
        if (is_array($t) && isset($t['priority']) && is_string($t['priority'])) $t['priority'] = $priorities[$t['priority']] ?? $t['priority'];
        return $t;
    }, $a));
    $remapJson('monthly_reports', 'mail_data', function ($a) use ($renameKeys) {
        $a = $renameKeys($a, ['metin' => 'text']);
        if (isset($a['text']) && is_array($a['text'])) {
            $a['text'] = $renameKeys($a['text'], ['baslik' => 'title', 'selam' => 'greeting', 'uretim_baslik' => 'production_title', 'stat_baslik' => 'stat_title',
                'stat_giris' => 'stat_intro', 'plan_baslik' => 'plan_title', 'kapanis' => 'closing', 'tesekkur' => 'thanks']);
        }
        if (isset($a['stats']) && is_array($a['stats'])) {
            $a['stats'] = array_map(fn ($s) => is_array($s) ? $renameKeys($s, ['tag' => 'label', 'deger' => 'value', 'degisim' => 'change']) : $s, $a['stats']);
        }
        return $a;
    });

    // 5. Settings keys (the old value wins only where the new key is not set yet)
    foreach (english_setting_keys() as $old => $new) {
        $v = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $v->execute([$old]);
        $value = $v->fetchColumn();
        if ($value === false) continue;
        $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$new, $value]);
        $pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$old]);
        $log[] = "setting $old → $new";
    }
    $theme = ['gece' => 'night', 'koyu' => 'classic-dark', 'acik' => 'classic-light'];
    foreach ($theme as $old => $new) $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='default_theme' AND setting_value=?")->execute([$new, $old]);
    $pdo->exec("DELETE FROM settings WHERE setting_key='legacy_localized'");

    // 6. Stored links: project and finance tab anchors, list filters
    $anchors = ['gorevler' => 'tasks', 'onaylar' => 'approvals', 'ozet' => 'summary', 'donemler' => 'periods', 'icerik' => 'content', 'istasyon' => 'station',
        'arsiv' => 'archive', 'tartisma' => 'discussion', 'aktivite' => 'activity', 'kayitlar' => 'records', 'giderler' => 'expenses', 'belgeler' => 'documents',
        'cari' => 'account', 'karzarar' => 'profit_loss', 'kapasite' => 'capacity'];
    $links = 0;
    foreach ($anchors as $old => $new) {
        $st = $pdo->prepare("UPDATE notifications SET link = REPLACE(link, ?, ?) WHERE link LIKE ?");
        $st->execute(["#$old", "#$new", "%#$old"]);
        $links += $st->rowCount();
    }
    if ($links) $log[] = "notification links: $links";
    return $log;
}

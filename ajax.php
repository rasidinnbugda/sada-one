<?php
/**
 * SADA One — Central AJAX handler
 * All client actions pass through here. CSRF and permission checked.
 */
define('IS_AJAX', true); // returns JSON 403 instead of redirecting on unauthorized access
require __DIR__ . '/includes/init.php';
if (!user()) json_out(['ok' => false, 'error' => 'Oturumunuz sona erdi. Sayfayı yenileyip tekrar giriş yapın.'], 401);
csrf_check();

$action = $_POST['action'] ?? '';
$u = user();
$now = date('Y-m-d H:i:s');
$g = fn($k, $v = '') => $_POST[$k] ?? $v;

switch ($action) {

/* ==================== THEME & NOTIFICATIONS ==================== */
case 'theme_change':
    $theme = isset(THEMES[$g('theme')]) ? $g('theme') : 'lime';
    update_row('users', ['theme' => $theme, 'color' => THEMES[$theme][1]], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

case 'notification_read':
    update_row('notifications', ['is_read' => 1], 'id=? AND user_id=?', [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'version_check':
    if ($u['role'] !== 'yonetici') deny();
    require_once __DIR__ . '/includes/updater-core.php';
    $rel = github_json('https://api.github.com/repos/' . GITHUB_REPO . '/releases/latest');
    if (!$rel || empty($rel['tag_name'])) json_out(['ok' => false, 'error' => 'GitHub\'a ulaşılamadı veya yayınlanmış sürüm yok.']);
    $last = ltrim($rel['tag_name'], 'vV');
    json_out([
        'ok' => true, 'mevcut' => APP_VERSION, 'last' => $rel['tag_name'],
        'new_var' => version_compare($last, APP_VERSION, '>'),
        'notes' => mb_substr(trim((string)($rel['body'] ?? '')), 0, 300),
    ]);

/* ==================== v14: SOP MODULES ==================== */
case 'mentorship_save':
    if (!is_admin() && $u['role'] !== 'pm') deny();
    $data = [
        'member_id' => (int)$g('member_id'), 'field' => trim($g('field')),
        'mentor_id' => (int)$g('mentor_id') ?: null, 'project_id' => (int)$g('project_id') ?: null,
        'practice_arena' => trim($g('practice_arena')) ?: null, 'output' => trim($g('output')) ?: null,
        'status' => in_array($g('status'), ['planlandi', 'devam', 'tamamlandi']) ? $g('status') : 'planlandi',
    ];
    if (!$data['member_id'] || $data['field'] === '') json_out(['ok' => false, 'error' => 'Ekip üyesi ve gelişim alanı zorunludur.']);
    if ($id = (int)$g('id')) { $data['updated'] = $now; update_row('mentorship', $data, 'id=?', [$id]); }
    else { $data['created'] = $now; insert('mentorship', $data); }
    json_out(['ok' => true, 'mesaj' => 'Mentörlük kaydı güncellendi.', 'refresh' => true]);

case 'mentorship_output':
    // A member can update the output note of their own record
    $entry = row("SELECT * FROM mentorship WHERE id=?", [(int)$g('id')]);
    if (!$entry || (!is_admin() && $u['role'] !== 'pm' && $entry['member_id'] != $u['id'])) deny();
    update_row('mentorship', ['output' => trim($g('output')), 'updated' => $now], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Çıktı notu kaydedildi.']);

case 'mentorship_delete':
    if (!is_admin() && $u['role'] !== 'pm') deny();
    q("DELETE FROM mentorship WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'refresh' => true]);

case 'pool_save':
    if (is_intern() || is_customer()) deny();
    $data = [
        'name' => trim($g('name')), 'skill' => trim($g('skill')) ?: null,
        'worked_before' => (int)(bool)$g('worked_before'), 'contact' => trim($g('contact')) ?: null,
        'note' => trim($g('note')) ?: null,
    ];
    if ($data['name'] === '') json_out(['ok' => false, 'error' => 'İsim zorunludur.']);
    if ($cv = file_upload('cv')) {
        $data['cv_archive_id'] = insert('archive', ['name' => $cv['name'], 'file_path' => $cv['path'], 'size' => $cv['size'], 'extension' => $cv['extension'], 'uploader_id' => $u['id'], 'created' => $now]);
    }
    if ($id = (int)$g('id')) update_row('talent_pool', $data, 'id=?', [$id]);
    else { $data['added_by'] = $u['id']; $data['created'] = $now; insert('talent_pool', $data); }
    json_out(['ok' => true, 'mesaj' => 'Havuz kaydı güncellendi.', 'refresh' => true]);

case 'pool_delete':
    if (!is_admin() && $u['role'] !== 'pm') deny();
    q("DELETE FROM talent_pool WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'refresh' => true]);

case 'idea_save':
    if (is_customer()) deny();
    $idea = trim($g('idea'));
    if ($idea === '') json_out(['ok' => false, 'error' => 'Fikir boş olamaz.']);
    insert('ideas', ['idea' => $idea, 'organization' => trim($g('organization')) ?: null, 'description' => trim($g('description')) ?: null, 'proposer_id' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Fikir panoya eklendi.', 'refresh' => true]);

case 'idea_status':
    if (!is_admin() && $u['role'] !== 'pm') deny();
    if (!in_array($g('status'), ['yeni', 'begenildi', 'uygulandi'])) json_out(['ok' => false, 'error' => 'Geçersiz durum.']);
    update_row('ideas', ['status' => $g('status')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true]);

case 'idea_delete':
    $f = row("SELECT proposer_id FROM ideas WHERE id=?", [(int)$g('id')]);
    if (!$f || (!is_admin() && $f['proposer_id'] != $u['id'])) deny();
    q("DELETE FROM ideas WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'refresh' => true]);

case 'monthly_report_save':
    if (is_intern() || is_customer()) deny();
    $clientId = (int)$g('client_id'); $period = $g('period');
    if (!$clientId || !preg_match('/^\d{4}-\d{2}$/', $period)) json_out(['ok' => false, 'error' => 'Dosya ve dönem (YYYY-AA) zorunludur.']);
    $data = ['summary' => trim($g('summary')), 'work_done' => trim($g('work_done')), 'metrics' => trim($g('metrics')), 'plan' => trim($g('plan')),
        'status' => $g('status') === 'tamamlandi' ? 'tamamlandi' : 'taslak', 'updated' => $now];
    $var = row("SELECT id FROM monthly_reports WHERE client_id=? AND period=?", [$clientId, $period]);
    if ($var) update_row('monthly_reports', $data, 'id=?', [$var['id']]);
    else insert('monthly_reports', $data + ['client_id' => $clientId, 'period' => $period, 'author_id' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Aylık rapor kaydedildi.', 'refresh' => true]);

case 'mnote_save':
    if (!is_admin() && $u['role'] !== 'pm') deny();
    $gid = (int)$g('task_id');
    if (!val("SELECT id FROM tasks WHERE id=?", [$gid])) json_out(['ok' => false, 'error' => 'Görev bulunamadı.']);
    q("INSERT INTO task_manager_notes (task_id, user_id, note, updated) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE note=VALUES(note), updated=VALUES(updated)", [$gid, $u['id'], trim($g('note')), $now]);
    json_out(['ok' => true, 'mesaj' => 'Not kaydedildi.']);

/* ---- Project station ---- */
case 'station_save':
    if (!permission('dosya_yonet')) deny();
    $pid = (int)$g('project_id');
    if (!project_access($pid)) deny();
    $data = ['handover' => trim($g('handover')) ?: null, 'team_roles' => $g('team_roles') ?: null];
    if (permission('butce_gor')) {
        $data['budget'] = (float)str_replace(',', '.', $g('budget', '0'));
        $data['revision_limit'] = max(0, (int)$g('revision_limit', 2));
    }
    update_row('projects', $data, 'id=?', [$pid]);
    json_out(['ok' => true, 'mesaj' => 'İstasyon bilgileri kaydedildi.', 'refresh' => true]);

case 'extra_request_save':
    if (!permission('butce_gor')) deny();
    $pid = (int)$g('project_id');
    if (!project_access($pid)) deny();
    $title = trim($g('title'));
    if ($title === '') json_out(['ok' => false, 'error' => 'Talep başlığı zorunludur.']);
    insert('project_ek_requests', ['project_id' => $pid, 'title' => $title, 'amount' => (float)str_replace(',', '.', $g('amount', '0')),
        'out_of_scope' => (int)(bool)$g('out_of_scope'), 'description' => trim($g('description')) ?: null, 'created_by' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Ek talep kaydedildi.', 'refresh' => true]);

case 'extra_request_status':
    if (!permission('butce_gor')) deny();
    if (!in_array($g('status'), ['bekliyor', 'onaylandi', 'reddedildi'])) json_out(['ok' => false, 'error' => 'Geçersiz durum.']);
    update_row('project_ek_requests', ['status' => $g('status')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true]);

case 'extra_request_delete':
    if (!permission('butce_gor')) deny();
    q("DELETE FROM project_ek_requests WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'refresh' => true]);

case 'pcheck_add':
    if (!is_staff()) deny();
    $pid = (int)$g('project_id');
    if (!project_access($pid)) deny();
    $item = trim($g('item'));
    if ($item === '') json_out(['ok' => false, 'error' => 'Kalem adı boş olamaz.']);
    $id = insert('project_checklist', ['project_id' => $pid, 'item' => $item, 'check_note' => trim($g('check_note')) ?: null,
        'owner_id' => (int)$g('owner_id') ?: null, 'sort_order' => (int)val("SELECT COALESCE(MAX(sort_order),0)+1 FROM project_checklist WHERE project_id=?", [$pid])]);
    json_out(['ok' => true, 'id' => $id, 'refresh' => true]);

case 'pcheck_standard':
    // Loads the standard SOP technical checklist with one click
    if (!is_staff()) deny();
    $pid = (int)$g('project_id');
    if (!project_access($pid)) deny();
    $standard = [
        ['Kamera ve Lensler', 'Yedek bataryalar, hafıza kartları formatlandı mı, temizlik kitleri hazır mı?'],
        ['Işık Sistemleri', 'Ana ışık, dolgu ışığı, softbox, uzatma kabloları ve tripodlar hazır mı?'],
        ['Ses Ekipmanları', 'Yaka mikrofonları, telsiz alıcılar, kayıt cihazları ve yedek piller kontrol edildi mi?'],
        ['Prompter Hazırlığı', 'Prompter yazılımı güncellendi mi, konuşma metinleri sisteme yüklendi mi?'],
        ['Lojistik ve İzinler', 'Çekim mekan izinleri alındı mı, ulaşım ve akreditasyonlar sağlandı mı?'],
    ];
    $sort_order = (int)val("SELECT COALESCE(MAX(sort_order),0) FROM project_checklist WHERE project_id=?", [$pid]);
    foreach ($standard as $s) {
        insert('project_checklist', ['project_id' => $pid, 'item' => $s[0], 'check_note' => $s[1], 'sort_order' => ++$sort_order]);
    }
    json_out(['ok' => true, 'mesaj' => 'Standart SOP kontrol listesi yüklendi.', 'refresh' => true]);

case 'pcheck_toggle':
    if (!is_staff()) deny();
    $field = $g('field') === 'teslim' ? 'teslim' : 'tamam';
    q("UPDATE project_checklist SET $field = 1 - $field WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true]);

case 'pcheck_owner':
    if (!is_staff()) deny();
    update_row('project_checklist', ['owner_id' => (int)$g('owner_id') ?: null], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'refresh' => true]);

case 'pcheck_delete':
    if (!is_staff()) deny();
    q("DELETE FROM project_checklist WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true]);

case 'review_save':
    if (!is_staff()) deny();
    $pid = (int)$g('project_id');
    if (!project_access($pid)) deny();
    if (!in_array($g('type'), ['ic', 'dis', 'case_study'])) json_out(['ok' => false, 'error' => 'Geçersiz değerlendirme türü.']);
    q("INSERT INTO project_review (project_id, type, content, updated_by, updated) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE content=VALUES(content), updated_by=VALUES(updated_by), updated=VALUES(updated)",
       [$pid, $g('type'), trim($g('content')), $u['id'], $now]);
    json_out(['ok' => true, 'mesaj' => 'Değerlendirme kaydedildi.']);

case 'shoot_list_save':
    if (!permission('takvim_yonet')) deny();
    update_row('events', ['shopping_list' => trim($g('shopping_list')) ?: null, 'needs_list' => trim($g('needs_list')) ?: null], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Çekim listesi güncellendi.', 'refresh' => true]);

case 'notification_count':
    json_out(['ok' => true, 'count' => (int)val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0", [$u['id']])]);

case 'notification_delete':
    q("DELETE FROM notifications WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'notification_clear':
    q("DELETE FROM notifications WHERE user_id=?", [$u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Tüm bildirimler temizlendi.']);

case 'notification_all_read':
    update_row('notifications', ['is_read' => 1], 'user_id=?', [$u['id']]);
    json_out(['ok' => true]);

case 'live_status':
    // Live sync: returns the page's current state summary
    require_login();
    $context = $g('context');
    if ($context === 'task') json_out(['ok' => true, 'hash' => live_hash_task((int)$g('id'))]);
    if ($context === 'list') json_out(['ok' => true, 'hash' => live_hash_list()]);
    json_out(['ok' => false, 'error' => 'Geçersiz bağlam.']);

/* ==================== CLIENT FILES ==================== */
case 'client_save':
    require_permission('dosya_yonet');
    $data = [
        'name' => trim($g('name')), 'type' => $g('type', 'marka'), 'color' => $g('color', '#182f5d'),
        'description' => $g('description'), 'contact_name' => $g('contact_name'),
        'contact_email' => $g('contact_email'), 'contact_phone' => $g('contact_phone'),
        'status' => $g('status', 'aktif'),
    ];
    if ($data['name'] === '') json_out(['ok' => false, 'error' => 'Dosya adı gerekli.']);
    $logo = file_upload('logo');
    if ($logo) {
        if (!in_array($logo['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp'])) json_out(['ok' => false, 'error' => 'Logo için görsel dosyası seçin (jpg, png, webp).']);
        $data['logo'] = $logo['path'];
    }
    if ($g('id')) {
        $id = (int)$g('id');
        update_row('clients', $data, 'id=?', [$id]);
        log_activity('"' . $data['name'] . '" dosyasını güncelledi', 'dosya', $id);
        client_members_save($id, $g('members'));
        json_out(['ok' => true, 'mesaj' => 'Dosya güncellendi.']);
    } else {
        $data['created'] = $now;
        $id = insert('clients', $data);
        log_activity('"' . $data['name'] . '" dosyasını oluşturdu', 'dosya', $id);
        client_members_save($id, $g('members'));
        json_out(['ok' => true, 'mesaj' => 'Dosya oluşturuldu.', 'redirect' => 'client.php?id=' . $id]);
    }

case 'client_delete':
    require_admin();
    $id = (int)$g('id');
    if (val("SELECT COUNT(*) FROM projects WHERE client_id=?", [$id]) > 0)
        json_out(['ok' => false, 'error' => 'Bu dosyada projeler var. Önce projeleri silin.']);
    q("DELETE FROM clients WHERE id=?", [$id]);
    json_out(['ok' => true, 'mesaj' => 'Dosya silindi.', 'redirect' => 'clients.php']);

/* ==================== PROJECTS ==================== */
case 'project_save':
    require_permission('dosya_yonet');
    $data = [
        'client_id' => (int)$g('client_id'), 'name' => trim($g('name')), 'type' => $g('type', 'aylik'),
        'description' => $g('description'), 'status' => $g('status', 'aktif'),
        'start' => $g('start') ?: null, 'end' => $g('end') ?: null,
        'pm_id' => $g('pm_id') ? (int)$g('pm_id') : null,
        'contract_amount' => (float)str_replace(',', '.', $g('contract_amount', '0')),
    ];
    if ($data['name'] === '' || !$data['client_id']) json_out(['ok' => false, 'error' => 'Proje adı ve dosya gerekli.']);
    if ($g('id')) {
        $id = (int)$g('id');
        update_row('projects', $data, 'id=?', [$id]);
        project_members_save($id, $g('members'));
        log_activity('"' . $data['name'] . '" projesini güncelledi', 'proje', $id);
        json_out(['ok' => true, 'mesaj' => 'Proje güncellendi.']);
    } else {
        $data['created'] = $now;
        $id = insert('projects', $data);
        // For monthly projects, open a period for the current month
        if ($data['type'] === 'aylik') get_or_create_period($id, (int)date('Y'), (int)date('n'));
        project_channel($id, 'project');
        project_channel($id, 'musteri');
        project_members_save($id, $g('members'));
        // Set up tasks from the project template
        if ($g('ptemplate_id')) {
            $ps = row("SELECT * FROM project_templates WHERE id=?", [(int)$g('ptemplate_id')]);
            foreach (json_decode($ps['tasks'] ?? '[]', true) ?: [] as $si => $sg) {
                $gid = insert('tasks', ['project_id' => $id, 'title' => $sg['title'], 'priority' => $sg['priority'] ?? 'normal', 'created_by' => $u['id'], 'status' => 'yapilacak', 'sort_order' => $si + 1, 'created' => $now]);
                if (!empty($sg['workflow_id'])) task_steps_setup($gid, (int)$sg['workflow_id']);
            }
        }
        log_activity('"' . $data['name'] . '" projesini oluşturdu', 'proje', $id);
        json_out(['ok' => true, 'mesaj' => 'Proje oluşturuldu.', 'redirect' => 'project.php?id=' . $id]);
    }

case 'project_delete':
    require_admin();
    $id = (int)$g('id');
    foreach (['tasks', 'contents', 'approvals', 'payments', 'periods'] as $t) q("DELETE FROM $t WHERE project_id=?", [$id]);
    q("DELETE FROM projects WHERE id=?", [$id]);
    json_out(['ok' => true, 'mesaj' => 'Proje silindi.', 'redirect' => 'projects.php']);

case 'period_open':
    require_pm();
    $projectId = (int)$g('project_id');
    $periodId = get_or_create_period($projectId, (int)$g('year'), (int)$g('month'));
    // Option to create a task from a template
    if ($g('template_id')) {
        $template = row("SELECT * FROM workflow_templates WHERE id=?", [(int)$g('template_id')]);
        $firstStep = row("SELECT name FROM template_steps WHERE template_id=? ORDER BY sort_order LIMIT 1", [(int)$g('template_id')]);
        if ($template) {
            $taskId = insert('tasks', [
                'project_id' => $projectId, 'period_id' => $periodId,
                'title' => $template['name'] . ' — ' . MONTHS[(int)$g('month')] . ' ' . $g('year'),
                'created_by' => $u['id'], 'status' => 'yapilacak', 'created' => $now,
            ]);
            task_steps_setup($taskId, (int)$g('template_id'));
        }
    }
    json_out(['ok' => true, 'mesaj' => 'Dönem açıldı.']);

/* ==================== TASKS ==================== */
case 'task_save':
    require_staff();
    if (!$g('id') && !permission('gorev_olustur')) json_out(['ok' => false, 'error' => 'Görev oluşturma yetkiniz yok.']);
    $data = [
        'project_id' => (int)$g('project_id'), 'title' => trim($g('title')), 'description' => $g('description'),
        'assignee_id' => $g('assignee_id') ? (int)$g('assignee_id') : null,
        'priority' => $g('priority', 'normal'), 'due_date' => $g('due_date') ?: null,
        'period_id' => $g('period_id') ? (int)$g('period_id') : null,
        'bagimli_id' => $g('bagimli_id') ? (int)$g('bagimli_id') : null,
        'repeat' => isset(REPEAT_OPTIONS[$g('repeat')]) ? $g('repeat') : 'yok',
        'tags' => mb_substr(trim($g('tags')), 0, 255) ?: null,
        'estimated_minutes' => max(0, (int)((float)str_replace(',', '.', $g('estimated_time', '0')) * 60)),
        'start_date' => $g('start_date') ?: null,
    ];
    if ($data['title'] === '' || !$data['project_id']) json_out(['ok' => false, 'error' => 'Görev başlığı ve proje gerekli.']);
    if ($data['bagimli_id'] === (int)$g('id') && $data['bagimli_id']) json_out(['ok' => false, 'error' => 'Görev kendisine bağlanamaz.']);
    // Multi-assignee list (JSON); assignee_id is set to the first person for compatibility
    $assignees = json_decode($g('assignees', ''), true);
    if (is_array($assignees)) {
        $assignees = array_values(array_unique(array_filter(array_map('intval', $assignees))));
        $data['assignee_id'] = $assignees[0] ?? null;
    }
    if ($g('id')) {
        $id = (int)$g('id');
        $eskiler = array_column(rows("SELECT user_id FROM task_assignees WHERE task_id=?", [$id]), 'user_id');
        update_row('tasks', $data, 'id=?', [$id]);
        if (is_array($assignees)) {
            q("DELETE FROM task_assignees WHERE task_id=?", [$id]);
            foreach ($assignees as $aid) {
                q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$id, $aid]);
                if (!in_array($aid, $eskiler)) notify($aid, 'Görev atandı', $data['title'], 'task.php?id=' . $id, 'gorev');
            }
        }
        json_out(['ok' => true, 'mesaj' => 'Görev güncellendi.']);
    } else {
        $data['created_by'] = $u['id']; $data['status'] = 'yapilacak'; $data['created'] = $now;
        $data['sort_order'] = (int)val("SELECT COALESCE(MAX(sort_order),0)+1 FROM tasks WHERE project_id=? AND status='yapilacak'", [$data['project_id']]);
        $id = insert('tasks', $data);
        // Content task: link to existing content or create new content
        if ($g('content_select') === 'yeni') {
            $projectClient = (int)val("SELECT client_id FROM projects WHERE id=?", [$data['project_id']]);
            $newContentId = insert('contents', [
                'client_id' => $projectClient ?: null, 'project_id' => $data['project_id'],
                'title' => $data['title'],
                'platform' => isset(PLATFORMS[$g('content_platform')]) ? $g('content_platform') : 'instagram',
                'date' => $g('content_date') ?: ($data['due_date'] ?: date('Y-m-d')),
                'status' => 'taslak', 'created_by' => $u['id'], 'created' => $now,
            ]);
            update_row('tasks', ['content_id' => $newContentId], 'id=?', [$id]);
        } elseif ((int)$g('content_select') > 0) {
            update_row('tasks', ['content_id' => (int)$g('content_select')], 'id=?', [$id]);
        }
        if ($g('template_id')) task_steps_setup($id, (int)$g('template_id'));
        if (is_array($assignees)) {
            foreach ($assignees as $aid) {
                q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$id, $aid]);
                notify($aid, 'Yeni görev atandı', $data['title'], 'task.php?id=' . $id, 'gorev');
            }
        } elseif ($data['assignee_id']) {
            q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$id, $data['assignee_id']]);
            notify($data['assignee_id'], 'Yeni görev atandı', $data['title'], 'task.php?id=' . $id, 'gorev');
        }
        log_activity('"' . $data['title'] . '" görevini oluşturdu', 'proje', $data['project_id']);
        json_out(['ok' => true, 'mesaj' => 'Görev oluşturuldu.']);
    }

case 'task_status':
case 'task_sort':
    require_staff();
    $id = (int)$g('id');
    $status = $g('status');
    if (!isset(TASK_STATUSES[$status])) json_out(['ok' => false, 'error' => 'Geçersiz durum.']);
    $task = row("SELECT * FROM tasks WHERE id=?", [$id]);
    if (!$task) json_out(['ok' => false, 'error' => 'Görev bulunamadı.']);
    // Lock checks (dependency + workflow state)
    if ($task['status'] !== $status) {
        $engel = task_lock_reason($task, $status);
        if ($engel) json_out(['ok' => false, 'error' => '🔒 ' . $engel]);
    }
    $ek = ['status' => $status];
    if ($status === 'tamamlandi' && $task['status'] !== 'tamamlandi') $ek['completion'] = $now;
    update_row('tasks', $ek, 'id=?', [$id]);
    if ($status === 'tamamlandi') task_content_sync($id);
    // Save the in-column sort order
    $ids = json_decode($g('ids', '[]'), true);
    if (is_array($ids) && $ids) {
        $st = db()->prepare("UPDATE tasks SET sort_order=? WHERE id=?");
        foreach (array_values($ids) as $i => $gid) $st->execute([$i + 1, (int)$gid]);
    }
    if ($task['status'] !== $status) {
        log_activity('"' . $task['title'] . '" görevini ' . TASK_STATUSES[$status] . ' durumuna aldı', 'gorev', $id);
        $alicilar = array_column(rows("SELECT user_id FROM task_watchers WHERE task_id=?", [$id]), 'user_id');
        if ($task['assignee_id']) $alicilar[] = (int)$task['assignee_id'];
        foreach (array_unique($alicilar) as $aid)
            notify((int)$aid, 'Görev durumu değişti', $task['title'] . ' → ' . TASK_STATUSES[$status], 'task.php?id=' . $id, 'gorev');
    }
    json_out(['ok' => true]);

case 'gorev_sil':
    require_permission('gorev_sil');
    $id = (int)$g('id');
    foreach (['task_steps', 'time_entries', 'task_checklist'] as $t) q("DELETE FROM $t WHERE task_id=?", [$id]);
    q("UPDATE tasks SET bagimli_id=NULL WHERE bagimli_id=?", [$id]);
    q("DELETE FROM tasks WHERE id=?", [$id]);
    json_out(['ok' => true, 'mesaj' => 'Görev silindi.', 'redirect' => 'tasks.php']);

case 'task_field':
    // Inline cell editing in table view (per-field update)
    require_staff();
    $id = (int)$g('id');
    $field = $g('field');
    $setting_value = $g('setting_value');
    $task = row("SELECT * FROM tasks WHERE id=?", [$id]);
    if (!$task) json_out(['ok' => false, 'error' => 'Görev bulunamadı.']);
    $allowed = ['status', 'priority', 'assignee_id', 'due_date', 'start_date', 'estimated_minutes', 'tags'];
    if (!in_array($field, $allowed)) json_out(['ok' => false, 'error' => 'Bu alan düzenlenemez.']);
    if ($field === 'status') {
        if (!isset(TASK_STATUSES[$setting_value])) json_out(['ok' => false, 'error' => 'Geçersiz durum.']);
        if ($task['status'] !== $setting_value) {
            $engel = task_lock_reason($task, $setting_value);
            if ($engel) json_out(['ok' => false, 'error' => '🔒 ' . $engel]);
        }
        $ek = ['status' => $setting_value];
        if ($setting_value === 'tamamlandi' && $task['status'] !== 'tamamlandi') $ek['completion'] = $now;
        update_row('tasks', $ek, 'id=?', [$id]);
        if ($setting_value === 'tamamlandi') task_content_sync($id);
        log_activity('"' . $task['title'] . '" görevini ' . TASK_STATUSES[$setting_value] . ' durumuna aldı', 'gorev', $id);
    } elseif ($field === 'priority') {
        if (!isset(PRIORITIES[$setting_value])) json_out(['ok' => false, 'error' => 'Geçersiz öncelik.']);
        update_row('tasks', ['priority' => $setting_value], 'id=?', [$id]);
    } elseif ($field === 'assignee_id') {
        $new = $setting_value ? (int)$setting_value : null;
        update_row('tasks', ['assignee_id' => $new], 'id=?', [$id]);
        if ($task['assignee_id']) q("DELETE FROM task_assignees WHERE task_id=? AND user_id=?", [$id, $task['assignee_id']]);
        if ($new) {
            q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$id, $new]);
            if ($new != $task['assignee_id']) notify($new, 'Görev atandı', $task['title'], 'task.php?id=' . $id, 'gorev');
        }
    } elseif ($field === 'estimated_minutes') {
        update_row('tasks', ['estimated_minutes' => max(0, (int)((float)str_replace(',', '.', $setting_value) * 60))], 'id=?', [$id]);
    } elseif ($field === 'tags') {
        update_row('tasks', ['tags' => mb_substr(trim($setting_value), 0, 255) ?: null], 'id=?', [$id]);
    } else { // date fields
        update_row('tasks', [$field => $setting_value ?: null], 'id=?', [$id]);
    }
    json_out(['ok' => true]);

case 'task_archive':
    require_staff();
    $id = (int)$g('id');
    $task = row("SELECT * FROM tasks WHERE id=?", [$id]);
    if (!$task) json_out(['ok' => false, 'error' => 'Görev bulunamadı.']);
    $new = $task['is_archived'] ? 0 : 1;
    update_row('tasks', ['is_archived' => $new], 'id=?', [$id]);
    log_activity('"' . $task['title'] . '" görevini ' . ($new ? 'arşivledi' : 'arşivden çıkardı'), 'gorev', $id);
    json_out(['ok' => true, 'mesaj' => $new ? 'Görev arşive taşındı.' : 'Görev arşivden çıkarıldı.', 'redirect' => $new ? 'tasks.php' : '']);

case 'view_preference':
    require_login();
    $view = in_array($g('view'), ['kanban', 'tablo']) ? $g('view') : 'kanban';
    update_row('users', ['task_view' => $view], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

case 'watcher_toggle':
    require_staff();
    $gid = (int)$g('task_id');
    $target = (int)$g('user_id');
    if (!val("SELECT COUNT(*) FROM tasks WHERE id=?", [$gid])) json_out(['ok' => false, 'error' => 'Görev bulunamadı.']);
    $var = val("SELECT COUNT(*) FROM task_watchers WHERE task_id=? AND user_id=?", [$gid, $target]);
    if ($var) { q("DELETE FROM task_watchers WHERE task_id=? AND user_id=?", [$gid, $target]); $m = 'İzleyici çıkarıldı.'; }
    else {
        q("INSERT IGNORE INTO task_watchers (task_id, user_id) VALUES (?,?)", [$gid, $target]);
        $title = val("SELECT title FROM tasks WHERE id=?", [$gid]);
        notify($target, 'Bir göreve izleyici eklendiniz', $title, 'task.php?id=' . $gid, 'gorev');
        $m = 'İzleyici eklendi.';
    }
    json_out(['ok' => true, 'mesaj' => $m]);

case 'content_move':
    require_permission('icerik_yonet');
    $internal = row("SELECT * FROM contents WHERE id=?", [(int)$g('id')]);
    if (!$internal) json_out(['ok' => false, 'error' => 'İçerik bulunamadı.']);
    $new = ['date' => $g('date') ?: $internal['date']];
    if ($g('time') !== '') $new['time'] = $g('time') ?: null;
    update_row('contents', $new, 'id=?', [$internal['id']]);
    json_out(['ok' => true, 'mesaj' => 'İçerik ' . format_date($new['date']) . ' tarihine taşındı.']);

case 'event_move':
    require_permission('takvim_yonet');
    $et = row("SELECT * FROM events WHERE id=?", [(int)$g('id')]);
    if (!$et) json_out(['ok' => false, 'error' => 'Etkinlik bulunamadı.']);
    if ($g('start')) {
        // Full datetime provided (modal edit)
        $newInitial = $g('start');
        $newBit = $g('end') ?: null;
    } else {
        // Only the day provided (drag): keep the time, shift the end by the same day offset
        $day = $g('date');
        if (!$day) json_out(['ok' => false, 'error' => 'Tarih gerekli.']);
        $fark = strtotime($day) - strtotime(date('Y-m-d', strtotime($et['start'])));
        $newInitial = date('Y-m-d H:i:s', strtotime($et['start']) + $fark);
        $newBit = $et['end'] ? date('Y-m-d H:i:s', strtotime($et['end']) + $fark) : null;
    }
    update_row('events', ['start' => $newInitial, 'end' => $newBit, 'is_reminded' => 0], 'id=?', [$et['id']]);
    json_out(['ok' => true, 'mesaj' => 'Etkinlik taşındı: ' . format_date($newInitial, true)]);

case 'clientnote_save':
    require_staff();
    $title = mb_substr(trim($g('title')), 0, 150);
    if ($title === '') json_out(['ok' => false, 'error' => 'Bölüm başlığı gerekli.']);
    if ($g('id')) {
        update_row('client_notes', ['title' => $title, 'text' => $g('text'), 'updated_by' => $u['id'], 'update' => $now], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Not güncellendi.']);
    }
    insert('client_notes', ['client_id' => (int)$g('client_id'), 'title' => $title, 'text' => $g('text'), 'sort_order' => (int)val("SELECT COALESCE(MAX(sort_order),0)+1 FROM client_notes WHERE client_id=?", [(int)$g('client_id')]), 'updated_by' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Bilgi notu eklendi.']);

case 'clientnote_delete':
    require_staff();
    q("DELETE FROM client_notes WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Not silindi.']);

case 'ptemplate_save':
    require_admin();
    $name = trim($g('name'));
    $templateTasks = json_decode($g('tasks', '[]'), true) ?: [];
    $templateTasks = array_values(array_filter(array_map(fn($s) => ['title' => mb_substr(trim($s['title'] ?? ''), 0, 200), 'workflow_id' => (int)($s['workflow_id'] ?? 0), 'priority' => isset(PRIORITIES[$s['priority'] ?? '']) ? $s['priority'] : 'normal'], $templateTasks), fn($s) => $s['title'] !== ''));
    if ($name === '' || !$templateTasks) json_out(['ok' => false, 'error' => 'Ad ve en az bir görev gerekli.']);
    if ($g('id')) {
        update_row('project_templates', ['name' => $name, 'description' => $g('description'), 'tasks' => json_encode($templateTasks, JSON_UNESCAPED_UNICODE)], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Şablon güncellendi.']);
    }
    insert('project_templates', ['name' => $name, 'description' => $g('description'), 'tasks' => json_encode($templateTasks, JSON_UNESCAPED_UNICODE), 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Proje şablonu kaydedildi.']);

case 'ptemplate_delete':
    require_admin();
    q("DELETE FROM project_templates WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Şablon silindi.']);

case 'lock_toggle':
    require_pm(); // only admins and PMs can disable the lock
    $id = (int)$g('id');
    $task = row("SELECT * FROM tasks WHERE id=?", [$id]);
    if (!$task) json_out(['ok' => false, 'error' => 'Görev bulunamadı.']);
    $new = $task['lock_bypassed'] ? 0 : 1;
    update_row('tasks', ['lock_bypassed' => $new], 'id=?', [$id]);
    log_activity('"' . $task['title'] . '" görevinin kilidini ' . ($new ? 'devre dışı bıraktı' : 'etkinleştirdi'), 'gorev', $id);
    json_out(['ok' => true, 'mesaj' => $new ? 'Kilit devre dışı — görev serbestçe ilerletilebilir.' : 'Kilit yeniden etkin.']);

case 'step_complete':
    require_staff();
    $stepId = (int)$g('id');
    $step = row("SELECT * FROM task_steps WHERE id=?", [$stepId]);
    if (!$step) json_out(['ok' => false, 'error' => 'Adım bulunamadı.']);
    $task = row("SELECT * FROM tasks WHERE id=?", [$step['task_id']]);
    $newStatus = $step['status'] === 'tamam' ? 'bekliyor' : 'tamam';
    // Sequential step rule: this step cannot be completed until earlier steps are done
    if ($newStatus === 'tamam' && empty($task['lock_bypassed'])) {
        $oncekiEksik = (int)val("SELECT COUNT(*) FROM task_steps WHERE task_id=? AND sort_order<? AND status!='tamam'", [$step['task_id'], $step['sort_order']]);
        if ($oncekiEksik > 0) json_out(['ok' => false, 'error' => '🔒 Önceki ' . $oncekiEksik . ' adım tamamlanmadan bu adım tamamlanamaz.' . (is_pm() ? ' (Görev sayfasından kilidi devre dışı bırakabilirsiniz.)' : '')]);
    }
    update_row('task_steps', ['status' => $newStatus, 'done_date' => $newStatus === 'tamam' ? $now : null], 'id=?', [$stepId]);
    if ($newStatus === 'tamam') {
        $sonraki = row("SELECT id FROM task_steps WHERE task_id=? AND sort_order>? AND status!='tamam' ORDER BY sort_order LIMIT 1", [$step['task_id'], $step['sort_order']]);
        if ($sonraki) update_row('task_steps', ['status' => 'aktif'], 'id=?', [$sonraki['id']]);
        $kalan = (int)val("SELECT COUNT(*) FROM task_steps WHERE task_id=? AND status!='tamam'", [$step['task_id']]);
        if ($kalan === 0) { update_row('tasks', ['status' => 'tamamlandi', 'completion' => $now], 'id=?', [$step['task_id']]); task_content_sync((int)$step['task_id']); }
        // Notify the step owner that it is their turn
        if ($sonraki) {
            $ownerId = val("SELECT owner_id FROM task_steps WHERE id=?", [$sonraki['id']]);
            if ($ownerId) notify((int)$ownerId, 'Akışta sıra sizde', $task['title'], 'task.php?id=' . $step['task_id'], 'gorev');
        }
    } else {
        // Steps completed after the reverted one drop back to 'bekliyor' (consistency)
        q("UPDATE task_steps SET status='bekliyor', done_date=NULL WHERE task_id=? AND sort_order>? AND status='tamam'", [$step['task_id'], $step['sort_order']]);
        q("UPDATE tasks SET status='devam', completion=NULL WHERE id=? AND status='tamamlandi'", [$step['task_id']]);
    }
    // Return the current workflow state (for refresh-free UI updates)
    $stepsLast = rows("SELECT id, sort_order, status FROM task_steps WHERE task_id=? ORDER BY sort_order", [$step['task_id']]);
    $taskLast = row("SELECT status FROM tasks WHERE id=?", [$step['task_id']]);
    json_out([
        'ok' => true, 'mesaj' => 'Akış adımı güncellendi.',
        'steps' => $stepsLast,
        'is_done_adet' => count(array_filter($stepsLast, fn($a) => $a['status'] === 'tamam')),
        'total' => count($stepsLast),
        'task_status' => $taskLast['status'],
        'task_status_tag' => TASK_STATUSES[$taskLast['status']],
    ]);

/* ==================== CHECKLIST ==================== */
case 'check_add':
    require_staff();
    $name = trim($g('name'));
    if ($name === '') json_out(['ok' => false, 'error' => 'Madde boş olamaz.']);
    $gid = (int)$g('task_id');
    $sort_order = (int)val("SELECT COALESCE(MAX(sort_order),0)+1 FROM task_checklist WHERE task_id=?", [$gid]);
    $id = insert('task_checklist', ['task_id' => $gid, 'name' => $name, 'is_done' => 0, 'sort_order' => $sort_order]);
    json_out(['ok' => true, 'id' => $id, 'name' => $name]);

case 'check_toggle':
    require_staff();
    q("UPDATE task_checklist SET is_done=1-is_done WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true]);

case 'check_delete':
    require_staff();
    q("DELETE FROM task_checklist WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true]);

case 'step_owner':
    require_staff();
    $newOwner = $g('owner_id') ? (int)$g('owner_id') : null;
    update_row('task_steps', ['owner_id' => $newOwner], 'id=?', [(int)$g('id')]);
    if ($newOwner) {
        $stepInfo = row("SELECT ga.name, g.title, g.id gid FROM task_steps ga JOIN tasks g ON g.id=ga.task_id WHERE ga.id=?", [(int)$g('id')]);
        if ($stepInfo) notify($newOwner, 'Akış adımı size atandı', $stepInfo['title'] . ' → ' . $stepInfo['name'], 'task.php?id=' . $stepInfo['gid'], 'gorev');
    }
    json_out(['ok' => true, 'mesaj' => 'Sorumlu atandı.']);

/* ==================== TIME TRACKING ==================== */
case 'time_add':
    require_staff();
    $min = (int)$g('time') * 60 + (int)$g('minutes');
    if ($min <= 0) json_out(['ok' => false, 'error' => 'Süre girin.']);
    insert('time_entries', [
        'task_id' => (int)$g('task_id'), 'user_id' => $u['id'], 'minutes' => $min,
        'date' => $g('date') ?: date('Y-m-d'), 'description' => $g('description'), 'created' => $now,
    ]);
    json_out(['ok' => true, 'mesaj' => format_minutes($min) . ' zaman kaydedildi.']);

/* ==================== COMMENTS ==================== */
case 'comment_box_add':
    require_login();
    $message = trim($g('message'));
    if ($message === '') json_out(['ok' => false, 'error' => 'Yorum boş olamaz.']);
    $refType = $g('ref_type'); $refId = (int)$g('ref_id');
    // Access: customers may only comment on tasks/projects of their own projects
    if (is_customer()) {
        $projectId = $refType === 'project' ? $refId : (int)val("SELECT project_id FROM tasks WHERE id=?", [$refId]);
        if (!project_access($projectId)) json_out(['ok' => false, 'error' => 'Bu alana yorum yazamazsınız.']);
    }
    $data = [
        'ref_type' => $refType, 'ref_id' => $refId, 'user_id' => $u['id'],
        'mesaj' => $message, 'created' => $now,
        'parent_id' => $g('parent_id') ? (int)$g('parent_id') : null,
    ];
    // File attachment
    $ek = file_upload('dosya');
    if ($ek) {
        $data['archive_id'] = insert('archive', [
            'project_id' => $refType === 'project' ? $refId : (int)val("SELECT project_id FROM tasks WHERE id=?", [$refId]),
            'task_id' => $refType === 'task' ? $refId : null,
            'name' => $ek['name'], 'file_path' => $ek['path'], 'size' => $ek['size'],
            'extension' => $ek['extension'], 'uploader_id' => $u['id'], 'created' => $now,
        ]);
    }
    $commentId = insert('comments', $data);
    // Context info + link
    if ($refType === 'task') {
        $context = row("SELECT title, assignee_id, project_id FROM tasks WHERE id=?", [$refId]);
        $link = 'task.php?id=' . $refId;
        // Notify watchers + the assignee
        $alicilar = array_column(rows("SELECT user_id FROM task_watchers WHERE task_id=?", [$refId]), 'user_id');
        if ($context['assignee_id']) $alicilar[] = (int)$context['assignee_id'];
        foreach (array_unique($alicilar) as $aid)
            notify((int)$aid, 'Yeni yorum: ' . $context['title'], $u['name'] . ': ' . mb_substr($message, 0, 80), $link, 'mesaj');
    } else {
        $context = row("SELECT name title FROM projects WHERE id=?", [$refId]);
        $link = 'project.php?id=' . $refId . '#tartisma';
        foreach (rows("SELECT user_id FROM project_members WHERE project_id=?", [$refId]) as $pu)
            notify((int)$pu['user_id'], 'Proje tartışması: ' . ($context['title'] ?? ''), $u['name'] . ': ' . mb_substr($message, 0, 80), $link, 'mesaj');
    }
    // If it is a reply, notify the parent comment's owner
    if ($data['parent_id']) {
        $topSahip = (int)val("SELECT user_id FROM comments WHERE id=?", [$data['parent_id']]);
        if ($topSahip) notify($topSahip, $u['name'] . ' yorumunuza yanıt verdi', mb_substr($message, 0, 80), $link, 'mesaj');
    }
    // Notify mentioned users
    notify_mentions($g('mention_ids', ''), $u['name'] . ' sizi etiketledi', mb_substr($message, 0, 90), $link);
    json_out(['ok' => true, 'mesaj' => 'Yorum eklendi.']);

case 'comment_box_edit':
    require_login();
    $comment_box = row("SELECT * FROM comments WHERE id=?", [(int)$g('id')]);
    if (!$comment_box || $comment_box['user_id'] != $u['id']) json_out(['ok' => false, 'error' => 'Yalnızca kendi yorumunuzu düzenleyebilirsiniz.']);
    $message = trim($g('message'));
    if ($message === '') json_out(['ok' => false, 'error' => 'Yorum boş olamaz.']);
    update_row('comments', ['mesaj' => $message, 'is_edited' => 1], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Yorum güncellendi.']);

case 'comment_box_delete':
    require_login();
    $comment_box = row("SELECT * FROM comments WHERE id=?", [(int)$g('id')]);
    if (!$comment_box || ($comment_box['user_id'] != $u['id'] && !is_admin())) json_out(['ok' => false, 'error' => 'Bu yorumu silme yetkiniz yok.']);
    q("DELETE FROM comments WHERE id=? OR parent_id=?", [(int)$g('id'), (int)$g('id')]);
    q("DELETE FROM comment_box_reactions WHERE comment_box_id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Yorum silindi.']);

case 'reaction_toggle':
    require_login();
    $commentId = (int)$g('comment_box_id');
    $emoji = mb_substr(trim($g('emoji')), 0, 8);
    if (!$emoji || !val("SELECT COUNT(*) FROM comments WHERE id=?", [$commentId])) json_out(['ok' => false, 'error' => 'Geçersiz.']);
    $var = val("SELECT COUNT(*) FROM comment_box_reactions WHERE comment_box_id=? AND user_id=? AND emoji=?", [$commentId, $u['id'], $emoji]);
    if ($var) q("DELETE FROM comment_box_reactions WHERE comment_box_id=? AND user_id=? AND emoji=?", [$commentId, $u['id'], $emoji]);
    else q("INSERT INTO comment_box_reactions (comment_box_id, user_id, emoji) VALUES (?,?,?)", [$commentId, $u['id'], $emoji]);
    $adet = (int)val("SELECT COUNT(*) FROM comment_box_reactions WHERE comment_box_id=? AND emoji=?", [$commentId, $emoji]);
    json_out(['ok' => true, 'adet' => $adet, 'mine' => $var ? 0 : 1]);

/* ==================== APPROVALS ==================== */
case 'onay_gonder':
    require_permission('onay_gonder');
    $data = [
        'project_id' => (int)$g('project_id'), 'title' => trim($g('title')), 'description' => $g('description'),
        'task_id' => $g('task_id') ? (int)$g('task_id') : null,
        'content_id' => $g('content_id') ? (int)$g('content_id') : null,
        'sender_id' => $u['id'], 'status' => 'bekliyor', 'created' => $now,
    ];
    if ($data['title'] === '') json_out(['ok' => false, 'error' => 'Onay başlığı gerekli.']);
    // File attachment or Drive link
    $yuklenen = file_upload('dosya');
    if ($yuklenen) {
        $archiveId = insert('archive', [
            'project_id' => $data['project_id'], 'name' => $yuklenen['name'], 'file_path' => $yuklenen['path'],
            'size' => $yuklenen['size'], 'extension' => $yuklenen['extension'], 'uploader_id' => $u['id'], 'created' => $now,
        ]);
        $data['archive_id'] = $archiveId;
    }
    $dLink = trim($g('drive_link'));
    if ($dLink !== '') {
        if (!preg_match('#^https?://#i', $dLink)) $dLink = 'https://' . $dLink;
        $data['drive_link'] = mb_substr($dLink, 0, 500);
    }
    $id = insert('approvals', $data);
    // Notify the customer (primary client file + extra file assignments)
    $clientId = (int)val("SELECT client_id FROM projects WHERE id=?", [$data['project_id']]);
    foreach (rows("SELECT DISTINCT us.id FROM users us LEFT JOIN customer_clients md ON md.user_id=us.id
        WHERE us.role='musteri' AND us.is_active=1 AND (us.client_id=? OR md.client_id=?)", [$clientId, $clientId]) as $m)
        notify((int)$m['id'], 'Onayınız bekleniyor', $data['title'], 'approvals.php', 'onay');
    log_activity('"' . $data['title'] . '" için onay gönderdi', 'proje', $data['project_id']);
    json_out(['ok' => true, 'mesaj' => 'Onaya gönderildi.']);

case 'approval_reply':
    require_login();
    $id = (int)$g('id');
    $approval = row("SELECT * FROM approvals WHERE id=?", [$id]);
    if (!$approval || !project_access($approval['project_id'])) json_out(['ok' => false, 'error' => 'Yetkisiz.']);
    $status = $g('status');
    if (!in_array($status, ['onaylandi', 'revize', 'reddedildi'])) json_out(['ok' => false, 'error' => 'Geçersiz.']);
    update_row('approvals', [
        'status' => $status, 'reply_note' => $g('not'), 'reply_date' => $now, 'responder_id' => $u['id'],
    ], 'id=?', [$id]);
    notify($approval['sender_id'], 'Onay yanıtlandı: ' . APPROVAL_STATUSES[$status], $approval['title'] . ($g('not') ? ' — ' . $g('not') : ''), 'approvals.php', 'onay');
    log_activity('"' . $approval['title'] . '" onayını ' . APPROVAL_STATUSES[$status] . ' olarak yanıtladı', 'proje', $approval['project_id']);
    json_out(['ok' => true, 'mesaj' => 'Yanıtınız kaydedildi.']);

/* ==================== CONTENT CALENDAR ==================== */
case 'content_save':
    require_permission('icerik_yonet');
    // Multi-platform: JSON array → CSV
    $platforms = json_decode($g('platforms', ''), true);
    if (is_array($platforms)) {
        $platforms = array_values(array_intersect(array_map('strval', $platforms), array_keys(PLATFORMS)));
        $platformCsv = $platforms ? implode(',', $platforms) : 'instagram';
    } else {
        $platformCsv = isset(PLATFORMS[$g('platform')]) ? $g('platform') : 'instagram';
    }
    $projectId = $g('project_id') ? (int)$g('project_id') : null;
    $clientId = $g('client_id') ? (int)$g('client_id') : null;
    if (!$clientId && $projectId) $clientId = (int)val("SELECT client_id FROM projects WHERE id=?", [$projectId]) ?: null;
    if (!$clientId) json_out(['ok' => false, 'error' => 'İçeriğin ait olduğu dosyayı seçin.']);
    $data = [
        'client_id' => $clientId, 'project_id' => $projectId,
        'title' => trim($g('title')), 'description' => $g('description'),
        'platform' => $platformCsv, 'date' => $g('date') ?: date('Y-m-d'),
        'time' => $g('time') ?: null, 'status' => $g('status', 'taslak'),
    ];
    if ($data['title'] === '') json_out(['ok' => false, 'error' => 'İçerik başlığı gerekli.']);
    if ($g('id')) {
        update_row('contents', $data, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'İçerik güncellendi.']);
    }
    $data['created_by'] = $u['id']; $data['created'] = $now;
    insert('contents', $data);
    json_out(['ok' => true, 'mesaj' => 'İçerik planlandı.']);

case 'content_status':
    require_login();
    $id = (int)$g('id');
    $content = row("SELECT * FROM contents WHERE id=?", [$id]);
    $contentClient = $content ? (int)($content['client_id'] ?: val("SELECT client_id FROM projects WHERE id=?", [$content['project_id']])) : 0;
    if (!$content || !client_access($contentClient)) json_out(['ok' => false, 'error' => 'Yetkisiz.']);
    update_row('contents', ['status' => $g('status')], 'id=?', [$id]);
    // Two-way sync: published → linked tasks get completed
    if ($g('status') === 'yayinlandi') {
        foreach (rows("SELECT id FROM tasks WHERE content_id=? AND status!='tamamlandi'", [$id]) as $bg2) {
            update_row('tasks', ['status' => 'tamamlandi', 'completion' => $now], 'id=?', [$bg2['id']]);
        }
    }
    json_out(['ok' => true, 'mesaj' => 'Durum güncellendi.']);

/* ==================== SOCIAL MEDIA TRACKING ==================== */
case 'social_account_add':
    require_permission('icerik_yonet');
    $clientId = (int)$g('client_id');
    $kadi = trim($g('username'));
    if (!$clientId || $kadi === '') json_out(['ok' => false, 'error' => 'Dosya ve kullanıcı adı gerekli.']);
    $url = trim($g('url'));
    if ($url && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    insert('social_accounts', [
        'client_id' => $clientId,
        'platform' => isset(PLATFORMS[$g('platform')]) ? $g('platform') : 'instagram',
        'username' => mb_substr($kadi, 0, 100), 'url' => $url ?: null, 'created' => $now,
    ]);
    json_out(['ok' => true, 'mesaj' => 'Sosyal medya hesabı eklendi.']);

case 'social_account_delete':
    require_permission('icerik_yonet');
    q("DELETE FROM social_metrics WHERE account_id=?", [(int)$g('id')]);
    q("DELETE FROM social_accounts WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Hesap ve metrik geçmişi silindi.']);

case 'social_metric_add':
    require_staff();
    $account = row("SELECT * FROM social_accounts WHERE id=?", [(int)$g('account_id')]);
    if (!$account) json_out(['ok' => false, 'error' => 'Hesap bulunamadı.']);
    $followers = (int)str_replace(['.', ' '], '', $g('followers'));
    if ($followers < 0) json_out(['ok' => false, 'error' => 'Takipçi sayısı geçersiz.']);
    $date = $g('date') ?: date('Y-m-d');
    q("INSERT INTO social_metrics (account_id, date, followers, post, engagement, entered_by, created) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE followers=VALUES(followers), post=VALUES(post), engagement=VALUES(engagement)",
        [$account['id'], $date, $followers,
         $g('post') !== '' ? (int)$g('post') : null,
         $g('engagement') !== '' ? (int)str_replace(['.', ' '], '', $g('engagement')) : null,
         $u['id'], $now]);
    json_out(['ok' => true, 'mesaj' => 'Metrik kaydedildi.']);

case 'social_metric_delete':
    require_permission('icerik_yonet');
    q("DELETE FROM social_metrics WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Kayıt silindi.']);

case 'content_delete':
    require_permission('icerik_yonet');
    q("DELETE FROM contents WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'İçerik silindi.']);

/* ==================== EVENTS / CALENDAR ==================== */
case 'event_save':
    require_permission('takvim_yonet');
    $data = [
        'project_id' => $g('project_id') ? (int)$g('project_id') : null,
        'client_id' => $g('client_id') ? (int)$g('client_id') : null,
        'title' => trim($g('title')), 'type' => $g('type', 'cekim'),
        'start' => $g('start'), 'end' => $g('end') ?: null,
        'place' => $g('place'), 'description' => $g('description'), 'participants' => $g('participants'),
        'online_link' => trim($g('online_link')) ?: null,
        'shopping_list' => trim($g('shopping_list')) ?: null,
        'needs_list' => trim($g('needs_list')) ?: null,
    ];
    if ($data['title'] === '' || !$data['start']) json_out(['ok' => false, 'error' => 'Başlık ve tarih gerekli.']);
    // In-system participants (for meetings)
    $participantIds = json_decode($g('participant_ids', ''), true);
    if ($g('id')) {
        $eventId = (int)$g('id');
        update_row('events', $data, 'id=?', [$eventId]);
        if (is_array($participantIds)) {
            $eskiler = array_column(rows("SELECT user_id FROM event_participants WHERE event_id=?", [$eventId]), 'user_id');
            q("DELETE FROM event_participants WHERE event_id=?", [$eventId]);
            foreach (array_unique(array_map('intval', $participantIds)) as $kid) {
                if (!$kid) continue;
                q("INSERT IGNORE INTO event_participants (event_id, user_id) VALUES (?,?)", [$eventId, $kid]);
                if (!in_array($kid, $eskiler)) notify($kid, '📅 Toplantıya davet edildiniz', $data['title'] . ' — ' . format_date($data['start'], true), 'meetings.php', 'gorev');
            }
        }
        json_out(['ok' => true, 'mesaj' => 'Etkinlik güncellendi.']);
    }
    $data['created_by'] = $u['id']; $data['created'] = $now;
    $eventId = insert('events', $data);
    if (is_array($participantIds)) {
        foreach (array_unique(array_map('intval', $participantIds)) as $kid) {
            if (!$kid) continue;
            q("INSERT IGNORE INTO event_participants (event_id, user_id) VALUES (?,?)", [$eventId, $kid]);
            notify($kid, '📅 Toplantıya davet edildiniz', $data['title'] . ' — ' . format_date($data['start'], true), 'meetings.php', 'gorev');
        }
    }
    // Check out the selected equipment for the shoot
    $equipmentIds = json_decode($g('equipment', '[]'), true) ?: [];
    $atlanabilen = [];
    foreach (array_unique(array_map('intval', $equipmentIds)) as $eid) {
        $ek = row("SELECT * FROM equipment WHERE id=?", [$eid]);
        if (!$ek) continue;
        if ($ek['status'] !== 'studyoda') { $atlanabilen[] = $ek['name']; continue; }
        q("INSERT IGNORE INTO event_equipment (event_id, equipment_id) VALUES (?,?)", [$eventId, $eid]);
        update_row('equipment', ['status' => 'cekimde', 'custody_event_id' => $eventId, 'custody_user_id' => $u['id']], 'id=?', [$eid]);
        log_equipment($eid, 'shoot_output', $data['title'], (int)$u['id'], $eventId);
    }
    $messageEk = $atlanabilen ? ' (müsait olmayanlar atlandı: ' . implode(', ', $atlanabilen) . ')' : '';
    json_out(['ok' => true, 'mesaj' => 'Etkinlik eklendi.' . $messageEk]);

case 'event_delete':
    require_staff();
    q("DELETE FROM events WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Etkinlik silindi.']);

/* ==================== MESSAGING ==================== */
case 'message_send':
    require_login();
    $channelId = (int)$g('channel_id');
    $message = trim($g('message'));
    if ($message === '') json_out(['ok' => false, 'error' => 'Mesaj boş.']);
    // Membership check
    if (!val("SELECT COUNT(*) FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]))
        json_out(['ok' => false, 'error' => 'Bu kanala erişiminiz yok.']);
    $id = insert('messages', ['channel_id' => $channelId, 'user_id' => $u['id'], 'mesaj' => $message, 'created' => $now]);
    update_row('channel_members', ['last_read' => $now], 'channel_id=? AND user_id=?', [$channelId, $u['id']]);
    // Notify other members
    foreach (rows("SELECT user_id FROM channel_members WHERE channel_id=? AND user_id!=?", [$channelId, $u['id']]) as $member) {
        notify($member['user_id'], $u['name'] . ' mesaj gönderdi', mb_substr($message, 0, 80), 'messages.php?channel=' . $channelId, 'mesaj', false);
    }
    // Also notify mentioned users (including email)
    notify_mentions($g('mention_ids', ''), $u['name'] . ' sizi bir sohbette etiketledi', mb_substr($message, 0, 90), 'messages.php?channel=' . $channelId);
    json_out(['ok' => true, 'id' => $id, 'created' => format_date($now, true)]);

case 'message_fetch':
    require_login();
    $channelId = (int)$g('channel_id');
    $lastId = (int)$g('last_id');
    $new = rows("SELECT m.*, u.name, u.color FROM messages m JOIN users u ON u.id=m.user_id WHERE m.channel_id=? AND m.id>? ORDER BY m.id", [$channelId, $lastId]);
    update_row('channel_members', ['last_read' => $now], 'channel_id=? AND user_id=?', [$channelId, $u['id']]);
    foreach ($new as &$m) { $m['mine'] = ($m['user_id'] == $u['id']); $m['time'] = date('H:i', strtotime($m['created'])); $m['initial'] = initials($m['name']); }
    json_out(['ok' => true, 'messages' => $new]);

case 'channel_create':
    require_permission('kanal_kur');
    $name = trim($g('name'));
    if ($name === '') json_out(['ok' => false, 'error' => 'Kanal adı gerekli.']);
    $channelId = insert('channels', ['name' => $name, 'type' => 'genel', 'created' => $now]);
    $members = json_decode($g('members', '[]'), true) ?: [];
    $members[] = $u['id'];
    foreach (array_unique($members) as $uid)
        q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channelId, (int)$uid]);
    json_out(['ok' => true, 'mesaj' => 'Kanal oluşturuldu.', 'redirect' => 'messages.php?channel=' . $channelId]);

case 'channel_member_add':
    require_staff();
    $channelId = (int)$g('channel_id');
    $targetId = (int)$g('user_id');
    $channel = row("SELECT * FROM channels WHERE id=?", [$channelId]);
    if (!$channel || $channel['type'] === 'ozel') json_out(['ok' => false, 'error' => 'Bu kanala üye eklenemez.']);
    if (!val("SELECT COUNT(*) FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]))
        json_out(['ok' => false, 'error' => 'Üyesi olmadığınız kanalı yönetemezsiniz.']);
    q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channelId, $targetId]);
    $target = row("SELECT name FROM users WHERE id=?", [$targetId]);
    notify($targetId, 'Bir sohbete eklendiniz', $channel['name'], 'messages.php?channel=' . $channelId, 'mesaj');
    log_activity('"' . $channel['name'] . '" kanalına ' . ($target['name'] ?? '') . ' kişisini ekledi');
    json_out(['ok' => true, 'mesaj' => 'Üye eklendi.']);

case 'channel_member_cikar':
    require_staff();
    $channelId = (int)$g('channel_id');
    $targetId = (int)$g('user_id');
    $channel = row("SELECT * FROM channels WHERE id=?", [$channelId]);
    if (!$channel || $channel['type'] === 'ozel') json_out(['ok' => false, 'error' => 'Bu kanaldan üye çıkarılamaz.']);
    if (!is_pm() && $targetId !== (int)$u['id'])
        json_out(['ok' => false, 'error' => 'Başkasını çıkarmak için PM/yönetici olmalısınız.']);
    q("DELETE FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $targetId]);
    json_out(['ok' => true, 'mesaj' => 'Üye çıkarıldı.']);

case 'channel_name':
    require_login();
    $channelId = (int)$g('channel_id');
    $channel = row("SELECT * FROM channels WHERE id=?", [$channelId]);
    if (!$channel || $channel['type'] === 'ozel') json_out(['ok' => false, 'error' => 'Bu sohbetin adı değiştirilemez.']);
    if (!val("SELECT COUNT(*) FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]))
        json_out(['ok' => false, 'error' => 'Bu kanalın üyesi değilsiniz.']);
    $name = mb_substr(trim($g('name')), 0, 120);
    if ($name === '') json_out(['ok' => false, 'error' => 'Kanal adı boş olamaz.']);
    update_row('channels', ['name' => $name], 'id=?', [$channelId]);
    log_activity('"' . $channel['name'] . '" kanalının adını "' . $name . '" yaptı');
    json_out(['ok' => true, 'mesaj' => 'Sohbet adı güncellendi.']);

case 'channel_icon':
    require_login();
    $channelId = (int)$g('channel_id');
    if (!val("SELECT COUNT(*) FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]))
        json_out(['ok' => false, 'error' => 'Bu kanalın üyesi değilsiniz.']);
    update_row('channels', ['icon' => mb_substr(trim($g('icon')), 0, 8) ?: null], 'id=?', [$channelId]);
    json_out(['ok' => true, 'mesaj' => 'Kanal simgesi güncellendi.']);

case 'channel_archive_toggle':
    require_login();
    $channelId = (int)$g('channel_id');
    $uyelik = row("SELECT * FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]);
    if (!$uyelik) json_out(['ok' => false, 'error' => 'Bu kanalın üyesi değilsiniz.']);
    $new = $uyelik['archive'] ? 0 : 1;
    update_row('channel_members', ['archive' => $new], 'channel_id=? AND user_id=?', [$channelId, $u['id']]);
    json_out(['ok' => true, 'mesaj' => $new ? 'Sohbet arşivlendi.' : 'Sohbet arşivden çıkarıldı.', 'redirect' => 'messages.php']);

case 'channel_delete':
    require_login();
    $channelId = (int)$g('channel_id');
    $channel = row("SELECT * FROM channels WHERE id=?", [$channelId]);
    if (!$channel) json_out(['ok' => false, 'error' => 'Kanal bulunamadı.']);
    $memberMi = val("SELECT COUNT(*) FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]);
    // A private (DM) chat can be deleted by a participant; other channels by PM/admin
    if ($channel['type'] === 'ozel' ? !$memberMi : !is_pm())
        json_out(['ok' => false, 'error' => 'Bu sohbeti silme yetkiniz yok.']);
    q("DELETE FROM messages WHERE channel_id=?", [$channelId]);
    q("DELETE FROM channel_members WHERE channel_id=?", [$channelId]);
    q("DELETE FROM channels WHERE id=?", [$channelId]);
    log_activity('"' . $channel['name'] . '" sohbetini sildi');
    json_out(['ok' => true, 'mesaj' => 'Sohbet silindi.', 'redirect' => 'messages.php']);

case 'dm_open':
    require_login();
    $targetId = (int)$g('user_id');
    if ($targetId === (int)$u['id']) json_out(['ok' => false, 'error' => 'Kendinizle sohbet açamazsınız.']);
    $target = row("SELECT * FROM users WHERE id=? AND is_active=1", [$targetId]);
    if (!$target) json_out(['ok' => false, 'error' => 'Kullanıcı bulunamadı.']);
    // Customers can only open DMs with staff
    if (is_customer() && $target['role'] === 'musteri') json_out(['ok' => false, 'error' => 'Bu kişiyle sohbet açılamaz.']);
    // Is there an existing private channel between these two people?
    $mevcut = row("SELECT k.id FROM channels k
        JOIN channel_members a ON a.channel_id=k.id AND a.user_id=?
        JOIN channel_members b ON b.channel_id=k.id AND b.user_id=?
        WHERE k.type='ozel' AND (SELECT COUNT(*) FROM channel_members x WHERE x.channel_id=k.id)=2", [$u['id'], $targetId]);
    if ($mevcut) json_out(['ok' => true, 'redirect' => 'messages.php?channel=' . $mevcut['id']]);
    $channelId = insert('channels', ['name' => 'DM', 'type' => 'ozel', 'created' => $now]);
    q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?),(?,?)", [$channelId, $u['id'], $channelId, $targetId]);
    json_out(['ok' => true, 'redirect' => 'messages.php?channel=' . $channelId]);

/* ==================== GLOBAL SEARCH ==================== */
case 'search':
    require_login();
    $q = trim($g('q'));
    if (mb_strlen($q) < 2) json_out(['ok' => true, 'results' => []]);
    $search = '%' . $q . '%';
    $results = [];
    if (is_staff()) {
        $results['Dosyalar'] = array_map(fn($r) => ['name' => $r['name'], 'bottom' => CLIENT_TYPES[$r['type']], 'link' => 'client.php?id=' . $r['id']],
            rows("SELECT id, name, type FROM clients WHERE name LIKE ? LIMIT 5", [$search]));
        $results['Projeler'] = array_map(fn($r) => ['name' => $r['name'], 'bottom' => PROJECT_TYPES[$r['type']], 'link' => 'project.php?id=' . $r['id']],
            rows("SELECT id, name, type FROM projects WHERE name LIKE ? LIMIT 5", [$search]));
        $results['Görevler'] = array_map(fn($r) => ['name' => $r['title'], 'bottom' => TASK_STATUSES[$r['status']], 'link' => 'task.php?id=' . $r['id']],
            rows("SELECT id, title, status FROM tasks WHERE title LIKE ? ORDER BY status!='tamamlandi' DESC LIMIT 6", [$search]));
        $results['İçerikler'] = array_map(fn($r) => ['name' => $r['title'], 'bottom' => format_date($r['date']), 'link' => 'content-calendar.php?month=' . date('n', strtotime($r['date'])) . '&year=' . date('Y', strtotime($r['date']))],
            rows("SELECT id, title, date FROM contents WHERE title LIKE ? LIMIT 4", [$search]));
        $results['Talepler'] = array_map(fn($r) => ['name' => $r['title'], 'bottom' => REQUEST_STATUSES[$r['status']], 'link' => 'request.php?id=' . $r['id']],
            rows("SELECT id, title, status FROM requests WHERE title LIKE ? LIMIT 4", [$search]));
    } else {
        [$in, $p] = in_clause(customer_client_ids());
        $results['Projeler'] = array_map(fn($r) => ['name' => $r['name'], 'bottom' => PROJECT_TYPES[$r['type']], 'link' => 'project.php?id=' . $r['id']],
            rows("SELECT id, name, type FROM projects WHERE client_id IN $in AND name LIKE ? LIMIT 6", array_merge($p, [$search])));
        $results['Talepler'] = array_map(fn($r) => ['name' => $r['title'], 'bottom' => REQUEST_STATUSES[$r['status']], 'link' => 'request.php?id=' . $r['id']],
            rows("SELECT id, title, status FROM requests WHERE sender_id=? AND title LIKE ? LIMIT 5", [$u['id'], $search]));
    }
    json_out(['ok' => true, 'results' => $results]);

/* ==================== EQUIPMENT / INVENTORY ==================== */
case 'equipment_save':
    require_permission('ekipman_yonet');
    $data = [
        'code' => mb_substr(trim($g('code')), 0, 20) ?: null,
        'name' => trim($g('name')),
        'category' => isset(EQUIPMENT_CATEGORIES[$g('category')]) ? $g('category') : 'diger',
        'purchase_date' => $g('purchase_date') ?: null,
        'price' => (float)str_replace(',', '.', $g('price', '0')),
        'description' => $g('description'),
    ];
    if ($data['name'] === '') json_out(['ok' => false, 'error' => 'Ekipman adı gerekli.']);
    $photo = file_upload('photo');
    if ($photo) {
        if (!in_array($photo['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp'])) json_out(['ok' => false, 'error' => 'Fotoğraf için görsel dosyası seçin.']);
        $data['photo'] = $photo['path'];
    }
    if ($g('id')) {
        update_row('equipment', $data, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Ekipman güncellendi.']);
    }
    $data['created'] = $now;
    if ($data['category'] === 'sd_kart') $data['sd_status'] = 'bos';
    $eid = insert('equipment', $data);
    log_equipment($eid, 'eklendi', $data['name']);
    json_out(['ok' => true, 'mesaj' => 'Ekipman envantere eklendi.']);

case 'equipment_delete':
    require_permission('ekipman_yonet');
    $ek = row("SELECT * FROM equipment WHERE id=?", [(int)$g('id')]);
    if ($ek && $ek['status'] !== 'studyoda' && $ek['status'] !== 'arizali') json_out(['ok' => false, 'error' => 'Zimmette/çekimde olan ekipman silinemez. Önce iade alın.']);
    q("DELETE FROM equipment_logs WHERE equipment_id=?", [(int)$g('id')]);
    q("DELETE FROM event_equipment WHERE equipment_id=?", [(int)$g('id')]);
    q("DELETE FROM equipment WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Ekipman silindi.']);

case 'equipment_custody':
    require_staff();
    $ek = row("SELECT * FROM equipment WHERE id=?", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'error' => 'Ekipman bulunamadı.']);
    if (!in_array($ek['status'], ['studyoda'])) json_out(['ok' => false, 'error' => 'Bu ekipman şu an ' . mb_strtolower(EKIPMAN_DURUMLARI[$ek['status']]) . ' — zimmet verilemez.']);
    $target = (int)($g('user_id') ?: $u['id']);
    if ($target !== (int)$u['id'] && !permission('ekipman_yonet')) json_out(['ok' => false, 'error' => 'Başkası adına zimmet için ekipman yönetim yetkisi gerekir.']);
    $targetName = val("SELECT name FROM users WHERE id=?", [$target]);
    update_row('equipment', ['status' => 'zimmette', 'custody_user_id' => $target, 'custody_event_id' => null], 'id=?', [(int)$g('id')]);
    log_equipment((int)$g('id'), 'custody', trim($g('description')), $target);
    if ($target !== (int)$u['id']) notify($target, 'Ekipman zimmetlendi', ($ek['code'] ? $ek['code'] . ' — ' : '') . $ek['name'], 'equipment.php', 'gorev');
    json_out(['ok' => true, 'mesaj' => $ek['name'] . ' → ' . $targetName . ' zimmetine verildi.']);

case 'equipment_return':
    require_staff();
    $ek = row("SELECT * FROM equipment WHERE id=?", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'error' => 'Ekipman bulunamadı.']);
    if (!in_array($ek['status'], ['zimmette', 'cekimde'])) json_out(['ok' => false, 'error' => 'Bu ekipman zaten stüdyoda.']);
    if ($ek['custody_user_id'] != $u['id'] && !permission('ekipman_yonet')) json_out(['ok' => false, 'error' => 'Yalnızca kendi zimmetinizi iade edebilirsiniz.']);
    update_row('equipment', ['status' => 'studyoda', 'custody_user_id' => null, 'custody_event_id' => null], 'id=?', [(int)$g('id')]);
    log_equipment((int)$g('id'), $ek['status'] === 'cekimde' ? 'cekimden_dondu' : 'return', trim($g('description')), $ek['custody_user_id'] ? (int)$ek['custody_user_id'] : null, $ek['custody_event_id'] ? (int)$ek['custody_event_id'] : null);
    json_out(['ok' => true, 'mesaj' => $ek['name'] . ' stüdyoya iade alındı.']);

case 'equipment_fault':
    require_staff();
    $ek = row("SELECT * FROM equipment WHERE id=?", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'error' => 'Ekipman bulunamadı.']);
    $newStatus = in_array($g('status'), ['arizali', 'bakimda', 'studyoda']) ? $g('status') : 'arizali';
    update_row('equipment', [
        'status' => $newStatus,
        'fault_note' => $newStatus === 'studyoda' ? null : trim($g('not')),
        'custody_user_id' => null, 'custody_event_id' => null,
    ], 'id=?', [(int)$g('id')]);
    log_equipment((int)$g('id'), $newStatus === 'studyoda' ? 'duzeltildi' : ($newStatus === 'bakimda' ? 'bakim' : 'fault'), trim($g('not')));
    json_out(['ok' => true, 'mesaj' => 'Ekipman durumu güncellendi.']);

case 'sd_update_row':
    require_staff();
    $ek = row("SELECT * FROM equipment WHERE id=? AND category='sd_kart'", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'error' => 'SD kart bulunamadı.']);
    $operation = $g('operation'); // dolu | aktarildi | bosalt
    if ($operation === 'dolu') {
        $content = trim($g('content'));
        if ($content === '') json_out(['ok' => false, 'error' => 'Hangi çekim/içerik olduğunu yazın.']);
        update_row('equipment', ['sd_status' => 'dolu', 'sd_content' => $content, 'sd_drive_link' => null], 'id=?', [$ek['id']]);
        log_equipment($ek['id'], 'sd_full', $content);
        json_out(['ok' => true, 'mesaj' => 'Kart dolu olarak işaretlendi.']);
    }
    if ($operation === 'aktarildi') {
        if ($ek['sd_status'] !== 'dolu') json_out(['ok' => false, 'error' => 'Önce kartı "dolu" olarak işaretleyin.']);
        $link = trim($g('drive_link'));
        update_row('equipment', ['sd_status' => 'aktarildi', 'sd_drive_link' => $link ?: null], 'id=?', [$ek['id']]);
        log_equipment($ek['id'], 'sd_aktarildi', trim(($ek['sd_content'] ?: '') . ($link ? ' → ' . $link : '')));
        json_out(['ok' => true, 'mesaj' => "Drive'a aktarıldı olarak işaretlendi."]);
    }
    if ($operation === 'bosalt') {
        if ($ek['sd_status'] === 'dolu') json_out(['ok' => false, 'error' => "Dikkat: içerik henüz Drive'a aktarılmadı! Önce aktarımı işaretleyin."]);
        // Content + link are stored in the history log, the card is reset
        log_equipment($ek['id'], 'sd_bosaltildi', trim(($ek['sd_content'] ?: '') . ($ek['sd_drive_link'] ? ' (arşiv: ' . $ek['sd_drive_link'] . ')' : '')));
        update_row('equipment', ['sd_status' => 'bos', 'sd_content' => null, 'sd_drive_link' => null], 'id=?', [$ek['id']]);
        json_out(['ok' => true, 'mesaj' => 'Kart boşaltıldı — tekrar kullanıma hazır.']);
    }
    json_out(['ok' => false, 'error' => 'Geçersiz işlem.']);

case 'event_equipment_return':
    require_staff();
    $eventId = (int)$g('event_id');
    $adet = 0;
    foreach (rows("SELECT e.* FROM equipment e JOIN event_equipment ee ON ee.equipment_id=e.id WHERE ee.event_id=? AND e.status='cekimde'", [$eventId]) as $ek) {
        update_row('equipment', ['status' => 'studyoda', 'custody_user_id' => null, 'custody_event_id' => null], 'id=?', [$ek['id']]);
        log_equipment((int)$ek['id'], 'cekimden_dondu', '', null, $eventId);
        $adet++;
    }
    json_out(['ok' => true, 'mesaj' => $adet . ' ekipman stüdyoya iade alındı.']);

/* ==================== CUSTOMER RATING ==================== */
case 'rating_give':
    require_login();
    if (!is_customer()) json_out(['ok' => false, 'error' => 'Puanlamayı yalnızca müşteriler yapabilir.']);
    $refType = $g('ref_type') === 'approval' ? 'approval' : 'task';
    $refId = (int)$g('ref_id');
    $rating = max(1, min(5, (int)$g('rating')));
    // Access + status check
    if ($refType === 'task') {
        $target = row("SELECT id, title, project_id, status FROM tasks WHERE id=?", [$refId]);
        if (!$target || $target['status'] !== 'tamamlandi') json_out(['ok' => false, 'error' => 'Yalnızca tamamlanan işler puanlanabilir.']);
    } else {
        $target = row("SELECT id, title, project_id, status FROM approvals WHERE id=?", [$refId]);
        if (!$target || $target['status'] !== 'onaylandi') json_out(['ok' => false, 'error' => 'Yalnızca onaylanan işler puanlanabilir.']);
    }
    if (!project_access((int)$target['project_id'])) json_out(['ok' => false, 'error' => 'Bu işe erişiminiz yok.']);
    $comment_box = mb_substr(trim($g('comment_box')), 0, 500) ?: null;
    q("INSERT INTO ratings (ref_type, ref_id, project_id, user_id, rating, comment_box, created) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment_box=VALUES(comment_box)", [$refType, $refId, $target['project_id'], $u['id'], $rating, $comment_box, $now]);
    // Notify the PM on a low rating
    if ($rating <= 2) {
        $pmId = val("SELECT pm_id FROM projects WHERE id=?", [$target['project_id']]);
        $alicilar = $pmId ? [(int)$pmId] : array_column(rows("SELECT id FROM users WHERE role IN ('yonetici','pm') AND is_active=1"), 'id');
        foreach ($alicilar as $aid)
            notify((int)$aid, '⚠️ Düşük müşteri puanı: ' . $rating . '★', $target['title'] . ($comment_box ? ' — "' . $comment_box . '"' : ''), 'project.php?id=' . $target['project_id'], 'onay');
    }
    json_out(['ok' => true, 'mesaj' => 'Değerlendirmeniz kaydedildi, teşekkürler! ' . str_repeat('★', $rating)]);

/* ==================== APPOINTMENTS ==================== */
case 'appointment_create':
    require_login();
    if (!is_customer()) json_out(['ok' => false, 'error' => 'Randevu talebini müşteriler oluşturur.']);
    $topic = trim($g('topic'));
    $date = $g('date');
    if ($topic === '' || !$date) json_out(['ok' => false, 'error' => 'Konu ve tarih gerekli.']);
    if (strtotime($date) < time()) json_out(['ok' => false, 'error' => 'Geçmiş bir tarih seçilemez.']);
    $clientId = (int)$g('client_id');
    if ($clientId && !client_access($clientId)) json_out(['ok' => false, 'error' => 'Bu dosyaya erişiminiz yok.']);
    $id = insert('appointments', [
        'customer_id' => $u['id'], 'client_id' => $clientId ?: null, 'topic' => $topic,
        'date' => $date, 'online_request' => (int)(bool)$g('online_request'),
        'notes' => mb_substr(trim($g('notes')), 0, 500) ?: null, 'status' => 'bekliyor', 'created' => $now,
    ]);
    foreach (rows("SELECT id FROM users WHERE role IN ('yonetici','pm') AND is_active=1") as $pm)
        notify((int)$pm['id'], '📆 Yeni randevu talebi', $u['name'] . ': ' . $topic . ' — ' . format_date($date, true), 'appointments.php', 'talep');
    json_out(['ok' => true, 'mesaj' => 'Randevu talebiniz iletildi. Onaylanınca haber verilecek.']);

case 'appointment_respond':
    require_pm();
    $r = row("SELECT * FROM appointments WHERE id=?", [(int)$g('id')]);
    if (!$r) json_out(['ok' => false, 'error' => 'Randevu bulunamadı.']);
    $operation = $g('operation'); // onayla | alternatif | reddet
    if ($operation === 'approve') {
        $link = trim($g('online_link'));
        // Create a meeting: customer + responding PM as participants
        $eventId = insert('events', [
            'client_id' => $r['client_id'], 'title' => 'Randevu: ' . $r['topic'], 'type' => 'toplanti',
            'start' => $r['date'], 'online_link' => $link ?: null,
            'description' => $r['notes'], 'created_by' => $u['id'], 'created' => $now,
        ]);
        q("INSERT IGNORE INTO event_participants (event_id, user_id) VALUES (?,?),(?,?)", [$eventId, $r['customer_id'], $eventId, $u['id']]);
        update_row('appointments', ['status' => 'onaylandi', 'online_link' => $link ?: null, 'event_id' => $eventId, 'reply_note' => trim($g('not')) ?: null], 'id=?', [$r['id']]);
        notify((int)$r['customer_id'], '✅ Randevunuz onaylandı', $r['topic'] . ' — ' . format_date($r['date'], true) . ($link ? ' (online)' : ''), 'appointments.php', 'talep');
        json_out(['ok' => true, 'mesaj' => 'Randevu onaylandı ve toplantı takvimine eklendi.']);
    }
    if ($operation === 'alternative') {
        $new = $g('alternative_date');
        if (!$new) json_out(['ok' => false, 'error' => 'Alternatif tarih seçin.']);
        update_row('appointments', ['status' => 'alternatif', 'alternative_date' => $new, 'reply_note' => trim($g('not')) ?: null], 'id=?', [$r['id']]);
        notify((int)$r['customer_id'], '🔁 Randevu için farklı saat önerildi', $r['topic'] . ' → ' . format_date($new, true), 'appointments.php', 'talep');
        json_out(['ok' => true, 'mesaj' => 'Alternatif saat önerildi.']);
    }
    if ($operation === 'reject') {
        update_row('appointments', ['status' => 'reddedildi', 'reply_note' => trim($g('not')) ?: null], 'id=?', [$r['id']]);
        notify((int)$r['customer_id'], 'Randevu talebiniz yanıtlandı', $r['topic'] . ' — uygun değil' . ($g('not') ? ': ' . $g('not') : ''), 'appointments.php', 'talep');
        json_out(['ok' => true, 'mesaj' => 'Talep yanıtlandı.']);
    }
    json_out(['ok' => false, 'error' => 'Geçersiz işlem.']);

case 'appointment_accept':
    // The customer accepts the proposed alternative time
    require_login();
    $r = row("SELECT * FROM appointments WHERE id=? AND customer_id=? AND status='alternatif'", [(int)$g('id'), $u['id']]);
    if (!$r || !$r['alternative_date']) json_out(['ok' => false, 'error' => 'Bekleyen öneri bulunamadı.']);
    update_row('appointments', ['date' => $r['alternative_date'], 'alternative_date' => null, 'status' => 'bekliyor'], 'id=?', [$r['id']]);
    foreach (rows("SELECT id FROM users WHERE role IN ('yonetici','pm') AND is_active=1") as $pm)
        notify((int)$pm['id'], '📆 Müşteri önerilen saati kabul etti', $r['topic'] . ' — ' . format_date($r['alternative_date'], true) . ' (onay bekliyor)', 'appointments.php', 'talep');
    json_out(['ok' => true, 'mesaj' => 'Yeni saat kabul edildi; ajans onayı bekleniyor.']);

/* ==================== RELEASE NOTES ==================== */
case 'version_close':
    require_login();
    update_row('users', ['seen_version' => APP_VERSION], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

/* ==================== ANNOUNCEMENTS ==================== */
case 'announcement_save':
    require_permission('duyuru_yayinla');
    $title = trim($g('title'));
    if ($title === '') json_out(['ok' => false, 'error' => 'Duyuru başlığı gerekli.']);
    $is_important = (int)(bool)$g('is_important');
    $id = insert('duyurular', ['title' => $title, 'text' => $g('text'), 'is_important' => $is_important, 'created_by' => $u['id'], 'created' => $now]);
    if ($is_important) {
        foreach (rows("SELECT id FROM users WHERE is_active=1 AND role!='musteri' AND id!=?", [$u['id']]) as $take)
            notify((int)$take['id'], '📢 Duyuru: ' . $title, mb_substr($g('text'), 0, 90), 'index.php', 'gorev');
    }
    log_activity('"' . $title . '" duyurusunu yayınladı');
    json_out(['ok' => true, 'mesaj' => 'Duyuru yayınlandı.']);

case 'announcement_read':
    require_login();
    q("INSERT IGNORE INTO announcement_readers (announcement_id, user_id) VALUES (?,?)", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'announcement_delete':
    require_pm();
    q("DELETE FROM announcements WHERE id=?", [(int)$g('id')]);
    q("DELETE FROM announcement_readers WHERE announcement_id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Duyuru silindi.']);

/* ==================== CONTRACTS ==================== */
case 'contract_save':
    require_permission('dosya_yonet');
    $data = [
        'client_id' => (int)$g('client_id'), 'title' => trim($g('title')),
        'start' => $g('start') ?: null, 'end' => $g('end') ?: null,
        'amount' => (float)str_replace(',', '.', $g('amount', '0')), 'description' => $g('description'),
        'is_reminded' => 0,
    ];
    if ($data['title'] === '' || !$data['client_id']) json_out(['ok' => false, 'error' => 'Sözleşme başlığı gerekli.']);
    $ek = file_upload('dosya');
    if ($ek) {
        $data['archive_id'] = insert('archive', [
            'client_id' => $data['client_id'], 'name' => $ek['name'], 'file_path' => $ek['path'],
            'size' => $ek['size'], 'extension' => $ek['extension'], 'uploader_id' => $u['id'], 'created' => $now,
        ]);
    }
    if ($g('id')) {
        update_row('contracts', $data, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Sözleşme güncellendi.']);
    }
    $data['created'] = $now;
    insert('contracts', $data);
    json_out(['ok' => true, 'mesaj' => 'Sözleşme kaydedildi.']);

case 'contract_delete':
    require_permission('dosya_yonet');
    q("DELETE FROM contracts WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Sözleşme silindi.']);

/* ==================== PERSONAL SPACE ==================== */
case 'not_save':
    require_login();
    $text = trim($g('text'));
    $title = mb_substr(trim($g('title')), 0, 150);
    if ($text === '' && $title === '') json_out(['ok' => false, 'error' => 'Not boş olamaz.']);
    $color = in_array($g('color'), ['default', 'sari', 'yesil', 'mavi', 'pembe']) ? $g('color') : 'default';
    if ($g('id')) {
        $not = row("SELECT * FROM personal_notes WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
        if (!$not) json_out(['ok' => false, 'error' => 'Not bulunamadı.']);
        update_row('personal_notes', ['title' => $title ?: null, 'text' => $text, 'color' => $color, 'update' => $now], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Not güncellendi.']);
    }
    insert('personal_notes', ['user_id' => $u['id'], 'title' => $title ?: null, 'text' => $text, 'color' => $color, 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Not eklendi.']);

case 'not_delete':
    require_login();
    q("DELETE FROM personal_notes WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Not silindi.']);

case 'personal_is_add':
    require_login();
    $name = trim($g('name'));
    if ($name === '') json_out(['ok' => false, 'error' => 'Boş madde eklenemez.']);
    $sort_order = (int)val("SELECT COALESCE(MAX(sort_order),0)+1 FROM personal_todos WHERE user_id=?", [$u['id']]);
    $id = insert('personal_todos', ['user_id' => $u['id'], 'name' => mb_substr($name, 0, 255), 'is_done' => 0, 'sort_order' => $sort_order]);
    json_out(['ok' => true, 'id' => $id, 'name' => $name]);

case 'personal_is_toggle':
    require_login();
    q("UPDATE personal_todos SET is_done=1-is_done WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'personal_is_delete':
    require_login();
    q("DELETE FROM personal_todos WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'link_add':
    require_login();
    $name = trim($g('name')); $url = trim($g('url'));
    if ($name === '' || $url === '') json_out(['ok' => false, 'error' => 'Ad ve adres gerekli.']);
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    insert('personal_links', ['user_id' => $u['id'], 'name' => mb_substr($name, 0, 150), 'url' => mb_substr($url, 0, 500)]);
    json_out(['ok' => true, 'mesaj' => 'Yer imi eklendi.']);

case 'link_delete':
    require_login();
    q("DELETE FROM personal_links WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Yer imi silindi.']);

case 'scratchpad_save':
    require_login();
    update_row('users', ['scratchpad' => mb_substr($g('text'), 0, 100000)], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

/* ==================== PREFERENCES & WIDGETS ==================== */
case 'preference_save':
    require_login();
    $preferences = [];
    foreach (array_keys(NOTIFICATION_CATEGORIES) as $k) $preferences[$k] = (int)(bool)$g('t_' . $k);
    $preferences['email'] = (int)(bool)$g('t_email');
    $preferences['only_own_steps'] = (int)(bool)$g('t_only_step');
    update_row('users', ['notification_preferences' => json_encode($preferences)], 'id=?', [$u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Bildirim tercihleri kaydedildi.']);

case 'widget_save':
    require_login();
    $selected = json_decode($g('widgets', '[]'), true) ?: [];
    update_row('users', ['widgets' => json_encode(array_values($selected))], 'id=?', [$u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Panel görünümü kaydedildi.']);

/* ==================== REQUESTS ==================== */
case 'request_send':
    require_login();
    $templateId = (int)$g('template_id');
    $template = row("SELECT * FROM form_templates WHERE id=? AND is_active=1", [$templateId]);
    if (!$template) json_out(['ok' => false, 'error' => 'Form bulunamadı.']);
    $fields = rows("SELECT * FROM form_fields WHERE template_id=? ORDER BY sort_order", [$templateId]);
    $title = $template['name'];
    $clientId = is_customer() ? (customer_client_ids()[0] ?? null) : ($g('client_id') ? (int)$g('client_id') : null);
    if (is_customer() && $g('project_id')) $clientId = (int)val("SELECT client_id FROM projects WHERE id=?", [(int)$g('project_id')]) ?: $clientId;
    $requestId = insert('requests', [
        'template_id' => $templateId, 'client_id' => $clientId,
        'project_id' => $g('project_id') ? (int)$g('project_id') : null,
        'sender_id' => $u['id'], 'title' => $title, 'status' => 'yeni', 'created' => $now,
    ]);
    foreach ($fields as $field) {
        $setting_value = $g('field_' . $field['id']);
        if ($field['type'] === 'dosya') {
            $tYuk = file_upload('field_' . $field['id']);
            if ($tYuk) {
                insert('archive', ['client_id' => $clientId ?: null, 'name' => $tYuk['name'], 'file_path' => $tYuk['path'], 'size' => $tYuk['size'], 'extension' => $tYuk['extension'], 'uploader_id' => $u['id'], 'created' => $now]);
                $setting_value = $tYuk['path'];
            }
            if ($field['is_required'] && !$tYuk) { q("DELETE FROM requests WHERE id=?", [$requestId]); json_out(['ok' => false, 'error' => '"' . $field['tag'] . '" için dosya yükleyin.']); }
            insert('request_replies', ['request_id' => $requestId, 'field_id' => $field['id'], 'setting_value' => $setting_value]);
            continue;
        }
        if ($field['is_required'] && trim((string)$setting_value) === '') {
            q("DELETE FROM requests WHERE id=?", [$requestId]);
            json_out(['ok' => false, 'error' => '"' . $field['tag'] . '" alanı zorunlu.']);
        }
        insert('request_replies', ['request_id' => $requestId, 'field_id' => $field['id'], 'setting_value' => $setting_value]);
    }
    // Notify PMs
    foreach (rows("SELECT id FROM users WHERE role IN ('yonetici','pm') AND is_active=1") as $pm)
        notify($pm['id'], 'Yeni talep: ' . $title, $u['name'] . ' bir talep gönderdi', 'request.php?id=' . $requestId, 'talep');
    json_out(['ok' => true, 'mesaj' => 'Talebiniz iletildi. En kısa sürede dönüş yapılacak.']);

case 'request_status':
    require_permission('talep_yonet');
    $id = (int)$g('id');
    update_row('requests', ['status' => $g('status'), 'assignee_id' => $g('assignee_id') ? (int)$g('assignee_id') : null], 'id=?', [$id]);
    json_out(['ok' => true, 'mesaj' => 'Talep güncellendi.']);

case 'request_to_task':
    require_permission('talep_yonet');
    $id = (int)$g('id');
    $request = row("SELECT * FROM requests WHERE id=?", [$id]);
    if (!$request || !$request['project_id']) json_out(['ok' => false, 'error' => 'Talebe önce proje atayın.']);
    $taskId = insert('tasks', [
        'project_id' => $request['project_id'], 'title' => $request['title'],
        'description' => 'Talep #' . $id . ' üzerinden oluşturuldu.',
        'assignee_id' => $request['assignee_id'], 'created_by' => $u['id'],
        'priority' => 'normal', 'status' => 'yapilacak', 'created' => $now,
    ]);
    update_row('requests', ['status' => 'gorev_olusturuldu', 'task_id' => $taskId], 'id=?', [$id]);
    notify($request['sender_id'], 'Talebiniz işleme alındı', $request['title'], 'request.php?id=' . $id, 'talep');
    json_out(['ok' => true, 'mesaj' => 'Göreve dönüştürüldü.', 'redirect' => 'task.php?id=' . $taskId]);

case 'request_project':
    require_pm();
    update_row('requests', ['project_id' => (int)$g('project_id')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Proje atandı.']);

/* ==================== ARCHIVE ==================== */
case 'archive_upload':
    require_login();
    $yuklenen = file_upload('dosya');
    if (!$yuklenen) json_out(['ok' => false, 'error' => 'Dosya yüklenemedi. Boyut (max 50MB) veya tür uygun değil.']);
    $projectId = $g('project_id') ? (int)$g('project_id') : null;
    if ($projectId && !project_access($projectId)) json_out(['ok' => false, 'error' => 'Yetkisiz.']);
    insert('archive', [
        'client_id' => $g('client_id') ? (int)$g('client_id') : null, 'project_id' => $projectId,
        'task_id' => $g('task_id') ? (int)$g('task_id') : null,
        'name' => $yuklenen['name'], 'file_path' => $yuklenen['path'], 'size' => $yuklenen['size'],
        'extension' => $yuklenen['extension'], 'uploader_id' => $u['id'], 'created' => $now,
    ]);
    json_out(['ok' => true, 'mesaj' => 'Dosya yüklendi.']);

case 'archive_link_add':
    require_staff();
    $lAd = mb_substr(trim($g('name')), 0, 200) ?: 'Drive bağlantısı';
    $lUrl = trim($g('url'));
    if ($lUrl === '') json_out(['ok' => false, 'error' => 'Link gerekli.']);
    if (!preg_match('#^https?://#i', $lUrl)) $lUrl = 'https://' . $lUrl;
    insert('archive', [
        'client_id' => $g('client_id') ? (int)$g('client_id') : null,
        'project_id' => $g('project_id') ? (int)$g('project_id') : null,
        'task_id' => $g('task_id') ? (int)$g('task_id') : null,
        'name' => $lAd, 'file_path' => '', 'size' => 0, 'extension' => 'link',
        'url' => mb_substr($lUrl, 0, 500), 'uploader_id' => $u['id'], 'created' => $now,
    ]);
    json_out(['ok' => true, 'mesaj' => 'Bağlantı eklendi.']);

case 'arsiv_sil':
    require_permission('arsiv_sil');
    $a = row("SELECT * FROM archive WHERE id=?", [(int)$g('id')]);
    if ($a) {
        @unlink(ROOT . '/uploads/' . $a['file_path']);
        q("DELETE FROM archive WHERE id=?", [$a['id']]);
    }
    json_out(['ok' => true, 'mesaj' => 'Dosya silindi.']);

/* ==================== FINANCE ==================== */
case 'payment_save':
    require_permission('finans');
    $data = [
        'project_id' => (int)$g('project_id'), 'type' => $g('type', 'fatura'), 'title' => trim($g('title')),
        'amount' => (float)str_replace(',', '.', $g('amount', '0')), 'date' => $g('date') ?: date('Y-m-d'),
        'status' => $g('status', 'bekliyor'), 'description' => $g('description'),
    ];
    if ($data['title'] === '') json_out(['ok' => false, 'error' => 'Başlık gerekli.']);
    if ($g('id')) {
        update_row('payments', $data, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Kayıt güncellendi.']);
    }
    $data['created'] = $now;
    insert('payments', $data);
    json_out(['ok' => true, 'mesaj' => 'Finans kaydı eklendi.']);

case 'payment_status':
    require_permission('finans');
    update_row('payments', ['status' => $g('status')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Durum güncellendi.']);

case 'payment_delete':
    require_permission('finans');
    q("DELETE FROM payments WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Kayıt silindi.']);

/* ==================== EXPENSES ==================== */
case 'expense_save':
    require_permission('finans');
    $data = [
        'type' => isset(EXPENSE_TYPES[$g('type')]) ? $g('type') : 'diger',
        'title' => trim($g('title')),
        'amount' => (float)str_replace(',', '.', $g('amount', '0')),
        'date' => $g('date') ?: date('Y-m-d'),
        'status' => $g('status') === 'odendi' ? 'odendi' : 'bekliyor',
        'repeat' => $g('repeat') === 'aylik' ? 'aylik' : 'yok',
        'description' => $g('description'),
    ];
    if ($data['title'] === '') json_out(['ok' => false, 'error' => 'Gider başlığı gerekli.']);
    if ($g('id')) {
        update_row('expenses', $data, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Gider güncellendi.']);
    }
    $data['created'] = $now;
    insert('expenses', $data);
    json_out(['ok' => true, 'mesaj' => 'Gider eklendi.']);

case 'expense_status':
    require_permission('finans');
    update_row('expenses', ['status' => $g('status') === 'odendi' ? 'odendi' : 'bekliyor'], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Durum güncellendi.']);

case 'expense_delete':
    require_permission('finans');
    q("DELETE FROM expenses WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Gider silindi.']);

/* ==================== QUOTE & INVOICE DOCUMENTS ==================== */
case 'document_save':
    require_permission('belge_olustur');
    $type = $g('type') === 'fatura' ? 'fatura' : 'teklif';
    $items = json_decode($g('items', '[]'), true) ?: [];
    $items = array_values(array_filter(array_map(fn($k) => [
        'name' => mb_substr(trim($k['name'] ?? ''), 0, 200),
        'adet' => max(1, (float)str_replace(',', '.', $k['adet'] ?? 1)),
        'price' => (float)str_replace(',', '.', $k['price'] ?? 0),
    ], $items), fn($k) => $k['name'] !== ''));
    $title = trim($g('title'));
    if ($title === '' || !$items) json_out(['ok' => false, 'error' => 'Başlık ve en az bir kalem gerekli.']);
    if ($g('id')) {
        update_row('documents', ['title' => $title, 'client_id' => $g('client_id') ? (int)$g('client_id') : null,
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'vat_rate' => max(0, min(50, (int)$g('vat_rate', 20))),
            'valid_until' => $g('valid_until') ?: null, 'notes' => $g('notes')], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Belge güncellendi.']);
    }
    // Numbering: TKF-2026-001 / FTR-2026-001
    $onek = $type === 'fatura' ? 'FTR' : 'TKF';
    $counterKey = 'document_counter_' . $type . '_' . date('Y');
    $counter = (int)val("SELECT setting_value FROM settings WHERE setting_key=?", [$counterKey]) + 1;
    q("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?", [$counterKey, $counter, $counter]);
    $doc_no = $onek . '-' . date('Y') . '-' . str_pad($counter, 3, '0', STR_PAD_LEFT);
    $bid = insert('documents', [
        'type' => $type, 'doc_no' => $doc_no, 'client_id' => $g('client_id') ? (int)$g('client_id') : null,
        'title' => $title, 'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
        'vat_rate' => max(0, min(50, (int)$g('vat_rate', 20))), 'valid_until' => $g('valid_until') ?: null,
        'notes' => $g('notes'), 'created_by' => $u['id'], 'created' => $now,
    ]);
    json_out(['ok' => true, 'mesaj' => $doc_no . ' oluşturuldu.', 'redirect' => 'document.php?id=' . $bid]);

case 'document_status':
    require_permission('finans');
    $b = row("SELECT * FROM documents WHERE id=?", [(int)$g('id')]);
    if (!$b) json_out(['ok' => false, 'error' => 'Belge bulunamadı.']);
    $status = in_array($g('status'), ['taslak', 'gonderildi', 'onaylandi', 'reddedildi']) ? $g('status') : 'taslak';
    update_row('documents', ['status' => $status], 'id=?', [$b['id']]);
    // If the quote was approved: suggest/create an income (invoice) record on the file's first active project
    if ($status === 'onaylandi' && $b['type'] === 'teklif' && $b['client_id']) {
        $projectId = val("SELECT id FROM projects WHERE client_id=? AND status='aktif' ORDER BY id LIMIT 1", [$b['client_id']]);
        if ($projectId) {
            $items = json_decode($b['items'], true) ?: [];
            $searchTotal = array_sum(array_map(fn($k) => $k['adet'] * $k['price'], $items));
            $total = $searchTotal * (1 + $b['vat_rate'] / 100);
            insert('payments', ['project_id' => (int)$projectId, 'type' => 'fatura', 'title' => $b['doc_no'] . ' — ' . $b['title'],
                'amount' => round($total, 2), 'date' => date('Y-m-d'), 'status' => 'bekliyor',
                'description' => 'Onaylanan tekliften otomatik oluşturuldu', 'created' => $now]);
            json_out(['ok' => true, 'mesaj' => 'Teklif onaylandı — gelir kaydı (fatura) oluşturuldu.']);
        }
    }
    json_out(['ok' => true, 'mesaj' => 'Belge durumu güncellendi.']);

case 'document_delete':
    require_permission('finans');
    q("DELETE FROM documents WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Belge silindi.', 'redirect' => 'finance.php#documents']);

case 'budget_save':
    require_permission('finans');
    $target = (float)str_replace(['.', ','], ['', '.'], $g('target', '0'));
    q("INSERT INTO settings (setting_key, setting_value) VALUES ('butce_hedef', ?) ON DUPLICATE KEY UPDATE setting_value=?", [$target, $target]);
    json_out(['ok' => true, 'mesaj' => 'Aylık gelir hedefi kaydedildi.']);

/* ==================== USERS (admin) ==================== */
case 'user_save':
    require_admin();
    $email = mb_strtolower(trim($g('email'))); // email uniqueness: stored in normalized form
    $data = [
        'name' => trim($g('name')), 'email' => $email, 'role' => $g('role', 'team'),
        'job_title' => $g('job_title'), 'client_id' => $g('client_id') ? (int)$g('client_id') : null,
        'weekly_capacity' => max(0, (int)$g('weekly_capacity', 45)),
        'maas' => max(0, (float)str_replace(',', '.', $g('salary', '0'))),
    ];
    if (!isset(ROLES[$data['role']])) json_out(['ok' => false, 'error' => 'Geçersiz rol.']);
    // Per-user permission overrides
    if ($g('permissions') !== '') {
        $permissions = json_decode($g('permissions'), true);
        if (is_array($permissions)) {
            $temiz = [];
            foreach (PERMISSION_KEYS as $setting_key => $_) if (isset($permissions[$setting_key])) $temiz[$setting_key] = (int)(bool)$permissions[$setting_key];
            $data['permissions'] = $temiz ? json_encode($temiz) : null;
        }
    }
    if ($data['name'] === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
        json_out(['ok' => false, 'error' => 'Ad ve geçerli e-posta gerekli.']);
    // Customer multi-file list (JSON); primary file = first selection
    $customerClients = json_decode($g('customer_clients', ''), true);
    if ($data['role'] === 'musteri' && is_array($customerClients)) {
        $customerClients = array_values(array_unique(array_filter(array_map('intval', $customerClients))));
        $data['client_id'] = $customerClients[0] ?? null;
    }
    if ($data['role'] === 'musteri' && !$data['client_id'])
        json_out(['ok' => false, 'error' => 'Müşteri için en az bir dosya seçin.']);
    $customerClientSave = function (int $uid) use ($data, $customerClients) {
        if ($data['role'] !== 'musteri' || !is_array($customerClients)) return;
        q("DELETE FROM customer_clients WHERE user_id=?", [$uid]);
        foreach ($customerClients as $did) q("INSERT IGNORE INTO customer_clients (user_id, client_id) VALUES (?,?)", [$uid, $did]);
    };
    if ($g('id')) {
        $id = (int)$g('id');
        if (val("SELECT COUNT(*) FROM users WHERE email=? AND id!=?", [$email, $id]))
            json_out(['ok' => false, 'error' => 'Bu e-posta kullanımda.']);
        if ($g('password')) $data['password'] = password_hash($g('password'), PASSWORD_DEFAULT);
        update_row('users', $data, 'id=?', [$id]);
        $customerClientSave($id);
        json_out(['ok' => true, 'mesaj' => 'Kullanıcı güncellendi.']);
    }
    if (val("SELECT COUNT(*) FROM users WHERE email=?", [$email]))
        json_out(['ok' => false, 'error' => 'Bu e-posta kullanımda.']);
    if (strlen($g('password')) < 6) json_out(['ok' => false, 'error' => 'Şifre en az 6 karakter.']);
    $data['password'] = password_hash($g('password'), PASSWORD_DEFAULT);
    $data['theme'] = 'lime'; $data['color'] = '#b1fb01'; $data['created'] = $now;
    $id = insert('users', $data);
    // Automatically add the new user to the relevant channels
    if ($data['role'] !== 'musteri') {
        // General channel + all project channels (except for interns)
        foreach (rows("SELECT id, type FROM channels WHERE type='genel' OR (type='proje' AND ?='tam')", [$data['role'] === 'stajyer' ? 'stajyer' : 'full']) as $channel) {
            q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channel['id'], $id]);
        }
    } else {
        // Customer: added to the customer channels of all files they can access
        $customerClientSave($id);
        $clientList = is_array($customerClients) && $customerClients ? $customerClients : [$data['client_id']];
        [$in, $p] = in_clause(array_map('intval', $clientList));
        foreach (rows("SELECT k.id FROM channels k JOIN projects pr ON pr.id=k.project_id WHERE k.type='musteri' AND pr.client_id IN $in", $p) as $channel) {
            q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channel['id'], $id]);
        }
    }
    json_out(['ok' => true, 'mesaj' => 'Kullanıcı oluşturuldu.']);

case 'user_status':
    require_admin();
    if ((int)$g('id') === (int)$u['id']) json_out(['ok' => false, 'error' => 'Kendinizi pasifleştiremezsiniz.']);
    update_row('users', ['is_active' => (int)$g('is_active')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Durum güncellendi.']);

/* ==================== WORKFLOW TEMPLATES (admin) ==================== */
case 'workflow_save':
    require_admin();
    $name = trim($g('name'));
    $steps = json_decode($g('steps', '[]'), true) ?: [];
    if ($name === '' || !$steps) json_out(['ok' => false, 'error' => 'Şablon adı ve en az bir adım gerekli.']);
    if ($g('id')) {
        $sid = (int)$g('id');
        update_row('workflow_templates', ['name' => $name, 'description' => $g('description')], 'id=?', [$sid]);
        q("DELETE FROM template_steps WHERE template_id=?", [$sid]);
    } else {
        $sid = insert('workflow_templates', ['name' => $name, 'description' => $g('description'), 'created' => $now]);
    }
    foreach ($steps as $i => $stepName) {
        if (trim($stepName) !== '') insert('template_steps', ['template_id' => $sid, 'sort_order' => $i + 1, 'name' => trim($stepName)]);
    }
    json_out(['ok' => true, 'mesaj' => 'Akış şablonu kaydedildi.']);

case 'workflow_delete':
    require_admin();
    q("DELETE FROM template_steps WHERE template_id=?", [(int)$g('id')]);
    q("DELETE FROM workflow_templates WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Şablon silindi.']);

/* ==================== FORM TEMPLATES (admin) ==================== */
case 'form_save':
    require_admin();
    $name = trim($g('name'));
    $fields = json_decode($g('fields', '[]'), true) ?: [];
    if ($name === '' || !$fields) json_out(['ok' => false, 'error' => 'Form adı ve en az bir alan gerekli.']);
    if ($g('id')) {
        $fid = (int)$g('id');
        update_row('form_templates', ['name' => $name, 'description' => $g('description'), 'is_active' => (int)$g('is_active', 1)], 'id=?', [$fid]);
        q("DELETE FROM form_fields WHERE template_id=?", [$fid]);
    } else {
        $fid = insert('form_templates', ['name' => $name, 'description' => $g('description'), 'is_active' => 1, 'created' => $now]);
    }
    foreach ($fields as $i => $field) {
        if (trim($field['tag'] ?? '') === '') continue;
        insert('form_fields', [
            'template_id' => $fid, 'sort_order' => $i + 1, 'tag' => trim($field['tag']),
            'type' => $field['type'] ?? 'metin', 'options' => $field['options'] ?? null,
            'is_required' => !empty($field['is_required']) ? 1 : 0,
        ]);
    }
    json_out(['ok' => true, 'mesaj' => 'Form şablonu kaydedildi.']);

case 'form_delete':
    require_admin();
    q("DELETE FROM form_fields WHERE template_id=?", [(int)$g('id')]);
    q("DELETE FROM form_templates WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Form silindi.']);

/* ==================== SETTINGS (admin) ==================== */
case 'setting_save':
    require_admin();
    $fieldToKey = ['site_name' => 'site_adi', 'default_theme' => 'varsayilan_tema', 'smtp_is_active' => 'smtp_aktif',
        'smtp_host' => 'smtp_host', 'smtp_port' => 'smtp_port', 'smtp_user' => 'smtp_kullanici',
        'smtp_sender' => 'smtp_gonderen', 'email_notification' => 'eposta_bildirim'];
    foreach ($fieldToKey as $fieldName => $setting_key) {
        if (isset($_POST[$fieldName])) q("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?", [$setting_key, $_POST[$fieldName], $_POST[$fieldName]]);
    }
    if (!empty($_POST['smtp_password'])) q("INSERT INTO settings (setting_key,setting_value) VALUES ('smtp_sifre',?) ON DUPLICATE KEY UPDATE setting_value=?", [$_POST['smtp_password'], $_POST['smtp_password']]);
    // Logo & favicon upload
    foreach (['site_logo' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'site_favicon' => ['png', 'ico', 'jpg', 'jpeg', 'gif', 'webp'],
              'site_logo_dark' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'site_favicon_dark' => ['png', 'ico', 'jpg', 'jpeg', 'gif', 'webp']] as $fieldName => $allowed_ones) {
        $new = file_upload($fieldName);
        if ($new) {
            if (!in_array($new['extension'], $allowed_ones)) json_out(['ok' => false, 'error' => ($fieldName === 'site_logo' ? 'Logo' : 'Favicon') . ' için görsel dosyası seçin.']);
            $old = setting($fieldName);
            if ($old) @unlink(ROOT . '/uploads/' . $old);
            q("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?", [$fieldName, $new['path'], $new['path']]);
        }
    }
    json_out(['ok' => true, 'mesaj' => 'Ayarlar kaydedildi.']);

case 'setting_image_delete':
    require_admin();
    $setting_key = in_array($g('setting_key'), ['site_logo', 'site_favicon', 'site_logo_dark', 'site_favicon_dark']) ? $g('setting_key') : '';
    if (!$setting_key) json_out(['ok' => false, 'error' => 'Geçersiz.']);
    $old = setting($setting_key);
    if ($old) @unlink(ROOT . '/uploads/' . $old);
    q("DELETE FROM settings WHERE setting_key=?", [$setting_key]);
    json_out(['ok' => true, 'mesaj' => 'Görsel kaldırıldı.']);

case 'test_email':
    require_admin();
    require_once __DIR__ . '/includes/mailer.php';
    // Apply temporary settings (test without saving)
    $ok = send_email($u['email'], 'SADA Test E-postası', "Bu bir test e-postasıdır.\nSMTP ayarlarınız çalışıyor. 🎉");
    json_out(['ok' => $ok, 'mesaj' => $ok ? 'Test e-postası gönderildi: ' . $u['email'] : 'Gönderilemedi. SMTP ayarlarını kontrol edin.', 'error' => $ok ? '' : 'SMTP gönderimi başarısız.']);

/* ==================== PROFILE ==================== */
case 'profile_save':
    require_login();
    $data = ['name' => trim($g('name')), 'job_title' => $g('job_title')];
    if ($data['name'] === '') json_out(['ok' => false, 'error' => 'Ad gerekli.']);
    if ($g('password')) {
        if (strlen($g('password')) < 6) json_out(['ok' => false, 'error' => 'Şifre en az 6 karakter.']);
        $data['password'] = password_hash($g('password'), PASSWORD_DEFAULT);
    }
    $avatarClient = file_upload('avatar');
    if ($avatarClient) {
        if (!in_array($avatarClient['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp'])) json_out(['ok' => false, 'error' => 'Profil fotoğrafı için görsel dosyası seçin.']);
        if ($u['avatar']) @unlink(ROOT . '/uploads/' . $u['avatar']);
        $data['avatar'] = $avatarClient['path'];
    }
    update_row('users', $data, 'id=?', [$u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Profil güncellendi.']);

case 'avatar_delete':
    require_login();
    if ($u['avatar']) @unlink(ROOT . '/uploads/' . $u['avatar']);
    update_row('users', ['avatar' => null], 'id=?', [$u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Profil fotoğrafı kaldırıldı.']);

default:
    json_out(['ok' => false, 'error' => 'Bilinmeyen işlem.'], 400);
}

/* ---------- Helper: set up task steps from a template ---------- */
function task_steps_setup(int $taskId, int $templateId): void {
    $steps = rows("SELECT * FROM template_steps WHERE template_id=? ORDER BY sort_order", [$templateId]);
    foreach ($steps as $i => $a) {
        insert('task_steps', [
            'task_id' => $taskId, 'sort_order' => $a['sort_order'], 'name' => $a['name'],
            'status' => $i === 0 ? 'aktif' : 'bekliyor',
        ]);
    }
}

/* ---------- Helper: save project/file members (multi-assign) ---------- */
function project_members_save(int $projectId, string $membersJson): void {
    if ($membersJson === '') return; // do nothing if the form did not send a members field
    $members = json_decode($membersJson, true);
    if (!is_array($members)) return;
    $old = array_column(rows("SELECT user_id FROM project_members WHERE project_id=?", [$projectId]), 'user_id');
    q("DELETE FROM project_members WHERE project_id=?", [$projectId]);
    $projectName = val("SELECT name FROM projects WHERE id=?", [$projectId]);
    foreach (array_unique(array_map('intval', $members)) as $uid) {
        if (!$uid) continue;
        q("INSERT IGNORE INTO project_members (project_id, user_id) VALUES (?,?)", [$projectId, $uid]);
        if (!in_array($uid, $old)) notify($uid, 'Projeye atandınız', $projectName, 'project.php?id=' . $projectId, 'gorev');
    }
}

function client_members_save(int $clientId, string $membersJson): void {
    if ($membersJson === '') return;
    $members = json_decode($membersJson, true);
    if (!is_array($members)) return;
    $old = array_column(rows("SELECT user_id FROM client_members WHERE client_id=?", [$clientId]), 'user_id');
    q("DELETE FROM client_members WHERE client_id=?", [$clientId]);
    $clientName = val("SELECT name FROM clients WHERE id=?", [$clientId]);
    foreach (array_unique(array_map('intval', $members)) as $uid) {
        if (!$uid) continue;
        q("INSERT IGNORE INTO client_members (client_id, user_id) VALUES (?,?)", [$clientId, $uid]);
        if (!in_array($uid, $old)) notify($uid, 'Dosyaya atandınız', $clientName, 'client.php?id=' . $clientId, 'gorev');
    }
}

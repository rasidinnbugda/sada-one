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

/* ==================== v6.0: DRIVE + AI ==================== */
case 'drive_test':
    if (!is_admin()) deny();
    require_once __DIR__ . '/includes/google-drive.php';
    $r = drive_test();
    if (!$r['ok']) json_out(['ok' => false, 'error' => $r['error']]);
    json_out(['ok' => true, 'message' => 'Bağlantı kuruldu. Servis hesabı: ' . $r['service_email'], 'service_email' => $r['service_email']]);

case 'drive_folder_test':
    // Verify a specific folder is readable by the service account
    if (!is_staff()) deny();
    require_once __DIR__ . '/includes/google-drive.php';
    $fid = trim($g('folder_id'));
    if (preg_match('~folders/([A-Za-z0-9_-]{10,})~', $fid, $fm)) $fid = $fm[1];
    if ($fid === '') json_out(['ok' => false, 'error' => 'Klasör ID boş.']);
    $r = drive_files_after($fid, '1970-01-01T00:00:00Z');
    if (!$r['ok']) json_out(['ok' => false, 'error' => 'Klasör okunamadı: ' . $r['error'] . ' (Klasörü servis hesabı e-postasıyla paylaştınız mı?)']);
    json_out(['ok' => true, 'message' => 'Klasör erişilebilir ✓' . ($r['sample'] ? ' (örnek dosya: ' . $r['sample'] . ')' : ' (klasör şu an boş)')]);

case 'client_contact_save':
    require_permission('client_manage');
    $data = ['client_id' => (int)$g('client_id'), 'name' => mb_substr(trim($g('name')), 0, 120),
        'title' => mb_substr(trim($g('title')), 0, 120) ?: null,
        'email' => mb_strtolower(trim($g('email'))) ?: null, 'phone' => mb_substr(trim($g('phone')), 0, 40) ?: null];
    if ($data['name'] === '') json_out(['ok' => false, 'error' => 'İsim gerekli.']);
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'Geçersiz e-posta adresi.']);
    if ($g('id')) { update_row('client_contacts', $data, 'id=?', [(int)$g('id')]); json_out(['ok' => true, 'message' => 'Kişi güncellendi.']); }
    insert('client_contacts', $data + ['created' => $now]);
    json_out(['ok' => true, 'message' => 'Kişi eklendi.']);

case 'client_contact_delete':
    require_permission('client_manage');
    q("DELETE FROM client_contacts WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Kişi silindi.']);

case 'report_mail_preview':
    require_permission('report');
    require_once __DIR__ . '/includes/report-mail.php';
    $report = row("SELECT * FROM monthly_reports WHERE client_id=? AND period=?", [(int)$g('client_id'), $g('period')]);
    $client = row("SELECT * FROM clients WHERE id=?", [(int)$g('client_id')]);
    if (!$report || !$client) json_out(['ok' => false, 'error' => 'Önce raporu kaydedin.']);
    $main = setting('smtp_sender') ?: setting('smtp_user');
    $senders = array_values(array_filter(array_unique(array_merge([$main],
        array_map('trim', explode(',', (string)setting('mail_aliases')))))));
    [$py, $pa] = explode('-', $g('period'));
    json_out(['ok' => true,
        'html' => report_mail_html($report, $client, $g('period')),
        'subject' => $client['name'] . ' — ' . MONTHS[(int)$pa] . " $py Aylık Raporu",
        'to' => $client['contact_email'] ?: '',
        'senders' => $senders,
        'sent_at' => $report['sent_at'] ? format_date($report['sent_at'], true) : null,
        'mail_data' => json_decode((string)($report['mail_data'] ?? ''), true) ?: new stdClass(),
        'contacts' => rows("SELECT name, title, email FROM client_contacts WHERE client_id=? AND email IS NOT NULL ORDER BY name", [(int)$g('client_id')]),
    ]);

case 'report_mail_data_save':
    // The mail modal's design editor: cover image, favourite block, stat tiles
    require_permission('report');
    $report = row("SELECT * FROM monthly_reports WHERE client_id=? AND period=?", [(int)$g('client_id'), $g('period')]);
    if (!$report) json_out(['ok' => false, 'error' => 'Önce raporu kaydedin.']);
    $data = json_decode((string)($report['mail_data'] ?? ''), true) ?: [];
    // Images: picture files only
    foreach (['hero_img' => 'hero', 'fav_img' => 'fav_img'] as $fieldName => $key) {
        if (!empty($_FILES[$fieldName]['tmp_name'])) {
            $uploaded = file_upload($fieldName);
            if (!$uploaded || !in_array($uploaded['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                json_out(['ok' => false, 'error' => 'Görsel yüklenemedi (JPG/PNG/WebP kullanın).']);
            if ($key === 'hero') $data['hero'] = 'uploads/' . $uploaded['path'];
            else { $data['fav']['img'] = 'uploads/' . $uploaded['path']; }
        }
    }
    if ($g('hero_remove') === '1') unset($data['hero']);
    if ($g('fav_img_remove') === '1') unset($data['fav']['img']);
    $data['fav']['title'] = mb_substr(trim($g('fav_title')), 0, 120);
    $data['fav']['text'] = mb_substr(trim($g('fav_text')), 0, 600);
    $data['fav']['stat'] = mb_substr(trim($g('fav_stat')), 0, 60);
    // Editable title / greeting / closing texts (empty = default)
    $data['text'] = [];
    foreach (['title', 'greeting', 'production_title', 'stat_title', 'stat_intro', 'plan_title', 'closing', 'thanks'] as $mk) {
        $data['text'][$mk] = mb_substr(trim($g('text_' . $mk)), 0, 200);
    }
    $stats = json_decode($g('stats', '[]'), true) ?: [];
    $data['stats'] = [];
    foreach (array_slice($stats, 0, 4) as $s) {
        $data['stats'][] = ['label' => mb_substr(trim((string)($s['label'] ?? '')), 0, 60),
            'value' => mb_substr(trim((string)($s['value'] ?? '')), 0, 30),
            'change' => mb_substr(trim((string)($s['change'] ?? '')), 0, 20)];
    }
    update_row('monthly_reports', ['mail_data' => json_encode($data, JSON_UNESCAPED_UNICODE)], 'id=?', [$report['id']]);
    json_out(['ok' => true, 'message' => 'Tasarım kaydedildi.']);

case 'report_mail_send':
    require_permission('report');
    require_once __DIR__ . '/includes/report-mail.php';
    require_once __DIR__ . '/includes/mailer.php';
    $report = row("SELECT * FROM monthly_reports WHERE client_id=? AND period=?", [(int)$g('client_id'), $g('period')]);
    $client = row("SELECT * FROM clients WHERE id=?", [(int)$g('client_id')]);
    if (!$report || !$client) json_out(['ok' => false, 'error' => 'Önce raporu kaydedin.']);
    // Several recipients: separated by commas or semicolons
    $recipients = array_values(array_unique(array_filter(array_map(
        fn($a) => mb_strtolower(trim($a)), preg_split('/[;,]+/', (string)$g('to'))))));
    if (!$recipients) json_out(['ok' => false, 'error' => 'En az bir alıcı adresi girin.']);
    foreach ($recipients as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL))
        json_out(['ok' => false, 'error' => 'Geçersiz adres: ' . $a]);
    // The sender must be one of the configured addresses (main or a Send-As alias)
    $main = setting('smtp_sender') ?: setting('smtp_user');
    $allowed = array_values(array_filter(array_unique(array_merge([$main],
        array_map('trim', explode(',', (string)setting('mail_aliases')))))));
    $from = trim($g('from')) ?: $main;
    if (!in_array($from, $allowed, true)) json_out(['ok' => false, 'error' => 'Bu gönderen adresi tanımlı değil (Ayarlar → SMTP → Ek gönderen adresleri).']);
    $subject = trim($g('subject')) ?: ($client['name'] . ' Aylık Raporu');
    $body = report_mail_html($report, $client, $g('period'));
    $successful = []; $failed = [];
    foreach ($recipients as $a) {
        if (send_email_html($a, $subject, $body, $from)) $successful[] = $a;
        else $failed[] = $a;
    }
    if (!$successful)
        json_out(['ok' => false, 'error' => 'E-posta gönderilemedi' . (!empty($GLOBALS['smtp_last_error']) ? ' — sunucu yanıtı: ' . $GLOBALS['smtp_last_error'] : ' — SMTP ayarlarını kontrol edin.')]);
    update_row('monthly_reports', ['sent_at' => $now, 'sent_to' => mb_substr(implode(', ', $successful), 0, 255)], 'id=?', [$report['id']]);
    log_activity('Aylık rapor maili gönderildi: ' . $client['name'] . ' / ' . $g('period') . ' → ' . implode(', ', $successful));
    json_out(['ok' => true, 'message' => 'Rapor maili gönderildi: ' . implode(', ', $successful)
        . ($failed ? ' — GÖNDERİLEMEYEN: ' . implode(', ', $failed) : '')]);

case 'drive_files':
    require_login();
    require_once __DIR__ . '/includes/google-drive.php';
    $ev = row("SELECT id, drive_folder_id, drive_link FROM events WHERE id=? AND type='shoot'", [(int)$g('id')]);
    if (!$ev || !$ev['drive_folder_id']) json_out(['ok' => false, 'error' => 'Klasör bağlı değil.']);
    $r = drive_list_files($ev['drive_folder_id'], 25);
    if (!$r['ok']) json_out(['ok' => false, 'error' => $r['error']]);
    json_out(['ok' => true] + drive_files_summary($ev, $r['files']));

case 'drive_files_batch':
    // One request for the whole shoot list instead of one PHP process + Google call per card
    require_login();
    require_once __DIR__ . '/includes/google-drive.php';
    $ids = array_slice(array_values(array_filter(array_map('intval', (array)json_decode((string)$g('ids'), true)))), 0, 40);
    $out = [];
    if ($ids) {
        $evs = rows("SELECT id, drive_folder_id, drive_link FROM events WHERE type='shoot' AND COALESCE(drive_folder_id,'')!=''
            AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids);
        $deadline = microtime(true) + 20; // a slow Google day must not turn into a hung page
        foreach ($evs as $ev) {
            if (microtime(true) > $deadline) break;
            $r = drive_list_files($ev['drive_folder_id'], 25);
            if ($r['ok']) $out[$ev['id']] = drive_files_summary($ev, $r['files']);
        }
    }
    json_out(['ok' => true, 'events' => $out]);

case 'drive_disconnect':
    require_admin();
    q("DELETE FROM settings WHERE setting_key IN ('google_refresh_token','google_drive_email','google_drive_token')");
    log_activity('Google Drive bağlantısı kesildi');
    json_out(['ok' => true, 'message' => 'Google bağlantısı kesildi.']);

case 'drive_folder_create':
    // Create (or complete) the Drive folder of an existing shoot
    require_staff();
    require_once __DIR__ . '/includes/google-drive.php';
    $ev = row("SELECT e.*, c.name client_name, c.drive_folder_id client_folder
        FROM events e LEFT JOIN clients c ON c.id = COALESCE(e.client_id, (SELECT client_id FROM projects WHERE id=e.project_id))
        WHERE e.id=? AND e.type='shoot'", [(int)$g('id')]);
    if (!$ev) json_out(['ok' => false, 'error' => 'Çekim bulunamadı.']);
    if ($ev['drive_folder_id']) json_out(['ok' => false, 'error' => 'Bu çekimin zaten bir klasörü var.']);
    $r = event_drive_folder($ev);
    if (!$r) json_out(['ok' => false, 'error' => 'Klasör oluşturulamadı: ' . ($GLOBALS['drive_last_error'] ?? 'Drive bağlantısını kontrol edin.')]);
    json_out(['ok' => true, 'message' => 'Drive klasörü oluşturuldu.', 'link' => $r['link']]);

case 'drive_mark':
    // Manually mark a shoot as transferred (with an optional Drive link)
    if (!is_staff()) deny();
    $eventId = (int)$g('id');
    // Only touch the link when a new one is supplied — a bare confirm keeps the folder link. Linked work moves on.
    $moved = shoot_transferred($eventId, (int)$u['id'], trim($g('drive_link')) !== '' ? trim($g('drive_link')) : null);
    json_out(['ok' => true, 'message' => 'Çekim "Drive\'a aktarıldı" olarak işaretlendi.' . ($moved ? " Bağlı $moved işin çekim adımı bitti." : ''), 'refresh' => true]);

case 'ai_test':
    if (!is_admin()) deny();
    require_once __DIR__ . '/includes/ai.php';
    $r = ai_ask('Kısa yanıt ver.', 'Sadece "SADA One bağlantısı hazır." yaz.', 64);
    if (!$r['ok']) json_out(['ok' => false, 'error' => $r['error']]);
    json_out(['ok' => true, 'message' => 'AI bağlantısı çalışıyor: ' . $r['text']]);

case 'ai_report_draft':
    require_permission('ai_use');
    require_once __DIR__ . '/includes/ai.php';
    if (!ai_enabled()) json_out(['ok' => false, 'error' => 'AI anahtarı tanımlı değil (Ayarlar → Yapay Zeka).']);
    $clientId = (int)$g('client_id');
    $period = preg_match('/^\d{4}-\d{2}$/', $g('period')) ? $g('period') : date('Y-m');
    $pStart = "$period-01"; $pEnd = date('Y-m-t', strtotime($pStart));
    $clientName = val("SELECT name FROM clients WHERE id=?", [$clientId]);
    if (!$clientName) json_out(['ok' => false, 'error' => 'Dosya bulunamadı.']);
    // Collect this month's panel data for the client
    $doneTasks = rows("SELECT g.title FROM tasks g JOIN projects p ON p.id=g.project_id WHERE p.client_id=? AND " . task_done_sql('g') . " AND g.completion BETWEEN ? AND ? LIMIT 40", [$clientId, "$pStart 00:00:00", "$pEnd 23:59:59"]);
    $contents = rows("SELECT g.title, g.platforms platform, g.status FROM tasks g JOIN projects p ON p.id=g.project_id WHERE p.client_id=? AND g.status!='cancelled' AND g.publish_date BETWEEN ? AND ? LIMIT 60", [$clientId, $pStart, $pEnd]);
    $shoots = rows("SELECT e.title, e.start FROM events e LEFT JOIN projects p ON p.id=e.project_id WHERE e.type='shoot' AND (e.client_id=? OR p.client_id=?) AND e.start BETWEEN ? AND ? LIMIT 20", [$clientId, $clientId, "$pStart 00:00:00", "$pEnd 23:59:59"]);
    $metrics = rows("SELECT m.date, m.followers, m.engagement, h.platform FROM social_metrics m JOIN social_accounts h ON h.id=m.account_id WHERE h.client_id=? AND m.date BETWEEN ? AND ? ORDER BY m.date LIMIT 30", [$clientId, $pStart, $pEnd]);
    $dataJson = json_encode(['customer' => $clientName, 'period' => $period,
        'completed_tasks' => array_column($doneTasks, 'title'),
        'contents' => $contents, 'shoots' => $shoots, 'social_metrics' => $metrics], JSON_UNESCAPED_UNICODE);
    $draft = ai_ask_json(
        'Bir dijital ajansın müşteri dosyası için Türkçe aylık faaliyet raporu taslağı yazıyorsun. Profesyonel, net, abartısız yaz. Veri yoksa uydurma; "bu ay ... çalışması yapılmadı" gibi dürüst ifadeler kullan. JSON anahtarları: summary, work_done, metrics, plan. work_done ve metrics alanlarında madde işaretli satırlar (- ile) kullan.',
        $dataJson, 3000);
    if (!$draft || !isset($draft['summary'])) json_out(['ok' => false, 'error' => 'AI taslak üretemedi, lütfen tekrar deneyin.']);
    json_out(['ok' => true, 'draft' => ['summary' => (string)$draft['summary'], 'work_done' => (string)($draft['work_done'] ?? ''), 'metrics' => (string)($draft['metrics'] ?? ''), 'plan' => (string)($draft['plan'] ?? '')]]);

case 'ai_idea_generate':
    require_permission('ai_use');
    require_once __DIR__ . '/includes/ai.php';
    if (!ai_enabled()) json_out(['ok' => false, 'error' => 'AI anahtarı tanımlı değil (Ayarlar → Yapay Zeka).']);
    $topic = trim($g('topic'));
    if ($topic === '') json_out(['ok' => false, 'error' => 'Kurum/konu yazın.']);
    $list = ai_ask_json(
        'Bir sosyal medya ajansı için Türkçe içerik fikirleri üretiyorsun. Özgün, uygulanabilir, kuruma özgü fikirler ver; klişelerden kaçın. JSON: {"ideas":[{"idea":"...","description":"..."}]} — tam 6 fikir.',
        'Kurum/konu: ' . $topic, 2500);
    if (!$list || empty($list['ideas'])) json_out(['ok' => false, 'error' => 'Fikir üretilemedi, tekrar deneyin.']);
    json_out(['ok' => true, 'ideas' => array_slice(array_values($list['ideas']), 0, 8)]);

case 'ai_summarize':
    require_permission('ai_use');
    require_once __DIR__ . '/includes/ai.php';
    if (!ai_enabled()) json_out(['ok' => false, 'error' => 'AI anahtarı tanımlı değil (Ayarlar → Yapay Zeka).']);
    $taskId = (int)$g('task_id');
    $task = row("SELECT title, description FROM tasks WHERE id=?", [$taskId]);
    if (!$task) json_out(['ok' => false, 'error' => 'İş bulunamadı.']);
    $comments = rows("SELECT u.name, y.message AS comment FROM comments y JOIN users u ON u.id=y.user_id WHERE y.ref_type='task' AND y.ref_id=? ORDER BY y.id LIMIT 60", [$taskId]);
    $text = "GÖREV: {$task['title']}\nAÇIKLAMA: {$task['description']}\n\nYORUMLAR:\n";
    foreach ($comments as $c) $text .= "- {$c['name']}: {$c['comment']}\n";
    $r = ai_ask('Bir iş ve tartışmasını Türkçe özetle: mevcut durum, alınan kararlar, açık sorular ve yapılacaklar. Kısa madde işaretleri kullan, 150 kelimeyi geçme.', mb_substr($text, 0, 12000), 1200);
    if (!$r['ok']) json_out(['ok' => false, 'error' => $r['error']]);
    json_out(['ok' => true, 'summary' => $r['text']]);

case 'version_check':
    if ($u['role'] !== 'admin') deny();
    require_once __DIR__ . '/includes/updater-core.php';
    $rel = github_json('https://api.github.com/repos/' . GITHUB_REPO . '/releases/latest');
    if (!$rel || empty($rel['tag_name'])) json_out(['ok' => false, 'error' => 'GitHub\'a ulaşılamadı veya yayınlanmış sürüm yok.']);
    $last = ltrim($rel['tag_name'], 'vV');
    json_out([
        'ok' => true, 'current' => APP_VERSION, 'last' => $rel['tag_name'],
        'new_var' => version_compare($last, APP_VERSION, '>'),
        'notes' => mb_substr(trim((string)($rel['body'] ?? '')), 0, 300),
    ]);

/* ==================== v14: SOP MODULES ==================== */
case 'mentorship_save':
    require_permission('mentorship_manage');
    $data = [
        'member_id' => (int)$g('member_id'), 'field' => trim($g('field')),
        'mentor_id' => (int)$g('mentor_id') ?: null, 'project_id' => (int)$g('project_id') ?: null,
        'practice_area' => trim($g('practice_area')) ?: null, 'output' => trim($g('output')) ?: null,
        'status' => isset(MENTORSHIP_STATUSES[$g('status')]) ? $g('status') : 'planned',
    ];
    if (!$data['member_id'] || $data['field'] === '') json_out(['ok' => false, 'error' => 'Ekip üyesi ve gelişim alanı zorunludur.']);
    if ($id = (int)$g('id')) { $data['updated'] = $now; update_row('mentorship', $data, 'id=?', [$id]); }
    else { $data['created'] = $now; insert('mentorship', $data); }
    json_out(['ok' => true, 'message' => 'Mentörlük kaydı güncellendi.', 'refresh' => true]);

case 'mentorship_output':
    // A member can update the output note of their own record
    $entry = row("SELECT * FROM mentorship WHERE id=?", [(int)$g('id')]);
    if (!$entry || (!is_admin() && $u['role'] !== 'pm' && $entry['member_id'] != $u['id'])) deny();
    update_row('mentorship', ['output' => trim($g('output')), 'updated' => $now], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Çıktı notu kaydedildi.']);

case 'mentorship_delete':
    if (!is_admin() && $u['role'] !== 'pm') deny();
    q("DELETE FROM mentorship WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'refresh' => true]);

case 'pool_save':
    require_permission('pool_manage');
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
    json_out(['ok' => true, 'message' => 'Havuz kaydı güncellendi.', 'refresh' => true]);

case 'pool_delete':
    require_permission('pool_manage');
    q("DELETE FROM talent_pool WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'refresh' => true]);

case 'idea_save':
    if (is_customer()) deny();
    $idea = trim($g('idea'));
    if ($idea === '') json_out(['ok' => false, 'error' => 'Fikir boş olamaz.']);
    insert('ideas', ['idea' => $idea, 'organization' => trim($g('organization')) ?: null, 'description' => trim($g('description')) ?: null, 'proposer_id' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'message' => 'Fikir panoya eklendi.', 'refresh' => true]);

case 'idea_status':
    if (!is_admin() && $u['role'] !== 'pm') deny();
    if (!isset(IDEA_STATUSES[$g('status')])) json_out(['ok' => false, 'error' => 'Geçersiz durum.']);
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
        'status' => $g('status') === 'completed' ? 'completed' : 'draft', 'updated' => $now];
    $var = row("SELECT id FROM monthly_reports WHERE client_id=? AND period=?", [$clientId, $period]);
    if ($var) update_row('monthly_reports', $data, 'id=?', [$var['id']]);
    else insert('monthly_reports', $data + ['client_id' => $clientId, 'period' => $period, 'author_id' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'message' => 'Aylık rapor kaydedildi.', 'refresh' => true]);

case 'mnote_save':
    if (!is_admin() && $u['role'] !== 'pm') deny();
    $gid = (int)$g('task_id');
    if (!val("SELECT id FROM tasks WHERE id=?", [$gid])) json_out(['ok' => false, 'error' => 'İş bulunamadı.']);
    q("INSERT INTO task_manager_notes (task_id, user_id, note, updated) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE note=VALUES(note), updated=VALUES(updated)", [$gid, $u['id'], trim($g('note')), $now]);
    json_out(['ok' => true, 'message' => 'Not kaydedildi.']);

/* ---- Project station ---- */
case 'station_save':
    if (!permission('client_manage')) deny();
    $pid = (int)$g('project_id');
    if (!project_access($pid)) deny();
    $data = ['handover' => trim($g('handover')) ?: null, 'team_roles' => $g('team_roles') ?: null];
    if (permission('budget_view')) {
        $data['budget'] = (float)str_replace(',', '.', $g('budget', '0'));
        $data['revision_limit'] = max(0, (int)$g('revision_limit', 2));
    }
    update_row('projects', $data, 'id=?', [$pid]);
    json_out(['ok' => true, 'message' => 'İstasyon bilgileri kaydedildi.', 'refresh' => true]);

case 'extra_request_save':
    if (!permission('budget_view')) deny();
    $pid = (int)$g('project_id');
    if (!project_access($pid)) deny();
    $title = trim($g('title'));
    if ($title === '') json_out(['ok' => false, 'error' => 'Talep başlığı zorunludur.']);
    insert('project_extra_requests', ['project_id' => $pid, 'title' => $title, 'amount' => (float)str_replace(',', '.', $g('amount', '0')),
        'out_of_scope' => (int)(bool)$g('out_of_scope'), 'description' => trim($g('description')) ?: null, 'created_by' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'message' => 'Ek talep kaydedildi.', 'refresh' => true]);

case 'extra_request_status':
    if (!permission('budget_view')) deny();
    if (!isset(EXTRA_REQUEST_STATUSES[$g('status')])) json_out(['ok' => false, 'error' => 'Geçersiz durum.']);
    update_row('project_extra_requests', ['status' => $g('status')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true]);

case 'extra_request_delete':
    if (!permission('budget_view')) deny();
    q("DELETE FROM project_extra_requests WHERE id=?", [(int)$g('id')]);
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
    json_out(['ok' => true, 'message' => 'Standart SOP kontrol listesi yüklendi.', 'refresh' => true]);

case 'pcheck_toggle':
    if (!is_staff()) deny();
    $field = $g('field') === 'delivery' ? 'delivery' : 'done';
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
    if (!in_array($g('type'), ['internal', 'external', 'case_study'])) json_out(['ok' => false, 'error' => 'Geçersiz değerlendirme türü.']);
    q("INSERT INTO project_review (project_id, type, content, updated_by, updated) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE content=VALUES(content), updated_by=VALUES(updated_by), updated=VALUES(updated)",
       [$pid, $g('type'), trim($g('content')), $u['id'], $now]);
    json_out(['ok' => true, 'message' => 'Değerlendirme kaydedildi.']);

case 'shoot_list_save':
    if (!permission('calendar_manage')) deny();
    update_row('events', ['shopping_list' => trim($g('shopping_list')) ?: null, 'needs_list' => trim($g('needs_list')) ?: null], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Çekim listesi güncellendi.', 'refresh' => true]);

case 'notification_count':
    json_out(['ok' => true, 'count' => (int)val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0", [$u['id']])]);

case 'notification_delete':
    q("DELETE FROM notifications WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'notification_clear':
    q("DELETE FROM notifications WHERE user_id=?", [$u['id']]);
    json_out(['ok' => true, 'message' => 'Tüm bildirimler temizlendi.']);

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
    require_permission('client_manage');
    $data = [
        'name' => trim($g('name')), 'type' => $g('type', 'brand'), 'color' => $g('color', '#182f5d'),
        'description' => $g('description'), 'contact_name' => $g('contact_name'),
        'contact_email' => $g('contact_email'), 'contact_phone' => $g('contact_phone'),
        'status' => $g('status', 'active'),
        'manager_id' => (int)$g('manager_id') ?: null,
    ];
    // Drive folder: accept a full URL or a bare folder ID
    if ($g('drive_folder') !== '') {
        $df = trim($g('drive_folder'));
        if (preg_match('~folders/([A-Za-z0-9_-]{10,})~', $df, $folderMatch)) $df = $folderMatch[1];
        $data['drive_folder_id'] = $df !== '' ? mb_substr($df, 0, 120) : null;
    } elseif (isset($_POST['drive_folder'])) {
        $data['drive_folder_id'] = null;
    }
    if ($data['name'] === '') json_out(['ok' => false, 'error' => 'Dosya adı gerekli.']);
    $logo = file_upload('logo');
    if ($logo) {
        if (!in_array($logo['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp'])) json_out(['ok' => false, 'error' => 'Logo için görsel dosyası seçin (jpg, png, webp).']);
        $data['logo'] = $logo['path'];
    }
    if ($g('id')) {
        $id = (int)$g('id');
        update_row('clients', $data, 'id=?', [$id]);
        log_activity('"' . $data['name'] . '" dosyasını güncelledi', 'client', $id);
        client_members_save($id, $g('members'));
        json_out(['ok' => true, 'message' => 'Dosya güncellendi.']);
    } else {
        $data['created'] = $now;
        $id = insert('clients', $data);
        log_activity('"' . $data['name'] . '" dosyasını oluşturdu', 'client', $id);
        client_members_save($id, $g('members'));
        json_out(['ok' => true, 'message' => 'Dosya oluşturuldu.', 'redirect' => 'client.php?id=' . $id]);
    }

case 'client_strategy_save':
    // Strategy, brand kit and the file's approval rules (plan approval, work types that skip client approval)
    require_permission('client_manage');
    $id = (int)$g('id');
    if (!val("SELECT id FROM clients WHERE id=?", [$id])) json_out(['ok' => false, 'error' => 'Dosya bulunamadı.']);
    $typeIds = json_decode($g('no_approval_types', '[]'), true);
    $typeIds = is_array($typeIds) ? array_values(array_unique(array_filter(array_map('intval', $typeIds)))) : [];
    update_row('clients', [
        'strategy' => trim($g('strategy')) ?: null, 'brand_kit' => trim($g('brand_kit')) ?: null,
        'plan_approval' => $g('plan_approval') ? 1 : 0, 'no_approval_types' => $typeIds ? implode(',', $typeIds) : null,
    ], 'id=?', [$id]);
    log_activity('Strateji ve marka bilgilerini güncelledi', 'client', $id);
    json_out(['ok' => true, 'message' => 'Strateji ve marka bilgileri kaydedildi.']);

case 'client_delete':
    require_admin();
    $id = (int)$g('id');
    if (val("SELECT COUNT(*) FROM projects WHERE client_id=?", [$id]) > 0)
        json_out(['ok' => false, 'error' => 'Bu dosyada projeler var. Önce projeleri silin.']);
    q("DELETE FROM clients WHERE id=?", [$id]);
    json_out(['ok' => true, 'message' => 'Dosya silindi.', 'redirect' => 'clients.php']);

/* ==================== PROJECTS ==================== */
case 'project_save':
    require_permission('client_manage');
    $data = [
        'client_id' => (int)$g('client_id'), 'name' => trim($g('name')), 'type' => $g('type', 'monthly'),
        'description' => $g('description'), 'status' => $g('status', 'active'),
        'start' => $g('start') ?: null, 'end' => $g('end') ?: null,
        'pm_id' => $g('pm_id') ? (int)$g('pm_id') : null,
        'contract_amount' => (float)str_replace(',', '.', $g('contract_amount', '0')),
    ];
    if ($data['name'] === '' || !$data['client_id']) json_out(['ok' => false, 'error' => 'Proje adı ve dosya gerekli.']);
    if ($g('id')) {
        $id = (int)$g('id');
        update_row('projects', $data, 'id=?', [$id]);
        project_members_save($id, $g('members'));
        log_activity('"' . $data['name'] . '" projesini güncelledi', 'project', $id);
        json_out(['ok' => true, 'message' => 'Proje güncellendi.']);
    } else {
        $data['created'] = $now;
        $id = insert('projects', $data);
        // A monthly project starts with this month in planning
        if ($data['type'] === 'monthly') get_or_create_period($id, (int)date('Y'), (int)date('n'), 'planning');
        project_channel($id, 'project');
        project_channel($id, 'customer');
        project_members_save($id, $g('members'));
        // Set up tasks from the project template
        if ($g('ptemplate_id')) {
            $ps = row("SELECT * FROM project_templates WHERE id=?", [(int)$g('ptemplate_id')]);
            foreach (json_decode($ps['tasks'] ?? '[]', true) ?: [] as $si => $sg) {
                $gid = insert('tasks', ['project_id' => $id, 'title' => $sg['title'], 'priority' => $sg['priority'] ?? 'normal', 'created_by' => $u['id'], 'status' => 'todo', 'sort_order' => $si + 1, 'created' => $now]);
                if (!empty($sg['type_id'])) task_steps_setup($gid, (int)$sg['type_id']);
            }
        }
        log_activity('"' . $data['name'] . '" projesini oluşturdu', 'project', $id);
        json_out(['ok' => true, 'message' => 'Proje oluşturuldu.', 'redirect' => 'project.php?id=' . $id]);
    }

case 'project_delete':
    require_admin();
    $id = (int)$g('id');
    foreach (['tasks', 'contents', 'approvals', 'payments', 'periods'] as $t) q("DELETE FROM $t WHERE project_id=?", [$id]);
    q("DELETE FROM projects WHERE id=?", [$id]);
    json_out(['ok' => true, 'message' => 'Proje silindi.', 'redirect' => 'projects.php']);

case 'period_open':
    require_pm();
    $projectId = (int)$g('project_id');
    // A month opened by hand starts with its planning
    $periodId = get_or_create_period($projectId, (int)$g('year'), (int)$g('month'), 'planning');
    // Option to create this month's work from a task type
    if ($g('type_id')) {
        $template = row("SELECT * FROM task_types WHERE id=?", [(int)$g('type_id')]);
        if ($template) {
            $taskId = insert('tasks', [
                'project_id' => $projectId, 'period_id' => $periodId,
                'title' => $template['name'] . ' — ' . MONTHS[(int)$g('month')] . ' ' . $g('year'),
                'created_by' => $u['id'], 'status' => 'todo', 'created' => $now,
            ]);
            task_steps_setup($taskId, (int)$g('type_id'));
        }
    }
    json_out(['ok' => true, 'message' => 'Ay açıldı.', 'redirect' => 'month.php?id=' . $periodId]);

case 'month_phase':
    // Planning → production → closing → closed, one step at a time; the month closes only with no open work in it
    require_pm();
    $month = row("SELECT d.* FROM periods d WHERE d.id=?", [(int)$g('id')]);
    if (!$month || !project_access((int)$month['project_id'])) json_out(['ok' => false, 'error' => 'Ay bulunamadı.']);
    $order = array_keys(MONTH_PHASES);
    $to = (string)$g('phase');
    $toIndex = array_search($to, $order, true);
    if ($toIndex === false || abs($toIndex - (int)array_search($month['phase'], $order, true)) !== 1) json_out(['ok' => false, 'error' => 'Ay bir adım ileri ya da geri alınabilir.']);
    if ($to === 'closed' && ($openCount = (int)val("SELECT COUNT(*) FROM tasks WHERE period_id=? AND is_archived=0 AND " . task_open_sql(), [$month['id']])))
        json_out(['ok' => false, 'error' => "Ayda $openCount açık iş var: önce sonraki aya taşıyın ya da iptal edin."]);
    update_row('periods', ['phase' => $to, 'status' => $to === 'closed' ? 'closed' : 'open', 'closed_at' => $to === 'closed' ? $now : null], 'id=?', [$month['id']]);
    log_activity(period_name($month) . ' ayı: ' . MONTH_PHASES[$to], 'project', (int)$month['project_id']);
    json_out(['ok' => true, 'message' => period_name($month) . ': ' . ($month['phase'] === 'closed' ? 'yeniden açıldı' : MONTH_PHASES[$to]) . '.']);

case 'month_carry':
    // Month end: open work moves on to the next month (created if needed) and keeps its lane
    require_pm();
    $month = row("SELECT d.* FROM periods d WHERE d.id=?", [(int)$g('id')]);
    if (!$month || !project_access((int)$month['project_id'])) json_out(['ok' => false, 'error' => 'Ay bulunamadı.']);
    $nextMonth = (int)$month['month'] % 12 + 1;
    $nextId = get_or_create_period((int)$month['project_id'], (int)$month['year'] + ($nextMonth === 1 ? 1 : 0), $nextMonth);
    $moved = q("UPDATE tasks SET period_id=? WHERE period_id=? AND is_archived=0 AND " . task_open_sql(), [$nextId, $month['id']])->rowCount();
    if ($moved) log_activity("$moved açık işi " . period_name($month) . ' ayından ' . MONTHS[$nextMonth] . ' ayına taşıdı', 'project', (int)$month['project_id']);
    json_out(['ok' => true, 'message' => $moved ? "$moved açık iş " . MONTHS[$nextMonth] . ' ayına taşındı.' : 'Taşınacak açık iş yok.']);

case 'month_plan_send':
    // The month's planned client work goes to the client as one approval; the answer moves the month
    require_pm();
    require_permission('approval_send');
    $month = row("SELECT d.*, p.client_id, p.name project_name FROM periods d JOIN projects p ON p.id=d.project_id WHERE d.id=?", [(int)$g('id')]);
    if (!$month || !project_access((int)$month['project_id'])) json_out(['ok' => false, 'error' => 'Ay bulunamadı.']);
    if ($month['phase'] !== 'planning') json_out(['ok' => false, 'error' => 'Plan, ay planlama aşamasındayken gönderilir.']);
    $plan = rows("SELECT title, publish_date, publish_time, platforms FROM tasks WHERE period_id=? AND kind='client' AND lane='planned' AND status!='cancelled' AND is_archived=0
        ORDER BY publish_date IS NULL, publish_date, publish_time, id", [$month['id']]);
    if (!$plan) json_out(['ok' => false, 'error' => 'Planda henüz müşteri işi yok.']);
    $lines = array_map(fn($p) => '• ' . ($p['publish_date'] ? format_date($p['publish_date']) . ($p['publish_time'] ? ' ' . substr($p['publish_time'], 0, 5) : '') . ' — ' : '') . $p['title']
        . ($p['platforms'] ? ' (' . implode(', ', array_map(fn($k) => PLATFORMS[$k] ?? $k, explode(',', $p['platforms']))) . ')' : ''), $plan);
    $note = trim($g('description'));
    $title = period_name($month) . ' planı — ' . $month['project_name'];
    insert('approvals', [
        'project_id' => (int)$month['project_id'], 'period_id' => (int)$month['id'], 'title' => $title,
        'description' => ($note !== '' ? $note . "\n\n" : '') . count($plan) . " iş:\n" . implode("\n", $lines),
        'sender_id' => $u['id'], 'status' => 'pending', 'created' => $now, 'token' => ($token = bin2hex(random_bytes(16))),
    ]);
    update_row('periods', ['plan_status' => 'pending'], 'id=?', [$month['id']]);
    if ($g('send_email')) approval_mail_contact((int)$month['client_id'], $title, $note, $token);
    foreach (client_customer_ids((int)$month['client_id']) as $cid) notify($cid, 'Aylık plan onayınızı bekliyor', $title, 'approvals.php', 'approval');
    log_activity(period_name($month) . ' planını müşteriye gönderdi', 'project', (int)$month['project_id']);
    json_out(['ok' => true, 'message' => 'Plan müşteriye gönderildi.']);

/* ==================== TASKS ==================== */
case 'step_deliver':
    // The maker hands in the work: files and / or a link go on the work and its step finishes in one go
    require_staff();
    $step = row("SELECT * FROM task_steps WHERE id=?", [(int)$g('id')]);
    if (!$step || $step['status'] !== 'active' || $step['kind'] !== 'work') json_out(['ok' => false, 'error' => 'Teslim, sıradaki üretim adımına yapılır.']);
    $task = row("SELECT * FROM tasks WHERE id=?", [$step['task_id']]);
    if ($task['status'] === 'cancelled') json_out(['ok' => false, 'error' => 'İptal edilmiş işe teslim yapılmaz.']);
    if (!step_can_act($step, $u)) json_out(['ok' => false, 'error' => 'Bu adım size ait değil. Havuzdaysa önce "Ben alıyorum" deyin.']);
    $names = [];
    foreach (array_merge(['file'], array_map(fn($i) => "file__$i", range(0, 19))) as $field) {
        if (!($uploaded = file_upload($field))) continue;
        insert('archive', ['project_id' => $task['project_id'], 'task_id' => $task['id'], 'name' => $uploaded['name'], 'file_path' => $uploaded['path'],
            'size' => $uploaded['size'], 'extension' => $uploaded['extension'], 'uploader_id' => $u['id'], 'created' => $now]);
        $names[] = $uploaded['name'];
    }
    $link = trim($g('drive_link'));
    if ($link !== '') {
        if (!preg_match('#^https?://#i', $link)) $link = 'https://' . $link;
        insert('archive', ['project_id' => $task['project_id'], 'task_id' => $task['id'], 'name' => $step['name'] . ' — teslim bağlantısı', 'file_path' => '',
            'size' => 0, 'extension' => 'link', 'url' => mb_substr($link, 0, 500), 'uploader_id' => $u['id'], 'created' => $now]);
        $names[] = 'bağlantı';
    }
    if (!$names) json_out(['ok' => false, 'error' => $_FILES ? 'Dosya yüklenemedi. Boyut (max 50MB) veya tür uygun değil.' : 'Teslim için dosya ya da bağlantı ekleyin.']);
    $note = trim($g('note'));
    insert('comments', ['ref_type' => 'task', 'ref_id' => $task['id'], 'user_id' => $u['id'], 'created' => $now,
        'message' => '📎 ' . $step['name'] . ' teslim edildi: ' . implode(', ', $names) . ($note !== '' ? "\n" . $note : '')]);
    if ($error = step_finish($step, (int)$u['id'])) json_out(['ok' => false, 'error' => $error]);
    $next = task_active_step((int)$task['id']);
    json_out(['ok' => true, 'message' => 'Teslim edildi' . ($next ? '; sıra: ' . $next['name'] . '.' : '; tüm adımlar bitti.')]);

case 'event_task_link':
case 'event_task_unlink':
    // A shoot day can feed several pieces of work; linking lets the Drive transfer move their shoot step
    require_staff();
    $eventId = (int)$g('event_id'); $taskId = (int)$g('task_id');
    if (!val("SELECT id FROM events WHERE id=? AND type='shoot'", [$eventId]) || !val("SELECT id FROM tasks WHERE id=?", [$taskId])) json_out(['ok' => false, 'error' => 'Çekim ya da iş bulunamadı.']);
    if ($action === 'event_task_unlink') { q("DELETE FROM event_tasks WHERE event_id=? AND task_id=?", [$eventId, $taskId]); json_out(['ok' => true, 'message' => 'Çekim bağlantısı kaldırıldı.']); }
    q("INSERT IGNORE INTO event_tasks (event_id, task_id) VALUES (?,?)", [$eventId, $taskId]);
    json_out(['ok' => true, 'message' => 'İş çekime bağlandı.']);
case 'task_save':
    require_staff();
    $id = (int)$g('id');
    // Creating needs task_create — or content_manage when planning a publish date from the content calendar
    if (!$id && !permission('task_create') && !(permission('content_manage') && $g('publish_date'))) json_out(['ok' => false, 'error' => 'İş oluşturma yetkiniz yok.']);
    $data = [
        'project_id' => (int)$g('project_id'), 'title' => trim($g('title')), 'description' => $g('description'),
        'assignee_id' => $g('assignee_id') ? (int)$g('assignee_id') : null,
        'priority' => isset(PRIORITIES[$g('priority')]) ? $g('priority') : 'normal', 'due_date' => $g('due_date') ?: null,
        'depends_on_id' => $g('depends_on_id') ? (int)$g('depends_on_id') : null,
        'repeat' => isset(REPEAT_OPTIONS[$g('repeat')]) ? $g('repeat') : 'none',
        'tags' => mb_substr(trim($g('tags')), 0, 255) ?: null,
        'estimated_minutes' => max(0, (int)((float)str_replace(',', '.', $g('estimated_time', '0')) * 60)),
        'start_date' => $g('start_date') ?: null,
    ];
    // Kind and publish plan are only touched when the form sends them (forms without them keep the stored values)
    if (array_key_exists('kind', $_POST)) $data['kind'] = isset(TASK_KINDS[$g('kind')]) ? $g('kind') : 'client';
    $kind = $data['kind'] ?? ($id ? (string)val("SELECT kind FROM tasks WHERE id=?", [$id]) : 'client');
    if (array_key_exists('publish_date', $_POST) || $kind === 'internal') {
        // Only client work is published; internal work has no publish plan
        $publishDate = preg_match('~^\d{4}-\d{2}-\d{2}$~', $g('publish_date')) ? $g('publish_date') : null;
        $platforms = json_decode($g('platforms', ''), true);
        $platformCsv = is_array($platforms) ? implode(',', array_values(array_intersect(array_map('strval', $platforms), array_keys(PLATFORMS)))) : '';
        $data['publish_date'] = $kind === 'client' ? $publishDate : null;
        $data['publish_time'] = $kind === 'client' && $publishDate && preg_match('~^\d{2}:\d{2}~', $g('publish_time')) ? $g('publish_time') : null;
        $data['platforms'] = $kind === 'client' ? ($platformCsv ?: null) : null;
    }
    // Planned work is the month's plan, agenda work came up during the month; internal work has no lane
    if (array_key_exists('lane', $_POST)) $data['lane'] = $kind === 'client' && isset(TASK_LANES[$g('lane')]) ? $g('lane') : 'planned';
    // The month is only changed when the form carries it (an edit form without it must not drop the work out of its month)
    if (!$id || array_key_exists('period_id', $_POST)) {
        $data['period_id'] = $g('period_id') ? (int)$g('period_id') : null;
        if ($data['period_id'] && (int)val("SELECT project_id FROM periods WHERE id=?", [$data['period_id']]) !== $data['project_id']) $data['period_id'] = null;
    }
    if ($data['title'] === '' || !$data['project_id']) json_out(['ok' => false, 'error' => 'İş başlığı ve proje gerekli.']);
    if ($data['depends_on_id'] === $id && $data['depends_on_id']) json_out(['ok' => false, 'error' => 'Bir iş kendisine bağlanamaz.']);
    // Planned from the calendar in a monthly project: file it under the month it is published in
    if (!$id && !$data['period_id'] && !empty($data['publish_date']) && val("SELECT type FROM projects WHERE id=?", [$data['project_id']]) === 'monthly')
        $data['period_id'] = get_or_create_period($data['project_id'], (int)substr($data['publish_date'], 0, 4), (int)substr($data['publish_date'], 5, 2));
    // Multi-assignee list (JSON); assignee_id is set to the first person for compatibility
    $assignees = json_decode($g('assignees', ''), true);
    if (is_array($assignees)) {
        $assignees = array_values(array_unique(array_filter(array_map('intval', $assignees))));
        $data['assignee_id'] = $assignees[0] ?? null;
    }
    if ($id) {
        $older = array_column(rows("SELECT user_id FROM task_assignees WHERE task_id=?", [$id]), 'user_id');
        update_row('tasks', $data, 'id=?', [$id]);
        if (is_array($assignees)) {
            q("DELETE FROM task_assignees WHERE task_id=?", [$id]);
            foreach ($assignees as $aid) {
                q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$id, $aid]);
                if (!in_array($aid, $older)) notify($aid, 'İş atandı', $data['title'], 'task.php?id=' . $id, 'task');
            }
        }
        json_out(['ok' => true, 'message' => 'İş güncellendi.']);
    } else {
        $data['created_by'] = $u['id']; $data['status'] = 'todo'; $data['created'] = $now;
        $data['sort_order'] = (int)val("SELECT COALESCE(MAX(sort_order),0)+1 FROM tasks WHERE project_id=? AND status='todo'", [$data['project_id']]);
        $id = insert('tasks', $data);
        $typeId = (int)$g('type_id');
        if ($typeId && val("SELECT id FROM task_types WHERE id=?", [$typeId])) {
            $owners = [];
            foreach ((json_decode($g('step_owners', ''), true) ?: []) as $typeStepId => $ownerId) $owners[(int)$typeStepId] = (int)$ownerId;
            if (!array_key_exists('kind', $_POST)) update_row('tasks', ['kind' => val("SELECT kind FROM task_types WHERE id=?", [$typeId])], 'id=?', [$id]);
            task_steps_setup($id, $typeId, $owners);
        }
        if (is_array($assignees)) {
            foreach ($assignees as $aid) {
                q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$id, $aid]);
                notify($aid, 'Yeni iş atandı', $data['title'], 'task.php?id=' . $id, 'task');
            }
        } elseif ($data['assignee_id']) {
            q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$id, $data['assignee_id']]);
            notify($data['assignee_id'], 'Yeni iş atandı', $data['title'], 'task.php?id=' . $id, 'task');
        }
        log_activity('"' . $data['title'] . '" işini oluşturdu', 'project', $data['project_id']);
        json_out(['ok' => true, 'message' => 'İş oluşturuldu.', 'id' => $id]);
    }

case 'task_status':
case 'task_sort':
    require_staff();
    $id = (int)$g('id');
    $task = row("SELECT * FROM tasks WHERE id=?", [$id]);
    if (!$task) json_out(['ok' => false, 'error' => 'İş bulunamadı.']);
    $newStatus = (string)$g('status');
    // Work with steps moves by its steps; by hand it can only be cancelled or reopened
    if (task_has_steps((int)$task['id']) && $newStatus !== 'cancelled') {
        if ($newStatus !== 'reopen' || $task['status'] !== 'cancelled') json_out(['ok' => false, 'error' => 'Bu işin durumu adımlarından geliyor: adımları ilerletin ya da işi iptal edin.']);
        update_row('tasks', ['status' => 'in_progress'], 'id=?', [$task['id']]);
        task_sync_from_steps((int)$task['id']);
        json_out(['ok' => true, 'message' => 'İş yeniden açıldı.']);
    }
    if ($error = task_set_status($task, $newStatus)) json_out(['ok' => false, 'error' => $error]);
    // Save the in-column sort order
    $ids = json_decode($g('ids', '[]'), true);
    if (is_array($ids) && $ids) {
        $st = db()->prepare("UPDATE tasks SET sort_order=? WHERE id=?");
        foreach (array_values($ids) as $i => $gid) $st->execute([$i + 1, (int)$gid]);
    }
    json_out(['ok' => true]);
case 'task_delete':
    require_permission('task_delete');
    $id = (int)$g('id');
    foreach (['task_steps', 'time_entries', 'task_checklist', 'event_tasks'] as $t) q("DELETE FROM $t WHERE task_id=?", [$id]);
    q("UPDATE tasks SET depends_on_id=NULL WHERE depends_on_id=?", [$id]);
    q("DELETE FROM tasks WHERE id=?", [$id]);
    json_out(['ok' => true, 'message' => 'İş silindi.', 'redirect' => 'tasks.php']);

case 'task_field':
    // Inline cell editing in table view (per-field update)
    require_staff();
    $id = (int)$g('id');
    $field = $g('field');
    $value = $g('value');
    $task = row("SELECT * FROM tasks WHERE id=?", [$id]);
    if (!$task) json_out(['ok' => false, 'error' => 'İş bulunamadı.']);
    $allowed = ['status', 'priority', 'assignee_id', 'due_date', 'start_date', 'publish_date', 'estimated_minutes', 'tags'];
    if (!in_array($field, $allowed)) json_out(['ok' => false, 'error' => 'Bu alan düzenlenemez.']);
    if ($field === 'status') {
        $newStatus = (string)$value;
        // Work with steps moves by its steps; by hand it can only be cancelled or reopened
        if (task_has_steps((int)$task['id']) && $newStatus !== 'cancelled') {
                if ($newStatus !== 'reopen' || $task['status'] !== 'cancelled') json_out(['ok' => false, 'error' => 'Bu işin durumu adımlarından geliyor: adımları ilerletin ya da işi iptal edin.']);
                update_row('tasks', ['status' => 'in_progress'], 'id=?', [$task['id']]);
                task_sync_from_steps((int)$task['id']);
                json_out(['ok' => true, 'message' => 'İş yeniden açıldı.']);
        }
        if ($error = task_set_status($task, $newStatus)) json_out(['ok' => false, 'error' => $error]);
    } elseif ($field === 'publish_date' && $task['kind'] !== 'client') {
        json_out(['ok' => false, 'error' => 'İç işlerin yayın tarihi olmaz.']);
    } elseif ($field === 'priority') {
        if (!isset(PRIORITIES[$value])) json_out(['ok' => false, 'error' => 'Geçersiz öncelik.']);
        update_row('tasks', ['priority' => $value], 'id=?', [$id]);
    } elseif ($field === 'assignee_id') {
        $new = $value ? (int)$value : null;
        update_row('tasks', ['assignee_id' => $new], 'id=?', [$id]);
        if ($task['assignee_id']) q("DELETE FROM task_assignees WHERE task_id=? AND user_id=?", [$id, $task['assignee_id']]);
        if ($new) {
            q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$id, $new]);
            if ($new != $task['assignee_id']) notify($new, 'İş atandı', $task['title'], 'task.php?id=' . $id, 'task');
        }
    } elseif ($field === 'estimated_minutes') {
        update_row('tasks', ['estimated_minutes' => max(0, (int)((float)str_replace(',', '.', $value) * 60))], 'id=?', [$id]);
    } elseif ($field === 'tags') {
        update_row('tasks', ['tags' => mb_substr(trim($value), 0, 255) ?: null], 'id=?', [$id]);
    } else { // date fields
        update_row('tasks', [$field => $value ?: null], 'id=?', [$id]);
    }
    json_out(['ok' => true]);

case 'task_archive':
    require_staff();
    $id = (int)$g('id');
    $task = row("SELECT * FROM tasks WHERE id=?", [$id]);
    if (!$task) json_out(['ok' => false, 'error' => 'İş bulunamadı.']);
    $new = $task['is_archived'] ? 0 : 1;
    update_row('tasks', ['is_archived' => $new], 'id=?', [$id]);
    log_activity('"' . $task['title'] . '" işini ' . ($new ? 'arşivledi' : 'arşivden çıkardı'), 'task', $id);
    json_out(['ok' => true, 'message' => $new ? 'İş arşive taşındı.' : 'İş arşivden çıkarıldı.', 'redirect' => $new ? 'tasks.php' : '']);

case 'view_preference':
    require_login();
    $view = in_array($g('view'), ['kanban', 'table']) ? $g('view') : 'kanban';
    update_row('users', ['task_view' => $view], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

case 'watcher_toggle':
    require_staff();
    $gid = (int)$g('task_id');
    $target = (int)$g('user_id');
    if (!val("SELECT COUNT(*) FROM tasks WHERE id=?", [$gid])) json_out(['ok' => false, 'error' => 'İş bulunamadı.']);
    $var = val("SELECT COUNT(*) FROM task_watchers WHERE task_id=? AND user_id=?", [$gid, $target]);
    if ($var) { q("DELETE FROM task_watchers WHERE task_id=? AND user_id=?", [$gid, $target]); $m = 'İzleyici çıkarıldı.'; }
    else {
        q("INSERT IGNORE INTO task_watchers (task_id, user_id) VALUES (?,?)", [$gid, $target]);
        $title = val("SELECT title FROM tasks WHERE id=?", [$gid]);
        notify($target, 'Bir işe izleyici eklendiniz', $title, 'task.php?id=' . $gid, 'task');
        $m = 'İzleyici eklendi.';
    }
    json_out(['ok' => true, 'message' => $m]);

case 'task_publish_move':
    // Content calendar: drag a deliverable to another day, or set its publish time
    require_permission('content_manage');
    $task = row("SELECT id, kind, publish_date FROM tasks WHERE id=?", [(int)$g('id')]);
    if (!$task || $task['kind'] !== 'client') json_out(['ok' => false, 'error' => 'İş bulunamadı.']);
    $new = ['publish_date' => preg_match('~^\d{4}-\d{2}-\d{2}$~', $g('date')) ? $g('date') : $task['publish_date']];
    if ($g('time') !== '') $new['publish_time'] = preg_match('~^\d{2}:\d{2}~', $g('time')) ? $g('time') : null;
    update_row('tasks', $new, 'id=?', [$task['id']]);
    json_out(['ok' => true, 'message' => 'Yayın ' . format_date($new['publish_date']) . ' tarihine taşındı.']);
case 'event_move':
    require_permission('calendar_manage');
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
        $diff = strtotime($day) - strtotime(date('Y-m-d', strtotime($et['start'])));
        $newInitial = date('Y-m-d H:i:s', strtotime($et['start']) + $diff);
        $newBit = $et['end'] ? date('Y-m-d H:i:s', strtotime($et['end']) + $diff) : null;
    }
    update_row('events', ['start' => $newInitial, 'end' => $newBit, 'is_reminded' => 0], 'id=?', [$et['id']]);
    json_out(['ok' => true, 'message' => 'Etkinlik taşındı: ' . format_date($newInitial, true)]);

case 'clientnote_save':
    require_staff();
    $title = mb_substr(trim($g('title')), 0, 150);
    if ($title === '') json_out(['ok' => false, 'error' => 'Bölüm başlığı gerekli.']);
    $category = isset(NOTE_CATEGORIES[$g('category')]) ? $g('category') : 'general';
    if ($g('id')) {
        update_row('client_notes', ['title' => $title, 'text' => $g('text'), 'category' => $category, 'pinned' => (int)(bool)$g('pinned'), 'updated_by' => $u['id'], 'update' => $now], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'message' => 'Not güncellendi.']);
    }
    insert('client_notes', ['client_id' => (int)$g('client_id'), 'title' => $title, 'text' => $g('text'), 'category' => $category, 'pinned' => (int)(bool)$g('pinned'), 'sort_order' => (int)val("SELECT COALESCE(MAX(sort_order),0)+1 FROM client_notes WHERE client_id=?", [(int)$g('client_id')]), 'updated_by' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'message' => 'Bilgi notu eklendi.']);

case 'clientnote_delete':
    require_staff();
    q("DELETE FROM client_notes WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Not silindi.']);

case 'ptemplate_save':
    require_admin();
    $name = trim($g('name'));
    $templateTasks = json_decode($g('tasks', '[]'), true) ?: [];
    $templateTasks = array_values(array_filter(array_map(fn($s) => ['title' => mb_substr(trim($s['title'] ?? ''), 0, 200), 'type_id' => (int)($s['type_id'] ?? 0), 'priority' => isset(PRIORITIES[$s['priority'] ?? '']) ? $s['priority'] : 'normal'], $templateTasks), fn($s) => $s['title'] !== ''));
    if ($name === '' || !$templateTasks) json_out(['ok' => false, 'error' => 'Ad ve en az bir iş gerekli.']);
    if ($g('id')) {
        update_row('project_templates', ['name' => $name, 'description' => $g('description'), 'tasks' => json_encode($templateTasks, JSON_UNESCAPED_UNICODE)], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'message' => 'Şablon güncellendi.']);
    }
    insert('project_templates', ['name' => $name, 'description' => $g('description'), 'tasks' => json_encode($templateTasks, JSON_UNESCAPED_UNICODE), 'created' => $now]);
    json_out(['ok' => true, 'message' => 'Proje şablonu kaydedildi.']);

case 'ptemplate_delete':
    require_admin();
    q("DELETE FROM project_templates WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Şablon silindi.']);

case 'lock_toggle':
    require_pm(); // only admins and PMs can disable the lock
    $id = (int)$g('id');
    $task = row("SELECT * FROM tasks WHERE id=?", [$id]);
    if (!$task) json_out(['ok' => false, 'error' => 'İş bulunamadı.']);
    $new = $task['lock_bypassed'] ? 0 : 1;
    update_row('tasks', ['lock_bypassed' => $new], 'id=?', [$id]);
    log_activity('"' . $task['title'] . '" işinin kilidini ' . ($new ? 'devre dışı bıraktı' : 'etkinleştirdi'), 'task', $id);
    json_out(['ok' => true, 'message' => $new ? 'Kilit devre dışı — iş serbestçe ilerletilebilir.' : 'Kilit yeniden etkin.']);

case 'step_complete':
    // Finish the active step (work / review approve / publish); a done step toggles back open (undo)
    require_staff();
    $step = row("SELECT * FROM task_steps WHERE id=?", [(int)$g('id')]);
    if (!$step) json_out(['ok' => false, 'error' => 'Adım bulunamadı.']);
    $task = row("SELECT * FROM tasks WHERE id=?", [$step['task_id']]);
    if ($task['status'] === 'cancelled') json_out(['ok' => false, 'error' => 'İptal edilmiş işin adımları değiştirilemez.']);
    if ($step['status'] === 'done') {
        if (!is_pm() && (int)$step['done_by'] !== (int)$u['id'] && (int)$step['owner_id'] !== (int)$u['id']) json_out(['ok' => false, 'error' => 'Bu adımı yalnızca bitiren kişi ya da yönetici geri açabilir.']);
        $error = step_reopen($step);
    } else {
        if ($step['status'] !== 'active' && empty($task['lock_bypassed'])) json_out(['ok' => false, 'error' => '🔒 Önce sıradaki adımlar bitmeli.' . (is_pm() ? ' (İş sayfasından kilidi devre dışı bırakabilirsiniz.)' : '')]);
        if (!step_can_act($step, $u)) json_out(['ok' => false, 'error' => 'Bu adım size ait değil. Havuzdaysa önce "Ben alıyorum" deyin.']);
        // The client's approval comes from the client; managers may record it on their behalf
        if ($step['kind'] === 'client_approval' && !is_pm()) json_out(['ok' => false, 'error' => 'Müşteri onayı müşteriden gelir: "Müşteriye gönder" ile yollayın.']);
        if ($step['status'] !== 'active') { update_row('task_steps', ['status' => 'active'], 'id=?', [$step['id']]); $step['status'] = 'active'; }
        $error = step_finish($step, (int)$u['id']);
    }
    if ($error) json_out(['ok' => false, 'error' => $error]);
    $stepsLast = rows("SELECT id, sort_order, status FROM task_steps WHERE task_id=? ORDER BY sort_order, id", [$step['task_id']]);
    $taskLast = row("SELECT status FROM tasks WHERE id=?", [$step['task_id']]);
    json_out([
        'ok' => true, 'message' => $step['status'] === 'done' ? 'Adım yeniden açıldı.' : 'Adım tamamlandı.',
        'steps' => $stepsLast,
        'done_count' => count(array_filter($stepsLast, fn($a) => $a['status'] === 'done')),
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
    // Hand a step to someone (or back to the skill pool); managers, or the owner passing it on
    require_staff();
    $step = row("SELECT * FROM task_steps WHERE id=?", [(int)$g('id')]);
    if (!$step) json_out(['ok' => false, 'error' => 'Adım bulunamadı.']);
    if (!is_pm() && (int)$step['owner_id'] !== (int)$u['id']) json_out(['ok' => false, 'error' => 'Adımı yalnızca sahibi ya da yönetici devredebilir.']);
    $newOwner = $g('owner_id') ? (int)$g('owner_id') : null;
    update_row('task_steps', ['owner_id' => $newOwner], 'id=?', [$step['id']]);
    if ($newOwner) q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$step['task_id'], $newOwner]);
    if ($step['status'] === 'active') { $step['owner_id'] = $newOwner; step_announce($step); task_sync_from_steps((int)$step['task_id']); }
    elseif ($newOwner) notify($newOwner, 'Akış adımı size atandı', val("SELECT title FROM tasks WHERE id=?", [$step['task_id']]) . ' → ' . $step['name'], 'task.php?id=' . $step['task_id'], 'task');
    json_out(['ok' => true, 'message' => $newOwner ? 'Sorumlu atandı.' : 'Adım havuza bırakıldı.']);

case 'step_claim':
    // "Ben alıyorum": take an unowned active step from your skill's pool (two people can't both take it)
    require_staff();
    $step = row("SELECT * FROM task_steps WHERE id=?", [(int)$g('id')]);
    if (!$step || $step['status'] !== 'active') json_out(['ok' => false, 'error' => 'Bu adım şu an alınamaz.']);
    if ($step['skill_id'] && !is_pm() && !in_array((int)$step['skill_id'], user_skill_ids((int)$u['id']), true))
        json_out(['ok' => false, 'error' => 'Bu adım ' . val("SELECT name FROM skills WHERE id=?", [$step['skill_id']]) . ' uzmanlığı isteyen bir havuzda.']);
    $taken = q("UPDATE task_steps SET owner_id=? WHERE id=? AND owner_id IS NULL AND status='active'", [$u['id'], $step['id']])->rowCount();
    if (!$taken) json_out(['ok' => false, 'error' => 'Bu adımı az önce başkası aldı.']);
    q("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)", [$step['task_id'], $u['id']]);
    task_sync_from_steps((int)$step['task_id']);
    log_activity('"' . $step['name'] . '" adımını havuzdan aldı', 'task', (int)$step['task_id']);
    json_out(['ok' => true, 'message' => 'Adım sende.']);

case 'step_return':
    // Send the work back from an internal review to the last production step, with the reason
    require_staff();
    $step = row("SELECT * FROM task_steps WHERE id=?", [(int)$g('id')]);
    if (!$step || $step['status'] !== 'active') json_out(['ok' => false, 'error' => 'Yalnızca sıradaki adım geri gönderilebilir.']);
    if (!in_array($step['kind'], ['review', 'client_approval'], true)) json_out(['ok' => false, 'error' => 'Geri gönderme kontrol ve onay adımlarında yapılır.']);
    if (!step_can_act($step, $u)) json_out(['ok' => false, 'error' => 'Bu adım size ait değil.']);
    if (trim($g('note')) === '') json_out(['ok' => false, 'error' => 'Neyin değişmesi gerektiğini yazın.']);
    if ($error = step_send_back($step, $g('note'), (int)$u['id'])) json_out(['ok' => false, 'error' => $error]);
    json_out(['ok' => true, 'message' => 'Geri gönderildi.']);
/* ==================== TIME TRACKING ==================== */
case 'time_add':
    require_staff();
    $min = (int)$g('time') * 60 + (int)$g('minutes');
    if ($min <= 0) json_out(['ok' => false, 'error' => 'Süre girin.']);
    insert('time_entries', [
        'task_id' => (int)$g('task_id'), 'user_id' => $u['id'], 'minutes' => $min,
        'date' => $g('date') ?: date('Y-m-d'), 'description' => $g('description'), 'created' => $now,
    ]);
    json_out(['ok' => true, 'message' => format_minutes($min) . ' zaman kaydedildi.']);

/* ==================== COMMENTS ==================== */
case 'comment_add':
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
        'message' => $message, 'created' => $now,
        'parent_id' => $g('parent_id') ? (int)$g('parent_id') : null,
    ];
    // File attachment
    $ek = file_upload('file');
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
        $recipients = array_column(rows("SELECT user_id FROM task_watchers WHERE task_id=?", [$refId]), 'user_id');
        if ($context['assignee_id']) $recipients[] = (int)$context['assignee_id'];
        foreach (array_unique($recipients) as $aid)
            notify((int)$aid, 'Yeni yorum: ' . $context['title'], $u['name'] . ': ' . mb_substr($message, 0, 80), $link, 'message');
    } else {
        $context = row("SELECT name title FROM projects WHERE id=?", [$refId]);
        $link = 'project.php?id=' . $refId . '#discussion';
        foreach (rows("SELECT user_id FROM project_members WHERE project_id=?", [$refId]) as $pu)
            notify((int)$pu['user_id'], 'Proje tartışması: ' . ($context['title'] ?? ''), $u['name'] . ': ' . mb_substr($message, 0, 80), $link, 'message');
    }
    // If it is a reply, notify the parent comment's owner
    if ($data['parent_id']) {
        $topOwner = (int)val("SELECT user_id FROM comments WHERE id=?", [$data['parent_id']]);
        if ($topOwner) notify($topOwner, $u['name'] . ' yorumunuza yanıt verdi', mb_substr($message, 0, 80), $link, 'message');
    }
    // Notify mentioned users
    notify_mentions($g('mention_ids', ''), $u['name'] . ' sizi etiketledi', mb_substr($message, 0, 90), $link);
    json_out(['ok' => true, 'message' => 'Yorum eklendi.']);

case 'comment_edit':
    require_login();
    $comment = row("SELECT * FROM comments WHERE id=?", [(int)$g('id')]);
    if (!$comment || $comment['user_id'] != $u['id']) json_out(['ok' => false, 'error' => 'Yalnızca kendi yorumunuzu düzenleyebilirsiniz.']);
    $message = trim($g('message'));
    if ($message === '') json_out(['ok' => false, 'error' => 'Yorum boş olamaz.']);
    update_row('comments', ['message' => $message, 'is_edited' => 1], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Yorum güncellendi.']);

case 'comment_delete':
    require_login();
    $comment = row("SELECT * FROM comments WHERE id=?", [(int)$g('id')]);
    if (!$comment || ($comment['user_id'] != $u['id'] && !is_admin())) json_out(['ok' => false, 'error' => 'Bu yorumu silme yetkiniz yok.']);
    q("DELETE FROM comments WHERE id=? OR parent_id=?", [(int)$g('id'), (int)$g('id')]);
    q("DELETE FROM comment_reactions WHERE comment_id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Yorum silindi.']);

case 'reaction_toggle':
    require_login();
    $commentId = (int)$g('comment_id');
    $emoji = mb_substr(trim($g('emoji')), 0, 8);
    if (!$emoji || !val("SELECT COUNT(*) FROM comments WHERE id=?", [$commentId])) json_out(['ok' => false, 'error' => 'Geçersiz.']);
    $var = val("SELECT COUNT(*) FROM comment_reactions WHERE comment_id=? AND user_id=? AND emoji=?", [$commentId, $u['id'], $emoji]);
    if ($var) q("DELETE FROM comment_reactions WHERE comment_id=? AND user_id=? AND emoji=?", [$commentId, $u['id'], $emoji]);
    else q("INSERT INTO comment_reactions (comment_id, user_id, emoji) VALUES (?,?,?)", [$commentId, $u['id'], $emoji]);
    $qty = (int)val("SELECT COUNT(*) FROM comment_reactions WHERE comment_id=? AND emoji=?", [$commentId, $emoji]);
    json_out(['ok' => true, 'qty' => $qty, 'mine' => $var ? 0 : 1]);

/* ==================== APPROVALS ==================== */
case 'approval_send':
    require_permission('approval_send');
    $task = row("SELECT * FROM tasks WHERE id=?", [(int)$g('task_id')]);
    if (!$task) json_out(['ok' => false, 'error' => 'Müşteriye gönderilecek işi seçin.']);
    if ($task['kind'] !== 'client') json_out(['ok' => false, 'error' => 'İç işler müşteriye gönderilmez.']);
    $activeStep = task_active_step((int)$task['id']);
    if (task_has_steps((int)$task['id']) && (!$activeStep || $activeStep['kind'] !== 'client_approval'))
        json_out(['ok' => false, 'error' => 'Bu iş henüz müşteri onayı adımına gelmedi' . ($activeStep ? ' (sıradaki adım: ' . $activeStep['name'] . ')' : '') . '.']);
    $data = [
        'project_id' => (int)$task['project_id'], 'task_id' => (int)$task['id'],
        'title' => trim($g('title')) ?: $task['title'], 'description' => $g('description'),
        'sender_id' => $u['id'], 'status' => 'pending', 'created' => $now, 'token' => bin2hex(random_bytes(16)),
    ];
    // File attachment or Drive link (the file also appears among the task's attachments)
    $uploaded = file_upload('file');
    if ($uploaded) {
        $data['archive_id'] = insert('archive', [
            'project_id' => $data['project_id'], 'task_id' => $data['task_id'], 'name' => $uploaded['name'], 'file_path' => $uploaded['path'],
            'size' => $uploaded['size'], 'extension' => $uploaded['extension'], 'uploader_id' => $u['id'], 'created' => $now,
        ]);
    }
    $dLink = trim($g('drive_link'));
    if ($dLink !== '') {
        if (!preg_match('#^https?://#i', $dLink)) $dLink = 'https://' . $dLink;
        $data['drive_link'] = mb_substr($dLink, 0, 500);
    }
    insert('approvals', $data);
    // Notify the customer (primary client file + extra file assignments)
    $clientId = (int)val("SELECT client_id FROM projects WHERE id=?", [$data['project_id']]);
    foreach (client_customer_ids($clientId) as $cid) notify($cid, 'Onayınız bekleniyor', $data['title'], 'approvals.php', 'approval');
    // Clients without an account answer through the link; it can go to the file's contact by e-mail
    $mailed = $g('send_email') && approval_mail_contact($clientId, $data['title'], (string)$data['description'], $data['token']);
    // The work now waits on the client
    if (task_is_open($task['status'])) task_set_status($task, 'awaiting_approval', false);
    log_activity('"' . $data['title'] . '" için onay gönderdi', 'project', $data['project_id']);
    json_out(['ok' => true, 'message' => 'Müşteriye gönderildi.' . ($mailed ? ' Onay linki e-postayla da iletildi.' : ''), 'link' => full_url('approve.php?t=' . $data['token'])]);

case 'approval_reply':
    require_login();
    $id = (int)$g('id');
    $approval = row("SELECT * FROM approvals WHERE id=?", [$id]);
    if (!$approval || !project_access($approval['project_id'])) json_out(['ok' => false, 'error' => 'Yetkisiz.']);
    $status = $g('status');
    if (!in_array($status, ['approved', 'revision', 'rejected'])) json_out(['ok' => false, 'error' => 'Geçersiz.']);
    // Moves the work / month; only the current approval of the work counts (shared with the account-free link page)
    approval_apply_reply($approval, $status, (string)$g('note'), (int)$u['id']);
    json_out(['ok' => true, 'message' => 'Yanıtınız kaydedildi.']);

case 'approval_link':
    // The account-free answer link, to share on WhatsApp or by e-mail
    require_permission('approval_send');
    $approval = row("SELECT * FROM approvals WHERE id=?", [(int)$g('id')]);
    if (!$approval || !project_access((int)$approval['project_id'])) json_out(['ok' => false, 'error' => 'Onay bulunamadı.']);
    if ($approval['status'] !== 'pending' || !approval_is_current($approval)) json_out(['ok' => false, 'error' => 'Bu onay artık cevap beklemiyor.']);
    json_out(['ok' => true, 'link' => approval_link($approval), 'title' => $approval['title']]);
/* ==================== SOCIAL MEDIA TRACKING ==================== */
case 'social_account_add':
    require_permission('content_manage');
    $clientId = (int)$g('client_id');
    $username = trim($g('username'));
    if (!$clientId || $username === '') json_out(['ok' => false, 'error' => 'Dosya ve kullanıcı adı gerekli.']);
    $url = trim($g('url'));
    if ($url && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    insert('social_accounts', [
        'client_id' => $clientId,
        'platform' => isset(PLATFORMS[$g('platform')]) ? $g('platform') : 'instagram',
        'username' => mb_substr($username, 0, 100), 'url' => $url ?: null, 'created' => $now,
    ]);
    json_out(['ok' => true, 'message' => 'Sosyal medya hesabı eklendi.']);

case 'social_account_delete':
    require_permission('content_manage');
    q("DELETE FROM social_metrics WHERE account_id=?", [(int)$g('id')]);
    q("DELETE FROM social_accounts WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Hesap ve metrik geçmişi silindi.']);

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
    json_out(['ok' => true, 'message' => 'Metrik kaydedildi.']);

case 'social_metric_delete':
    require_permission('content_manage');
    q("DELETE FROM social_metrics WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Kayıt silindi.']);

/* ==================== EVENTS / CALENDAR ==================== */
case 'event_save':
    require_permission('calendar_manage');
    $data = [
        'project_id' => $g('project_id') ? (int)$g('project_id') : null,
        'client_id' => $g('client_id') ? (int)$g('client_id') : null,
        'title' => trim($g('title')), 'type' => $g('type', 'shoot'),
        'start' => $g('start'), 'end' => $g('end') ?: null,
        'place' => $g('place'), 'description' => $g('description'), 'participants' => $g('participants'),
        'online_link' => trim($g('online_link')) ?: null,
        'shopping_list' => trim($g('shopping_list')) ?: null,
        'needs_list' => trim($g('needs_list')) ?: null,
        'cost' => max(0, (float)str_replace(',', '.', $g('cost', '0'))),
    ];
    // Drive folder for the shoot (URL or bare ID)
    if (isset($_POST['drive_folder'])) {
        $df = trim($g('drive_folder'));
        if (preg_match('~folders/([A-Za-z0-9_-]{10,})~', $df, $folderMatch)) $df = $folderMatch[1];
        $data['drive_folder_id'] = $df !== '' ? mb_substr($df, 0, 120) : null;
    }
    if ($data['title'] === '' || !$data['start']) json_out(['ok' => false, 'error' => 'Başlık ve tarih gerekli.']);
    // Only budget-permitted users may set the cost; others keep the existing value
    if (!permission('budget_view')) unset($data['cost']);
    // Shoot cost → automatic expense record in Finance (upsert/delete by event_id)
    $syncEventExpense = function (int $eventId) use ($data) {
        if (!array_key_exists('cost', $data)) return;
        $existing = row("SELECT id FROM expenses WHERE event_id=?", [$eventId]);
        if ($data['cost'] > 0) {
            $expense = ['type' => 'other', 'title' => 'Çekim: ' . $data['title'], 'amount' => $data['cost'],
                'date' => substr($data['start'], 0, 10), 'status' => 'paid', 'description' => 'Çekim maliyeti (otomatik kayıt)', 'event_id' => $eventId];
            if ($existing) update_row('expenses', $expense, 'id=?', [$existing['id']]);
            else insert('expenses', $expense + ['created' => date('Y-m-d H:i:s')]);
        } elseif ($existing) {
            q("DELETE FROM expenses WHERE id=?", [$existing['id']]);
        }
    };
    // In-system participants (for meetings)
    $participantIds = json_decode($g('participant_ids', ''), true);
    if ($g('id')) {
        $eventId = (int)$g('id');
        update_row('events', $data, 'id=?', [$eventId]);
        $syncEventExpense($eventId);
        if (is_array($participantIds)) {
            $older = array_column(rows("SELECT user_id FROM event_participants WHERE event_id=?", [$eventId]), 'user_id');
            q("DELETE FROM event_participants WHERE event_id=?", [$eventId]);
            foreach (array_unique(array_map('intval', $participantIds)) as $kid) {
                if (!$kid) continue;
                q("INSERT IGNORE INTO event_participants (event_id, user_id) VALUES (?,?)", [$eventId, $kid]);
                if (!in_array($kid, $older)) notify($kid, '📅 Toplantıya davet edildiniz', $data['title'] . ' — ' . format_date($data['start'], true), 'meetings.php', 'task');
            }
        }
        json_out(['ok' => true, 'message' => 'Etkinlik güncellendi.']);
    }
    $data['created_by'] = $u['id']; $data['created'] = $now;
    $eventId = insert('events', $data);
    // Planned from a piece of work: the shoot is linked to it
    if ($data['type'] === 'shoot' && $g('task_id') && val("SELECT id FROM tasks WHERE id=?", [(int)$g('task_id')])) q("INSERT IGNORE INTO event_tasks (event_id, task_id) VALUES (?,?)", [$eventId, (int)$g('task_id')]);
    $syncEventExpense($eventId);
    // Shoot planned → its Drive upload folder is created right away (best effort)
    $folderMessage = '';
    if ($data['type'] === 'shoot' && empty($data['drive_folder_id'])) {
        require_once __DIR__ . '/includes/google-drive.php';
        if (drive_configured()) {
            $ev = row("SELECT e.*, c.name client_name, c.drive_folder_id client_folder
                FROM events e LEFT JOIN clients c ON c.id = COALESCE(e.client_id, (SELECT client_id FROM projects WHERE id=e.project_id))
                WHERE e.id=?", [$eventId]);
            $r = $ev ? event_drive_folder($ev) : null;
            $folderMessage = $r ? ' Drive klasörü oluşturuldu.' : '';
        }
    }
    if (is_array($participantIds)) {
        foreach (array_unique(array_map('intval', $participantIds)) as $kid) {
            if (!$kid) continue;
            q("INSERT IGNORE INTO event_participants (event_id, user_id) VALUES (?,?)", [$eventId, $kid]);
            notify($kid, '📅 Toplantıya davet edildiniz', $data['title'] . ' — ' . format_date($data['start'], true), 'meetings.php', 'task');
        }
    }
    // Check out the selected equipment for the shoot
    $equipmentIds = json_decode($g('equipment', '[]'), true) ?: [];
    $skippable = [];
    foreach (array_unique(array_map('intval', $equipmentIds)) as $eid) {
        $ek = row("SELECT * FROM equipment WHERE id=?", [$eid]);
        if (!$ek) continue;
        if ($ek['status'] !== 'in_studio') { $skippable[] = $ek['name']; continue; }
        q("INSERT IGNORE INTO event_equipment (event_id, equipment_id) VALUES (?,?)", [$eventId, $eid]);
        update_row('equipment', ['status' => 'on_shoot', 'custody_event_id' => $eventId, 'custody_user_id' => $u['id']], 'id=?', [$eid]);
        log_equipment($eid, 'shoot_out', $data['title'], (int)$u['id'], $eventId);
    }
    $messageEk = $skippable ? ' (müsait olmayanlar atlandı: ' . implode(', ', $skippable) . ')' : '';
    json_out(['ok' => true, 'message' => 'Etkinlik eklendi.' . $folderMessage . $messageEk]);

case 'event_delete':
    require_staff();
    q("DELETE FROM event_tasks WHERE event_id=?", [(int)$g('id')]);
    q("DELETE FROM events WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Etkinlik silindi.']);

/* ==================== MESSAGING ==================== */
case 'message_send':
    require_login();
    $channelId = (int)$g('channel_id');
    $message = trim($g('message'));
    if ($message === '') json_out(['ok' => false, 'error' => 'Mesaj boş.']);
    // Membership check
    if (!val("SELECT COUNT(*) FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]))
        json_out(['ok' => false, 'error' => 'Bu kanala erişiminiz yok.']);
    $id = insert('messages', ['channel_id' => $channelId, 'user_id' => $u['id'], 'message' => $message, 'created' => $now]);
    update_row('channel_members', ['last_read' => $now], 'channel_id=? AND user_id=?', [$channelId, $u['id']]);
    // Notify other members
    foreach (rows("SELECT user_id FROM channel_members WHERE channel_id=? AND user_id!=?", [$channelId, $u['id']]) as $member) {
        notify($member['user_id'], $u['name'] . ' mesaj gönderdi', mb_substr($message, 0, 80), 'messages.php?channel=' . $channelId, 'message', false);
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
    require_permission('channel_create');
    $name = trim($g('name'));
    if ($name === '') json_out(['ok' => false, 'error' => 'Kanal adı gerekli.']);
    $channelId = insert('channels', ['name' => $name, 'type' => 'general', 'created' => $now]);
    $members = json_decode($g('members', '[]'), true) ?: [];
    $members[] = $u['id'];
    foreach (array_unique($members) as $uid)
        q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channelId, (int)$uid]);
    json_out(['ok' => true, 'message' => 'Kanal oluşturuldu.', 'redirect' => 'messages.php?channel=' . $channelId]);

case 'channel_member_add':
    require_staff();
    $channelId = (int)$g('channel_id');
    $targetId = (int)$g('user_id');
    $channel = row("SELECT * FROM channels WHERE id=?", [$channelId]);
    if (!$channel || $channel['type'] === 'private') json_out(['ok' => false, 'error' => 'Bu kanala üye eklenemez.']);
    if (!val("SELECT COUNT(*) FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]))
        json_out(['ok' => false, 'error' => 'Üyesi olmadığınız kanalı yönetemezsiniz.']);
    q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channelId, $targetId]);
    $target = row("SELECT name FROM users WHERE id=?", [$targetId]);
    notify($targetId, 'Bir sohbete eklendiniz', $channel['name'], 'messages.php?channel=' . $channelId, 'message');
    log_activity('"' . $channel['name'] . '" kanalına ' . ($target['name'] ?? '') . ' kişisini ekledi');
    json_out(['ok' => true, 'message' => 'Üye eklendi.']);

case 'channel_member_remove':
    require_staff();
    $channelId = (int)$g('channel_id');
    $targetId = (int)$g('user_id');
    $channel = row("SELECT * FROM channels WHERE id=?", [$channelId]);
    if (!$channel || $channel['type'] === 'private') json_out(['ok' => false, 'error' => 'Bu kanaldan üye çıkarılamaz.']);
    if (!is_pm() && $targetId !== (int)$u['id'])
        json_out(['ok' => false, 'error' => 'Başkasını çıkarmak için PM/yönetici olmalısınız.']);
    q("DELETE FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $targetId]);
    json_out(['ok' => true, 'message' => 'Üye çıkarıldı.']);

case 'channel_name':
    require_login();
    $channelId = (int)$g('channel_id');
    $channel = row("SELECT * FROM channels WHERE id=?", [$channelId]);
    if (!$channel || $channel['type'] === 'private') json_out(['ok' => false, 'error' => 'Bu sohbetin adı değiştirilemez.']);
    if (!val("SELECT COUNT(*) FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]))
        json_out(['ok' => false, 'error' => 'Bu kanalın üyesi değilsiniz.']);
    $name = mb_substr(trim($g('name')), 0, 120);
    if ($name === '') json_out(['ok' => false, 'error' => 'Kanal adı boş olamaz.']);
    update_row('channels', ['name' => $name], 'id=?', [$channelId]);
    log_activity('"' . $channel['name'] . '" kanalının adını "' . $name . '" yaptı');
    json_out(['ok' => true, 'message' => 'Sohbet adı güncellendi.']);

case 'channel_icon':
    require_login();
    $channelId = (int)$g('channel_id');
    if (!val("SELECT COUNT(*) FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]))
        json_out(['ok' => false, 'error' => 'Bu kanalın üyesi değilsiniz.']);
    update_row('channels', ['icon' => mb_substr(trim($g('icon')), 0, 8) ?: null], 'id=?', [$channelId]);
    json_out(['ok' => true, 'message' => 'Kanal simgesi güncellendi.']);

case 'channel_archive_toggle':
    require_login();
    $channelId = (int)$g('channel_id');
    $membership = row("SELECT * FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]);
    if (!$membership) json_out(['ok' => false, 'error' => 'Bu kanalın üyesi değilsiniz.']);
    $new = $membership['archive'] ? 0 : 1;
    update_row('channel_members', ['archive' => $new], 'channel_id=? AND user_id=?', [$channelId, $u['id']]);
    json_out(['ok' => true, 'message' => $new ? 'Sohbet arşivlendi.' : 'Sohbet arşivden çıkarıldı.', 'redirect' => 'messages.php']);

case 'channel_delete':
    require_login();
    $channelId = (int)$g('channel_id');
    $channel = row("SELECT * FROM channels WHERE id=?", [$channelId]);
    if (!$channel) json_out(['ok' => false, 'error' => 'Kanal bulunamadı.']);
    $memberMi = val("SELECT COUNT(*) FROM channel_members WHERE channel_id=? AND user_id=?", [$channelId, $u['id']]);
    // A private (DM) chat can be deleted by a participant; other channels by PM/admin
    if ($channel['type'] === 'private' ? !$memberMi : !is_pm())
        json_out(['ok' => false, 'error' => 'Bu sohbeti silme yetkiniz yok.']);
    q("DELETE FROM messages WHERE channel_id=?", [$channelId]);
    q("DELETE FROM channel_members WHERE channel_id=?", [$channelId]);
    q("DELETE FROM channels WHERE id=?", [$channelId]);
    log_activity('"' . $channel['name'] . '" sohbetini sildi');
    json_out(['ok' => true, 'message' => 'Sohbet silindi.', 'redirect' => 'messages.php']);

case 'dm_open':
    require_login();
    $targetId = (int)$g('user_id');
    if ($targetId === (int)$u['id']) json_out(['ok' => false, 'error' => 'Kendinizle sohbet açamazsınız.']);
    $target = row("SELECT * FROM users WHERE id=? AND is_active=1", [$targetId]);
    if (!$target) json_out(['ok' => false, 'error' => 'Kullanıcı bulunamadı.']);
    // Customers can only open DMs with staff
    if (is_customer() && $target['role'] === 'customer') json_out(['ok' => false, 'error' => 'Bu kişiyle sohbet açılamaz.']);
    // Is there an existing private channel between these two people?
    $current = row("SELECT k.id FROM channels k
        JOIN channel_members a ON a.channel_id=k.id AND a.user_id=?
        JOIN channel_members b ON b.channel_id=k.id AND b.user_id=?
        WHERE k.type='private' AND (SELECT COUNT(*) FROM channel_members x WHERE x.channel_id=k.id)=2", [$u['id'], $targetId]);
    if ($current) json_out(['ok' => true, 'redirect' => 'messages.php?channel=' . $current['id']]);
    $channelId = insert('channels', ['name' => 'DM', 'type' => 'private', 'created' => $now]);
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
        $results['İşler'] = array_map(fn($r) => ['name' => $r['title'], 'bottom' => TASK_STATUSES[$r['status']], 'link' => 'task.php?id=' . $r['id']],
            rows("SELECT id, title, status FROM tasks WHERE title LIKE ? ORDER BY " . task_open_sql() . " DESC, id DESC LIMIT 8", [$search]));
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
    require_permission('equipment_manage');
    $data = [
        'code' => mb_substr(trim($g('code')), 0, 20) ?: null,
        'name' => trim($g('name')),
        'category' => isset(EQUIPMENT_CATEGORIES[$g('category')]) ? $g('category') : 'other',
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
        json_out(['ok' => true, 'message' => 'Ekipman güncellendi.']);
    }
    $data['created'] = $now;
    if ($data['category'] === 'sd_card') $data['sd_status'] = 'empty';
    $eid = insert('equipment', $data);
    log_equipment($eid, 'added', $data['name']);
    json_out(['ok' => true, 'message' => 'Ekipman envantere eklendi.']);

case 'equipment_delete':
    require_permission('equipment_manage');
    $ek = row("SELECT * FROM equipment WHERE id=?", [(int)$g('id')]);
    if ($ek && $ek['status'] !== 'in_studio' && $ek['status'] !== 'faulty') json_out(['ok' => false, 'error' => 'Zimmette/çekimde olan ekipman silinemez. Önce iade alın.']);
    q("DELETE FROM equipment_logs WHERE equipment_id=?", [(int)$g('id')]);
    q("DELETE FROM event_equipment WHERE equipment_id=?", [(int)$g('id')]);
    q("DELETE FROM equipment WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Ekipman silindi.']);

case 'equipment_custody':
    require_staff();
    $ek = row("SELECT * FROM equipment WHERE id=?", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'error' => 'Ekipman bulunamadı.']);
    if (!in_array($ek['status'], ['in_studio'])) json_out(['ok' => false, 'error' => 'Bu ekipman şu an ' . mb_strtolower(EQUIPMENT_STATUSES[$ek['status']]) . ' — zimmet verilemez.']);
    $target = (int)($g('user_id') ?: $u['id']);
    if ($target !== (int)$u['id'] && !permission('equipment_manage')) json_out(['ok' => false, 'error' => 'Başkası adına zimmet için ekipman yönetim yetkisi gerekir.']);
    $targetName = val("SELECT name FROM users WHERE id=?", [$target]);
    update_row('equipment', ['status' => 'checked_out', 'custody_user_id' => $target, 'custody_event_id' => null], 'id=?', [(int)$g('id')]);
    log_equipment((int)$g('id'), 'custody', trim($g('description')), $target);
    if ($target !== (int)$u['id']) notify($target, 'Ekipman zimmetlendi', ($ek['code'] ? $ek['code'] . ' — ' : '') . $ek['name'], 'equipment.php', 'task');
    json_out(['ok' => true, 'message' => $ek['name'] . ' → ' . $targetName . ' zimmetine verildi.']);

case 'equipment_return':
    require_staff();
    $ek = row("SELECT * FROM equipment WHERE id=?", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'error' => 'Ekipman bulunamadı.']);
    if (!in_array($ek['status'], ['checked_out', 'on_shoot'])) json_out(['ok' => false, 'error' => 'Bu ekipman zaten stüdyoda.']);
    if ($ek['custody_user_id'] != $u['id'] && !permission('equipment_manage')) json_out(['ok' => false, 'error' => 'Yalnızca kendi zimmetinizi iade edebilirsiniz.']);
    update_row('equipment', ['status' => 'in_studio', 'custody_user_id' => null, 'custody_event_id' => null], 'id=?', [(int)$g('id')]);
    log_equipment((int)$g('id'), $ek['status'] === 'on_shoot' ? 'shoot_return' : 'return', trim($g('description')), $ek['custody_user_id'] ? (int)$ek['custody_user_id'] : null, $ek['custody_event_id'] ? (int)$ek['custody_event_id'] : null);
    json_out(['ok' => true, 'message' => $ek['name'] . ' stüdyoya iade alındı.']);

case 'equipment_fault':
    require_staff();
    $ek = row("SELECT * FROM equipment WHERE id=?", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'error' => 'Ekipman bulunamadı.']);
    $newStatus = in_array($g('status'), ['faulty', 'in_maintenance', 'in_studio']) ? $g('status') : 'faulty';
    update_row('equipment', [
        'status' => $newStatus,
        'fault_note' => $newStatus === 'in_studio' ? null : trim($g('note')),
        'custody_user_id' => null, 'custody_event_id' => null,
    ], 'id=?', [(int)$g('id')]);
    log_equipment((int)$g('id'), $newStatus === 'in_studio' ? 'fixed' : ($newStatus === 'in_maintenance' ? 'maintenance' : 'fault'), trim($g('note')));
    json_out(['ok' => true, 'message' => 'Ekipman durumu güncellendi.']);

case 'sd_update':
    require_staff();
    $ek = row("SELECT * FROM equipment WHERE id=? AND category='sd_card'", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'error' => 'SD kart bulunamadı.']);
    $operation = $g('operation'); // full | transferred | empty
    if ($operation === 'full') {
        $content = trim($g('content'));
        if ($content === '') json_out(['ok' => false, 'error' => 'Hangi çekim/içerik olduğunu yazın.']);
        update_row('equipment', ['sd_status' => 'full', 'sd_content' => $content, 'sd_drive_link' => null], 'id=?', [$ek['id']]);
        log_equipment($ek['id'], 'sd_full', $content);
        json_out(['ok' => true, 'message' => 'Kart dolu olarak işaretlendi.']);
    }
    if ($operation === 'transferred') {
        if ($ek['sd_status'] !== 'full') json_out(['ok' => false, 'error' => 'Önce kartı "dolu" olarak işaretleyin.']);
        $link = trim($g('drive_link'));
        update_row('equipment', ['sd_status' => 'transferred', 'sd_drive_link' => $link ?: null], 'id=?', [$ek['id']]);
        log_equipment($ek['id'], 'sd_transferred', trim(($ek['sd_content'] ?: '') . ($link ? ' → ' . $link : '')));
        // The linked shoot inherits the transfer: mark it too (manual link = transferred)
        $shoot = sd_last_shoot((int)$ek['id']);
        if ($shoot) {
            shoot_transferred((int)$shoot['id'], (int)$u['id'], $link ?: null);
        }
        json_out(['ok' => true, 'message' => "Drive'a aktarıldı olarak işaretlendi." . ($shoot ? ' Bağlı çekim de aktarıldı sayıldı: ' . $shoot['title'] : '')]);
    }
    if ($operation === 'clear') {
        if ($ek['sd_status'] === 'full') json_out(['ok' => false, 'error' => "Dikkat: içerik henüz Drive'a aktarılmadı! Önce aktarımı işaretleyin."]);
        // The card looks transferred, but is the SHOOT confirmed in Drive? If not, warn loudly.
        $shoot = sd_last_shoot((int)$ek['id']);
        if ($shoot) {
            foreach (array_filter(array_unique([(int)$shoot['manager_id'], (int)$shoot['created_by']])) as $uid) {
                notify($uid, '⚠️ SD kart boşaltıldı — çekim Drive\'da doğrulanmadı',
                    '"' . $ek['name'] . '" kartı boşaltıldı ama "' . $shoot['title'] . '" çekiminin dosyaları henüz Drive\'da görülmedi.',
                    'shoot-list.php', 'task');
            }
            log_equipment($ek['id'], 'sd_emptied', 'UYARI: bağlı çekim (' . $shoot['title'] . ') Drive\'da doğrulanmadan boşaltıldı');
            update_row('equipment', ['sd_status' => 'empty', 'sd_content' => null, 'sd_drive_link' => null], 'id=?', [$ek['id']]);
            json_out(['ok' => true, 'message' => '⚠️ Kart boşaltıldı ama "' . $shoot['title'] . '" çekimi Drive\'da doğrulanmadı — yöneticiye uyarı gönderildi.']);
        }
        // Content + link are stored in the history log, the card is reset
        log_equipment($ek['id'], 'sd_emptied', trim(($ek['sd_content'] ?: '') . ($ek['sd_drive_link'] ? ' (arşiv: ' . $ek['sd_drive_link'] . ')' : '')));
        update_row('equipment', ['sd_status' => 'empty', 'sd_content' => null, 'sd_drive_link' => null], 'id=?', [$ek['id']]);
        json_out(['ok' => true, 'message' => 'Kart boşaltıldı — tekrar kullanıma hazır.']);
    }
    json_out(['ok' => false, 'error' => 'Geçersiz işlem.']);

case 'event_equipment_return':
    require_staff();
    $eventId = (int)$g('event_id');
    $qty = 0;
    foreach (rows("SELECT e.* FROM equipment e JOIN event_equipment ee ON ee.equipment_id=e.id WHERE ee.event_id=? AND e.status='on_shoot'", [$eventId]) as $ek) {
        update_row('equipment', ['status' => 'in_studio', 'custody_user_id' => null, 'custody_event_id' => null], 'id=?', [$ek['id']]);
        log_equipment((int)$ek['id'], 'shoot_return', '', null, $eventId);
        $qty++;
    }
    json_out(['ok' => true, 'message' => $qty . ' ekipman stüdyoya iade alındı.']);

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
        if (!$target || !in_array($target['status'], ['completed', 'published'], true)) json_out(['ok' => false, 'error' => 'Yalnızca tamamlanan işler puanlanabilir.']);
    } else {
        $target = row("SELECT id, title, project_id, status FROM approvals WHERE id=?", [$refId]);
        if (!$target || $target['status'] !== 'approved') json_out(['ok' => false, 'error' => 'Yalnızca onaylanan işler puanlanabilir.']);
    }
    if (!project_access((int)$target['project_id'])) json_out(['ok' => false, 'error' => 'Bu işe erişiminiz yok.']);
    $comment = mb_substr(trim($g('comment')), 0, 500) ?: null;
    q("INSERT INTO ratings (ref_type, ref_id, project_id, user_id, rating, comment, created) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment)", [$refType, $refId, $target['project_id'], $u['id'], $rating, $comment, $now]);
    // Notify the PM on a low rating
    if ($rating <= 2) {
        $pmId = val("SELECT pm_id FROM projects WHERE id=?", [$target['project_id']]);
        $recipients = $pmId ? [(int)$pmId] : array_column(rows("SELECT id FROM users WHERE role IN ('admin','pm') AND is_active=1"), 'id');
        foreach ($recipients as $aid)
            notify((int)$aid, '⚠️ Düşük müşteri puanı: ' . $rating . '★', $target['title'] . ($comment ? ' — "' . $comment . '"' : ''), 'project.php?id=' . $target['project_id'], 'approval');
    }
    json_out(['ok' => true, 'message' => 'Değerlendirmeniz kaydedildi, teşekkürler! ' . str_repeat('★', $rating)]);

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
        'notes' => mb_substr(trim($g('notes')), 0, 500) ?: null, 'status' => 'pending', 'created' => $now,
    ]);
    foreach (rows("SELECT id FROM users WHERE role IN ('admin','pm') AND is_active=1") as $pm)
        notify((int)$pm['id'], '📆 Yeni randevu talebi', $u['name'] . ': ' . $topic . ' — ' . format_date($date, true), 'appointments.php', 'request');
    json_out(['ok' => true, 'message' => 'Randevu talebiniz iletildi. Onaylanınca haber verilecek.']);

case 'appointment_respond':
    require_permission('appointment_manage');
    $r = row("SELECT * FROM appointments WHERE id=?", [(int)$g('id')]);
    if (!$r) json_out(['ok' => false, 'error' => 'Randevu bulunamadı.']);
    $operation = $g('operation'); // approve | alternative | reject
    if ($operation === 'approve') {
        $link = trim($g('online_link'));
        // Create a meeting: customer + responding PM as participants
        $eventId = insert('events', [
            'client_id' => $r['client_id'], 'title' => 'Randevu: ' . $r['topic'], 'type' => 'meeting',
            'start' => $r['date'], 'online_link' => $link ?: null,
            'description' => $r['notes'], 'created_by' => $u['id'], 'created' => $now,
        ]);
        q("INSERT IGNORE INTO event_participants (event_id, user_id) VALUES (?,?),(?,?)", [$eventId, $r['customer_id'], $eventId, $u['id']]);
        update_row('appointments', ['status' => 'approved', 'online_link' => $link ?: null, 'event_id' => $eventId, 'reply_note' => trim($g('note')) ?: null], 'id=?', [$r['id']]);
        notify((int)$r['customer_id'], '✅ Randevunuz onaylandı', $r['topic'] . ' — ' . format_date($r['date'], true) . ($link ? ' (online)' : ''), 'appointments.php', 'request');
        json_out(['ok' => true, 'message' => 'Randevu onaylandı ve toplantı takvimine eklendi.']);
    }
    if ($operation === 'alternative') {
        $new = $g('alternative_date');
        if (!$new) json_out(['ok' => false, 'error' => 'Alternatif tarih seçin.']);
        update_row('appointments', ['status' => 'alternative', 'alternative_date' => $new, 'reply_note' => trim($g('note')) ?: null], 'id=?', [$r['id']]);
        notify((int)$r['customer_id'], '🔁 Randevu için farklı saat önerildi', $r['topic'] . ' → ' . format_date($new, true), 'appointments.php', 'request');
        json_out(['ok' => true, 'message' => 'Alternatif saat önerildi.']);
    }
    if ($operation === 'reject') {
        update_row('appointments', ['status' => 'rejected', 'reply_note' => trim($g('note')) ?: null], 'id=?', [$r['id']]);
        notify((int)$r['customer_id'], 'Randevu talebiniz yanıtlandı', $r['topic'] . ' — uygun değil' . ($g('note') ? ': ' . $g('note') : ''), 'appointments.php', 'request');
        json_out(['ok' => true, 'message' => 'Talep yanıtlandı.']);
    }
    json_out(['ok' => false, 'error' => 'Geçersiz işlem.']);

case 'appointment_accept':
    // The customer accepts the proposed alternative time
    require_login();
    $r = row("SELECT * FROM appointments WHERE id=? AND customer_id=? AND status='alternative'", [(int)$g('id'), $u['id']]);
    if (!$r || !$r['alternative_date']) json_out(['ok' => false, 'error' => 'Bekleyen öneri bulunamadı.']);
    update_row('appointments', ['date' => $r['alternative_date'], 'alternative_date' => null, 'status' => 'pending'], 'id=?', [$r['id']]);
    foreach (rows("SELECT id FROM users WHERE role IN ('admin','pm') AND is_active=1") as $pm)
        notify((int)$pm['id'], '📆 Müşteri önerilen saati kabul etti', $r['topic'] . ' — ' . format_date($r['alternative_date'], true) . ' (onay bekliyor)', 'appointments.php', 'request');
    json_out(['ok' => true, 'message' => 'Yeni saat kabul edildi; ajans onayı bekleniyor.']);

/* ==================== RELEASE NOTES ==================== */
case 'mikasa_lines':
    // The day's lines of the corner assistant, built from the person's own work
    require_staff();
    require_once __DIR__ . '/includes/mikasa.php';
    if (!mikasa_on($u)) json_out(['ok' => false]);
    json_out(['ok' => true, 'lines' => mikasa_lines($u)]);

case 'mikasa_off':
    require_staff();
    $preferences = json_decode((string)($u['notification_preferences'] ?? ''), true) ?: [];
    $preferences['mikasa'] = 0;
    update_row('users', ['notification_preferences' => json_encode($preferences)], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

case 'version_close':
    require_login();
    update_row('users', ['seen_version' => APP_VERSION], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

/* ==================== ANNOUNCEMENTS ==================== */
case 'announcement_save':
    require_permission('announcement_publish');
    $title = trim($g('title'));
    if ($title === '') json_out(['ok' => false, 'error' => 'Duyuru başlığı gerekli.']);
    $is_important = (int)(bool)$g('is_important');
    $id = insert('announcements', ['title' => $title, 'text' => $g('text'), 'is_important' => $is_important, 'created_by' => $u['id'], 'created' => $now]);
    if ($is_important) {
        foreach (rows("SELECT id FROM users WHERE is_active=1 AND role!='customer' AND id!=?", [$u['id']]) as $take)
            notify((int)$take['id'], '📢 Duyuru: ' . $title, mb_substr($g('text'), 0, 90), 'index.php', 'task');
    }
    log_activity('"' . $title . '" duyurusunu yayınladı');
    json_out(['ok' => true, 'message' => 'Duyuru yayınlandı.']);

case 'announcement_read':
    require_login();
    q("INSERT IGNORE INTO announcement_readers (announcement_id, user_id) VALUES (?,?)", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'announcement_delete':
    require_pm();
    q("DELETE FROM announcements WHERE id=?", [(int)$g('id')]);
    q("DELETE FROM announcement_readers WHERE announcement_id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Duyuru silindi.']);

/* ==================== CONTRACTS ==================== */
case 'contract_save':
    require_permission('client_manage');
    $data = [
        'client_id' => (int)$g('client_id'), 'title' => trim($g('title')),
        'start' => $g('start') ?: null, 'end' => $g('end') ?: null,
        'amount' => (float)str_replace(',', '.', $g('amount', '0')), 'description' => $g('description'),
        'is_reminded' => 0,
    ];
    if ($data['title'] === '' || !$data['client_id']) json_out(['ok' => false, 'error' => 'Sözleşme başlığı gerekli.']);
    $ek = file_upload('file');
    if ($ek) {
        $data['archive_id'] = insert('archive', [
            'client_id' => $data['client_id'], 'name' => $ek['name'], 'file_path' => $ek['path'],
            'size' => $ek['size'], 'extension' => $ek['extension'], 'uploader_id' => $u['id'], 'created' => $now,
        ]);
    }
    if ($g('id')) {
        update_row('contracts', $data, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'message' => 'Sözleşme güncellendi.']);
    }
    $data['created'] = $now;
    insert('contracts', $data);
    json_out(['ok' => true, 'message' => 'Sözleşme kaydedildi.']);

case 'contract_delete':
    require_permission('client_manage');
    q("DELETE FROM contracts WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Sözleşme silindi.']);

/* ==================== PERSONAL SPACE ==================== */
case 'note_save':
    require_login();
    $text = trim($g('text'));
    $title = mb_substr(trim($g('title')), 0, 150);
    if ($text === '' && $title === '') json_out(['ok' => false, 'error' => 'Not boş olamaz.']);
    $color = in_array($g('color'), ['default', 'yellow', 'green', 'blue', 'pink']) ? $g('color') : 'default';
    if ($g('id')) {
        $not = row("SELECT * FROM personal_notes WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
        if (!$not) json_out(['ok' => false, 'error' => 'Not bulunamadı.']);
        update_row('personal_notes', ['title' => $title ?: null, 'text' => $text, 'color' => $color, 'update' => $now], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'message' => 'Not güncellendi.']);
    }
    insert('personal_notes', ['user_id' => $u['id'], 'title' => $title ?: null, 'text' => $text, 'color' => $color, 'created' => $now]);
    json_out(['ok' => true, 'message' => 'Not eklendi.']);

case 'note_delete':
    require_login();
    q("DELETE FROM personal_notes WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true, 'message' => 'Not silindi.']);

case 'personal_todo_add':
    require_login();
    $name = trim($g('name'));
    if ($name === '') json_out(['ok' => false, 'error' => 'Boş madde eklenemez.']);
    $sort_order = (int)val("SELECT COALESCE(MAX(sort_order),0)+1 FROM personal_todos WHERE user_id=?", [$u['id']]);
    $id = insert('personal_todos', ['user_id' => $u['id'], 'name' => mb_substr($name, 0, 255), 'is_done' => 0, 'sort_order' => $sort_order]);
    json_out(['ok' => true, 'id' => $id, 'name' => $name]);

case 'personal_todo_toggle':
    require_login();
    q("UPDATE personal_todos SET is_done=1-is_done WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'personal_todo_delete':
    require_login();
    q("DELETE FROM personal_todos WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'link_add':
    require_login();
    $name = trim($g('name')); $url = trim($g('url'));
    if ($name === '' || $url === '') json_out(['ok' => false, 'error' => 'Ad ve adres gerekli.']);
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    insert('personal_links', ['user_id' => $u['id'], 'name' => mb_substr($name, 0, 150), 'url' => mb_substr($url, 0, 500)]);
    json_out(['ok' => true, 'message' => 'Yer imi eklendi.']);

case 'link_delete':
    require_login();
    q("DELETE FROM personal_links WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true, 'message' => 'Yer imi silindi.']);

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
    $preferences['mikasa'] = (int)(bool)$g('t_mikasa');
    update_row('users', ['notification_preferences' => json_encode($preferences)], 'id=?', [$u['id']]);
    json_out(['ok' => true, 'message' => 'Bildirim tercihleri kaydedildi.']);

case 'widget_save':
    require_login();
    $selected = json_decode($g('widgets', '[]'), true) ?: [];
    update_row('users', ['widgets' => json_encode(array_values($selected))], 'id=?', [$u['id']]);
    json_out(['ok' => true, 'message' => 'Panel görünümü kaydedildi.']);

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
        'sender_id' => $u['id'], 'title' => $title, 'status' => 'new', 'created' => $now,
    ]);
    foreach ($fields as $field) {
        if ($field['type'] === 'section') continue; // a visual heading, carries no answer
        // The renderer posts field_{id} (a name mismatch here used to drop every value)
        $value = $g('field_' . $field['id']);
        if ($field['type'] === 'file') {
            $tLoad = file_upload('field_' . $field['id']);
            if ($tLoad) {
                insert('archive', ['client_id' => $clientId ?: null, 'name' => $tLoad['name'], 'file_path' => $tLoad['path'], 'size' => $tLoad['size'], 'extension' => $tLoad['extension'], 'uploader_id' => $u['id'], 'created' => $now]);
                $value = $tLoad['path'];
            }
            if ($field['is_required'] && !$tLoad) { q("DELETE FROM requests WHERE id=?", [$requestId]); json_out(['ok' => false, 'error' => '"' . $field['label'] . '" için dosya yükleyin.']); }
            insert('request_replies', ['request_id' => $requestId, 'field_id' => $field['id'], 'value' => $value]);
            continue;
        }
        if ($field['type'] === 'multi_file') {
            // The form handler posts multiple files as field_{id}__0, field_{id}__1, ...
            $paths = [];
            foreach (array_keys($_FILES) as $fk) {
                if (!str_starts_with($fk, 'field_' . $field['id'] . '__')) continue;
                $tLoad = file_upload($fk);
                if ($tLoad) {
                    insert('archive', ['client_id' => $clientId ?: null, 'name' => $tLoad['name'], 'file_path' => $tLoad['path'], 'size' => $tLoad['size'], 'extension' => $tLoad['extension'], 'uploader_id' => $u['id'], 'created' => $now]);
                    $paths[] = $tLoad['path'];
                }
            }
            if ($field['is_required'] && !$paths) { q("DELETE FROM requests WHERE id=?", [$requestId]); json_out(['ok' => false, 'error' => '"' . $field['label'] . '" için en az bir dosya yükleyin.']); }
            insert('request_replies', ['request_id' => $requestId, 'field_id' => $field['id'], 'value' => implode(',', $paths)]);
            continue;
        }
        if ($field['is_required'] && trim((string)$value) === '') {
            q("DELETE FROM requests WHERE id=?", [$requestId]);
            json_out(['ok' => false, 'error' => '"' . $field['label'] . '" alanı zorunlu.']);
        }
        insert('request_replies', ['request_id' => $requestId, 'field_id' => $field['id'], 'value' => $value]);
    }
    // Notify PMs
    foreach (rows("SELECT id FROM users WHERE role IN ('admin','pm') AND is_active=1") as $pm)
        notify($pm['id'], 'Yeni talep: ' . $title, $u['name'] . ' bir talep gönderdi', 'request.php?id=' . $requestId, 'request');
    json_out(['ok' => true, 'message' => 'Talebiniz iletildi. En kısa sürede dönüş yapılacak.']);

case 'request_status':
    require_permission('request_manage');
    $id = (int)$g('id');
    update_row('requests', ['status' => $g('status'), 'assignee_id' => $g('assignee_id') ? (int)$g('assignee_id') : null], 'id=?', [$id]);
    json_out(['ok' => true, 'message' => 'Talep güncellendi.']);

case 'request_to_task':
    require_permission('request_manage');
    $id = (int)$g('id');
    $request = row("SELECT * FROM requests WHERE id=?", [$id]);
    if (!$request || !$request['project_id']) json_out(['ok' => false, 'error' => 'Talebe önce proje atayın.']);
    // The client's answers become the brief of the new work
    $brief = ['Talep #' . $id . ' üzerinden oluşturuldu.'];
    foreach (rows("SELECT f.label, f.type, r.value FROM request_replies r JOIN form_fields f ON f.id=r.field_id WHERE r.request_id=? ORDER BY f.sort_order, r.id", [$id]) as $answer) {
        if (in_array($answer['type'], ['file', 'multi_file', 'section'], true) || trim((string)$answer['value']) === '') continue;
        $brief[] = $answer['label'] . ': ' . trim($answer['value']);
    }
    $taskId = insert('tasks', [
        'project_id' => $request['project_id'], 'kind' => 'client', 'title' => $request['title'],
        'description' => implode("\n", $brief),
        'assignee_id' => $request['assignee_id'], 'created_by' => $u['id'],
        'priority' => 'normal', 'status' => 'todo', 'created' => $now,
    ]);
    update_row('requests', ['status' => 'task_created', 'task_id' => $taskId], 'id=?', [$id]);
    notify($request['sender_id'], 'Talebiniz işleme alındı', $request['title'], 'request.php?id=' . $id, 'request');
    json_out(['ok' => true, 'message' => 'İşe dönüştürüldü.', 'redirect' => 'task.php?id=' . $taskId]);
case 'request_project':
    require_pm();
    update_row('requests', ['project_id' => (int)$g('project_id')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Proje atandı.']);

/* ==================== ARCHIVE ==================== */
case 'archive_upload':
    require_login();
    $uploaded = file_upload('file');
    if (!$uploaded) json_out(['ok' => false, 'error' => 'Dosya yüklenemedi. Boyut (max 50MB) veya tür uygun değil.']);
    $projectId = $g('project_id') ? (int)$g('project_id') : null;
    if ($projectId && !project_access($projectId)) json_out(['ok' => false, 'error' => 'Yetkisiz.']);
    insert('archive', [
        'client_id' => $g('client_id') ? (int)$g('client_id') : null, 'project_id' => $projectId,
        'task_id' => $g('task_id') ? (int)$g('task_id') : null,
        'name' => $uploaded['name'], 'file_path' => $uploaded['path'], 'size' => $uploaded['size'],
        'extension' => $uploaded['extension'], 'uploader_id' => $u['id'], 'created' => $now,
    ]);
    json_out(['ok' => true, 'message' => 'Dosya yüklendi.']);

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
    json_out(['ok' => true, 'message' => 'Bağlantı eklendi.']);

case 'archive_delete':
    require_permission('archive_delete');
    $a = row("SELECT * FROM archive WHERE id=?", [(int)$g('id')]);
    if ($a) {
        @unlink(ROOT . '/uploads/' . $a['file_path']);
        q("DELETE FROM archive WHERE id=?", [$a['id']]);
    }
    json_out(['ok' => true, 'message' => 'Dosya silindi.']);

/* ==================== FINANCE ==================== */
case 'payment_save':
    require_permission('finance_manage');
    $data = [
        'project_id' => (int)$g('project_id'), 'type' => isset(PAYMENT_TYPES[$g('type')]) ? $g('type') : 'invoice', 'title' => trim($g('title')),
        'amount' => (float)str_replace(',', '.', $g('amount', '0')), 'date' => $g('date') ?: date('Y-m-d'),
        'status' => isset(PAYMENT_STATUSES[$g('status')]) ? $g('status') : 'pending', 'description' => $g('description'),
    ];
    if ($data['title'] === '') json_out(['ok' => false, 'error' => 'Başlık gerekli.']);
    if ($g('id')) {
        update_row('payments', $data, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'message' => 'Kayıt güncellendi.']);
    }
    $data['created'] = $now;
    insert('payments', $data);
    json_out(['ok' => true, 'message' => 'Finans kaydı eklendi.']);

case 'payment_status':
    require_permission('finance_manage');
    if (!isset(PAYMENT_STATUSES[$g('status')])) json_out(['ok' => false, 'error' => 'Geçersiz durum.']);
    update_row('payments', ['status' => $g('status')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Durum güncellendi.']);

case 'payment_delete':
    require_permission('finance_manage');
    q("DELETE FROM payments WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Kayıt silindi.']);

/* ==================== EXPENSES ==================== */
case 'expense_save':
    require_permission('finance_manage');
    $data = [
        'type' => isset(EXPENSE_TYPES[$g('type')]) ? $g('type') : 'other',
        'title' => trim($g('title')),
        'amount' => (float)str_replace(',', '.', $g('amount', '0')),
        'date' => $g('date') ?: date('Y-m-d'),
        'status' => $g('status') === 'paid' ? 'paid' : 'pending',
        'repeat' => $g('repeat') === 'monthly' ? 'monthly' : 'none',
        'description' => $g('description'),
    ];
    if ($data['title'] === '') json_out(['ok' => false, 'error' => 'Gider başlığı gerekli.']);
    if ($g('id')) {
        update_row('expenses', $data, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'message' => 'Gider güncellendi.']);
    }
    $data['created'] = $now;
    insert('expenses', $data);
    json_out(['ok' => true, 'message' => 'Gider eklendi.']);

case 'expense_status':
    require_permission('finance_manage');
    update_row('expenses', ['status' => $g('status') === 'paid' ? 'paid' : 'pending'], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Durum güncellendi.']);

case 'expense_delete':
    require_permission('finance_manage');
    q("DELETE FROM expenses WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Gider silindi.']);

/* ==================== QUOTE & INVOICE DOCUMENTS ==================== */
case 'document_save':
    require_permission('document_create');
    $type = $g('type') === 'invoice' ? 'invoice' : 'quote';
    $items = json_decode($g('items', '[]'), true) ?: [];
    $items = array_values(array_filter(array_map(fn($k) => [
        'name' => mb_substr(trim($k['name'] ?? ''), 0, 200),
        'qty' => max(1, (float)str_replace(',', '.', $k['qty'] ?? 1)),
        'price' => (float)str_replace(',', '.', $k['price'] ?? 0),
    ], $items), fn($k) => $k['name'] !== ''));
    $title = trim($g('title'));
    if ($title === '' || !$items) json_out(['ok' => false, 'error' => 'Başlık ve en az bir kalem gerekli.']);
    if ($g('id')) {
        update_row('documents', ['title' => $title, 'client_id' => $g('client_id') ? (int)$g('client_id') : null,
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'vat_rate' => max(0, min(50, (int)$g('vat_rate', 20))),
            'valid_until' => $g('valid_until') ?: null, 'notes' => $g('notes')], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'message' => 'Belge güncellendi.']);
    }
    // Numbering: TKF-2026-001 / FTR-2026-001
    $prefix = $type === 'invoice' ? 'FTR' : 'TKF';
    $counterKey = 'document_counter_' . $type . '_' . date('Y');
    $counter = (int)val("SELECT setting_value FROM settings WHERE setting_key=?", [$counterKey]) + 1;
    q("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?", [$counterKey, $counter, $counter]);
    $doc_no = $prefix . '-' . date('Y') . '-' . str_pad($counter, 3, '0', STR_PAD_LEFT);
    $bid = insert('documents', [
        'type' => $type, 'doc_no' => $doc_no, 'client_id' => $g('client_id') ? (int)$g('client_id') : null,
        'title' => $title, 'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
        'vat_rate' => max(0, min(50, (int)$g('vat_rate', 20))), 'valid_until' => $g('valid_until') ?: null,
        'notes' => $g('notes'), 'created_by' => $u['id'], 'created' => $now,
    ]);
    json_out(['ok' => true, 'message' => $doc_no . ' oluşturuldu.', 'redirect' => 'document.php?id=' . $bid]);

case 'document_status':
    require_permission('finance');
    $b = row("SELECT * FROM documents WHERE id=?", [(int)$g('id')]);
    if (!$b) json_out(['ok' => false, 'error' => 'Belge bulunamadı.']);
    $status = isset(DOCUMENT_STATUSES[$g('status')]) ? $g('status') : 'draft';
    update_row('documents', ['status' => $status], 'id=?', [$b['id']]);
    // If the quote was approved: suggest/create an income (invoice) record on the file's first active project
    if ($status === 'approved' && $b['type'] === 'quote' && $b['client_id']) {
        $projectId = val("SELECT id FROM projects WHERE client_id=? AND status='active' ORDER BY id LIMIT 1", [$b['client_id']]);
        if ($projectId) {
            $items = json_decode($b['items'], true) ?: [];
            $searchTotal = array_sum(array_map(fn($k) => $k['qty'] * $k['price'], $items));
            $total = $searchTotal * (1 + $b['vat_rate'] / 100);
            insert('payments', ['project_id' => (int)$projectId, 'type' => 'invoice', 'title' => $b['doc_no'] . ' — ' . $b['title'],
                'amount' => round($total, 2), 'date' => date('Y-m-d'), 'status' => 'pending',
                'description' => 'Onaylanan tekliften otomatik oluşturuldu', 'created' => $now]);
            json_out(['ok' => true, 'message' => 'Teklif onaylandı — gelir kaydı (fatura) oluşturuldu.']);
        }
    }
    json_out(['ok' => true, 'message' => 'Belge durumu güncellendi.']);

case 'document_delete':
    require_permission('finance');
    q("DELETE FROM documents WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Belge silindi.', 'redirect' => 'finance.php#documents']);

case 'budget_save':
    require_permission('finance');
    $target = (float)str_replace(['.', ','], ['', '.'], $g('target', '0'));
    q("INSERT INTO settings (setting_key, setting_value) VALUES ('budget_target', ?) ON DUPLICATE KEY UPDATE setting_value=?", [$target, $target]);
    json_out(['ok' => true, 'message' => 'Aylık gelir hedefi kaydedildi.']);

/* ==================== USERS (admin) ==================== */
case 'user_save':
    require_admin();
    $email = mb_strtolower(trim($g('email'))); // email uniqueness: stored in normalized form
    $data = [
        'name' => trim($g('name')), 'email' => $email, 'role' => $g('role', 'team'),
        'job_title' => $g('job_title'), 'client_id' => $g('client_id') ? (int)$g('client_id') : null,
        'weekly_capacity' => max(0, (int)$g('weekly_capacity', 45)),
        'salary' => max(0, (float)str_replace(',', '.', $g('salary', '0'))),
    ];
    if (!isset(ROLES[$data['role']])) json_out(['ok' => false, 'error' => 'Geçersiz rol.']);
    // Per-user permission overrides
    if ($g('permissions') !== '') {
        $permissions = json_decode($g('permissions'), true);
        if (is_array($permissions)) {
            $clean = [];
            foreach (PERMISSION_KEYS as $key => $_) if (isset($permissions[$key])) $clean[$key] = (int)(bool)$permissions[$key];
            $data['permissions'] = $clean ? json_encode($clean) : null;
        }
    }
    if ($data['name'] === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
        json_out(['ok' => false, 'error' => 'Ad ve geçerli e-posta gerekli.']);
    // Customer multi-file list (JSON); primary file = first selection
    $customerClients = json_decode($g('customer_clients', ''), true);
    if ($data['role'] === 'customer' && is_array($customerClients)) {
        $customerClients = array_values(array_unique(array_filter(array_map('intval', $customerClients))));
        $data['client_id'] = $customerClients[0] ?? null;
    }
    if ($data['role'] === 'customer' && !$data['client_id'])
        json_out(['ok' => false, 'error' => 'Müşteri için en az bir dosya seçin.']);
    $skillSave = function (int $uid) use ($data) {
        if (!array_key_exists('skills', $_POST) || $data['role'] === 'customer') return;
        q("DELETE FROM user_skills WHERE user_id=?", [$uid]);
        foreach (array_unique(array_filter(array_map('intval', json_decode((string)$_POST['skills'], true) ?: []))) as $sid) q("INSERT IGNORE INTO user_skills (user_id, skill_id) VALUES (?,?)", [$uid, $sid]);
    };
    $customerClientSave = function (int $uid) use ($data, $customerClients) {
        if ($data['role'] !== 'customer' || !is_array($customerClients)) return;
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
        $skillSave($id);
        json_out(['ok' => true, 'message' => 'Kullanıcı güncellendi.']);
    }
    if (val("SELECT COUNT(*) FROM users WHERE email=?", [$email]))
        json_out(['ok' => false, 'error' => 'Bu e-posta kullanımda.']);
    if (strlen($g('password')) < 8) json_out(['ok' => false, 'error' => 'Şifre en az 8 karakter.']);
    $data['password'] = password_hash($g('password'), PASSWORD_DEFAULT);
    $data['theme'] = 'lime'; $data['color'] = '#b1fb01'; $data['created'] = $now;
    $id = insert('users', $data);
    $skillSave($id);
    // Automatically add the new user to the relevant channels
    if ($data['role'] !== 'customer') {
        // General channel + all project channels (except for interns)
        foreach (rows("SELECT id, type FROM channels WHERE type='general' OR (type='project' AND ?='tam')", [$data['role'] === 'intern' ? 'intern' : 'tam']) as $channel) {
            q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channel['id'], $id]);
        }
    } else {
        // Customer: added to the customer channels of all files they can access
        $customerClientSave($id);
        $clientList = is_array($customerClients) && $customerClients ? $customerClients : [$data['client_id']];
        [$in, $p] = in_clause(array_map('intval', $clientList));
        foreach (rows("SELECT k.id FROM channels k JOIN projects pr ON pr.id=k.project_id WHERE k.type='customer' AND pr.client_id IN $in", $p) as $channel) {
            q("INSERT IGNORE INTO channel_members (channel_id, user_id) VALUES (?,?)", [$channel['id'], $id]);
        }
    }
    json_out(['ok' => true, 'message' => 'Kullanıcı oluşturuldu.']);

case 'user_status':
    require_admin();
    if ((int)$g('id') === (int)$u['id']) json_out(['ok' => false, 'error' => 'Kendinizi pasifleştiremezsiniz.']);
    update_row('users', ['is_active' => (int)$g('is_active')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Durum güncellendi.']);

case 'user_delete':
    require_admin();
    $id = (int)$g('id');
    if ($id === (int)$u['id']) json_out(['ok' => false, 'error' => 'Kendi hesabınızı silemezsiniz.']);
    $target = row("SELECT id, name, role FROM users WHERE id=?", [$id]);
    if (!$target) json_out(['ok' => false, 'error' => 'Kullanıcı bulunamadı.']);
    if ($target['role'] === 'admin' && !val("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id!=?", [$id]))
        json_out(['ok' => false, 'error' => 'Son aktif yönetici silinemez.']);
    // Memberships, assignments and personal data go with the account.
    // Authored content (comments, tasks, uploads, time entries) is kept — the
    // pages LEFT JOIN users, so history stays readable without the account.
    foreach (['customer_clients', 'task_assignees', 'task_watchers', 'channel_members',
              'project_members', 'client_members', 'notifications',
              'personal_todos', 'personal_links', 'personal_notes'] as $t)
        q("DELETE FROM `$t` WHERE user_id=?", [$id]);
    q("UPDATE tasks SET assignee_id=NULL WHERE assignee_id=?", [$id]);
    q("UPDATE clients SET manager_id=NULL WHERE manager_id=?", [$id]);
    q("DELETE FROM users WHERE id=?", [$id]);
    log_activity('Kullanıcı silindi: ' . $target['name']);
    json_out(['ok' => true, 'message' => 'Kullanıcı silindi.']);

/* ==================== TASK TYPES + SKILLS (admin) ==================== */
case 'task_type_save':
    require_admin();
    $name = trim($g('name'));
    $steps = json_decode($g('steps', '[]'), true) ?: [];
    $steps = array_values(array_filter(array_map(fn($s) => [
        'name' => mb_substr(trim((string)($s['name'] ?? '')), 0, 120),
        'skill_id' => (int)($s['skill_id'] ?? 0) ?: null,
        'kind' => isset(STEP_KINDS[$s['kind'] ?? '']) ? $s['kind'] : 'work',
        'owner_id' => (int)($s['owner_id'] ?? 0) ?: null,
    ], $steps), fn($s) => $s['name'] !== ''));
    if ($name === '') json_out(['ok' => false, 'error' => 'İş türünün adı gerekli.']);
    $data = ['name' => $name, 'description' => $g('description'), 'kind' => isset(TASK_KINDS[$g('kind')]) ? $g('kind') : 'client'];
    if ($g('id')) {
        $typeId = (int)$g('id');
        update_row('task_types', $data, 'id=?', [$typeId]);
        q("DELETE FROM task_type_steps WHERE type_id=?", [$typeId]); // running tasks keep their own copies of the steps
    } else {
        $data['created'] = $now;
        $typeId = insert('task_types', $data);
    }
    foreach ($steps as $i => $s) insert('task_type_steps', ['type_id' => $typeId, 'sort_order' => $i + 1] + $s);
    json_out(['ok' => true, 'message' => 'İş türü kaydedildi.']);

case 'task_type_delete':
    require_admin();
    q("DELETE FROM task_type_steps WHERE type_id=?", [(int)$g('id')]);
    q("DELETE FROM task_types WHERE id=?", [(int)$g('id')]);
    q("UPDATE tasks SET type_id=NULL WHERE type_id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'İş türü silindi.']);

case 'skill_save':
    require_admin();
    $name = mb_substr(trim($g('name')), 0, 60);
    if ($name === '') json_out(['ok' => false, 'error' => 'Uzmanlık adı gerekli.']);
    if ($g('id')) update_row('skills', ['name' => $name], 'id=?', [(int)$g('id')]);
    else insert('skills', ['name' => $name, 'sort_order' => (int)val("SELECT COALESCE(MAX(sort_order),0)+1 FROM skills")]);
    json_out(['ok' => true, 'message' => 'Uzmanlık kaydedildi.']);

case 'skill_delete':
    require_admin();
    $id = (int)$g('id');
    if (val("SELECT COUNT(*) FROM task_steps WHERE skill_id=? AND status!='done'", [$id])) json_out(['ok' => false, 'error' => 'Bu uzmanlığı bekleyen açık adımlar var; önce onları başka bir uzmanlığa ya da kişiye verin.']);
    q("DELETE FROM user_skills WHERE skill_id=?", [$id]);
    q("UPDATE task_type_steps SET skill_id=NULL WHERE skill_id=?", [$id]);
    q("DELETE FROM skills WHERE id=?", [$id]);
    json_out(['ok' => true, 'message' => 'Uzmanlık silindi.']);

/* ==================== FORM TEMPLATES (admin) ==================== */
case 'form_save':
    require_admin();
    $name = trim($g('name'));
    $fields = json_decode($g('fields', '[]'), true) ?: [];
    if ($name === '' || !$fields) json_out(['ok' => false, 'error' => 'Form adı ve en az bir alan gerekli.']);
    // Self-repair at the exact failure point: if the type column is still the old
    // six-value ENUM (a silently-failed migration leaves it that way), new type
    // keys get truncated to '' on insert and every choice is lost. Widen it NOW.
    $typeColumn = row("SHOW COLUMNS FROM form_fields LIKE 'type'");
    if (str_starts_with(strtolower((string)($typeColumn['Type'] ?? '')), 'enum')) {
        try { q("ALTER TABLE form_fields MODIFY type VARCHAR(20) NOT NULL DEFAULT 'text'"); }
        catch (Throwable $e) { json_out(['ok' => false, 'error' => 'Veritabanı kolonu genişletilemedi (tip seçimleri kaydedilemez): ' . mb_substr($e->getMessage(), 0, 150)]); }
    }
    if ($g('id')) {
        $fid = (int)$g('id');
        update_row('form_templates', ['name' => $name, 'description' => $g('description'), 'is_active' => (int)$g('is_active', 1)], 'id=?', [$fid]);
        q("DELETE FROM form_fields WHERE template_id=?", [$fid]);
    } else {
        $fid = insert('form_templates', ['name' => $name, 'description' => $g('description'), 'is_active' => 1, 'created' => $now]);
    }
    $expected = [];
    foreach ($fields as $i => $field) {
        if (trim($field['label'] ?? '') === '') continue;
        $expected[] = $field['type'] ?? 'text';
        insert('form_fields', [
            'template_id' => $fid, 'sort_order' => $i + 1, 'label' => trim($field['label']),
            'type' => $field['type'] ?? 'text', 'options' => $field['options'] ?? null,
            'is_required' => !empty($field['is_required']) ? 1 : 0,
        ]);
    }
    // Verify what actually landed — a truncating column must never pass as success
    $written = array_column(rows("SELECT type FROM form_fields WHERE template_id=? ORDER BY sort_order", [$fid]), 'type');
    if ($written !== $expected) {
        error_log('[SADA] form_save type check failed: expected=' . implode(',', $expected) . ' written=' . implode(',', $written));
        json_out(['ok' => false, 'error' => 'Alan tipleri veritabanına eksik yazıldı — lütfen tekrar kaydedin; sorun sürerse yöneticinize storage/error.log kaydını iletin.']);
    }
    json_out(['ok' => true, 'message' => 'Form şablonu kaydedildi.']);

case 'form_delete':
    require_admin();
    q("DELETE FROM form_fields WHERE template_id=?", [(int)$g('id')]);
    q("DELETE FROM form_templates WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'message' => 'Form silindi.']);

/* ==================== SETTINGS (admin) ==================== */
case 'setting_save':
    require_admin();
    $fieldToKey = ['site_name' => 'site_name', 'default_theme' => 'default_theme', 'smtp_is_active' => 'smtp_enabled',
        'smtp_host' => 'smtp_host', 'smtp_port' => 'smtp_port', 'smtp_user' => 'smtp_user',
        'smtp_sender' => 'smtp_sender', 'email_notification' => 'email_notifications',
        'ai_model' => 'ai_model', 'ai_provider' => 'ai_provider', 'gemini_model' => 'gemini_model',
        'mail_aliases' => 'mail_aliases', 'mikasa_enabled' => 'mikasa_enabled', 'mikasa_name' => 'mikasa_name'];
    // Google OAuth client: id is plain, the secret only overwrites on a fresh value
    if (isset($_POST['google_client_id'])) {
        q("INSERT INTO settings (setting_key,setting_value) VALUES ('google_client_id',?) ON DUPLICATE KEY UPDATE setting_value=?", [trim($_POST['google_client_id']), trim($_POST['google_client_id'])]);
    }
    if (!empty($_POST['google_client_secret']) && !str_starts_with($_POST['google_client_secret'], '••')) {
        q("INSERT INTO settings (setting_key,setting_value) VALUES ('google_client_secret',?) ON DUPLICATE KEY UPDATE setting_value=?", [trim($_POST['google_client_secret']), trim($_POST['google_client_secret'])]);
    }
    // Gemini key: only overwrite when a new value is typed
    if (!empty($_POST['gemini_api_key']) && !str_starts_with($_POST['gemini_api_key'], '••')) {
        q("INSERT INTO settings (setting_key,setting_value) VALUES ('gemini_api_key',?) ON DUPLICATE KEY UPDATE setting_value=?", [trim($_POST['gemini_api_key']), trim($_POST['gemini_api_key'])]);
    }
    // AI key: only overwrite when a new value is typed (placeholder dots stay put)
    if (!empty($_POST['anthropic_api_key']) && !str_starts_with($_POST['anthropic_api_key'], '••')) {
        q("INSERT INTO settings (setting_key,setting_value) VALUES ('anthropic_api_key',?) ON DUPLICATE KEY UPDATE setting_value=?", [trim($_POST['anthropic_api_key']), trim($_POST['anthropic_api_key'])]);
    }
    // Google service-account key file → storage/ (blocked from the web, outside uploads/)
    if (!empty($_FILES['google_service_key']['tmp_name'])) {
        $keyJson = json_decode((string)file_get_contents($_FILES['google_service_key']['tmp_name']), true);
        if (!is_array($keyJson) || empty($keyJson['client_email']) || empty($keyJson['private_key'])) {
            json_out(['ok' => false, 'error' => 'Geçersiz servis hesabı dosyası: JSON içinde client_email/private_key bulunamadı.']);
        }
        $storageDir = ROOT . '/storage';
        if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);
        if (!file_exists("$storageDir/.htaccess")) file_put_contents("$storageDir/.htaccess", "Require all denied\n");
        if (!file_exists("$storageDir/index.html")) file_put_contents("$storageDir/index.html", '');
        move_uploaded_file($_FILES['google_service_key']['tmp_name'], "$storageDir/google-service.json");
        q("DELETE FROM settings WHERE setting_key='google_drive_token'"); // a new key invalidates the old token
    }
    foreach ($fieldToKey as $fieldName => $key) {
        if (isset($_POST[$fieldName])) q("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?", [$key, $_POST[$fieldName], $_POST[$fieldName]]);
    }
    if (!empty($_POST['smtp_password']) && !str_starts_with($_POST['smtp_password'], '••')) {
        // Google app passwords are displayed with spaces (xxxx xxxx xxxx xxxx) — remove them
        $smtpPassword = preg_replace('/\s+/u', '', $_POST['smtp_password']);
        q("INSERT INTO settings (setting_key,setting_value) VALUES ('smtp_password',?) ON DUPLICATE KEY UPDATE setting_value=?", [$smtpPassword, $smtpPassword]);
    }
    // Logo & favicon upload
    foreach (['site_logo' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'site_favicon' => ['png', 'ico', 'jpg', 'jpeg', 'gif', 'webp'],
              'site_logo_dark' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'site_favicon_dark' => ['png', 'ico', 'jpg', 'jpeg', 'gif', 'webp'],
              'mikasa_avatar' => ['jpg', 'jpeg', 'png', 'gif', 'webp']] as $fieldName => $allowed_ones) {
        $new = file_upload($fieldName);
        if ($new) {
            if (!in_array($new['extension'], $allowed_ones)) json_out(['ok' => false, 'error' => (['site_logo' => 'Logo', 'site_logo_dark' => 'Logo', 'mikasa_avatar' => 'Asistan görseli'][$fieldName] ?? 'Favicon') . ' için görsel dosyası seçin.']);
            $old = setting($fieldName);
            if ($old) @unlink(ROOT . '/uploads/' . $old);
            q("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?", [$fieldName, $new['path'], $new['path']]);
        }
    }
    json_out(['ok' => true, 'message' => 'Ayarlar kaydedildi.']);

case 'setting_image_delete':
    require_admin();
    $key = in_array($g('setting_key'), ['site_logo', 'site_favicon', 'site_logo_dark', 'site_favicon_dark', 'mikasa_avatar']) ? $g('setting_key') : '';
    if (!$key) json_out(['ok' => false, 'error' => 'Geçersiz.']);
    $old = setting($key);
    if ($old) @unlink(ROOT . '/uploads/' . $old);
    q("DELETE FROM settings WHERE setting_key=?", [$key]);
    json_out(['ok' => true, 'message' => 'Görsel kaldırıldı.']);

case 'test_email':
    require_admin();
    require_once __DIR__ . '/includes/mailer.php';
    // Apply temporary settings (test without saving)
    $ok = send_email($u['email'], 'SADA Test E-postası', "Bu bir test e-postasıdır.\nSMTP ayarlarınız çalışıyor. 🎉");
    $reason = $ok ? '' : ('Gönderilemedi' . (!empty($GLOBALS['smtp_last_error']) ? ' — sunucu yanıtı: ' . $GLOBALS['smtp_last_error'] : '. SMTP ayarlarını kontrol edin.'));
    json_out(['ok' => $ok, 'message' => $ok ? 'Test e-postası gönderildi: ' . $u['email'] : $reason, 'error' => $reason]);

/* ==================== PROFILE ==================== */
case 'profile_save':
    require_login();
    $data = ['name' => trim($g('name')), 'job_title' => $g('job_title')];
    if ($data['name'] === '') json_out(['ok' => false, 'error' => 'Ad gerekli.']);
    if ($g('password')) {
        if (strlen($g('password')) < 8) json_out(['ok' => false, 'error' => 'Şifre en az 8 karakter.']);
        $data['password'] = password_hash($g('password'), PASSWORD_DEFAULT);
    }
    $avatarClient = file_upload('avatar');
    if ($avatarClient) {
        if (!in_array($avatarClient['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp'])) json_out(['ok' => false, 'error' => 'Profil fotoğrafı için görsel dosyası seçin.']);
        if ($u['avatar']) @unlink(ROOT . '/uploads/' . $u['avatar']);
        $data['avatar'] = $avatarClient['path'];
    }
    update_row('users', $data, 'id=?', [$u['id']]);
    json_out(['ok' => true, 'message' => 'Profil güncellendi.']);

case 'avatar_delete':
    require_login();
    if ($u['avatar']) @unlink(ROOT . '/uploads/' . $u['avatar']);
    update_row('users', ['avatar' => null], 'id=?', [$u['id']]);
    json_out(['ok' => true, 'message' => 'Profil fotoğrafı kaldırıldı.']);

default:
    json_out(['ok' => false, 'error' => 'Bilinmeyen işlem.'], 400);
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
        if (!in_array($uid, $old)) notify($uid, 'Projeye atandınız', $projectName, 'project.php?id=' . $projectId, 'task');
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
        if (!in_array($uid, $old)) notify($uid, 'Dosyaya atandınız', $clientName, 'client.php?id=' . $clientId, 'task');
    }
}

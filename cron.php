<?php
/**
 * SADA One — Optional real cron endpoint
 * The system checks recurring tasks automatically on page load (no setup required).
 * For more precise scheduling, call this URL hourly from Hostinger hPanel → Advanced → Cron Jobs:
 * php /home/uXXXX/public_html/cron.php  or  curl -s "https://siteniz.com/cron.php?key=SITE_ADINIZ"
 */
require __DIR__ . '/includes/init.php';

// Simple protection: the ?key= parameter must match the site name (no check when called from CLI)
if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? $_GET['anahtar'] ?? ''; // ?anahtar= is what cron jobs set up before 7.0 still send
    if (!hash_equals(setting('site_name', 'SADA One'), (string)$key)) {
        http_response_code(403);
        die('Yetkisiz. ?key=SITE_ADI parametresi gerekli.');
    }
}

// Only one cron run at a time (hPanel cron + a manual hit must not replay the batch twice)
if ((int)val("SELECT GET_LOCK('sada_cron', 0)") !== 1) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Başka bir cron çalışması sürüyor.'], JSON_UNESCAPED_UNICODE);
    exit;
}
@set_time_limit(280);
$GLOBALS['sada_deadline'] = microtime(true) + 240;
$count = run_recurring_jobs(true);

// Mark past-due pending finance records as overdue
q("UPDATE payments SET status='overdue' WHERE status='pending' AND date < CURDATE()");

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'repeating_task' => $count, 'time' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);

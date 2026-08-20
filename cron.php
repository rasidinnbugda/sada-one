<?php
/**
 * SADA One — Opsiyonel gerçek cron ucu
 * Sistem, tekrarlayan görevleri sayfa yüklenirken otomatik kontrol eder (kurulum gerektirmez).
 * Daha hassas zamanlama isterseniz Hostinger hPanel → Gelişmiş → Cron İşleri bölümünden
 * bu adresi saatlik çağırın:  php /home/uXXXX/public_html/cron.php  veya  curl -s "https://siteniz.com/cron.php?anahtar=SITE_ADINIZ"
 */
require __DIR__ . '/includes/init.php';

// Basit koruma: ?anahtar= parametresi site adıyla eşleşmeli (CLI'dan çağrılırsa kontrol yok)
if (php_sapi_name() !== 'cli') {
    $setting_key = $_GET['setting_key'] ?? '';
    if ($setting_key !== setting('site_adi', 'SADA One')) {
        http_response_code(403);
        die('Yetkisiz. ?setting_key=SITE_ADI parametresi gerekli.');
    }
}

$count = run_recurring_jobs(true);

// Vadesi geçen "bekliyor" finans kayıtlarını "gecikti" yap
q("UPDATE payments SET status='gecikti' WHERE status='bekliyor' AND date < CURDATE()");

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'repeating_task' => $count, 'time' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);

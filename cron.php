<?php
/**
 * SADA Dijital — Opsiyonel gerçek cron ucu
 * Sistem, tekrarlayan görevleri sayfa yüklenirken otomatik kontrol eder (kurulum gerektirmez).
 * Daha hassas zamanlama isterseniz Hostinger hPanel → Gelişmiş → Cron İşleri bölümünden
 * bu adresi saatlik çağırın:  php /home/uXXXX/public_html/cron.php  veya  curl -s "https://siteniz.com/cron.php?anahtar=SITE_ADINIZ"
 */
require __DIR__ . '/includes/init.php';

// Basit koruma: ?anahtar= parametresi site adıyla eşleşmeli (CLI'dan çağrılırsa kontrol yok)
if (php_sapi_name() !== 'cli') {
    $anahtar = $_GET['anahtar'] ?? '';
    if ($anahtar !== ayar('site_adi', 'SADA Dijital')) {
        http_response_code(403);
        die('Yetkisiz. ?anahtar=SITE_ADI parametresi gerekli.');
    }
}

$sayi = tekrar_kontrol(true);

// Vadesi geçen "bekliyor" finans kayıtlarını "gecikti" yap
q("UPDATE odemeler SET durum='gecikti' WHERE durum='bekliyor' AND tarih < CURDATE()");

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'tekrarlayan_gorev' => $sayi, 'zaman' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);

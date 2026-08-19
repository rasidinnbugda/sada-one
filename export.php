<?php
/**
 * SADA One — CSV Dışa Aktarım
 * tip=gorevler | finans | zaman
 * Excel'in Türkçe karakterleri doğru açması için UTF-8 BOM + noktalı virgül ayracı kullanılır.
 */
require __DIR__ . '/includes/init.php';
$u = require_staff();

$tip = $_GET['tip'] ?? 'gorevler';

function csv_gonder(string $dosyaAdi, array $basliklar, array $satirlar): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $dosyaAdi . '_' . date('Y-m-d') . '.csv"');
    $cikti = fopen('php://output', 'w');
    fwrite($cikti, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel uyumu)
    fputcsv($cikti, $basliklar, ';');
    foreach ($satirlar as $s) fputcsv($cikti, $s, ';');
    fclose($cikti);
    exit;
}

switch ($tip) {
case 'gorevler':
    $veriler = rows("SELECT g.baslik, p.ad proje, d.ad dosya, u.ad atanan, g.durum, g.oncelik, g.son_tarih, g.created, g.tamamlanma
        FROM gorevler g JOIN projeler p ON p.id=g.proje_id JOIN dosyalar d ON d.id=p.dosya_id LEFT JOIN users u ON u.id=g.atanan_id
        ORDER BY g.id DESC");
    csv_gonder('gorevler', ['Görev', 'Proje', 'Dosya', 'Atanan', 'Durum', 'Öncelik', 'Son Tarih', 'Oluşturulma', 'Tamamlanma'],
        array_map(fn($r) => [$r['baslik'], $r['proje'], $r['dosya'], $r['atanan'] ?? '', GOREV_DURUMLARI[$r['durum']], ONCELIKLER[$r['oncelik']], $r['son_tarih'] ?? '', substr($r['created'], 0, 10), $r['tamamlanma'] ? substr($r['tamamlanma'], 0, 10) : ''], $veriler));

case 'finans':
    if (!yetki('finans')) yetkisiz();
    $veriler = rows("SELECT o.baslik, p.ad proje, d.ad dosya, o.tur, o.tutar, o.tarih, o.durum, o.aciklama
        FROM odemeler o JOIN projeler p ON p.id=o.proje_id JOIN dosyalar d ON d.id=p.dosya_id ORDER BY o.tarih DESC");
    csv_gonder('finans', ['Kayıt', 'Proje', 'Dosya', 'Tür', 'Tutar (TL)', 'Tarih', 'Durum', 'Açıklama'],
        array_map(fn($r) => [$r['baslik'], $r['proje'], $r['dosya'], $r['tur'] === 'fatura' ? 'Fatura' : 'Tahsilat', number_format((float)$r['tutar'], 2, ',', ''), $r['tarih'], ['bekliyor' => 'Bekliyor', 'odendi' => 'Ödendi', 'gecikti' => 'Gecikti'][$r['durum']], $r['aciklama'] ?? ''], $veriler));

case 'zaman':
    if (!yetki('kapasite') && !yetki('rapor')) yetkisiz();
    $veriler = rows("SELECT u.ad kisi, g.baslik gorev, p.ad proje, z.dakika, z.tarih, z.aciklama
        FROM zaman_kayitlari z JOIN users u ON u.id=z.user_id JOIN gorevler g ON g.id=z.gorev_id JOIN projeler p ON p.id=g.proje_id
        ORDER BY z.tarih DESC");
    csv_gonder('zaman_raporu', ['Kişi', 'Görev', 'Proje', 'Süre (dk)', 'Süre', 'Tarih', 'Açıklama'],
        array_map(fn($r) => [$r['kisi'], $r['gorev'], $r['proje'], $r['dakika'], dakika_format((int)$r['dakika']), $r['tarih'], $r['aciklama'] ?? ''], $veriler));

default:
    header('Location: index.php');
    exit;
}

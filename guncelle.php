<?php
/**
 * SADA Dijital — Sürüm Güncelleme Scripti (v1 → v2)
 * Mevcut kurulumlara yeni kolonları/tabloları güvenle ekler.
 * Birden fazla kez çalıştırılabilir (idempotent).
 * Kullanım: tarayıcıda /guncelle.php açın (yönetici girişi gerekir),
 * bittikten sonra bu dosyayı sunucudan silin.
 */
require __DIR__ . '/includes/init.php';
$u = require_admin();

$komutlar = [
    // users
    "ALTER TABLE users MODIFY rol ENUM('yonetici','pm','ekip','finans','musteri') NOT NULL DEFAULT 'ekip'",
    "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN haftalik_kapasite SMALLINT NOT NULL DEFAULT 45",
    "ALTER TABLE users ADD COLUMN izinler TEXT",
    "ALTER TABLE users ADD COLUMN bildirim_tercihleri TEXT",
    "ALTER TABLE users ADD COLUMN widgetler TEXT",
    // dosyalar
    "ALTER TABLE dosyalar ADD COLUMN logo VARCHAR(255) DEFAULT NULL",
    // gorevler
    "ALTER TABLE gorevler ADD COLUMN sira INT NOT NULL DEFAULT 0",
    "ALTER TABLE gorevler ADD COLUMN bagimli_id INT DEFAULT NULL",
    "ALTER TABLE gorevler ADD COLUMN kilit_acik TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE gorevler ADD COLUMN tekrar ENUM('yok','haftalik','aylik') NOT NULL DEFAULT 'yok'",
    "ALTER TABLE gorevler ADD COLUMN son_tekrar VARCHAR(10) DEFAULT NULL",
    // yeni tablolar
    "CREATE TABLE IF NOT EXISTS proje_uyeleri (proje_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (proje_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS dosya_uyeleri (dosya_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (dosya_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS gorev_kontrol (id INT AUTO_INCREMENT PRIMARY KEY, gorev_id INT NOT NULL, ad VARCHAR(200) NOT NULL, tamam TINYINT(1) NOT NULL DEFAULT 0, sira TINYINT NOT NULL DEFAULT 1, INDEX(gorev_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v3 ----
    "ALTER TABLE users ADD COLUMN gorev_gorunum VARCHAR(10) NOT NULL DEFAULT 'kanban'",
    "ALTER TABLE gorevler ADD COLUMN etiketler VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE gorevler ADD COLUMN tahmini_dakika INT NOT NULL DEFAULT 0",
    "ALTER TABLE gorevler ADD COLUMN baslangic_tarihi DATE DEFAULT NULL",
    "ALTER TABLE yorumlar ADD COLUMN parent_id INT DEFAULT NULL",
    "ALTER TABLE yorumlar ADD COLUMN arsiv_id INT DEFAULT NULL",
    "ALTER TABLE yorumlar ADD COLUMN duzenlendi TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS yorum_tepkiler (yorum_id INT NOT NULL, user_id INT NOT NULL, emoji VARCHAR(8) NOT NULL, PRIMARY KEY (yorum_id, user_id, emoji)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS gorev_izleyiciler (gorev_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (gorev_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v4 ----
    "ALTER TABLE gorevler ADD COLUMN arsivlendi TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN maas DECIMAL(12,2) NOT NULL DEFAULT 0",
    "ALTER TABLE kanallar ADD COLUMN simge VARCHAR(8) DEFAULT NULL",
    "ALTER TABLE kanal_uyeleri ADD COLUMN arsiv TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS gorev_atananlar (gorev_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (gorev_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS giderler (id INT AUTO_INCREMENT PRIMARY KEY, tur ENUM('maas','kira','abonelik','ekipman','vergi','diger') NOT NULL DEFAULT 'diger', baslik VARCHAR(200) NOT NULL, tutar DECIMAL(12,2) NOT NULL DEFAULT 0, tarih DATE NOT NULL, durum ENUM('bekliyor','odendi') NOT NULL DEFAULT 'bekliyor', tekrar ENUM('yok','aylik') NOT NULL DEFAULT 'yok', son_tekrar VARCHAR(10) DEFAULT NULL, user_id INT DEFAULT NULL, aciklama VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(tarih)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS duyurular (id INT AUTO_INCREMENT PRIMARY KEY, baslik VARCHAR(200) NOT NULL, metin TEXT, onemli TINYINT(1) NOT NULL DEFAULT 0, olusturan_id INT NOT NULL, created DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS duyuru_okuyanlar (duyuru_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (duyuru_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS giris_denemeleri (id INT AUTO_INCREMENT PRIMARY KEY, eposta VARCHAR(150) NOT NULL, ip VARCHAR(45) DEFAULT NULL, basarili TINYINT(1) NOT NULL DEFAULT 0, created DATETIME NOT NULL, INDEX(eposta, created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS sozlesmeler (id INT AUTO_INCREMENT PRIMARY KEY, dosya_id INT NOT NULL, baslik VARCHAR(200) NOT NULL, baslangic DATE DEFAULT NULL, bitis DATE DEFAULT NULL, tutar DECIMAL(12,2) NOT NULL DEFAULT 0, arsiv_id INT DEFAULT NULL, aciklama VARCHAR(255) DEFAULT NULL, hatirlatildi TINYINT(1) NOT NULL DEFAULT 0, created DATETIME NOT NULL, INDEX(dosya_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v5 ----
    "CREATE TABLE IF NOT EXISTS ekipmanlar (id INT AUTO_INCREMENT PRIMARY KEY, kod VARCHAR(20) DEFAULT NULL, ad VARCHAR(150) NOT NULL, kategori ENUM('kamera','lens','sd_kart','tripod','isik','ses','drone','aksesuar','diger') NOT NULL DEFAULT 'diger', foto VARCHAR(255) DEFAULT NULL, durum ENUM('studyoda','zimmette','cekimde','arizali','bakimda') NOT NULL DEFAULT 'studyoda', zimmet_user_id INT DEFAULT NULL, zimmet_etkinlik_id INT DEFAULT NULL, ariza_notu VARCHAR(255) DEFAULT NULL, satin_alma DATE DEFAULT NULL, fiyat DECIMAL(12,2) NOT NULL DEFAULT 0, sd_durum ENUM('bos','dolu','aktarildi') DEFAULT NULL, sd_icerik VARCHAR(255) DEFAULT NULL, sd_drive_link VARCHAR(255) DEFAULT NULL, aciklama VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(kategori), INDEX(durum)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS ekipman_hareketleri (id INT AUTO_INCREMENT PRIMARY KEY, ekipman_id INT NOT NULL, user_id INT NOT NULL, hedef_user_id INT DEFAULT NULL, etkinlik_id INT DEFAULT NULL, tur VARCHAR(20) NOT NULL, aciklama VARCHAR(500) DEFAULT NULL, created DATETIME NOT NULL, INDEX(ekipman_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS etkinlik_ekipmanlari (etkinlik_id INT NOT NULL, ekipman_id INT NOT NULL, PRIMARY KEY (etkinlik_id, ekipman_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v6 ----
    "ALTER TABLE users MODIFY rol ENUM('yonetici','pm','ekip','finans','stajyer','musteri') NOT NULL DEFAULT 'ekip'",
    "ALTER TABLE users ADD COLUMN karalama MEDIUMTEXT",
    "ALTER TABLE etkinlikler ADD COLUMN online_link VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE etkinlikler ADD COLUMN hatirlatildi TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS etkinlik_katilimcilari (etkinlik_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (etkinlik_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS kisisel_notlar (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, baslik VARCHAR(150) DEFAULT NULL, metin TEXT, renk VARCHAR(20) NOT NULL DEFAULT 'varsayilan', created DATETIME NOT NULL, guncelleme DATETIME DEFAULT NULL, INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS kisisel_isler (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, ad VARCHAR(255) NOT NULL, tamam TINYINT(1) NOT NULL DEFAULT 0, sira INT NOT NULL DEFAULT 0, INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS kisisel_linkler (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, ad VARCHAR(150) NOT NULL, url VARCHAR(500) NOT NULL, INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v7 ----
    "ALTER TABLE users ADD COLUMN gorulen_surum VARCHAR(10) DEFAULT NULL",
    "CREATE TABLE IF NOT EXISTS musteri_dosyalari (user_id INT NOT NULL, dosya_id INT NOT NULL, PRIMARY KEY (user_id, dosya_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "INSERT IGNORE INTO musteri_dosyalari (user_id, dosya_id) SELECT id, dosya_id FROM users WHERE rol='musteri' AND dosya_id IS NOT NULL",
    "CREATE TABLE IF NOT EXISTS puanlar (id INT AUTO_INCREMENT PRIMARY KEY, ref_tur ENUM('gorev','onay') NOT NULL, ref_id INT NOT NULL, proje_id INT NOT NULL, user_id INT NOT NULL, puan TINYINT NOT NULL, yorum VARCHAR(500) DEFAULT NULL, created DATETIME NOT NULL, UNIQUE KEY uniq_puan (ref_tur, ref_id, user_id), INDEX(proje_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS randevular (id INT AUTO_INCREMENT PRIMARY KEY, musteri_id INT NOT NULL, dosya_id INT DEFAULT NULL, konu VARCHAR(200) NOT NULL, tarih DATETIME NOT NULL, online_istek TINYINT(1) NOT NULL DEFAULT 0, notlar VARCHAR(500) DEFAULT NULL, durum ENUM('bekliyor','onaylandi','alternatif','reddedildi') NOT NULL DEFAULT 'bekliyor', alternatif_tarih DATETIME DEFAULT NULL, online_link VARCHAR(255) DEFAULT NULL, etkinlik_id INT DEFAULT NULL, cevap_notu VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(musteri_id), INDEX(durum)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v8 ----
    "ALTER TABLE icerikler ADD COLUMN dosya_id INT DEFAULT NULL AFTER id",
    "ALTER TABLE icerikler MODIFY proje_id INT DEFAULT NULL",
    "ALTER TABLE icerikler MODIFY platform VARCHAR(120) NOT NULL DEFAULT 'instagram'",
    "UPDATE icerikler i JOIN projeler p ON p.id=i.proje_id SET i.dosya_id=p.dosya_id WHERE i.dosya_id IS NULL",
    "CREATE TABLE IF NOT EXISTS sosyal_hesaplar (id INT AUTO_INCREMENT PRIMARY KEY, dosya_id INT NOT NULL, platform VARCHAR(20) NOT NULL DEFAULT 'instagram', kullanici_adi VARCHAR(100) NOT NULL, url VARCHAR(255) DEFAULT NULL, created DATETIME NOT NULL, INDEX(dosya_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS sosyal_metrikler (id INT AUTO_INCREMENT PRIMARY KEY, hesap_id INT NOT NULL, tarih DATE NOT NULL, takipci INT NOT NULL DEFAULT 0, gonderi INT DEFAULT NULL, etkilesim INT DEFAULT NULL, girilen_id INT DEFAULT NULL, created DATETIME NOT NULL, UNIQUE KEY uniq_metrik (hesap_id, tarih)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v9 ----
    "CREATE TABLE IF NOT EXISTS belgeler (id INT AUTO_INCREMENT PRIMARY KEY, tur ENUM('teklif','fatura') NOT NULL DEFAULT 'teklif', no VARCHAR(20) NOT NULL, dosya_id INT DEFAULT NULL, baslik VARCHAR(200) NOT NULL, kalemler TEXT, kdv_oran TINYINT NOT NULL DEFAULT 20, durum ENUM('taslak','gonderildi','onaylandi','reddedildi') NOT NULL DEFAULT 'taslak', gecerlilik DATE DEFAULT NULL, notlar VARCHAR(500) DEFAULT NULL, olusturan_id INT NOT NULL, created DATETIME NOT NULL, INDEX(dosya_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    // ---- v11 ----
    "ALTER TABLE arsiv ADD COLUMN url VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE onaylar ADD COLUMN drive_link VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE form_alanlari MODIFY tip ENUM('metin','uzun_metin','secim','tarih','sayi','dosya') NOT NULL DEFAULT 'metin'",
    // ---- v10 ----
    "ALTER TABLE gorevler ADD COLUMN icerik_id INT DEFAULT NULL",
    "CREATE TABLE IF NOT EXISTS proje_sablonlari (id INT AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(150) NOT NULL, aciklama VARCHAR(255) DEFAULT NULL, gorevler TEXT, created DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
    "CREATE TABLE IF NOT EXISTS dosya_notlari (id INT AUTO_INCREMENT PRIMARY KEY, dosya_id INT NOT NULL, baslik VARCHAR(150) NOT NULL, metin TEXT, sira INT NOT NULL DEFAULT 0, guncelleyen_id INT DEFAULT NULL, guncelleme DATETIME DEFAULT NULL, created DATETIME NOT NULL, INDEX(dosya_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci",
];

$sonuclar = [];
foreach ($komutlar as $sql) {
    try {
        db()->exec($sql);
        $sonuclar[] = ['ok', $sql];
    } catch (PDOException $e) {
        // "Duplicate column" / "already exists" hataları normaldir (zaten uygulanmış)
        $zaten = (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'exists') !== false);
        $sonuclar[] = [$zaten ? 'atla' : 'hata', $sql . ($zaten ? '' : ' — ' . $e->getMessage())];
    }
}
?>
<!DOCTYPE html>
<html lang="tr" data-theme="lime">
<head><meta charset="UTF-8"><title>Güncelleme</title><link rel="stylesheet" href="assets/css/app.css"></head>
<body style="padding:40px;max-width:820px;margin:0 auto">
<h1 style="font-family:'Space Grotesk',sans-serif;margin-bottom:8px">Veritabanı Güncellemesi</h1>
<p class="metin-2" style="margin-bottom:24px">v2 şema değişiklikleri uygulandı. "Atlandı" satırları zaten güncel olan kısımlardır.</p>
<?php foreach ($sonuclar as [$durum, $sql]): ?>
<div style="padding:9px 14px;margin-bottom:6px;border-radius:10px;font-size:12.5px;font-family:monospace;background:var(--surface);border:1px solid var(--border)">
    <?= ['ok' => '✅', 'atla' => '⏭️', 'hata' => '❌'][$durum] ?> <?= e(mb_substr($sql, 0, 110)) ?>
</div>
<?php endforeach; ?>
<div style="margin-top:24px;padding:14px 18px;border-radius:12px;background:var(--parlak);border:1px solid var(--border-2)">
    <b>Bitti!</b> Güvenlik için bu dosyayı (<code>guncelle.php</code>) sunucudan silin. <a href="index.php" style="color:var(--marka);font-weight:600">Panele dön →</a>
</div>
</body>
</html>

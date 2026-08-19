<?php
/**
 * SADA One — Merkezi AJAX işleyici
 * Tüm istemci eylemleri buradan geçer. CSRF ve yetki kontrollüdür.
 */
define('AJAX_ISTEK', true); // yetkisiz erişimde yönlendirme yerine JSON 403 döner
require __DIR__ . '/includes/init.php';
if (!user()) json_out(['ok' => false, 'hata' => 'Oturumunuz sona erdi. Sayfayı yenileyip tekrar giriş yapın.'], 401);
csrf_check();

$eylem = $_POST['eylem'] ?? '';
$u = user();
$now = date('Y-m-d H:i:s');
$g = fn($k, $v = '') => $_POST[$k] ?? $v;

switch ($eylem) {

/* ==================== TEMA & BİLDİRİM ==================== */
case 'tema_degistir':
    $tema = isset(TEMALAR[$g('tema')]) ? $g('tema') : 'lime';
    guncelle('users', ['tema' => $tema, 'renk' => TEMALAR[$tema][1]], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

case 'bildirim_oku':
    guncelle('bildirimler', ['okundu' => 1], 'id=? AND user_id=?', [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'surum_kontrol':
    if ($u['rol'] !== 'yonetici') yetkisiz();
    require_once __DIR__ . '/includes/guncelleyici.php';
    $rel = github_json('https://api.github.com/repos/' . GITHUB_DEPO . '/releases/latest');
    if (!$rel || empty($rel['tag_name'])) json_out(['ok' => false, 'hata' => 'GitHub\'a ulaşılamadı veya yayınlanmış sürüm yok.']);
    $son = ltrim($rel['tag_name'], 'vV');
    json_out([
        'ok' => true, 'mevcut' => SURUM, 'son' => $rel['tag_name'],
        'yeni_var' => version_compare($son, SURUM, '>'),
        'notlar' => mb_substr(trim((string)($rel['body'] ?? '')), 0, 300),
    ]);

/* ==================== v14: SOP MODÜLLERİ ==================== */
case 'mentorluk_kaydet':
    if (!is_admin() && $u['rol'] !== 'pm') yetkisiz();
    $veri = [
        'uye_id' => (int)$g('uye_id'), 'alan' => trim($g('alan')),
        'mentor_id' => (int)$g('mentor_id') ?: null, 'proje_id' => (int)$g('proje_id') ?: null,
        'saha' => trim($g('saha')) ?: null, 'cikti' => trim($g('cikti')) ?: null,
        'durum' => in_array($g('durum'), ['planlandi', 'devam', 'tamamlandi']) ? $g('durum') : 'planlandi',
    ];
    if (!$veri['uye_id'] || $veri['alan'] === '') json_out(['ok' => false, 'hata' => 'Ekip üyesi ve gelişim alanı zorunludur.']);
    if ($id = (int)$g('id')) { $veri['updated'] = $now; guncelle('mentorluk', $veri, 'id=?', [$id]); }
    else { $veri['created'] = $now; insert('mentorluk', $veri); }
    json_out(['ok' => true, 'mesaj' => 'Mentörlük kaydı güncellendi.', 'yenile' => true]);

case 'mentorluk_cikti':
    // Üye kendi kaydının çıktı notunu güncelleyebilir
    $kayit = row("SELECT * FROM mentorluk WHERE id=?", [(int)$g('id')]);
    if (!$kayit || (!is_admin() && $u['rol'] !== 'pm' && $kayit['uye_id'] != $u['id'])) yetkisiz();
    guncelle('mentorluk', ['cikti' => trim($g('cikti')), 'updated' => $now], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Çıktı notu kaydedildi.']);

case 'mentorluk_sil':
    if (!is_admin() && $u['rol'] !== 'pm') yetkisiz();
    q("DELETE FROM mentorluk WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'yenile' => true]);

case 'havuz_kaydet':
    if (is_stajyer() || is_musteri()) yetkisiz();
    $veri = [
        'ad' => trim($g('ad')), 'yetkinlik' => trim($g('yetkinlik')) ?: null,
        'calisildi' => (int)(bool)$g('calisildi'), 'iletisim' => trim($g('iletisim')) ?: null,
        'notu' => trim($g('notu')) ?: null,
    ];
    if ($veri['ad'] === '') json_out(['ok' => false, 'hata' => 'İsim zorunludur.']);
    if ($cv = dosya_yukle('cv')) {
        $veri['cv_arsiv_id'] = insert('arsiv', ['ad' => $cv['ad'], 'dosya_yolu' => $cv['yol'], 'boyut' => $cv['boyut'], 'uzanti' => $cv['uzanti'], 'yukleyen_id' => $u['id'], 'created' => $now]);
    }
    if ($id = (int)$g('id')) guncelle('calisan_havuzu', $veri, 'id=?', [$id]);
    else { $veri['ekleyen_id'] = $u['id']; $veri['created'] = $now; insert('calisan_havuzu', $veri); }
    json_out(['ok' => true, 'mesaj' => 'Havuz kaydı güncellendi.', 'yenile' => true]);

case 'havuz_sil':
    if (!is_admin() && $u['rol'] !== 'pm') yetkisiz();
    q("DELETE FROM calisan_havuzu WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'yenile' => true]);

case 'fikir_kaydet':
    if (is_musteri()) yetkisiz();
    $fikir = trim($g('fikir'));
    if ($fikir === '') json_out(['ok' => false, 'hata' => 'Fikir boş olamaz.']);
    insert('fikirler', ['fikir' => $fikir, 'kurum' => trim($g('kurum')) ?: null, 'aciklama' => trim($g('aciklama')) ?: null, 'oneren_id' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Fikir panoya eklendi.', 'yenile' => true]);

case 'fikir_durum':
    if (!is_admin() && $u['rol'] !== 'pm') yetkisiz();
    if (!in_array($g('durum'), ['yeni', 'begenildi', 'uygulandi'])) json_out(['ok' => false, 'hata' => 'Geçersiz durum.']);
    guncelle('fikirler', ['durum' => $g('durum')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true]);

case 'fikir_sil':
    $f = row("SELECT oneren_id FROM fikirler WHERE id=?", [(int)$g('id')]);
    if (!$f || (!is_admin() && $f['oneren_id'] != $u['id'])) yetkisiz();
    q("DELETE FROM fikirler WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'yenile' => true]);

case 'aylikrapor_kaydet':
    if (is_stajyer() || is_musteri()) yetkisiz();
    $dosyaId = (int)$g('dosya_id'); $donem = $g('donem');
    if (!$dosyaId || !preg_match('/^\d{4}-\d{2}$/', $donem)) json_out(['ok' => false, 'hata' => 'Dosya ve dönem (YYYY-AA) zorunludur.']);
    $veri = ['ozet' => trim($g('ozet')), 'yapilanlar' => trim($g('yapilanlar')), 'metrikler' => trim($g('metrikler')), 'plan' => trim($g('plan')),
        'durum' => $g('durum') === 'tamamlandi' ? 'tamamlandi' : 'taslak', 'updated' => $now];
    $var = row("SELECT id FROM aylik_raporlar WHERE dosya_id=? AND donem=?", [$dosyaId, $donem]);
    if ($var) guncelle('aylik_raporlar', $veri, 'id=?', [$var['id']]);
    else insert('aylik_raporlar', $veri + ['dosya_id' => $dosyaId, 'donem' => $donem, 'yazan_id' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Aylık rapor kaydedildi.', 'yenile' => true]);

case 'ynot_kaydet':
    if (!is_admin() && $u['rol'] !== 'pm') yetkisiz();
    $gid = (int)$g('gorev_id');
    if (!val("SELECT id FROM gorevler WHERE id=?", [$gid])) json_out(['ok' => false, 'hata' => 'Görev bulunamadı.']);
    q("INSERT INTO gorev_yonetici_notlari (gorev_id, user_id, notu, updated) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE notu=VALUES(notu), updated=VALUES(updated)", [$gid, $u['id'], trim($g('notu')), $now]);
    json_out(['ok' => true, 'mesaj' => 'Not kaydedildi.']);

/* ---- Proje istasyonu ---- */
case 'istasyon_kaydet':
    if (!yetki('dosya_yonet')) yetkisiz();
    $pid = (int)$g('proje_id');
    if (!proje_erisim($pid)) yetkisiz();
    $veri = ['devralma' => trim($g('devralma')) ?: null, 'ekip_rolleri' => $g('ekip_rolleri') ?: null];
    if (yetki('butce_gor')) {
        $veri['butce'] = (float)str_replace(',', '.', $g('butce', '0'));
        $veri['revize_limit'] = max(0, (int)$g('revize_limit', 2));
    }
    guncelle('projeler', $veri, 'id=?', [$pid]);
    json_out(['ok' => true, 'mesaj' => 'İstasyon bilgileri kaydedildi.', 'yenile' => true]);

case 'ektalep_kaydet':
    if (!yetki('butce_gor')) yetkisiz();
    $pid = (int)$g('proje_id');
    if (!proje_erisim($pid)) yetkisiz();
    $baslik = trim($g('baslik'));
    if ($baslik === '') json_out(['ok' => false, 'hata' => 'Talep başlığı zorunludur.']);
    insert('proje_ek_talepler', ['proje_id' => $pid, 'baslik' => $baslik, 'tutar' => (float)str_replace(',', '.', $g('tutar', '0')),
        'kapsam_disi' => (int)(bool)$g('kapsam_disi'), 'aciklama' => trim($g('aciklama')) ?: null, 'olusturan_id' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Ek talep kaydedildi.', 'yenile' => true]);

case 'ektalep_durum':
    if (!yetki('butce_gor')) yetkisiz();
    if (!in_array($g('durum'), ['bekliyor', 'onaylandi', 'reddedildi'])) json_out(['ok' => false, 'hata' => 'Geçersiz durum.']);
    guncelle('proje_ek_talepler', ['durum' => $g('durum')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true]);

case 'ektalep_sil':
    if (!yetki('butce_gor')) yetkisiz();
    q("DELETE FROM proje_ek_talepler WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'yenile' => true]);

case 'pkontrol_ekle':
    if (!is_staff()) yetkisiz();
    $pid = (int)$g('proje_id');
    if (!proje_erisim($pid)) yetkisiz();
    $kalem = trim($g('kalem'));
    if ($kalem === '') json_out(['ok' => false, 'hata' => 'Kalem adı boş olamaz.']);
    $id = insert('proje_kontrol_listesi', ['proje_id' => $pid, 'kalem' => $kalem, 'kontrol_notu' => trim($g('kontrol_notu')) ?: null,
        'sorumlu_id' => (int)$g('sorumlu_id') ?: null, 'sira' => (int)val("SELECT COALESCE(MAX(sira),0)+1 FROM proje_kontrol_listesi WHERE proje_id=?", [$pid])]);
    json_out(['ok' => true, 'id' => $id, 'yenile' => true]);

case 'pkontrol_standart':
    // SOP standart teknik kontrol listesi tek tıkla yüklenir
    if (!is_staff()) yetkisiz();
    $pid = (int)$g('proje_id');
    if (!proje_erisim($pid)) yetkisiz();
    $standart = [
        ['Kamera ve Lensler', 'Yedek bataryalar, hafıza kartları formatlandı mı, temizlik kitleri hazır mı?'],
        ['Işık Sistemleri', 'Ana ışık, dolgu ışığı, softbox, uzatma kabloları ve tripodlar hazır mı?'],
        ['Ses Ekipmanları', 'Yaka mikrofonları, telsiz alıcılar, kayıt cihazları ve yedek piller kontrol edildi mi?'],
        ['Prompter Hazırlığı', 'Prompter yazılımı güncellendi mi, konuşma metinleri sisteme yüklendi mi?'],
        ['Lojistik ve İzinler', 'Çekim mekan izinleri alındı mı, ulaşım ve akreditasyonlar sağlandı mı?'],
    ];
    $sira = (int)val("SELECT COALESCE(MAX(sira),0) FROM proje_kontrol_listesi WHERE proje_id=?", [$pid]);
    foreach ($standart as $s) {
        insert('proje_kontrol_listesi', ['proje_id' => $pid, 'kalem' => $s[0], 'kontrol_notu' => $s[1], 'sira' => ++$sira]);
    }
    json_out(['ok' => true, 'mesaj' => 'Standart SOP kontrol listesi yüklendi.', 'yenile' => true]);

case 'pkontrol_toggle':
    if (!is_staff()) yetkisiz();
    $alan = $g('alan') === 'teslim' ? 'teslim' : 'tamam';
    q("UPDATE proje_kontrol_listesi SET $alan = 1 - $alan WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true]);

case 'pkontrol_sorumlu':
    if (!is_staff()) yetkisiz();
    guncelle('proje_kontrol_listesi', ['sorumlu_id' => (int)$g('sorumlu_id') ?: null], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'yenile' => true]);

case 'pkontrol_sil':
    if (!is_staff()) yetkisiz();
    q("DELETE FROM proje_kontrol_listesi WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true]);

case 'degerlendirme_kaydet':
    if (!is_staff()) yetkisiz();
    $pid = (int)$g('proje_id');
    if (!proje_erisim($pid)) yetkisiz();
    if (!in_array($g('tur'), ['ic', 'dis', 'case_study'])) json_out(['ok' => false, 'hata' => 'Geçersiz değerlendirme türü.']);
    q("INSERT INTO proje_degerlendirme (proje_id, tur, icerik, guncelleyen_id, updated) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE icerik=VALUES(icerik), guncelleyen_id=VALUES(guncelleyen_id), updated=VALUES(updated)",
       [$pid, $g('tur'), trim($g('icerik')), $u['id'], $now]);
    json_out(['ok' => true, 'mesaj' => 'Değerlendirme kaydedildi.']);

case 'cekim_liste_kaydet':
    if (!yetki('takvim_yonet')) yetkisiz();
    guncelle('etkinlikler', ['alinacaklar' => trim($g('alinacaklar')) ?: null, 'ihtiyac_listesi' => trim($g('ihtiyac_listesi')) ?: null], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Çekim listesi güncellendi.', 'yenile' => true]);

case 'bildirim_sayi':
    json_out(['ok' => true, 'sayi' => (int)val("SELECT COUNT(*) FROM bildirimler WHERE user_id=? AND okundu=0", [$u['id']])]);

case 'bildirim_sil':
    q("DELETE FROM bildirimler WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'bildirim_temizle':
    q("DELETE FROM bildirimler WHERE user_id=?", [$u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Tüm bildirimler temizlendi.']);

case 'bildirim_tumunu_oku':
    guncelle('bildirimler', ['okundu' => 1], 'user_id=?', [$u['id']]);
    json_out(['ok' => true]);

case 'canli_durum':
    // Canlı senkron: sayfanın güncel durum özetini döner
    require_login();
    $baglam = $g('baglam');
    if ($baglam === 'gorev') json_out(['ok' => true, 'hash' => canli_hash_gorev((int)$g('id'))]);
    if ($baglam === 'liste') json_out(['ok' => true, 'hash' => canli_hash_liste()]);
    json_out(['ok' => false, 'hata' => 'Geçersiz bağlam.']);

/* ==================== DOSYALAR ==================== */
case 'dosya_kaydet':
    require_yetki('dosya_yonet');
    $veri = [
        'ad' => trim($g('ad')), 'tur' => $g('tur', 'marka'), 'renk' => $g('renk', '#182f5d'),
        'aciklama' => $g('aciklama'), 'iletisim_ad' => $g('iletisim_ad'),
        'iletisim_eposta' => $g('iletisim_eposta'), 'iletisim_tel' => $g('iletisim_tel'),
        'durum' => $g('durum', 'aktif'),
    ];
    if ($veri['ad'] === '') json_out(['ok' => false, 'hata' => 'Dosya adı gerekli.']);
    $logo = dosya_yukle('logo');
    if ($logo) {
        if (!in_array($logo['uzanti'], ['jpg', 'jpeg', 'png', 'gif', 'webp'])) json_out(['ok' => false, 'hata' => 'Logo için görsel dosyası seçin (jpg, png, webp).']);
        $veri['logo'] = $logo['yol'];
    }
    if ($g('id')) {
        $id = (int)$g('id');
        guncelle('dosyalar', $veri, 'id=?', [$id]);
        log_aktivite('"' . $veri['ad'] . '" dosyasını güncelledi', 'dosya', $id);
        dosya_uyeleri_kaydet($id, $g('uyeler'));
        json_out(['ok' => true, 'mesaj' => 'Dosya güncellendi.']);
    } else {
        $veri['created'] = $now;
        $id = insert('dosyalar', $veri);
        log_aktivite('"' . $veri['ad'] . '" dosyasını oluşturdu', 'dosya', $id);
        dosya_uyeleri_kaydet($id, $g('uyeler'));
        json_out(['ok' => true, 'mesaj' => 'Dosya oluşturuldu.', 'yonlendir' => 'dosya.php?id=' . $id]);
    }

case 'dosya_sil':
    require_admin();
    $id = (int)$g('id');
    if (val("SELECT COUNT(*) FROM projeler WHERE dosya_id=?", [$id]) > 0)
        json_out(['ok' => false, 'hata' => 'Bu dosyada projeler var. Önce projeleri silin.']);
    q("DELETE FROM dosyalar WHERE id=?", [$id]);
    json_out(['ok' => true, 'mesaj' => 'Dosya silindi.', 'yonlendir' => 'dosyalar.php']);

/* ==================== PROJELER ==================== */
case 'proje_kaydet':
    require_yetki('dosya_yonet');
    $veri = [
        'dosya_id' => (int)$g('dosya_id'), 'ad' => trim($g('ad')), 'tur' => $g('tur', 'aylik'),
        'aciklama' => $g('aciklama'), 'durum' => $g('durum', 'aktif'),
        'baslangic' => $g('baslangic') ?: null, 'bitis' => $g('bitis') ?: null,
        'pm_id' => $g('pm_id') ? (int)$g('pm_id') : null,
        'sozlesme_tutari' => (float)str_replace(',', '.', $g('sozlesme_tutari', '0')),
    ];
    if ($veri['ad'] === '' || !$veri['dosya_id']) json_out(['ok' => false, 'hata' => 'Proje adı ve dosya gerekli.']);
    if ($g('id')) {
        $id = (int)$g('id');
        guncelle('projeler', $veri, 'id=?', [$id]);
        proje_uyeleri_kaydet($id, $g('uyeler'));
        log_aktivite('"' . $veri['ad'] . '" projesini güncelledi', 'proje', $id);
        json_out(['ok' => true, 'mesaj' => 'Proje güncellendi.']);
    } else {
        $veri['created'] = $now;
        $id = insert('projeler', $veri);
        // Aylık projeyse mevcut ay için dönem aç
        if ($veri['tur'] === 'aylik') donem_getir_veya_olustur($id, (int)date('Y'), (int)date('n'));
        proje_kanali($id, 'proje');
        proje_kanali($id, 'musteri');
        proje_uyeleri_kaydet($id, $g('uyeler'));
        // Proje şablonundan görevleri kur
        if ($g('psablon_id')) {
            $ps = row("SELECT * FROM proje_sablonlari WHERE id=?", [(int)$g('psablon_id')]);
            foreach (json_decode($ps['gorevler'] ?? '[]', true) ?: [] as $si => $sg) {
                $gid = insert('gorevler', ['proje_id' => $id, 'baslik' => $sg['baslik'], 'oncelik' => $sg['oncelik'] ?? 'normal', 'olusturan_id' => $u['id'], 'durum' => 'yapilacak', 'sira' => $si + 1, 'created' => $now]);
                if (!empty($sg['akis_id'])) gorev_adimlari_kur($gid, (int)$sg['akis_id']);
            }
        }
        log_aktivite('"' . $veri['ad'] . '" projesini oluşturdu', 'proje', $id);
        json_out(['ok' => true, 'mesaj' => 'Proje oluşturuldu.', 'yonlendir' => 'proje.php?id=' . $id]);
    }

case 'proje_sil':
    require_admin();
    $id = (int)$g('id');
    foreach (['gorevler', 'icerikler', 'onaylar', 'odemeler', 'donemler'] as $t) q("DELETE FROM $t WHERE proje_id=?", [$id]);
    q("DELETE FROM projeler WHERE id=?", [$id]);
    json_out(['ok' => true, 'mesaj' => 'Proje silindi.', 'yonlendir' => 'projeler.php']);

case 'donem_ac':
    require_pm();
    $projeId = (int)$g('proje_id');
    $donemId = donem_getir_veya_olustur($projeId, (int)$g('yil'), (int)$g('ay'));
    // Şablondan görev oluşturma opsiyonu
    if ($g('sablon_id')) {
        $sablon = row("SELECT * FROM akis_sablonlari WHERE id=?", [(int)$g('sablon_id')]);
        $ilkAdim = row("SELECT ad FROM sablon_adimlari WHERE sablon_id=? ORDER BY sira LIMIT 1", [(int)$g('sablon_id')]);
        if ($sablon) {
            $gorevId = insert('gorevler', [
                'proje_id' => $projeId, 'donem_id' => $donemId,
                'baslik' => $sablon['ad'] . ' — ' . AYLAR[(int)$g('ay')] . ' ' . $g('yil'),
                'olusturan_id' => $u['id'], 'durum' => 'yapilacak', 'created' => $now,
            ]);
            gorev_adimlari_kur($gorevId, (int)$g('sablon_id'));
        }
    }
    json_out(['ok' => true, 'mesaj' => 'Dönem açıldı.']);

/* ==================== GÖREVLER ==================== */
case 'gorev_kaydet':
    require_staff();
    if (!$g('id') && !yetki('gorev_olustur')) json_out(['ok' => false, 'hata' => 'Görev oluşturma yetkiniz yok.']);
    $veri = [
        'proje_id' => (int)$g('proje_id'), 'baslik' => trim($g('baslik')), 'aciklama' => $g('aciklama'),
        'atanan_id' => $g('atanan_id') ? (int)$g('atanan_id') : null,
        'oncelik' => $g('oncelik', 'normal'), 'son_tarih' => $g('son_tarih') ?: null,
        'donem_id' => $g('donem_id') ? (int)$g('donem_id') : null,
        'bagimli_id' => $g('bagimli_id') ? (int)$g('bagimli_id') : null,
        'tekrar' => isset(TEKRARLAR[$g('tekrar')]) ? $g('tekrar') : 'yok',
        'etiketler' => mb_substr(trim($g('etiketler')), 0, 255) ?: null,
        'tahmini_dakika' => max(0, (int)((float)str_replace(',', '.', $g('tahmini_saat', '0')) * 60)),
        'baslangic_tarihi' => $g('baslangic_tarihi') ?: null,
    ];
    if ($veri['baslik'] === '' || !$veri['proje_id']) json_out(['ok' => false, 'hata' => 'Görev başlığı ve proje gerekli.']);
    if ($veri['bagimli_id'] === (int)$g('id') && $veri['bagimli_id']) json_out(['ok' => false, 'hata' => 'Görev kendisine bağlanamaz.']);
    // Çoklu atama listesi (JSON); atanan_id uyumluluk için ilk kişiye ayarlanır
    $atananlar = json_decode($g('atananlar', ''), true);
    if (is_array($atananlar)) {
        $atananlar = array_values(array_unique(array_filter(array_map('intval', $atananlar))));
        $veri['atanan_id'] = $atananlar[0] ?? null;
    }
    if ($g('id')) {
        $id = (int)$g('id');
        $eskiler = array_column(rows("SELECT user_id FROM gorev_atananlar WHERE gorev_id=?", [$id]), 'user_id');
        guncelle('gorevler', $veri, 'id=?', [$id]);
        if (is_array($atananlar)) {
            q("DELETE FROM gorev_atananlar WHERE gorev_id=?", [$id]);
            foreach ($atananlar as $aid) {
                q("INSERT IGNORE INTO gorev_atananlar (gorev_id, user_id) VALUES (?,?)", [$id, $aid]);
                if (!in_array($aid, $eskiler)) bildir($aid, 'Görev atandı', $veri['baslik'], 'gorev.php?id=' . $id, 'gorev');
            }
        }
        json_out(['ok' => true, 'mesaj' => 'Görev güncellendi.']);
    } else {
        $veri['olusturan_id'] = $u['id']; $veri['durum'] = 'yapilacak'; $veri['created'] = $now;
        $veri['sira'] = (int)val("SELECT COALESCE(MAX(sira),0)+1 FROM gorevler WHERE proje_id=? AND durum='yapilacak'", [$veri['proje_id']]);
        $id = insert('gorevler', $veri);
        // İçerik görevi: mevcut içeriğe bağla veya yeni içerik oluştur
        if ($g('icerik_secim') === 'yeni') {
            $projeDosya = (int)val("SELECT dosya_id FROM projeler WHERE id=?", [$veri['proje_id']]);
            $yeniIcerikId = insert('icerikler', [
                'dosya_id' => $projeDosya ?: null, 'proje_id' => $veri['proje_id'],
                'baslik' => $veri['baslik'],
                'platform' => isset(PLATFORMLAR[$g('icerik_platform')]) ? $g('icerik_platform') : 'instagram',
                'tarih' => $g('icerik_tarih') ?: ($veri['son_tarih'] ?: date('Y-m-d')),
                'durum' => 'taslak', 'olusturan_id' => $u['id'], 'created' => $now,
            ]);
            guncelle('gorevler', ['icerik_id' => $yeniIcerikId], 'id=?', [$id]);
        } elseif ((int)$g('icerik_secim') > 0) {
            guncelle('gorevler', ['icerik_id' => (int)$g('icerik_secim')], 'id=?', [$id]);
        }
        if ($g('sablon_id')) gorev_adimlari_kur($id, (int)$g('sablon_id'));
        if (is_array($atananlar)) {
            foreach ($atananlar as $aid) {
                q("INSERT IGNORE INTO gorev_atananlar (gorev_id, user_id) VALUES (?,?)", [$id, $aid]);
                bildir($aid, 'Yeni görev atandı', $veri['baslik'], 'gorev.php?id=' . $id, 'gorev');
            }
        } elseif ($veri['atanan_id']) {
            q("INSERT IGNORE INTO gorev_atananlar (gorev_id, user_id) VALUES (?,?)", [$id, $veri['atanan_id']]);
            bildir($veri['atanan_id'], 'Yeni görev atandı', $veri['baslik'], 'gorev.php?id=' . $id, 'gorev');
        }
        log_aktivite('"' . $veri['baslik'] . '" görevini oluşturdu', 'proje', $veri['proje_id']);
        json_out(['ok' => true, 'mesaj' => 'Görev oluşturuldu.']);
    }

case 'gorev_durum':
case 'gorev_sirala':
    require_staff();
    $id = (int)$g('id');
    $durum = $g('durum');
    if (!isset(GOREV_DURUMLARI[$durum])) json_out(['ok' => false, 'hata' => 'Geçersiz durum.']);
    $gorev = row("SELECT * FROM gorevler WHERE id=?", [$id]);
    if (!$gorev) json_out(['ok' => false, 'hata' => 'Görev bulunamadı.']);
    // Kilit kontrolleri (bağımlılık + akış durumu)
    if ($gorev['durum'] !== $durum) {
        $engel = gorev_kilit_nedeni($gorev, $durum);
        if ($engel) json_out(['ok' => false, 'hata' => '🔒 ' . $engel]);
    }
    $ek = ['durum' => $durum];
    if ($durum === 'tamamlandi' && $gorev['durum'] !== 'tamamlandi') $ek['tamamlanma'] = $now;
    guncelle('gorevler', $ek, 'id=?', [$id]);
    if ($durum === 'tamamlandi') gorev_icerik_senkron($id);
    // Sütun içi sıralamayı kaydet
    $idler = json_decode($g('idler', '[]'), true);
    if (is_array($idler) && $idler) {
        $st = db()->prepare("UPDATE gorevler SET sira=? WHERE id=?");
        foreach (array_values($idler) as $i => $gid) $st->execute([$i + 1, (int)$gid]);
    }
    if ($gorev['durum'] !== $durum) {
        log_aktivite('"' . $gorev['baslik'] . '" görevini ' . GOREV_DURUMLARI[$durum] . ' durumuna aldı', 'gorev', $id);
        $alicilar = array_column(rows("SELECT user_id FROM gorev_izleyiciler WHERE gorev_id=?", [$id]), 'user_id');
        if ($gorev['atanan_id']) $alicilar[] = (int)$gorev['atanan_id'];
        foreach (array_unique($alicilar) as $aid)
            bildir((int)$aid, 'Görev durumu değişti', $gorev['baslik'] . ' → ' . GOREV_DURUMLARI[$durum], 'gorev.php?id=' . $id, 'gorev');
    }
    json_out(['ok' => true]);

case 'gorev_sil':
    require_yetki('gorev_sil');
    $id = (int)$g('id');
    foreach (['gorev_adimlari', 'zaman_kayitlari', 'gorev_kontrol'] as $t) q("DELETE FROM $t WHERE gorev_id=?", [$id]);
    q("UPDATE gorevler SET bagimli_id=NULL WHERE bagimli_id=?", [$id]);
    q("DELETE FROM gorevler WHERE id=?", [$id]);
    json_out(['ok' => true, 'mesaj' => 'Görev silindi.', 'yonlendir' => 'gorevler.php']);

case 'gorev_alan':
    // Tablo görünümünde hücre içi düzenleme (alan bazlı güncelleme)
    require_staff();
    $id = (int)$g('id');
    $alan = $g('alan');
    $deger = $g('deger');
    $gorev = row("SELECT * FROM gorevler WHERE id=?", [$id]);
    if (!$gorev) json_out(['ok' => false, 'hata' => 'Görev bulunamadı.']);
    $izinli = ['durum', 'oncelik', 'atanan_id', 'son_tarih', 'baslangic_tarihi', 'tahmini_dakika', 'etiketler'];
    if (!in_array($alan, $izinli)) json_out(['ok' => false, 'hata' => 'Bu alan düzenlenemez.']);
    if ($alan === 'durum') {
        if (!isset(GOREV_DURUMLARI[$deger])) json_out(['ok' => false, 'hata' => 'Geçersiz durum.']);
        if ($gorev['durum'] !== $deger) {
            $engel = gorev_kilit_nedeni($gorev, $deger);
            if ($engel) json_out(['ok' => false, 'hata' => '🔒 ' . $engel]);
        }
        $ek = ['durum' => $deger];
        if ($deger === 'tamamlandi' && $gorev['durum'] !== 'tamamlandi') $ek['tamamlanma'] = $now;
        guncelle('gorevler', $ek, 'id=?', [$id]);
        if ($deger === 'tamamlandi') gorev_icerik_senkron($id);
        log_aktivite('"' . $gorev['baslik'] . '" görevini ' . GOREV_DURUMLARI[$deger] . ' durumuna aldı', 'gorev', $id);
    } elseif ($alan === 'oncelik') {
        if (!isset(ONCELIKLER[$deger])) json_out(['ok' => false, 'hata' => 'Geçersiz öncelik.']);
        guncelle('gorevler', ['oncelik' => $deger], 'id=?', [$id]);
    } elseif ($alan === 'atanan_id') {
        $yeni = $deger ? (int)$deger : null;
        guncelle('gorevler', ['atanan_id' => $yeni], 'id=?', [$id]);
        if ($gorev['atanan_id']) q("DELETE FROM gorev_atananlar WHERE gorev_id=? AND user_id=?", [$id, $gorev['atanan_id']]);
        if ($yeni) {
            q("INSERT IGNORE INTO gorev_atananlar (gorev_id, user_id) VALUES (?,?)", [$id, $yeni]);
            if ($yeni != $gorev['atanan_id']) bildir($yeni, 'Görev atandı', $gorev['baslik'], 'gorev.php?id=' . $id, 'gorev');
        }
    } elseif ($alan === 'tahmini_dakika') {
        guncelle('gorevler', ['tahmini_dakika' => max(0, (int)((float)str_replace(',', '.', $deger) * 60))], 'id=?', [$id]);
    } elseif ($alan === 'etiketler') {
        guncelle('gorevler', ['etiketler' => mb_substr(trim($deger), 0, 255) ?: null], 'id=?', [$id]);
    } else { // tarih alanları
        guncelle('gorevler', [$alan => $deger ?: null], 'id=?', [$id]);
    }
    json_out(['ok' => true]);

case 'gorev_arsiv':
    require_staff();
    $id = (int)$g('id');
    $gorev = row("SELECT * FROM gorevler WHERE id=?", [$id]);
    if (!$gorev) json_out(['ok' => false, 'hata' => 'Görev bulunamadı.']);
    $yeni = $gorev['arsivlendi'] ? 0 : 1;
    guncelle('gorevler', ['arsivlendi' => $yeni], 'id=?', [$id]);
    log_aktivite('"' . $gorev['baslik'] . '" görevini ' . ($yeni ? 'arşivledi' : 'arşivden çıkardı'), 'gorev', $id);
    json_out(['ok' => true, 'mesaj' => $yeni ? 'Görev arşive taşındı.' : 'Görev arşivden çıkarıldı.', 'yonlendir' => $yeni ? 'gorevler.php' : '']);

case 'gorunum_tercih':
    require_login();
    $gorunum = in_array($g('gorunum'), ['kanban', 'tablo']) ? $g('gorunum') : 'kanban';
    guncelle('users', ['gorev_gorunum' => $gorunum], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

case 'izleyici_toggle':
    require_staff();
    $gid = (int)$g('gorev_id');
    $hedef = (int)$g('user_id');
    if (!val("SELECT COUNT(*) FROM gorevler WHERE id=?", [$gid])) json_out(['ok' => false, 'hata' => 'Görev bulunamadı.']);
    $var = val("SELECT COUNT(*) FROM gorev_izleyiciler WHERE gorev_id=? AND user_id=?", [$gid, $hedef]);
    if ($var) { q("DELETE FROM gorev_izleyiciler WHERE gorev_id=? AND user_id=?", [$gid, $hedef]); $m = 'İzleyici çıkarıldı.'; }
    else {
        q("INSERT IGNORE INTO gorev_izleyiciler (gorev_id, user_id) VALUES (?,?)", [$gid, $hedef]);
        $baslik = val("SELECT baslik FROM gorevler WHERE id=?", [$gid]);
        bildir($hedef, 'Bir göreve izleyici eklendiniz', $baslik, 'gorev.php?id=' . $gid, 'gorev');
        $m = 'İzleyici eklendi.';
    }
    json_out(['ok' => true, 'mesaj' => $m]);

case 'icerik_tasi':
    require_yetki('icerik_yonet');
    $ic = row("SELECT * FROM icerikler WHERE id=?", [(int)$g('id')]);
    if (!$ic) json_out(['ok' => false, 'hata' => 'İçerik bulunamadı.']);
    $yeni = ['tarih' => $g('tarih') ?: $ic['tarih']];
    if ($g('saat') !== '') $yeni['saat'] = $g('saat') ?: null;
    guncelle('icerikler', $yeni, 'id=?', [$ic['id']]);
    json_out(['ok' => true, 'mesaj' => 'İçerik ' . tarih($yeni['tarih']) . ' tarihine taşındı.']);

case 'etkinlik_tasi':
    require_yetki('takvim_yonet');
    $et = row("SELECT * FROM etkinlikler WHERE id=?", [(int)$g('id')]);
    if (!$et) json_out(['ok' => false, 'hata' => 'Etkinlik bulunamadı.']);
    if ($g('baslangic')) {
        // Tam datetime verildi (modal düzenleme)
        $yeniBas = $g('baslangic');
        $yeniBit = $g('bitis') ?: null;
    } else {
        // Yalnızca gün verildi (sürükleme): saati koru, bitişi aynı gün farkıyla kaydır
        $gun = $g('tarih');
        if (!$gun) json_out(['ok' => false, 'hata' => 'Tarih gerekli.']);
        $fark = strtotime($gun) - strtotime(date('Y-m-d', strtotime($et['baslangic'])));
        $yeniBas = date('Y-m-d H:i:s', strtotime($et['baslangic']) + $fark);
        $yeniBit = $et['bitis'] ? date('Y-m-d H:i:s', strtotime($et['bitis']) + $fark) : null;
    }
    guncelle('etkinlikler', ['baslangic' => $yeniBas, 'bitis' => $yeniBit, 'hatirlatildi' => 0], 'id=?', [$et['id']]);
    json_out(['ok' => true, 'mesaj' => 'Etkinlik taşındı: ' . tarih($yeniBas, true)]);

case 'dosyanot_kaydet':
    require_staff();
    $baslik = mb_substr(trim($g('baslik')), 0, 150);
    if ($baslik === '') json_out(['ok' => false, 'hata' => 'Bölüm başlığı gerekli.']);
    if ($g('id')) {
        guncelle('dosya_notlari', ['baslik' => $baslik, 'metin' => $g('metin'), 'guncelleyen_id' => $u['id'], 'guncelleme' => $now], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Not güncellendi.']);
    }
    insert('dosya_notlari', ['dosya_id' => (int)$g('dosya_id'), 'baslik' => $baslik, 'metin' => $g('metin'), 'sira' => (int)val("SELECT COALESCE(MAX(sira),0)+1 FROM dosya_notlari WHERE dosya_id=?", [(int)$g('dosya_id')]), 'guncelleyen_id' => $u['id'], 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Bilgi notu eklendi.']);

case 'dosyanot_sil':
    require_staff();
    q("DELETE FROM dosya_notlari WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Not silindi.']);

case 'psablon_kaydet':
    require_admin();
    $ad = trim($g('ad'));
    $sablonGorevler = json_decode($g('gorevler', '[]'), true) ?: [];
    $sablonGorevler = array_values(array_filter(array_map(fn($s) => ['baslik' => mb_substr(trim($s['baslik'] ?? ''), 0, 200), 'akis_id' => (int)($s['akis_id'] ?? 0), 'oncelik' => isset(ONCELIKLER[$s['oncelik'] ?? '']) ? $s['oncelik'] : 'normal'], $sablonGorevler), fn($s) => $s['baslik'] !== ''));
    if ($ad === '' || !$sablonGorevler) json_out(['ok' => false, 'hata' => 'Ad ve en az bir görev gerekli.']);
    if ($g('id')) {
        guncelle('proje_sablonlari', ['ad' => $ad, 'aciklama' => $g('aciklama'), 'gorevler' => json_encode($sablonGorevler, JSON_UNESCAPED_UNICODE)], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Şablon güncellendi.']);
    }
    insert('proje_sablonlari', ['ad' => $ad, 'aciklama' => $g('aciklama'), 'gorevler' => json_encode($sablonGorevler, JSON_UNESCAPED_UNICODE), 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Proje şablonu kaydedildi.']);

case 'psablon_sil':
    require_admin();
    q("DELETE FROM proje_sablonlari WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Şablon silindi.']);

case 'kilit_toggle':
    require_pm(); // yalnızca yönetici ve PM kilidi devre dışı bırakabilir
    $id = (int)$g('id');
    $gorev = row("SELECT * FROM gorevler WHERE id=?", [$id]);
    if (!$gorev) json_out(['ok' => false, 'hata' => 'Görev bulunamadı.']);
    $yeni = $gorev['kilit_acik'] ? 0 : 1;
    guncelle('gorevler', ['kilit_acik' => $yeni], 'id=?', [$id]);
    log_aktivite('"' . $gorev['baslik'] . '" görevinin kilidini ' . ($yeni ? 'devre dışı bıraktı' : 'etkinleştirdi'), 'gorev', $id);
    json_out(['ok' => true, 'mesaj' => $yeni ? 'Kilit devre dışı — görev serbestçe ilerletilebilir.' : 'Kilit yeniden etkin.']);

case 'adim_tamamla':
    require_staff();
    $adimId = (int)$g('id');
    $adim = row("SELECT * FROM gorev_adimlari WHERE id=?", [$adimId]);
    if (!$adim) json_out(['ok' => false, 'hata' => 'Adım bulunamadı.']);
    $gorev = row("SELECT * FROM gorevler WHERE id=?", [$adim['gorev_id']]);
    $yeniDurum = $adim['durum'] === 'tamam' ? 'bekliyor' : 'tamam';
    // Sıralı adım zorunluluğu: önceki adımlar bitmeden bu adım tamamlanamaz
    if ($yeniDurum === 'tamam' && empty($gorev['kilit_acik'])) {
        $oncekiEksik = (int)val("SELECT COUNT(*) FROM gorev_adimlari WHERE gorev_id=? AND sira<? AND durum!='tamam'", [$adim['gorev_id'], $adim['sira']]);
        if ($oncekiEksik > 0) json_out(['ok' => false, 'hata' => '🔒 Önceki ' . $oncekiEksik . ' adım tamamlanmadan bu adım tamamlanamaz.' . (is_pm() ? ' (Görev sayfasından kilidi devre dışı bırakabilirsiniz.)' : '')]);
    }
    guncelle('gorev_adimlari', ['durum' => $yeniDurum, 'tamam_tarih' => $yeniDurum === 'tamam' ? $now : null], 'id=?', [$adimId]);
    if ($yeniDurum === 'tamam') {
        $sonraki = row("SELECT id FROM gorev_adimlari WHERE gorev_id=? AND sira>? AND durum!='tamam' ORDER BY sira LIMIT 1", [$adim['gorev_id'], $adim['sira']]);
        if ($sonraki) guncelle('gorev_adimlari', ['durum' => 'aktif'], 'id=?', [$sonraki['id']]);
        $kalan = (int)val("SELECT COUNT(*) FROM gorev_adimlari WHERE gorev_id=? AND durum!='tamam'", [$adim['gorev_id']]);
        if ($kalan === 0) { guncelle('gorevler', ['durum' => 'tamamlandi', 'tamamlanma' => $now], 'id=?', [$adim['gorev_id']]); gorev_icerik_senkron((int)$adim['gorev_id']); }
        // Adım sorumlusuna sıra geldi bildirimi
        if ($sonraki) {
            $sorumluId = val("SELECT sorumlu_id FROM gorev_adimlari WHERE id=?", [$sonraki['id']]);
            if ($sorumluId) bildir((int)$sorumluId, 'Akışta sıra sizde', $gorev['baslik'], 'gorev.php?id=' . $adim['gorev_id'], 'gorev');
        }
    } else {
        // Geri alınan adımdan sonraki tamamlar bekliyor'a düşsün (tutarlılık)
        q("UPDATE gorev_adimlari SET durum='bekliyor', tamam_tarih=NULL WHERE gorev_id=? AND sira>? AND durum='tamam'", [$adim['gorev_id'], $adim['sira']]);
        q("UPDATE gorevler SET durum='devam', tamamlanma=NULL WHERE id=? AND durum='tamamlandi'", [$adim['gorev_id']]);
    }
    // Güncel akış durumunu döndür (yenilemesiz arayüz güncellemesi için)
    $adimlarSon = rows("SELECT id, sira, durum FROM gorev_adimlari WHERE gorev_id=? ORDER BY sira", [$adim['gorev_id']]);
    $gorevSon = row("SELECT durum FROM gorevler WHERE id=?", [$adim['gorev_id']]);
    json_out([
        'ok' => true, 'mesaj' => 'Akış adımı güncellendi.',
        'adimlar' => $adimlarSon,
        'tamam_adet' => count(array_filter($adimlarSon, fn($a) => $a['durum'] === 'tamam')),
        'toplam' => count($adimlarSon),
        'gorev_durum' => $gorevSon['durum'],
        'gorev_durum_etiket' => GOREV_DURUMLARI[$gorevSon['durum']],
    ]);

/* ==================== KONTROL LİSTESİ (checklist) ==================== */
case 'kontrol_ekle':
    require_staff();
    $ad = trim($g('ad'));
    if ($ad === '') json_out(['ok' => false, 'hata' => 'Madde boş olamaz.']);
    $gid = (int)$g('gorev_id');
    $sira = (int)val("SELECT COALESCE(MAX(sira),0)+1 FROM gorev_kontrol WHERE gorev_id=?", [$gid]);
    $id = insert('gorev_kontrol', ['gorev_id' => $gid, 'ad' => $ad, 'tamam' => 0, 'sira' => $sira]);
    json_out(['ok' => true, 'id' => $id, 'ad' => $ad]);

case 'kontrol_toggle':
    require_staff();
    q("UPDATE gorev_kontrol SET tamam=1-tamam WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true]);

case 'kontrol_sil':
    require_staff();
    q("DELETE FROM gorev_kontrol WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true]);

case 'adim_sorumlu':
    require_staff();
    $yeniSorumlu = $g('sorumlu_id') ? (int)$g('sorumlu_id') : null;
    guncelle('gorev_adimlari', ['sorumlu_id' => $yeniSorumlu], 'id=?', [(int)$g('id')]);
    if ($yeniSorumlu) {
        $adimBilgi = row("SELECT ga.ad, g.baslik, g.id gid FROM gorev_adimlari ga JOIN gorevler g ON g.id=ga.gorev_id WHERE ga.id=?", [(int)$g('id')]);
        if ($adimBilgi) bildir($yeniSorumlu, 'Akış adımı size atandı', $adimBilgi['baslik'] . ' → ' . $adimBilgi['ad'], 'gorev.php?id=' . $adimBilgi['gid'], 'gorev');
    }
    json_out(['ok' => true, 'mesaj' => 'Sorumlu atandı.']);

/* ==================== ZAMAN TAKİBİ ==================== */
case 'zaman_ekle':
    require_staff();
    $dk = (int)$g('saat') * 60 + (int)$g('dakika');
    if ($dk <= 0) json_out(['ok' => false, 'hata' => 'Süre girin.']);
    insert('zaman_kayitlari', [
        'gorev_id' => (int)$g('gorev_id'), 'user_id' => $u['id'], 'dakika' => $dk,
        'tarih' => $g('tarih') ?: date('Y-m-d'), 'aciklama' => $g('aciklama'), 'created' => $now,
    ]);
    json_out(['ok' => true, 'mesaj' => dakika_format($dk) . ' zaman kaydedildi.']);

/* ==================== YORUMLAR ==================== */
case 'yorum_ekle':
    require_login();
    $mesaj = trim($g('mesaj'));
    if ($mesaj === '') json_out(['ok' => false, 'hata' => 'Yorum boş olamaz.']);
    $refTur = $g('ref_tur'); $refId = (int)$g('ref_id');
    // Erişim: müşteri yalnızca kendi projelerinin görev/projelerine yorum yazabilir
    if (is_musteri()) {
        $projeId = $refTur === 'proje' ? $refId : (int)val("SELECT proje_id FROM gorevler WHERE id=?", [$refId]);
        if (!proje_erisim($projeId)) json_out(['ok' => false, 'hata' => 'Bu alana yorum yazamazsınız.']);
    }
    $veri = [
        'ref_tur' => $refTur, 'ref_id' => $refId, 'user_id' => $u['id'],
        'mesaj' => $mesaj, 'created' => $now,
        'parent_id' => $g('parent_id') ? (int)$g('parent_id') : null,
    ];
    // Dosya eki
    $ek = dosya_yukle('dosya');
    if ($ek) {
        $veri['arsiv_id'] = insert('arsiv', [
            'proje_id' => $refTur === 'proje' ? $refId : (int)val("SELECT proje_id FROM gorevler WHERE id=?", [$refId]),
            'gorev_id' => $refTur === 'gorev' ? $refId : null,
            'ad' => $ek['ad'], 'dosya_yolu' => $ek['yol'], 'boyut' => $ek['boyut'],
            'uzanti' => $ek['uzanti'], 'yukleyen_id' => $u['id'], 'created' => $now,
        ]);
    }
    $yorumId = insert('yorumlar', $veri);
    // Bağlam bilgisi + link
    if ($refTur === 'gorev') {
        $baglam = row("SELECT baslik, atanan_id, proje_id FROM gorevler WHERE id=?", [$refId]);
        $link = 'gorev.php?id=' . $refId;
        // İzleyiciler + atanan kişiye bildir
        $alicilar = array_column(rows("SELECT user_id FROM gorev_izleyiciler WHERE gorev_id=?", [$refId]), 'user_id');
        if ($baglam['atanan_id']) $alicilar[] = (int)$baglam['atanan_id'];
        foreach (array_unique($alicilar) as $aid)
            bildir((int)$aid, 'Yeni yorum: ' . $baglam['baslik'], $u['ad'] . ': ' . mb_substr($mesaj, 0, 80), $link, 'mesaj');
    } else {
        $baglam = row("SELECT ad baslik FROM projeler WHERE id=?", [$refId]);
        $link = 'proje.php?id=' . $refId . '#tartisma';
        foreach (rows("SELECT user_id FROM proje_uyeleri WHERE proje_id=?", [$refId]) as $pu)
            bildir((int)$pu['user_id'], 'Proje tartışması: ' . ($baglam['baslik'] ?? ''), $u['ad'] . ': ' . mb_substr($mesaj, 0, 80), $link, 'mesaj');
    }
    // Yanıtsa, üst yorumun sahibine bildir
    if ($veri['parent_id']) {
        $ustSahip = (int)val("SELECT user_id FROM yorumlar WHERE id=?", [$veri['parent_id']]);
        if ($ustSahip) bildir($ustSahip, $u['ad'] . ' yorumunuza yanıt verdi', mb_substr($mesaj, 0, 80), $link, 'mesaj');
    }
    // Etiketlenenlere bildir
    mentionlari_bildir($g('mention_idler', ''), $u['ad'] . ' sizi etiketledi', mb_substr($mesaj, 0, 90), $link);
    json_out(['ok' => true, 'mesaj' => 'Yorum eklendi.']);

case 'yorum_duzenle':
    require_login();
    $yorum = row("SELECT * FROM yorumlar WHERE id=?", [(int)$g('id')]);
    if (!$yorum || $yorum['user_id'] != $u['id']) json_out(['ok' => false, 'hata' => 'Yalnızca kendi yorumunuzu düzenleyebilirsiniz.']);
    $mesaj = trim($g('mesaj'));
    if ($mesaj === '') json_out(['ok' => false, 'hata' => 'Yorum boş olamaz.']);
    guncelle('yorumlar', ['mesaj' => $mesaj, 'duzenlendi' => 1], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Yorum güncellendi.']);

case 'yorum_sil':
    require_login();
    $yorum = row("SELECT * FROM yorumlar WHERE id=?", [(int)$g('id')]);
    if (!$yorum || ($yorum['user_id'] != $u['id'] && !is_admin())) json_out(['ok' => false, 'hata' => 'Bu yorumu silme yetkiniz yok.']);
    q("DELETE FROM yorumlar WHERE id=? OR parent_id=?", [(int)$g('id'), (int)$g('id')]);
    q("DELETE FROM yorum_tepkiler WHERE yorum_id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Yorum silindi.']);

case 'tepki_toggle':
    require_login();
    $yorumId = (int)$g('yorum_id');
    $emoji = mb_substr(trim($g('emoji')), 0, 8);
    if (!$emoji || !val("SELECT COUNT(*) FROM yorumlar WHERE id=?", [$yorumId])) json_out(['ok' => false, 'hata' => 'Geçersiz.']);
    $var = val("SELECT COUNT(*) FROM yorum_tepkiler WHERE yorum_id=? AND user_id=? AND emoji=?", [$yorumId, $u['id'], $emoji]);
    if ($var) q("DELETE FROM yorum_tepkiler WHERE yorum_id=? AND user_id=? AND emoji=?", [$yorumId, $u['id'], $emoji]);
    else q("INSERT INTO yorum_tepkiler (yorum_id, user_id, emoji) VALUES (?,?,?)", [$yorumId, $u['id'], $emoji]);
    $adet = (int)val("SELECT COUNT(*) FROM yorum_tepkiler WHERE yorum_id=? AND emoji=?", [$yorumId, $emoji]);
    json_out(['ok' => true, 'adet' => $adet, 'benim' => $var ? 0 : 1]);

/* ==================== ONAYLAR ==================== */
case 'onay_gonder':
    require_yetki('onay_gonder');
    $veri = [
        'proje_id' => (int)$g('proje_id'), 'baslik' => trim($g('baslik')), 'aciklama' => $g('aciklama'),
        'gorev_id' => $g('gorev_id') ? (int)$g('gorev_id') : null,
        'icerik_id' => $g('icerik_id') ? (int)$g('icerik_id') : null,
        'gonderen_id' => $u['id'], 'durum' => 'bekliyor', 'created' => $now,
    ];
    if ($veri['baslik'] === '') json_out(['ok' => false, 'hata' => 'Onay başlığı gerekli.']);
    // Dosya eki veya Drive linki
    $yuklenen = dosya_yukle('dosya');
    if ($yuklenen) {
        $arsivId = insert('arsiv', [
            'proje_id' => $veri['proje_id'], 'ad' => $yuklenen['ad'], 'dosya_yolu' => $yuklenen['yol'],
            'boyut' => $yuklenen['boyut'], 'uzanti' => $yuklenen['uzanti'], 'yukleyen_id' => $u['id'], 'created' => $now,
        ]);
        $veri['arsiv_id'] = $arsivId;
    }
    $dLink = trim($g('drive_link'));
    if ($dLink !== '') {
        if (!preg_match('#^https?://#i', $dLink)) $dLink = 'https://' . $dLink;
        $veri['drive_link'] = mb_substr($dLink, 0, 500);
    }
    $id = insert('onaylar', $veri);
    // Müşteriye bildir (birincil dosya + ek dosya atamaları)
    $dosyaId = (int)val("SELECT dosya_id FROM projeler WHERE id=?", [$veri['proje_id']]);
    foreach (rows("SELECT DISTINCT us.id FROM users us LEFT JOIN musteri_dosyalari md ON md.user_id=us.id
        WHERE us.rol='musteri' AND us.aktif=1 AND (us.dosya_id=? OR md.dosya_id=?)", [$dosyaId, $dosyaId]) as $m)
        bildir((int)$m['id'], 'Onayınız bekleniyor', $veri['baslik'], 'onaylar.php', 'onay');
    log_aktivite('"' . $veri['baslik'] . '" için onay gönderdi', 'proje', $veri['proje_id']);
    json_out(['ok' => true, 'mesaj' => 'Onaya gönderildi.']);

case 'onay_cevap':
    require_login();
    $id = (int)$g('id');
    $onay = row("SELECT * FROM onaylar WHERE id=?", [$id]);
    if (!$onay || !proje_erisim($onay['proje_id'])) json_out(['ok' => false, 'hata' => 'Yetkisiz.']);
    $durum = $g('durum');
    if (!in_array($durum, ['onaylandi', 'revize', 'reddedildi'])) json_out(['ok' => false, 'hata' => 'Geçersiz.']);
    guncelle('onaylar', [
        'durum' => $durum, 'cevap_notu' => $g('not'), 'cevap_tarih' => $now, 'cevaplayan_id' => $u['id'],
    ], 'id=?', [$id]);
    bildir($onay['gonderen_id'], 'Onay yanıtlandı: ' . ONAY_DURUMLARI[$durum], $onay['baslik'] . ($g('not') ? ' — ' . $g('not') : ''), 'onaylar.php', 'onay');
    log_aktivite('"' . $onay['baslik'] . '" onayını ' . ONAY_DURUMLARI[$durum] . ' olarak yanıtladı', 'proje', $onay['proje_id']);
    json_out(['ok' => true, 'mesaj' => 'Yanıtınız kaydedildi.']);

/* ==================== İÇERİK TAKVİMİ ==================== */
case 'icerik_kaydet':
    require_yetki('icerik_yonet');
    // Çoklu platform: JSON dizi → CSV
    $platformlar = json_decode($g('platformlar', ''), true);
    if (is_array($platformlar)) {
        $platformlar = array_values(array_intersect(array_map('strval', $platformlar), array_keys(PLATFORMLAR)));
        $platformCsv = $platformlar ? implode(',', $platformlar) : 'instagram';
    } else {
        $platformCsv = isset(PLATFORMLAR[$g('platform')]) ? $g('platform') : 'instagram';
    }
    $projeId = $g('proje_id') ? (int)$g('proje_id') : null;
    $dosyaId = $g('dosya_id') ? (int)$g('dosya_id') : null;
    if (!$dosyaId && $projeId) $dosyaId = (int)val("SELECT dosya_id FROM projeler WHERE id=?", [$projeId]) ?: null;
    if (!$dosyaId) json_out(['ok' => false, 'hata' => 'İçeriğin ait olduğu dosyayı seçin.']);
    $veri = [
        'dosya_id' => $dosyaId, 'proje_id' => $projeId,
        'baslik' => trim($g('baslik')), 'aciklama' => $g('aciklama'),
        'platform' => $platformCsv, 'tarih' => $g('tarih') ?: date('Y-m-d'),
        'saat' => $g('saat') ?: null, 'durum' => $g('durum', 'taslak'),
    ];
    if ($veri['baslik'] === '') json_out(['ok' => false, 'hata' => 'İçerik başlığı gerekli.']);
    if ($g('id')) {
        guncelle('icerikler', $veri, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'İçerik güncellendi.']);
    }
    $veri['olusturan_id'] = $u['id']; $veri['created'] = $now;
    insert('icerikler', $veri);
    json_out(['ok' => true, 'mesaj' => 'İçerik planlandı.']);

case 'icerik_durum':
    require_login();
    $id = (int)$g('id');
    $icerik = row("SELECT * FROM icerikler WHERE id=?", [$id]);
    $icerikDosya = $icerik ? (int)($icerik['dosya_id'] ?: val("SELECT dosya_id FROM projeler WHERE id=?", [$icerik['proje_id']])) : 0;
    if (!$icerik || !dosya_erisim($icerikDosya)) json_out(['ok' => false, 'hata' => 'Yetkisiz.']);
    guncelle('icerikler', ['durum' => $g('durum')], 'id=?', [$id]);
    // Çift yönlü senkron: yayınlandı → bağlı görevler tamamlanır
    if ($g('durum') === 'yayinlandi') {
        foreach (rows("SELECT id FROM gorevler WHERE icerik_id=? AND durum!='tamamlandi'", [$id]) as $bg2) {
            guncelle('gorevler', ['durum' => 'tamamlandi', 'tamamlanma' => $now], 'id=?', [$bg2['id']]);
        }
    }
    json_out(['ok' => true, 'mesaj' => 'Durum güncellendi.']);

/* ==================== SOSYAL MEDYA TAKİBİ ==================== */
case 'sosyal_hesap_ekle':
    require_yetki('icerik_yonet');
    $dosyaId = (int)$g('dosya_id');
    $kadi = trim($g('kullanici_adi'));
    if (!$dosyaId || $kadi === '') json_out(['ok' => false, 'hata' => 'Dosya ve kullanıcı adı gerekli.']);
    $url = trim($g('url'));
    if ($url && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    insert('sosyal_hesaplar', [
        'dosya_id' => $dosyaId,
        'platform' => isset(PLATFORMLAR[$g('platform')]) ? $g('platform') : 'instagram',
        'kullanici_adi' => mb_substr($kadi, 0, 100), 'url' => $url ?: null, 'created' => $now,
    ]);
    json_out(['ok' => true, 'mesaj' => 'Sosyal medya hesabı eklendi.']);

case 'sosyal_hesap_sil':
    require_yetki('icerik_yonet');
    q("DELETE FROM sosyal_metrikler WHERE hesap_id=?", [(int)$g('id')]);
    q("DELETE FROM sosyal_hesaplar WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Hesap ve metrik geçmişi silindi.']);

case 'sosyal_metrik_ekle':
    require_staff();
    $hesap = row("SELECT * FROM sosyal_hesaplar WHERE id=?", [(int)$g('hesap_id')]);
    if (!$hesap) json_out(['ok' => false, 'hata' => 'Hesap bulunamadı.']);
    $takipci = (int)str_replace(['.', ' '], '', $g('takipci'));
    if ($takipci < 0) json_out(['ok' => false, 'hata' => 'Takipçi sayısı geçersiz.']);
    $tarih = $g('tarih') ?: date('Y-m-d');
    q("INSERT INTO sosyal_metrikler (hesap_id, tarih, takipci, gonderi, etkilesim, girilen_id, created) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE takipci=VALUES(takipci), gonderi=VALUES(gonderi), etkilesim=VALUES(etkilesim)",
        [$hesap['id'], $tarih, $takipci,
         $g('gonderi') !== '' ? (int)$g('gonderi') : null,
         $g('etkilesim') !== '' ? (int)str_replace(['.', ' '], '', $g('etkilesim')) : null,
         $u['id'], $now]);
    json_out(['ok' => true, 'mesaj' => 'Metrik kaydedildi.']);

case 'sosyal_metrik_sil':
    require_yetki('icerik_yonet');
    q("DELETE FROM sosyal_metrikler WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Kayıt silindi.']);

case 'icerik_sil':
    require_yetki('icerik_yonet');
    q("DELETE FROM icerikler WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'İçerik silindi.']);

/* ==================== ETKİNLİK / TAKVİM ==================== */
case 'etkinlik_kaydet':
    require_yetki('takvim_yonet');
    $veri = [
        'proje_id' => $g('proje_id') ? (int)$g('proje_id') : null,
        'dosya_id' => $g('dosya_id') ? (int)$g('dosya_id') : null,
        'baslik' => trim($g('baslik')), 'tur' => $g('tur', 'cekim'),
        'baslangic' => $g('baslangic'), 'bitis' => $g('bitis') ?: null,
        'yer' => $g('yer'), 'aciklama' => $g('aciklama'), 'katilimcilar' => $g('katilimcilar'),
        'online_link' => trim($g('online_link')) ?: null,
        'alinacaklar' => trim($g('alinacaklar')) ?: null,
        'ihtiyac_listesi' => trim($g('ihtiyac_listesi')) ?: null,
    ];
    if ($veri['baslik'] === '' || !$veri['baslangic']) json_out(['ok' => false, 'hata' => 'Başlık ve tarih gerekli.']);
    // Sistem içi katılımcılar (toplantılar için)
    $katilimciIdler = json_decode($g('katilimci_idler', ''), true);
    if ($g('id')) {
        $etkinlikId = (int)$g('id');
        guncelle('etkinlikler', $veri, 'id=?', [$etkinlikId]);
        if (is_array($katilimciIdler)) {
            $eskiler = array_column(rows("SELECT user_id FROM etkinlik_katilimcilari WHERE etkinlik_id=?", [$etkinlikId]), 'user_id');
            q("DELETE FROM etkinlik_katilimcilari WHERE etkinlik_id=?", [$etkinlikId]);
            foreach (array_unique(array_map('intval', $katilimciIdler)) as $kid) {
                if (!$kid) continue;
                q("INSERT IGNORE INTO etkinlik_katilimcilari (etkinlik_id, user_id) VALUES (?,?)", [$etkinlikId, $kid]);
                if (!in_array($kid, $eskiler)) bildir($kid, '📅 Toplantıya davet edildiniz', $veri['baslik'] . ' — ' . tarih($veri['baslangic'], true), 'toplantilar.php', 'gorev');
            }
        }
        json_out(['ok' => true, 'mesaj' => 'Etkinlik güncellendi.']);
    }
    $veri['olusturan_id'] = $u['id']; $veri['created'] = $now;
    $etkinlikId = insert('etkinlikler', $veri);
    if (is_array($katilimciIdler)) {
        foreach (array_unique(array_map('intval', $katilimciIdler)) as $kid) {
            if (!$kid) continue;
            q("INSERT IGNORE INTO etkinlik_katilimcilari (etkinlik_id, user_id) VALUES (?,?)", [$etkinlikId, $kid]);
            bildir($kid, '📅 Toplantıya davet edildiniz', $veri['baslik'] . ' — ' . tarih($veri['baslangic'], true), 'toplantilar.php', 'gorev');
        }
    }
    // Seçilen ekipmanları çekime çıkar
    $ekipmanIdler = json_decode($g('ekipmanlar', '[]'), true) ?: [];
    $atlanabilen = [];
    foreach (array_unique(array_map('intval', $ekipmanIdler)) as $eid) {
        $ek = row("SELECT * FROM ekipmanlar WHERE id=?", [$eid]);
        if (!$ek) continue;
        if ($ek['durum'] !== 'studyoda') { $atlanabilen[] = $ek['ad']; continue; }
        q("INSERT IGNORE INTO etkinlik_ekipmanlari (etkinlik_id, ekipman_id) VALUES (?,?)", [$etkinlikId, $eid]);
        guncelle('ekipmanlar', ['durum' => 'cekimde', 'zimmet_etkinlik_id' => $etkinlikId, 'zimmet_user_id' => $u['id']], 'id=?', [$eid]);
        ekipman_logla($eid, 'cekime_cikti', $veri['baslik'], (int)$u['id'], $etkinlikId);
    }
    $mesajEk = $atlanabilen ? ' (müsait olmayanlar atlandı: ' . implode(', ', $atlanabilen) . ')' : '';
    json_out(['ok' => true, 'mesaj' => 'Etkinlik eklendi.' . $mesajEk]);

case 'etkinlik_sil':
    require_staff();
    q("DELETE FROM etkinlikler WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Etkinlik silindi.']);

/* ==================== MESAJLAŞMA ==================== */
case 'mesaj_gonder':
    require_login();
    $kanalId = (int)$g('kanal_id');
    $mesaj = trim($g('mesaj'));
    if ($mesaj === '') json_out(['ok' => false, 'hata' => 'Mesaj boş.']);
    // Üyelik kontrolü
    if (!val("SELECT COUNT(*) FROM kanal_uyeleri WHERE kanal_id=? AND user_id=?", [$kanalId, $u['id']]))
        json_out(['ok' => false, 'hata' => 'Bu kanala erişiminiz yok.']);
    $id = insert('mesajlar', ['kanal_id' => $kanalId, 'user_id' => $u['id'], 'mesaj' => $mesaj, 'created' => $now]);
    guncelle('kanal_uyeleri', ['son_okuma' => $now], 'kanal_id=? AND user_id=?', [$kanalId, $u['id']]);
    // Diğer üyelere bildir
    foreach (rows("SELECT user_id FROM kanal_uyeleri WHERE kanal_id=? AND user_id!=?", [$kanalId, $u['id']]) as $uye) {
        bildir($uye['user_id'], $u['ad'] . ' mesaj gönderdi', mb_substr($mesaj, 0, 80), 'mesajlar.php?kanal=' . $kanalId, 'mesaj', false);
    }
    // Etiketlenenlere ayrıca bildir (e-posta dahil)
    mentionlari_bildir($g('mention_idler', ''), $u['ad'] . ' sizi bir sohbette etiketledi', mb_substr($mesaj, 0, 90), 'mesajlar.php?kanal=' . $kanalId);
    json_out(['ok' => true, 'id' => $id, 'created' => tarih($now, true)]);

case 'mesaj_getir':
    require_login();
    $kanalId = (int)$g('kanal_id');
    $sonId = (int)$g('son_id');
    $yeni = rows("SELECT m.*, u.ad, u.renk FROM mesajlar m JOIN users u ON u.id=m.user_id WHERE m.kanal_id=? AND m.id>? ORDER BY m.id", [$kanalId, $sonId]);
    guncelle('kanal_uyeleri', ['son_okuma' => $now], 'kanal_id=? AND user_id=?', [$kanalId, $u['id']]);
    foreach ($yeni as &$m) { $m['benim'] = ($m['user_id'] == $u['id']); $m['zaman'] = date('H:i', strtotime($m['created'])); $m['bas'] = bas_harf($m['ad']); }
    json_out(['ok' => true, 'mesajlar' => $yeni]);

case 'kanal_olustur':
    require_yetki('kanal_kur');
    $ad = trim($g('ad'));
    if ($ad === '') json_out(['ok' => false, 'hata' => 'Kanal adı gerekli.']);
    $kanalId = insert('kanallar', ['ad' => $ad, 'tur' => 'genel', 'created' => $now]);
    $uyeler = json_decode($g('uyeler', '[]'), true) ?: [];
    $uyeler[] = $u['id'];
    foreach (array_unique($uyeler) as $uid)
        q("INSERT IGNORE INTO kanal_uyeleri (kanal_id, user_id) VALUES (?,?)", [$kanalId, (int)$uid]);
    json_out(['ok' => true, 'mesaj' => 'Kanal oluşturuldu.', 'yonlendir' => 'mesajlar.php?kanal=' . $kanalId]);

case 'kanal_uye_ekle':
    require_staff();
    $kanalId = (int)$g('kanal_id');
    $hedefId = (int)$g('user_id');
    $kanal = row("SELECT * FROM kanallar WHERE id=?", [$kanalId]);
    if (!$kanal || $kanal['tur'] === 'ozel') json_out(['ok' => false, 'hata' => 'Bu kanala üye eklenemez.']);
    if (!val("SELECT COUNT(*) FROM kanal_uyeleri WHERE kanal_id=? AND user_id=?", [$kanalId, $u['id']]))
        json_out(['ok' => false, 'hata' => 'Üyesi olmadığınız kanalı yönetemezsiniz.']);
    q("INSERT IGNORE INTO kanal_uyeleri (kanal_id, user_id) VALUES (?,?)", [$kanalId, $hedefId]);
    $hedef = row("SELECT ad FROM users WHERE id=?", [$hedefId]);
    bildir($hedefId, 'Bir sohbete eklendiniz', $kanal['ad'], 'mesajlar.php?kanal=' . $kanalId, 'mesaj');
    log_aktivite('"' . $kanal['ad'] . '" kanalına ' . ($hedef['ad'] ?? '') . ' kişisini ekledi');
    json_out(['ok' => true, 'mesaj' => 'Üye eklendi.']);

case 'kanal_uye_cikar':
    require_staff();
    $kanalId = (int)$g('kanal_id');
    $hedefId = (int)$g('user_id');
    $kanal = row("SELECT * FROM kanallar WHERE id=?", [$kanalId]);
    if (!$kanal || $kanal['tur'] === 'ozel') json_out(['ok' => false, 'hata' => 'Bu kanaldan üye çıkarılamaz.']);
    if (!is_pm() && $hedefId !== (int)$u['id'])
        json_out(['ok' => false, 'hata' => 'Başkasını çıkarmak için PM/yönetici olmalısınız.']);
    q("DELETE FROM kanal_uyeleri WHERE kanal_id=? AND user_id=?", [$kanalId, $hedefId]);
    json_out(['ok' => true, 'mesaj' => 'Üye çıkarıldı.']);

case 'kanal_ad':
    require_login();
    $kanalId = (int)$g('kanal_id');
    $kanal = row("SELECT * FROM kanallar WHERE id=?", [$kanalId]);
    if (!$kanal || $kanal['tur'] === 'ozel') json_out(['ok' => false, 'hata' => 'Bu sohbetin adı değiştirilemez.']);
    if (!val("SELECT COUNT(*) FROM kanal_uyeleri WHERE kanal_id=? AND user_id=?", [$kanalId, $u['id']]))
        json_out(['ok' => false, 'hata' => 'Bu kanalın üyesi değilsiniz.']);
    $ad = mb_substr(trim($g('ad')), 0, 120);
    if ($ad === '') json_out(['ok' => false, 'hata' => 'Kanal adı boş olamaz.']);
    guncelle('kanallar', ['ad' => $ad], 'id=?', [$kanalId]);
    log_aktivite('"' . $kanal['ad'] . '" kanalının adını "' . $ad . '" yaptı');
    json_out(['ok' => true, 'mesaj' => 'Sohbet adı güncellendi.']);

case 'kanal_simge':
    require_login();
    $kanalId = (int)$g('kanal_id');
    if (!val("SELECT COUNT(*) FROM kanal_uyeleri WHERE kanal_id=? AND user_id=?", [$kanalId, $u['id']]))
        json_out(['ok' => false, 'hata' => 'Bu kanalın üyesi değilsiniz.']);
    guncelle('kanallar', ['simge' => mb_substr(trim($g('simge')), 0, 8) ?: null], 'id=?', [$kanalId]);
    json_out(['ok' => true, 'mesaj' => 'Kanal simgesi güncellendi.']);

case 'kanal_arsiv_toggle':
    require_login();
    $kanalId = (int)$g('kanal_id');
    $uyelik = row("SELECT * FROM kanal_uyeleri WHERE kanal_id=? AND user_id=?", [$kanalId, $u['id']]);
    if (!$uyelik) json_out(['ok' => false, 'hata' => 'Bu kanalın üyesi değilsiniz.']);
    $yeni = $uyelik['arsiv'] ? 0 : 1;
    guncelle('kanal_uyeleri', ['arsiv' => $yeni], 'kanal_id=? AND user_id=?', [$kanalId, $u['id']]);
    json_out(['ok' => true, 'mesaj' => $yeni ? 'Sohbet arşivlendi.' : 'Sohbet arşivden çıkarıldı.', 'yonlendir' => 'mesajlar.php']);

case 'kanal_sil':
    require_login();
    $kanalId = (int)$g('kanal_id');
    $kanal = row("SELECT * FROM kanallar WHERE id=?", [$kanalId]);
    if (!$kanal) json_out(['ok' => false, 'hata' => 'Kanal bulunamadı.']);
    $uyeMi = val("SELECT COUNT(*) FROM kanal_uyeleri WHERE kanal_id=? AND user_id=?", [$kanalId, $u['id']]);
    // Özel (DM) sohbeti katılımcısı silebilir; diğer kanalları PM/yönetici silebilir
    if ($kanal['tur'] === 'ozel' ? !$uyeMi : !is_pm())
        json_out(['ok' => false, 'hata' => 'Bu sohbeti silme yetkiniz yok.']);
    q("DELETE FROM mesajlar WHERE kanal_id=?", [$kanalId]);
    q("DELETE FROM kanal_uyeleri WHERE kanal_id=?", [$kanalId]);
    q("DELETE FROM kanallar WHERE id=?", [$kanalId]);
    log_aktivite('"' . $kanal['ad'] . '" sohbetini sildi');
    json_out(['ok' => true, 'mesaj' => 'Sohbet silindi.', 'yonlendir' => 'mesajlar.php']);

case 'dm_ac':
    require_login();
    $hedefId = (int)$g('user_id');
    if ($hedefId === (int)$u['id']) json_out(['ok' => false, 'hata' => 'Kendinizle sohbet açamazsınız.']);
    $hedef = row("SELECT * FROM users WHERE id=? AND aktif=1", [$hedefId]);
    if (!$hedef) json_out(['ok' => false, 'hata' => 'Kullanıcı bulunamadı.']);
    // Müşteriler yalnızca ekiple DM açabilir
    if (is_musteri() && $hedef['rol'] === 'musteri') json_out(['ok' => false, 'hata' => 'Bu kişiyle sohbet açılamaz.']);
    // İki kişi arasında mevcut özel kanal var mı?
    $mevcut = row("SELECT k.id FROM kanallar k
        JOIN kanal_uyeleri a ON a.kanal_id=k.id AND a.user_id=?
        JOIN kanal_uyeleri b ON b.kanal_id=k.id AND b.user_id=?
        WHERE k.tur='ozel' AND (SELECT COUNT(*) FROM kanal_uyeleri x WHERE x.kanal_id=k.id)=2", [$u['id'], $hedefId]);
    if ($mevcut) json_out(['ok' => true, 'yonlendir' => 'mesajlar.php?kanal=' . $mevcut['id']]);
    $kanalId = insert('kanallar', ['ad' => 'DM', 'tur' => 'ozel', 'created' => $now]);
    q("INSERT IGNORE INTO kanal_uyeleri (kanal_id, user_id) VALUES (?,?),(?,?)", [$kanalId, $u['id'], $kanalId, $hedefId]);
    json_out(['ok' => true, 'yonlendir' => 'mesajlar.php?kanal=' . $kanalId]);

/* ==================== GLOBAL ARAMA ==================== */
case 'arama':
    require_login();
    $q = trim($g('q'));
    if (mb_strlen($q) < 2) json_out(['ok' => true, 'sonuclar' => []]);
    $ara = '%' . $q . '%';
    $sonuclar = [];
    if (is_staff()) {
        $sonuclar['Dosyalar'] = array_map(fn($r) => ['ad' => $r['ad'], 'alt' => DOSYA_TURLERI[$r['tur']], 'link' => 'dosya.php?id=' . $r['id']],
            rows("SELECT id, ad, tur FROM dosyalar WHERE ad LIKE ? LIMIT 5", [$ara]));
        $sonuclar['Projeler'] = array_map(fn($r) => ['ad' => $r['ad'], 'alt' => PROJE_TURLERI[$r['tur']], 'link' => 'proje.php?id=' . $r['id']],
            rows("SELECT id, ad, tur FROM projeler WHERE ad LIKE ? LIMIT 5", [$ara]));
        $sonuclar['Görevler'] = array_map(fn($r) => ['ad' => $r['baslik'], 'alt' => GOREV_DURUMLARI[$r['durum']], 'link' => 'gorev.php?id=' . $r['id']],
            rows("SELECT id, baslik, durum FROM gorevler WHERE baslik LIKE ? ORDER BY durum!='tamamlandi' DESC LIMIT 6", [$ara]));
        $sonuclar['İçerikler'] = array_map(fn($r) => ['ad' => $r['baslik'], 'alt' => tarih($r['tarih']), 'link' => 'icerik-takvimi.php?ay=' . date('n', strtotime($r['tarih'])) . '&yil=' . date('Y', strtotime($r['tarih']))],
            rows("SELECT id, baslik, tarih FROM icerikler WHERE baslik LIKE ? LIMIT 4", [$ara]));
        $sonuclar['Talepler'] = array_map(fn($r) => ['ad' => $r['baslik'], 'alt' => TALEP_DURUMLARI[$r['durum']], 'link' => 'talep.php?id=' . $r['id']],
            rows("SELECT id, baslik, durum FROM talepler WHERE baslik LIKE ? LIMIT 4", [$ara]));
    } else {
        [$in, $p] = in_sorgu(musteri_dosya_idler());
        $sonuclar['Projeler'] = array_map(fn($r) => ['ad' => $r['ad'], 'alt' => PROJE_TURLERI[$r['tur']], 'link' => 'proje.php?id=' . $r['id']],
            rows("SELECT id, ad, tur FROM projeler WHERE dosya_id IN $in AND ad LIKE ? LIMIT 6", array_merge($p, [$ara])));
        $sonuclar['Talepler'] = array_map(fn($r) => ['ad' => $r['baslik'], 'alt' => TALEP_DURUMLARI[$r['durum']], 'link' => 'talep.php?id=' . $r['id']],
            rows("SELECT id, baslik, durum FROM talepler WHERE gonderen_id=? AND baslik LIKE ? LIMIT 5", [$u['id'], $ara]));
    }
    json_out(['ok' => true, 'sonuclar' => $sonuclar]);

/* ==================== EKİPMAN / DEMİRBAŞ ==================== */
case 'ekipman_kaydet':
    require_yetki('ekipman_yonet');
    $veri = [
        'kod' => mb_substr(trim($g('kod')), 0, 20) ?: null,
        'ad' => trim($g('ad')),
        'kategori' => isset(EKIPMAN_KATEGORILERI[$g('kategori')]) ? $g('kategori') : 'diger',
        'satin_alma' => $g('satin_alma') ?: null,
        'fiyat' => (float)str_replace(',', '.', $g('fiyat', '0')),
        'aciklama' => $g('aciklama'),
    ];
    if ($veri['ad'] === '') json_out(['ok' => false, 'hata' => 'Ekipman adı gerekli.']);
    $foto = dosya_yukle('foto');
    if ($foto) {
        if (!in_array($foto['uzanti'], ['jpg', 'jpeg', 'png', 'gif', 'webp'])) json_out(['ok' => false, 'hata' => 'Fotoğraf için görsel dosyası seçin.']);
        $veri['foto'] = $foto['yol'];
    }
    if ($g('id')) {
        guncelle('ekipmanlar', $veri, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Ekipman güncellendi.']);
    }
    $veri['created'] = $now;
    if ($veri['kategori'] === 'sd_kart') $veri['sd_durum'] = 'bos';
    $eid = insert('ekipmanlar', $veri);
    ekipman_logla($eid, 'eklendi', $veri['ad']);
    json_out(['ok' => true, 'mesaj' => 'Ekipman envantere eklendi.']);

case 'ekipman_sil':
    require_yetki('ekipman_yonet');
    $ek = row("SELECT * FROM ekipmanlar WHERE id=?", [(int)$g('id')]);
    if ($ek && $ek['durum'] !== 'studyoda' && $ek['durum'] !== 'arizali') json_out(['ok' => false, 'hata' => 'Zimmette/çekimde olan ekipman silinemez. Önce iade alın.']);
    q("DELETE FROM ekipman_hareketleri WHERE ekipman_id=?", [(int)$g('id')]);
    q("DELETE FROM etkinlik_ekipmanlari WHERE ekipman_id=?", [(int)$g('id')]);
    q("DELETE FROM ekipmanlar WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Ekipman silindi.']);

case 'ekipman_zimmet':
    require_staff();
    $ek = row("SELECT * FROM ekipmanlar WHERE id=?", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'hata' => 'Ekipman bulunamadı.']);
    if (!in_array($ek['durum'], ['studyoda'])) json_out(['ok' => false, 'hata' => 'Bu ekipman şu an ' . mb_strtolower(EKIPMAN_DURUMLARI[$ek['durum']]) . ' — zimmet verilemez.']);
    $hedef = (int)($g('user_id') ?: $u['id']);
    if ($hedef !== (int)$u['id'] && !yetki('ekipman_yonet')) json_out(['ok' => false, 'hata' => 'Başkası adına zimmet için ekipman yönetim yetkisi gerekir.']);
    $hedefAd = val("SELECT ad FROM users WHERE id=?", [$hedef]);
    guncelle('ekipmanlar', ['durum' => 'zimmette', 'zimmet_user_id' => $hedef, 'zimmet_etkinlik_id' => null], 'id=?', [(int)$g('id')]);
    ekipman_logla((int)$g('id'), 'zimmet', trim($g('aciklama')), $hedef);
    if ($hedef !== (int)$u['id']) bildir($hedef, 'Ekipman zimmetlendi', ($ek['kod'] ? $ek['kod'] . ' — ' : '') . $ek['ad'], 'ekipman.php', 'gorev');
    json_out(['ok' => true, 'mesaj' => $ek['ad'] . ' → ' . $hedefAd . ' zimmetine verildi.']);

case 'ekipman_iade':
    require_staff();
    $ek = row("SELECT * FROM ekipmanlar WHERE id=?", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'hata' => 'Ekipman bulunamadı.']);
    if (!in_array($ek['durum'], ['zimmette', 'cekimde'])) json_out(['ok' => false, 'hata' => 'Bu ekipman zaten stüdyoda.']);
    if ($ek['zimmet_user_id'] != $u['id'] && !yetki('ekipman_yonet')) json_out(['ok' => false, 'hata' => 'Yalnızca kendi zimmetinizi iade edebilirsiniz.']);
    guncelle('ekipmanlar', ['durum' => 'studyoda', 'zimmet_user_id' => null, 'zimmet_etkinlik_id' => null], 'id=?', [(int)$g('id')]);
    ekipman_logla((int)$g('id'), $ek['durum'] === 'cekimde' ? 'cekimden_dondu' : 'iade', trim($g('aciklama')), $ek['zimmet_user_id'] ? (int)$ek['zimmet_user_id'] : null, $ek['zimmet_etkinlik_id'] ? (int)$ek['zimmet_etkinlik_id'] : null);
    json_out(['ok' => true, 'mesaj' => $ek['ad'] . ' stüdyoya iade alındı.']);

case 'ekipman_ariza':
    require_staff();
    $ek = row("SELECT * FROM ekipmanlar WHERE id=?", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'hata' => 'Ekipman bulunamadı.']);
    $yeniDurum = in_array($g('durum'), ['arizali', 'bakimda', 'studyoda']) ? $g('durum') : 'arizali';
    guncelle('ekipmanlar', [
        'durum' => $yeniDurum,
        'ariza_notu' => $yeniDurum === 'studyoda' ? null : trim($g('not')),
        'zimmet_user_id' => null, 'zimmet_etkinlik_id' => null,
    ], 'id=?', [(int)$g('id')]);
    ekipman_logla((int)$g('id'), $yeniDurum === 'studyoda' ? 'duzeltildi' : ($yeniDurum === 'bakimda' ? 'bakim' : 'ariza'), trim($g('not')));
    json_out(['ok' => true, 'mesaj' => 'Ekipman durumu güncellendi.']);

case 'sd_guncelle':
    require_staff();
    $ek = row("SELECT * FROM ekipmanlar WHERE id=? AND kategori='sd_kart'", [(int)$g('id')]);
    if (!$ek) json_out(['ok' => false, 'hata' => 'SD kart bulunamadı.']);
    $islem = $g('islem'); // dolu | aktarildi | bosalt
    if ($islem === 'dolu') {
        $icerik = trim($g('icerik'));
        if ($icerik === '') json_out(['ok' => false, 'hata' => 'Hangi çekim/içerik olduğunu yazın.']);
        guncelle('ekipmanlar', ['sd_durum' => 'dolu', 'sd_icerik' => $icerik, 'sd_drive_link' => null], 'id=?', [$ek['id']]);
        ekipman_logla($ek['id'], 'sd_dolu', $icerik);
        json_out(['ok' => true, 'mesaj' => 'Kart dolu olarak işaretlendi.']);
    }
    if ($islem === 'aktarildi') {
        if ($ek['sd_durum'] !== 'dolu') json_out(['ok' => false, 'hata' => 'Önce kartı "dolu" olarak işaretleyin.']);
        $link = trim($g('drive_link'));
        guncelle('ekipmanlar', ['sd_durum' => 'aktarildi', 'sd_drive_link' => $link ?: null], 'id=?', [$ek['id']]);
        ekipman_logla($ek['id'], 'sd_aktarildi', trim(($ek['sd_icerik'] ?: '') . ($link ? ' → ' . $link : '')));
        json_out(['ok' => true, 'mesaj' => "Drive'a aktarıldı olarak işaretlendi."]);
    }
    if ($islem === 'bosalt') {
        if ($ek['sd_durum'] === 'dolu') json_out(['ok' => false, 'hata' => "Dikkat: içerik henüz Drive'a aktarılmadı! Önce aktarımı işaretleyin."]);
        // Geçmiş hareket kaydında içerik + link saklanır, kart sıfırlanır
        ekipman_logla($ek['id'], 'sd_bosaltildi', trim(($ek['sd_icerik'] ?: '') . ($ek['sd_drive_link'] ? ' (arşiv: ' . $ek['sd_drive_link'] . ')' : '')));
        guncelle('ekipmanlar', ['sd_durum' => 'bos', 'sd_icerik' => null, 'sd_drive_link' => null], 'id=?', [$ek['id']]);
        json_out(['ok' => true, 'mesaj' => 'Kart boşaltıldı — tekrar kullanıma hazır.']);
    }
    json_out(['ok' => false, 'hata' => 'Geçersiz işlem.']);

case 'etkinlik_ekipman_iade':
    require_staff();
    $etkinlikId = (int)$g('etkinlik_id');
    $adet = 0;
    foreach (rows("SELECT e.* FROM ekipmanlar e JOIN etkinlik_ekipmanlari ee ON ee.ekipman_id=e.id WHERE ee.etkinlik_id=? AND e.durum='cekimde'", [$etkinlikId]) as $ek) {
        guncelle('ekipmanlar', ['durum' => 'studyoda', 'zimmet_user_id' => null, 'zimmet_etkinlik_id' => null], 'id=?', [$ek['id']]);
        ekipman_logla((int)$ek['id'], 'cekimden_dondu', '', null, $etkinlikId);
        $adet++;
    }
    json_out(['ok' => true, 'mesaj' => $adet . ' ekipman stüdyoya iade alındı.']);

/* ==================== MÜŞTERİ PUANLAMASI ==================== */
case 'puan_ver':
    require_login();
    if (!is_musteri()) json_out(['ok' => false, 'hata' => 'Puanlamayı yalnızca müşteriler yapabilir.']);
    $refTur = $g('ref_tur') === 'onay' ? 'onay' : 'gorev';
    $refId = (int)$g('ref_id');
    $puan = max(1, min(5, (int)$g('puan')));
    // Erişim + durum kontrolü
    if ($refTur === 'gorev') {
        $hedef = row("SELECT id, baslik, proje_id, durum FROM gorevler WHERE id=?", [$refId]);
        if (!$hedef || $hedef['durum'] !== 'tamamlandi') json_out(['ok' => false, 'hata' => 'Yalnızca tamamlanan işler puanlanabilir.']);
    } else {
        $hedef = row("SELECT id, baslik, proje_id, durum FROM onaylar WHERE id=?", [$refId]);
        if (!$hedef || $hedef['durum'] !== 'onaylandi') json_out(['ok' => false, 'hata' => 'Yalnızca onaylanan işler puanlanabilir.']);
    }
    if (!proje_erisim((int)$hedef['proje_id'])) json_out(['ok' => false, 'hata' => 'Bu işe erişiminiz yok.']);
    $yorum = mb_substr(trim($g('yorum')), 0, 500) ?: null;
    q("INSERT INTO puanlar (ref_tur, ref_id, proje_id, user_id, puan, yorum, created) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE puan=VALUES(puan), yorum=VALUES(yorum)", [$refTur, $refId, $hedef['proje_id'], $u['id'], $puan, $yorum, $now]);
    // Düşük puanda PM'e haber ver
    if ($puan <= 2) {
        $pmId = val("SELECT pm_id FROM projeler WHERE id=?", [$hedef['proje_id']]);
        $alicilar = $pmId ? [(int)$pmId] : array_column(rows("SELECT id FROM users WHERE rol IN ('yonetici','pm') AND aktif=1"), 'id');
        foreach ($alicilar as $aid)
            bildir((int)$aid, '⚠️ Düşük müşteri puanı: ' . $puan . '★', $hedef['baslik'] . ($yorum ? ' — "' . $yorum . '"' : ''), 'proje.php?id=' . $hedef['proje_id'], 'onay');
    }
    json_out(['ok' => true, 'mesaj' => 'Değerlendirmeniz kaydedildi, teşekkürler! ' . str_repeat('★', $puan)]);

/* ==================== RANDEVULAR ==================== */
case 'randevu_olustur':
    require_login();
    if (!is_musteri()) json_out(['ok' => false, 'hata' => 'Randevu talebini müşteriler oluşturur.']);
    $konu = trim($g('konu'));
    $tarih = $g('tarih');
    if ($konu === '' || !$tarih) json_out(['ok' => false, 'hata' => 'Konu ve tarih gerekli.']);
    if (strtotime($tarih) < time()) json_out(['ok' => false, 'hata' => 'Geçmiş bir tarih seçilemez.']);
    $dosyaId = (int)$g('dosya_id');
    if ($dosyaId && !dosya_erisim($dosyaId)) json_out(['ok' => false, 'hata' => 'Bu dosyaya erişiminiz yok.']);
    $id = insert('randevular', [
        'musteri_id' => $u['id'], 'dosya_id' => $dosyaId ?: null, 'konu' => $konu,
        'tarih' => $tarih, 'online_istek' => (int)(bool)$g('online_istek'),
        'notlar' => mb_substr(trim($g('notlar')), 0, 500) ?: null, 'durum' => 'bekliyor', 'created' => $now,
    ]);
    foreach (rows("SELECT id FROM users WHERE rol IN ('yonetici','pm') AND aktif=1") as $pm)
        bildir((int)$pm['id'], '📆 Yeni randevu talebi', $u['ad'] . ': ' . $konu . ' — ' . tarih($tarih, true), 'randevular.php', 'talep');
    json_out(['ok' => true, 'mesaj' => 'Randevu talebiniz iletildi. Onaylanınca haber verilecek.']);

case 'randevu_cevapla':
    require_pm();
    $r = row("SELECT * FROM randevular WHERE id=?", [(int)$g('id')]);
    if (!$r) json_out(['ok' => false, 'hata' => 'Randevu bulunamadı.']);
    $islem = $g('islem'); // onayla | alternatif | reddet
    if ($islem === 'onayla') {
        $link = trim($g('online_link'));
        // Toplantı oluştur: müşteri + cevaplayan PM katılımcı
        $etkinlikId = insert('etkinlikler', [
            'dosya_id' => $r['dosya_id'], 'baslik' => 'Randevu: ' . $r['konu'], 'tur' => 'toplanti',
            'baslangic' => $r['tarih'], 'online_link' => $link ?: null,
            'aciklama' => $r['notlar'], 'olusturan_id' => $u['id'], 'created' => $now,
        ]);
        q("INSERT IGNORE INTO etkinlik_katilimcilari (etkinlik_id, user_id) VALUES (?,?),(?,?)", [$etkinlikId, $r['musteri_id'], $etkinlikId, $u['id']]);
        guncelle('randevular', ['durum' => 'onaylandi', 'online_link' => $link ?: null, 'etkinlik_id' => $etkinlikId, 'cevap_notu' => trim($g('not')) ?: null], 'id=?', [$r['id']]);
        bildir((int)$r['musteri_id'], '✅ Randevunuz onaylandı', $r['konu'] . ' — ' . tarih($r['tarih'], true) . ($link ? ' (online)' : ''), 'randevular.php', 'talep');
        json_out(['ok' => true, 'mesaj' => 'Randevu onaylandı ve toplantı takvimine eklendi.']);
    }
    if ($islem === 'alternatif') {
        $yeni = $g('alternatif_tarih');
        if (!$yeni) json_out(['ok' => false, 'hata' => 'Alternatif tarih seçin.']);
        guncelle('randevular', ['durum' => 'alternatif', 'alternatif_tarih' => $yeni, 'cevap_notu' => trim($g('not')) ?: null], 'id=?', [$r['id']]);
        bildir((int)$r['musteri_id'], '🔁 Randevu için farklı saat önerildi', $r['konu'] . ' → ' . tarih($yeni, true), 'randevular.php', 'talep');
        json_out(['ok' => true, 'mesaj' => 'Alternatif saat önerildi.']);
    }
    if ($islem === 'reddet') {
        guncelle('randevular', ['durum' => 'reddedildi', 'cevap_notu' => trim($g('not')) ?: null], 'id=?', [$r['id']]);
        bildir((int)$r['musteri_id'], 'Randevu talebiniz yanıtlandı', $r['konu'] . ' — uygun değil' . ($g('not') ? ': ' . $g('not') : ''), 'randevular.php', 'talep');
        json_out(['ok' => true, 'mesaj' => 'Talep yanıtlandı.']);
    }
    json_out(['ok' => false, 'hata' => 'Geçersiz işlem.']);

case 'randevu_kabul':
    // Müşteri, önerilen alternatif saati kabul eder
    require_login();
    $r = row("SELECT * FROM randevular WHERE id=? AND musteri_id=? AND durum='alternatif'", [(int)$g('id'), $u['id']]);
    if (!$r || !$r['alternatif_tarih']) json_out(['ok' => false, 'hata' => 'Bekleyen öneri bulunamadı.']);
    guncelle('randevular', ['tarih' => $r['alternatif_tarih'], 'alternatif_tarih' => null, 'durum' => 'bekliyor'], 'id=?', [$r['id']]);
    foreach (rows("SELECT id FROM users WHERE rol IN ('yonetici','pm') AND aktif=1") as $pm)
        bildir((int)$pm['id'], '📆 Müşteri önerilen saati kabul etti', $r['konu'] . ' — ' . tarih($r['alternatif_tarih'], true) . ' (onay bekliyor)', 'randevular.php', 'talep');
    json_out(['ok' => true, 'mesaj' => 'Yeni saat kabul edildi; ajans onayı bekleniyor.']);

/* ==================== SÜRÜM NOTLARI ==================== */
case 'surum_kapat':
    require_login();
    guncelle('users', ['gorulen_surum' => SURUM], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

/* ==================== DUYURULAR ==================== */
case 'duyuru_kaydet':
    require_yetki('duyuru_yayinla');
    $baslik = trim($g('baslik'));
    if ($baslik === '') json_out(['ok' => false, 'hata' => 'Duyuru başlığı gerekli.']);
    $onemli = (int)(bool)$g('onemli');
    $id = insert('duyurular', ['baslik' => $baslik, 'metin' => $g('metin'), 'onemli' => $onemli, 'olusturan_id' => $u['id'], 'created' => $now]);
    if ($onemli) {
        foreach (rows("SELECT id FROM users WHERE aktif=1 AND rol!='musteri' AND id!=?", [$u['id']]) as $al)
            bildir((int)$al['id'], '📢 Duyuru: ' . $baslik, mb_substr($g('metin'), 0, 90), 'index.php', 'gorev');
    }
    log_aktivite('"' . $baslik . '" duyurusunu yayınladı');
    json_out(['ok' => true, 'mesaj' => 'Duyuru yayınlandı.']);

case 'duyuru_oku':
    require_login();
    q("INSERT IGNORE INTO duyuru_okuyanlar (duyuru_id, user_id) VALUES (?,?)", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'duyuru_sil':
    require_pm();
    q("DELETE FROM duyurular WHERE id=?", [(int)$g('id')]);
    q("DELETE FROM duyuru_okuyanlar WHERE duyuru_id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Duyuru silindi.']);

/* ==================== SÖZLEŞMELER ==================== */
case 'sozlesme_kaydet':
    require_yetki('dosya_yonet');
    $veri = [
        'dosya_id' => (int)$g('dosya_id'), 'baslik' => trim($g('baslik')),
        'baslangic' => $g('baslangic') ?: null, 'bitis' => $g('bitis') ?: null,
        'tutar' => (float)str_replace(',', '.', $g('tutar', '0')), 'aciklama' => $g('aciklama'),
        'hatirlatildi' => 0,
    ];
    if ($veri['baslik'] === '' || !$veri['dosya_id']) json_out(['ok' => false, 'hata' => 'Sözleşme başlığı gerekli.']);
    $ek = dosya_yukle('dosya');
    if ($ek) {
        $veri['arsiv_id'] = insert('arsiv', [
            'dosya_id' => $veri['dosya_id'], 'ad' => $ek['ad'], 'dosya_yolu' => $ek['yol'],
            'boyut' => $ek['boyut'], 'uzanti' => $ek['uzanti'], 'yukleyen_id' => $u['id'], 'created' => $now,
        ]);
    }
    if ($g('id')) {
        guncelle('sozlesmeler', $veri, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Sözleşme güncellendi.']);
    }
    $veri['created'] = $now;
    insert('sozlesmeler', $veri);
    json_out(['ok' => true, 'mesaj' => 'Sözleşme kaydedildi.']);

case 'sozlesme_sil':
    require_yetki('dosya_yonet');
    q("DELETE FROM sozlesmeler WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Sözleşme silindi.']);

/* ==================== KİŞİSEL ALAN ==================== */
case 'not_kaydet':
    require_login();
    $metin = trim($g('metin'));
    $baslik = mb_substr(trim($g('baslik')), 0, 150);
    if ($metin === '' && $baslik === '') json_out(['ok' => false, 'hata' => 'Not boş olamaz.']);
    $renk = in_array($g('renk'), ['varsayilan', 'sari', 'yesil', 'mavi', 'pembe']) ? $g('renk') : 'varsayilan';
    if ($g('id')) {
        $not = row("SELECT * FROM kisisel_notlar WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
        if (!$not) json_out(['ok' => false, 'hata' => 'Not bulunamadı.']);
        guncelle('kisisel_notlar', ['baslik' => $baslik ?: null, 'metin' => $metin, 'renk' => $renk, 'guncelleme' => $now], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Not güncellendi.']);
    }
    insert('kisisel_notlar', ['user_id' => $u['id'], 'baslik' => $baslik ?: null, 'metin' => $metin, 'renk' => $renk, 'created' => $now]);
    json_out(['ok' => true, 'mesaj' => 'Not eklendi.']);

case 'not_sil':
    require_login();
    q("DELETE FROM kisisel_notlar WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Not silindi.']);

case 'kisisel_is_ekle':
    require_login();
    $ad = trim($g('ad'));
    if ($ad === '') json_out(['ok' => false, 'hata' => 'Boş madde eklenemez.']);
    $sira = (int)val("SELECT COALESCE(MAX(sira),0)+1 FROM kisisel_isler WHERE user_id=?", [$u['id']]);
    $id = insert('kisisel_isler', ['user_id' => $u['id'], 'ad' => mb_substr($ad, 0, 255), 'tamam' => 0, 'sira' => $sira]);
    json_out(['ok' => true, 'id' => $id, 'ad' => $ad]);

case 'kisisel_is_toggle':
    require_login();
    q("UPDATE kisisel_isler SET tamam=1-tamam WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'kisisel_is_sil':
    require_login();
    q("DELETE FROM kisisel_isler WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true]);

case 'link_ekle':
    require_login();
    $ad = trim($g('ad')); $url = trim($g('url'));
    if ($ad === '' || $url === '') json_out(['ok' => false, 'hata' => 'Ad ve adres gerekli.']);
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    insert('kisisel_linkler', ['user_id' => $u['id'], 'ad' => mb_substr($ad, 0, 150), 'url' => mb_substr($url, 0, 500)]);
    json_out(['ok' => true, 'mesaj' => 'Yer imi eklendi.']);

case 'link_sil':
    require_login();
    q("DELETE FROM kisisel_linkler WHERE id=? AND user_id=?", [(int)$g('id'), $u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Yer imi silindi.']);

case 'karalama_kaydet':
    require_login();
    guncelle('users', ['karalama' => mb_substr($g('metin'), 0, 100000)], 'id=?', [$u['id']]);
    json_out(['ok' => true]);

/* ==================== TERCİHLER & WIDGET ==================== */
case 'tercih_kaydet':
    require_login();
    $tercihler = [];
    foreach (array_keys(BILDIRIM_KATEGORILERI) as $k) $tercihler[$k] = (int)(bool)$g('t_' . $k);
    $tercihler['eposta'] = (int)(bool)$g('t_eposta');
    $tercihler['sadece_kendi_adimlarim'] = (int)(bool)$g('t_sadece_adim');
    guncelle('users', ['bildirim_tercihleri' => json_encode($tercihler)], 'id=?', [$u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Bildirim tercihleri kaydedildi.']);

case 'widget_kaydet':
    require_login();
    $secili = json_decode($g('widgetler', '[]'), true) ?: [];
    guncelle('users', ['widgetler' => json_encode(array_values($secili))], 'id=?', [$u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Panel görünümü kaydedildi.']);

/* ==================== TALEPLER ==================== */
case 'talep_gonder':
    require_login();
    $sablonId = (int)$g('sablon_id');
    $sablon = row("SELECT * FROM form_sablonlari WHERE id=? AND aktif=1", [$sablonId]);
    if (!$sablon) json_out(['ok' => false, 'hata' => 'Form bulunamadı.']);
    $alanlar = rows("SELECT * FROM form_alanlari WHERE sablon_id=? ORDER BY sira", [$sablonId]);
    $baslik = $sablon['ad'];
    $dosyaId = is_musteri() ? (musteri_dosya_idler()[0] ?? null) : ($g('dosya_id') ? (int)$g('dosya_id') : null);
    if (is_musteri() && $g('proje_id')) $dosyaId = (int)val("SELECT dosya_id FROM projeler WHERE id=?", [(int)$g('proje_id')]) ?: $dosyaId;
    $talepId = insert('talepler', [
        'sablon_id' => $sablonId, 'dosya_id' => $dosyaId,
        'proje_id' => $g('proje_id') ? (int)$g('proje_id') : null,
        'gonderen_id' => $u['id'], 'baslik' => $baslik, 'durum' => 'yeni', 'created' => $now,
    ]);
    foreach ($alanlar as $alan) {
        $deger = $g('alan_' . $alan['id']);
        if ($alan['tip'] === 'dosya') {
            $tYuk = dosya_yukle('alan_' . $alan['id']);
            if ($tYuk) {
                insert('arsiv', ['dosya_id' => $dosyaId ?: null, 'ad' => $tYuk['ad'], 'dosya_yolu' => $tYuk['yol'], 'boyut' => $tYuk['boyut'], 'uzanti' => $tYuk['uzanti'], 'yukleyen_id' => $u['id'], 'created' => $now]);
                $deger = $tYuk['yol'];
            }
            if ($alan['zorunlu'] && !$tYuk) { q("DELETE FROM talepler WHERE id=?", [$talepId]); json_out(['ok' => false, 'hata' => '"' . $alan['etiket'] . '" için dosya yükleyin.']); }
            insert('talep_cevaplari', ['talep_id' => $talepId, 'alan_id' => $alan['id'], 'deger' => $deger]);
            continue;
        }
        if ($alan['zorunlu'] && trim((string)$deger) === '') {
            q("DELETE FROM talepler WHERE id=?", [$talepId]);
            json_out(['ok' => false, 'hata' => '"' . $alan['etiket'] . '" alanı zorunlu.']);
        }
        insert('talep_cevaplari', ['talep_id' => $talepId, 'alan_id' => $alan['id'], 'deger' => $deger]);
    }
    // PM'lere bildir
    foreach (rows("SELECT id FROM users WHERE rol IN ('yonetici','pm') AND aktif=1") as $pm)
        bildir($pm['id'], 'Yeni talep: ' . $baslik, $u['ad'] . ' bir talep gönderdi', 'talep.php?id=' . $talepId, 'talep');
    json_out(['ok' => true, 'mesaj' => 'Talebiniz iletildi. En kısa sürede dönüş yapılacak.']);

case 'talep_durum':
    require_yetki('talep_yonet');
    $id = (int)$g('id');
    guncelle('talepler', ['durum' => $g('durum'), 'atanan_id' => $g('atanan_id') ? (int)$g('atanan_id') : null], 'id=?', [$id]);
    json_out(['ok' => true, 'mesaj' => 'Talep güncellendi.']);

case 'talep_goreve':
    require_yetki('talep_yonet');
    $id = (int)$g('id');
    $talep = row("SELECT * FROM talepler WHERE id=?", [$id]);
    if (!$talep || !$talep['proje_id']) json_out(['ok' => false, 'hata' => 'Talebe önce proje atayın.']);
    $gorevId = insert('gorevler', [
        'proje_id' => $talep['proje_id'], 'baslik' => $talep['baslik'],
        'aciklama' => 'Talep #' . $id . ' üzerinden oluşturuldu.',
        'atanan_id' => $talep['atanan_id'], 'olusturan_id' => $u['id'],
        'oncelik' => 'normal', 'durum' => 'yapilacak', 'created' => $now,
    ]);
    guncelle('talepler', ['durum' => 'gorev_olusturuldu', 'gorev_id' => $gorevId], 'id=?', [$id]);
    bildir($talep['gonderen_id'], 'Talebiniz işleme alındı', $talep['baslik'], 'talep.php?id=' . $id, 'talep');
    json_out(['ok' => true, 'mesaj' => 'Göreve dönüştürüldü.', 'yonlendir' => 'gorev.php?id=' . $gorevId]);

case 'talep_proje':
    require_pm();
    guncelle('talepler', ['proje_id' => (int)$g('proje_id')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Proje atandı.']);

/* ==================== ARŞİV ==================== */
case 'arsiv_yukle':
    require_login();
    $yuklenen = dosya_yukle('dosya');
    if (!$yuklenen) json_out(['ok' => false, 'hata' => 'Dosya yüklenemedi. Boyut (max 50MB) veya tür uygun değil.']);
    $projeId = $g('proje_id') ? (int)$g('proje_id') : null;
    if ($projeId && !proje_erisim($projeId)) json_out(['ok' => false, 'hata' => 'Yetkisiz.']);
    insert('arsiv', [
        'dosya_id' => $g('dosya_id') ? (int)$g('dosya_id') : null, 'proje_id' => $projeId,
        'gorev_id' => $g('gorev_id') ? (int)$g('gorev_id') : null,
        'ad' => $yuklenen['ad'], 'dosya_yolu' => $yuklenen['yol'], 'boyut' => $yuklenen['boyut'],
        'uzanti' => $yuklenen['uzanti'], 'yukleyen_id' => $u['id'], 'created' => $now,
    ]);
    json_out(['ok' => true, 'mesaj' => 'Dosya yüklendi.']);

case 'arsiv_link_ekle':
    require_staff();
    $lAd = mb_substr(trim($g('ad')), 0, 200) ?: 'Drive bağlantısı';
    $lUrl = trim($g('url'));
    if ($lUrl === '') json_out(['ok' => false, 'hata' => 'Link gerekli.']);
    if (!preg_match('#^https?://#i', $lUrl)) $lUrl = 'https://' . $lUrl;
    insert('arsiv', [
        'dosya_id' => $g('dosya_id') ? (int)$g('dosya_id') : null,
        'proje_id' => $g('proje_id') ? (int)$g('proje_id') : null,
        'gorev_id' => $g('gorev_id') ? (int)$g('gorev_id') : null,
        'ad' => $lAd, 'dosya_yolu' => '', 'boyut' => 0, 'uzanti' => 'link',
        'url' => mb_substr($lUrl, 0, 500), 'yukleyen_id' => $u['id'], 'created' => $now,
    ]);
    json_out(['ok' => true, 'mesaj' => 'Bağlantı eklendi.']);

case 'arsiv_sil':
    require_yetki('arsiv_sil');
    $a = row("SELECT * FROM arsiv WHERE id=?", [(int)$g('id')]);
    if ($a) {
        @unlink(ROOT . '/uploads/' . $a['dosya_yolu']);
        q("DELETE FROM arsiv WHERE id=?", [$a['id']]);
    }
    json_out(['ok' => true, 'mesaj' => 'Dosya silindi.']);

/* ==================== FİNANS ==================== */
case 'odeme_kaydet':
    require_yetki('finans');
    $veri = [
        'proje_id' => (int)$g('proje_id'), 'tur' => $g('tur', 'fatura'), 'baslik' => trim($g('baslik')),
        'tutar' => (float)str_replace(',', '.', $g('tutar', '0')), 'tarih' => $g('tarih') ?: date('Y-m-d'),
        'durum' => $g('durum', 'bekliyor'), 'aciklama' => $g('aciklama'),
    ];
    if ($veri['baslik'] === '') json_out(['ok' => false, 'hata' => 'Başlık gerekli.']);
    if ($g('id')) {
        guncelle('odemeler', $veri, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Kayıt güncellendi.']);
    }
    $veri['created'] = $now;
    insert('odemeler', $veri);
    json_out(['ok' => true, 'mesaj' => 'Finans kaydı eklendi.']);

case 'odeme_durum':
    require_yetki('finans');
    guncelle('odemeler', ['durum' => $g('durum')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Durum güncellendi.']);

case 'odeme_sil':
    require_yetki('finans');
    q("DELETE FROM odemeler WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Kayıt silindi.']);

/* ==================== GİDERLER ==================== */
case 'gider_kaydet':
    require_yetki('finans');
    $veri = [
        'tur' => isset(GIDER_TURLERI[$g('tur')]) ? $g('tur') : 'diger',
        'baslik' => trim($g('baslik')),
        'tutar' => (float)str_replace(',', '.', $g('tutar', '0')),
        'tarih' => $g('tarih') ?: date('Y-m-d'),
        'durum' => $g('durum') === 'odendi' ? 'odendi' : 'bekliyor',
        'tekrar' => $g('tekrar') === 'aylik' ? 'aylik' : 'yok',
        'aciklama' => $g('aciklama'),
    ];
    if ($veri['baslik'] === '') json_out(['ok' => false, 'hata' => 'Gider başlığı gerekli.']);
    if ($g('id')) {
        guncelle('giderler', $veri, 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Gider güncellendi.']);
    }
    $veri['created'] = $now;
    insert('giderler', $veri);
    json_out(['ok' => true, 'mesaj' => 'Gider eklendi.']);

case 'gider_durum':
    require_yetki('finans');
    guncelle('giderler', ['durum' => $g('durum') === 'odendi' ? 'odendi' : 'bekliyor'], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Durum güncellendi.']);

case 'gider_sil':
    require_yetki('finans');
    q("DELETE FROM giderler WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Gider silindi.']);

/* ==================== TEKLİF & FATURA BELGELERİ ==================== */
case 'belge_kaydet':
    require_yetki('belge_olustur');
    $tur = $g('tur') === 'fatura' ? 'fatura' : 'teklif';
    $kalemler = json_decode($g('kalemler', '[]'), true) ?: [];
    $kalemler = array_values(array_filter(array_map(fn($k) => [
        'ad' => mb_substr(trim($k['ad'] ?? ''), 0, 200),
        'adet' => max(1, (float)str_replace(',', '.', $k['adet'] ?? 1)),
        'fiyat' => (float)str_replace(',', '.', $k['fiyat'] ?? 0),
    ], $kalemler), fn($k) => $k['ad'] !== ''));
    $baslik = trim($g('baslik'));
    if ($baslik === '' || !$kalemler) json_out(['ok' => false, 'hata' => 'Başlık ve en az bir kalem gerekli.']);
    if ($g('id')) {
        guncelle('belgeler', ['baslik' => $baslik, 'dosya_id' => $g('dosya_id') ? (int)$g('dosya_id') : null,
            'kalemler' => json_encode($kalemler, JSON_UNESCAPED_UNICODE), 'kdv_oran' => max(0, min(50, (int)$g('kdv_oran', 20))),
            'gecerlilik' => $g('gecerlilik') ?: null, 'notlar' => $g('notlar')], 'id=?', [(int)$g('id')]);
        json_out(['ok' => true, 'mesaj' => 'Belge güncellendi.']);
    }
    // Numaralandırma: TKF-2026-001 / FTR-2026-001
    $onek = $tur === 'fatura' ? 'FTR' : 'TKF';
    $sayacAnahtar = 'belge_sayac_' . $tur . '_' . date('Y');
    $sayac = (int)val("SELECT deger FROM settings WHERE anahtar=?", [$sayacAnahtar]) + 1;
    q("INSERT INTO settings (anahtar, deger) VALUES (?,?) ON DUPLICATE KEY UPDATE deger=?", [$sayacAnahtar, $sayac, $sayac]);
    $no = $onek . '-' . date('Y') . '-' . str_pad($sayac, 3, '0', STR_PAD_LEFT);
    $bid = insert('belgeler', [
        'tur' => $tur, 'no' => $no, 'dosya_id' => $g('dosya_id') ? (int)$g('dosya_id') : null,
        'baslik' => $baslik, 'kalemler' => json_encode($kalemler, JSON_UNESCAPED_UNICODE),
        'kdv_oran' => max(0, min(50, (int)$g('kdv_oran', 20))), 'gecerlilik' => $g('gecerlilik') ?: null,
        'notlar' => $g('notlar'), 'olusturan_id' => $u['id'], 'created' => $now,
    ]);
    json_out(['ok' => true, 'mesaj' => $no . ' oluşturuldu.', 'yonlendir' => 'belge.php?id=' . $bid]);

case 'belge_durum':
    require_yetki('finans');
    $b = row("SELECT * FROM belgeler WHERE id=?", [(int)$g('id')]);
    if (!$b) json_out(['ok' => false, 'hata' => 'Belge bulunamadı.']);
    $durum = in_array($g('durum'), ['taslak', 'gonderildi', 'onaylandi', 'reddedildi']) ? $g('durum') : 'taslak';
    guncelle('belgeler', ['durum' => $durum], 'id=?', [$b['id']]);
    // Teklif onaylandıysa: dosyanın ilk aktif projesine gelir (fatura) kaydı öner/oluştur
    if ($durum === 'onaylandi' && $b['tur'] === 'teklif' && $b['dosya_id']) {
        $projeId = val("SELECT id FROM projeler WHERE dosya_id=? AND durum='aktif' ORDER BY id LIMIT 1", [$b['dosya_id']]);
        if ($projeId) {
            $kalemler = json_decode($b['kalemler'], true) ?: [];
            $araToplam = array_sum(array_map(fn($k) => $k['adet'] * $k['fiyat'], $kalemler));
            $toplam = $araToplam * (1 + $b['kdv_oran'] / 100);
            insert('odemeler', ['proje_id' => (int)$projeId, 'tur' => 'fatura', 'baslik' => $b['no'] . ' — ' . $b['baslik'],
                'tutar' => round($toplam, 2), 'tarih' => date('Y-m-d'), 'durum' => 'bekliyor',
                'aciklama' => 'Onaylanan tekliften otomatik oluşturuldu', 'created' => $now]);
            json_out(['ok' => true, 'mesaj' => 'Teklif onaylandı — gelir kaydı (fatura) oluşturuldu.']);
        }
    }
    json_out(['ok' => true, 'mesaj' => 'Belge durumu güncellendi.']);

case 'belge_sil':
    require_yetki('finans');
    q("DELETE FROM belgeler WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Belge silindi.', 'yonlendir' => 'finans.php#belgeler']);

case 'butce_kaydet':
    require_yetki('finans');
    $hedef = (float)str_replace(['.', ','], ['', '.'], $g('hedef', '0'));
    q("INSERT INTO settings (anahtar, deger) VALUES ('butce_hedef', ?) ON DUPLICATE KEY UPDATE deger=?", [$hedef, $hedef]);
    json_out(['ok' => true, 'mesaj' => 'Aylık gelir hedefi kaydedildi.']);

/* ==================== KULLANICILAR (admin) ==================== */
case 'kullanici_kaydet':
    require_admin();
    $eposta = mb_strtolower(trim($g('eposta'))); // e-posta tekilliği: normalize edilmiş halde saklanır
    $veri = [
        'ad' => trim($g('ad')), 'eposta' => $eposta, 'rol' => $g('rol', 'ekip'),
        'unvan' => $g('unvan'), 'dosya_id' => $g('dosya_id') ? (int)$g('dosya_id') : null,
        'haftalik_kapasite' => max(0, (int)$g('haftalik_kapasite', 45)),
        'maas' => max(0, (float)str_replace(',', '.', $g('maas', '0'))),
    ];
    if (!isset(ROLLER[$veri['rol']])) json_out(['ok' => false, 'hata' => 'Geçersiz rol.']);
    // Kullanıcı bazlı izin geçersiz kılmaları
    if ($g('izinler') !== '') {
        $izinler = json_decode($g('izinler'), true);
        if (is_array($izinler)) {
            $temiz = [];
            foreach (IZIN_ANAHTARLARI as $anahtar => $_) if (isset($izinler[$anahtar])) $temiz[$anahtar] = (int)(bool)$izinler[$anahtar];
            $veri['izinler'] = $temiz ? json_encode($temiz) : null;
        }
    }
    if ($veri['ad'] === '' || !filter_var($eposta, FILTER_VALIDATE_EMAIL))
        json_out(['ok' => false, 'hata' => 'Ad ve geçerli e-posta gerekli.']);
    // Müşteri çoklu dosya listesi (JSON); birincil dosya = ilk seçim
    $musteriDosyalar = json_decode($g('musteri_dosyalar', ''), true);
    if ($veri['rol'] === 'musteri' && is_array($musteriDosyalar)) {
        $musteriDosyalar = array_values(array_unique(array_filter(array_map('intval', $musteriDosyalar))));
        $veri['dosya_id'] = $musteriDosyalar[0] ?? null;
    }
    if ($veri['rol'] === 'musteri' && !$veri['dosya_id'])
        json_out(['ok' => false, 'hata' => 'Müşteri için en az bir dosya seçin.']);
    $musteriDosyaKaydet = function (int $uid) use ($veri, $musteriDosyalar) {
        if ($veri['rol'] !== 'musteri' || !is_array($musteriDosyalar)) return;
        q("DELETE FROM musteri_dosyalari WHERE user_id=?", [$uid]);
        foreach ($musteriDosyalar as $did) q("INSERT IGNORE INTO musteri_dosyalari (user_id, dosya_id) VALUES (?,?)", [$uid, $did]);
    };
    if ($g('id')) {
        $id = (int)$g('id');
        if (val("SELECT COUNT(*) FROM users WHERE eposta=? AND id!=?", [$eposta, $id]))
            json_out(['ok' => false, 'hata' => 'Bu e-posta kullanımda.']);
        if ($g('sifre')) $veri['sifre'] = password_hash($g('sifre'), PASSWORD_DEFAULT);
        guncelle('users', $veri, 'id=?', [$id]);
        $musteriDosyaKaydet($id);
        json_out(['ok' => true, 'mesaj' => 'Kullanıcı güncellendi.']);
    }
    if (val("SELECT COUNT(*) FROM users WHERE eposta=?", [$eposta]))
        json_out(['ok' => false, 'hata' => 'Bu e-posta kullanımda.']);
    if (strlen($g('sifre')) < 6) json_out(['ok' => false, 'hata' => 'Şifre en az 6 karakter.']);
    $veri['sifre'] = password_hash($g('sifre'), PASSWORD_DEFAULT);
    $veri['tema'] = 'lime'; $veri['renk'] = '#b1fb01'; $veri['created'] = $now;
    $id = insert('users', $veri);
    // Yeni kullanıcıyı ilgili kanallara otomatik ekle
    if ($veri['rol'] !== 'musteri') {
        // Genel kanal + (stajyer hariç) tüm proje kanalları
        foreach (rows("SELECT id, tur FROM kanallar WHERE tur='genel' OR (tur='proje' AND ?='tam')", [$veri['rol'] === 'stajyer' ? 'stajyer' : 'tam']) as $kanal) {
            q("INSERT IGNORE INTO kanal_uyeleri (kanal_id, user_id) VALUES (?,?)", [$kanal['id'], $id]);
        }
    } else {
        // Müşteri: erişebildiği tüm dosyaların müşteri kanallarına
        $musteriDosyaKaydet($id);
        $dosyaListe = is_array($musteriDosyalar) && $musteriDosyalar ? $musteriDosyalar : [$veri['dosya_id']];
        [$in, $p] = in_sorgu(array_map('intval', $dosyaListe));
        foreach (rows("SELECT k.id FROM kanallar k JOIN projeler pr ON pr.id=k.proje_id WHERE k.tur='musteri' AND pr.dosya_id IN $in", $p) as $kanal) {
            q("INSERT IGNORE INTO kanal_uyeleri (kanal_id, user_id) VALUES (?,?)", [$kanal['id'], $id]);
        }
    }
    json_out(['ok' => true, 'mesaj' => 'Kullanıcı oluşturuldu.']);

case 'kullanici_durum':
    require_admin();
    if ((int)$g('id') === (int)$u['id']) json_out(['ok' => false, 'hata' => 'Kendinizi pasifleştiremezsiniz.']);
    guncelle('users', ['aktif' => (int)$g('aktif')], 'id=?', [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Durum güncellendi.']);

/* ==================== AKIŞ ŞABLONLARI (admin) ==================== */
case 'akis_kaydet':
    require_admin();
    $ad = trim($g('ad'));
    $adimlar = json_decode($g('adimlar', '[]'), true) ?: [];
    if ($ad === '' || !$adimlar) json_out(['ok' => false, 'hata' => 'Şablon adı ve en az bir adım gerekli.']);
    if ($g('id')) {
        $sid = (int)$g('id');
        guncelle('akis_sablonlari', ['ad' => $ad, 'aciklama' => $g('aciklama')], 'id=?', [$sid]);
        q("DELETE FROM sablon_adimlari WHERE sablon_id=?", [$sid]);
    } else {
        $sid = insert('akis_sablonlari', ['ad' => $ad, 'aciklama' => $g('aciklama'), 'created' => $now]);
    }
    foreach ($adimlar as $i => $adimAd) {
        if (trim($adimAd) !== '') insert('sablon_adimlari', ['sablon_id' => $sid, 'sira' => $i + 1, 'ad' => trim($adimAd)]);
    }
    json_out(['ok' => true, 'mesaj' => 'Akış şablonu kaydedildi.']);

case 'akis_sil':
    require_admin();
    q("DELETE FROM sablon_adimlari WHERE sablon_id=?", [(int)$g('id')]);
    q("DELETE FROM akis_sablonlari WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Şablon silindi.']);

/* ==================== FORM ŞABLONLARI (admin) ==================== */
case 'form_kaydet':
    require_admin();
    $ad = trim($g('ad'));
    $alanlar = json_decode($g('alanlar', '[]'), true) ?: [];
    if ($ad === '' || !$alanlar) json_out(['ok' => false, 'hata' => 'Form adı ve en az bir alan gerekli.']);
    if ($g('id')) {
        $fid = (int)$g('id');
        guncelle('form_sablonlari', ['ad' => $ad, 'aciklama' => $g('aciklama'), 'aktif' => (int)$g('aktif', 1)], 'id=?', [$fid]);
        q("DELETE FROM form_alanlari WHERE sablon_id=?", [$fid]);
    } else {
        $fid = insert('form_sablonlari', ['ad' => $ad, 'aciklama' => $g('aciklama'), 'aktif' => 1, 'created' => $now]);
    }
    foreach ($alanlar as $i => $alan) {
        if (trim($alan['etiket'] ?? '') === '') continue;
        insert('form_alanlari', [
            'sablon_id' => $fid, 'sira' => $i + 1, 'etiket' => trim($alan['etiket']),
            'tip' => $alan['tip'] ?? 'metin', 'secenekler' => $alan['secenekler'] ?? null,
            'zorunlu' => !empty($alan['zorunlu']) ? 1 : 0,
        ]);
    }
    json_out(['ok' => true, 'mesaj' => 'Form şablonu kaydedildi.']);

case 'form_sil':
    require_admin();
    q("DELETE FROM form_alanlari WHERE sablon_id=?", [(int)$g('id')]);
    q("DELETE FROM form_sablonlari WHERE id=?", [(int)$g('id')]);
    json_out(['ok' => true, 'mesaj' => 'Form silindi.']);

/* ==================== AYARLAR (admin) ==================== */
case 'ayar_kaydet':
    require_admin();
    foreach (['site_adi', 'varsayilan_tema', 'smtp_aktif', 'smtp_host', 'smtp_port', 'smtp_kullanici', 'smtp_gonderen', 'eposta_bildirim'] as $anahtar) {
        if (isset($_POST[$anahtar])) q("INSERT INTO settings (anahtar,deger) VALUES (?,?) ON DUPLICATE KEY UPDATE deger=?", [$anahtar, $_POST[$anahtar], $_POST[$anahtar]]);
    }
    if (!empty($_POST['smtp_sifre'])) q("INSERT INTO settings (anahtar,deger) VALUES ('smtp_sifre',?) ON DUPLICATE KEY UPDATE deger=?", [$_POST['smtp_sifre'], $_POST['smtp_sifre']]);
    // Logo & favicon yükleme
    foreach (['site_logo' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'site_favicon' => ['png', 'ico', 'jpg', 'jpeg', 'gif', 'webp']] as $alanAd => $izinliler) {
        $yeni = dosya_yukle($alanAd);
        if ($yeni) {
            if (!in_array($yeni['uzanti'], $izinliler)) json_out(['ok' => false, 'hata' => ($alanAd === 'site_logo' ? 'Logo' : 'Favicon') . ' için görsel dosyası seçin.']);
            $eski = ayar($alanAd);
            if ($eski) @unlink(ROOT . '/uploads/' . $eski);
            q("INSERT INTO settings (anahtar,deger) VALUES (?,?) ON DUPLICATE KEY UPDATE deger=?", [$alanAd, $yeni['yol'], $yeni['yol']]);
        }
    }
    json_out(['ok' => true, 'mesaj' => 'Ayarlar kaydedildi.']);

case 'ayar_gorsel_sil':
    require_admin();
    $anahtar = in_array($g('anahtar'), ['site_logo', 'site_favicon']) ? $g('anahtar') : '';
    if (!$anahtar) json_out(['ok' => false, 'hata' => 'Geçersiz.']);
    $eski = ayar($anahtar);
    if ($eski) @unlink(ROOT . '/uploads/' . $eski);
    q("DELETE FROM settings WHERE anahtar=?", [$anahtar]);
    json_out(['ok' => true, 'mesaj' => 'Görsel kaldırıldı.']);

case 'test_eposta':
    require_admin();
    require_once __DIR__ . '/includes/mailer.php';
    // Geçici ayarları uygula (kaydetmeden test)
    $ok = eposta_gonder($u['eposta'], 'SADA Test E-postası', "Bu bir test e-postasıdır.\nSMTP ayarlarınız çalışıyor. 🎉");
    json_out(['ok' => $ok, 'mesaj' => $ok ? 'Test e-postası gönderildi: ' . $u['eposta'] : 'Gönderilemedi. SMTP ayarlarını kontrol edin.', 'hata' => $ok ? '' : 'SMTP gönderimi başarısız.']);

/* ==================== PROFİL ==================== */
case 'profil_kaydet':
    require_login();
    $veri = ['ad' => trim($g('ad')), 'unvan' => $g('unvan')];
    if ($veri['ad'] === '') json_out(['ok' => false, 'hata' => 'Ad gerekli.']);
    if ($g('sifre')) {
        if (strlen($g('sifre')) < 6) json_out(['ok' => false, 'hata' => 'Şifre en az 6 karakter.']);
        $veri['sifre'] = password_hash($g('sifre'), PASSWORD_DEFAULT);
    }
    $avatarDosya = dosya_yukle('avatar');
    if ($avatarDosya) {
        if (!in_array($avatarDosya['uzanti'], ['jpg', 'jpeg', 'png', 'gif', 'webp'])) json_out(['ok' => false, 'hata' => 'Profil fotoğrafı için görsel dosyası seçin.']);
        if ($u['avatar']) @unlink(ROOT . '/uploads/' . $u['avatar']);
        $veri['avatar'] = $avatarDosya['yol'];
    }
    guncelle('users', $veri, 'id=?', [$u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Profil güncellendi.']);

case 'avatar_sil':
    require_login();
    if ($u['avatar']) @unlink(ROOT . '/uploads/' . $u['avatar']);
    guncelle('users', ['avatar' => null], 'id=?', [$u['id']]);
    json_out(['ok' => true, 'mesaj' => 'Profil fotoğrafı kaldırıldı.']);

default:
    json_out(['ok' => false, 'hata' => 'Bilinmeyen işlem.'], 400);
}

/* ---------- Yardımcı: görev adımlarını şablondan kur ---------- */
function gorev_adimlari_kur(int $gorevId, int $sablonId): void {
    $adimlar = rows("SELECT * FROM sablon_adimlari WHERE sablon_id=? ORDER BY sira", [$sablonId]);
    foreach ($adimlar as $i => $a) {
        insert('gorev_adimlari', [
            'gorev_id' => $gorevId, 'sira' => $a['sira'], 'ad' => $a['ad'],
            'durum' => $i === 0 ? 'aktif' : 'bekliyor',
        ]);
    }
}

/* ---------- Yardımcı: proje/dosya üyelerini kaydet (çoklu atama) ---------- */
function proje_uyeleri_kaydet(int $projeId, string $uyelerJson): void {
    if ($uyelerJson === '') return; // form üye alanı göndermediyse dokunma
    $uyeler = json_decode($uyelerJson, true);
    if (!is_array($uyeler)) return;
    $eski = array_column(rows("SELECT user_id FROM proje_uyeleri WHERE proje_id=?", [$projeId]), 'user_id');
    q("DELETE FROM proje_uyeleri WHERE proje_id=?", [$projeId]);
    $projeAd = val("SELECT ad FROM projeler WHERE id=?", [$projeId]);
    foreach (array_unique(array_map('intval', $uyeler)) as $uid) {
        if (!$uid) continue;
        q("INSERT IGNORE INTO proje_uyeleri (proje_id, user_id) VALUES (?,?)", [$projeId, $uid]);
        if (!in_array($uid, $eski)) bildir($uid, 'Projeye atandınız', $projeAd, 'proje.php?id=' . $projeId, 'gorev');
    }
}

function dosya_uyeleri_kaydet(int $dosyaId, string $uyelerJson): void {
    if ($uyelerJson === '') return;
    $uyeler = json_decode($uyelerJson, true);
    if (!is_array($uyeler)) return;
    $eski = array_column(rows("SELECT user_id FROM dosya_uyeleri WHERE dosya_id=?", [$dosyaId]), 'user_id');
    q("DELETE FROM dosya_uyeleri WHERE dosya_id=?", [$dosyaId]);
    $dosyaAd = val("SELECT ad FROM dosyalar WHERE id=?", [$dosyaId]);
    foreach (array_unique(array_map('intval', $uyeler)) as $uid) {
        if (!$uid) continue;
        q("INSERT IGNORE INTO dosya_uyeleri (dosya_id, user_id) VALUES (?,?)", [$dosyaId, $uid]);
        if (!in_array($uid, $eski)) bildir($uid, 'Dosyaya atandınız', $dosyaAd, 'dosya.php?id=' . $dosyaId, 'gorev');
    }
}

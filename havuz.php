<?php
/**
 * SADA One — Çalışan Havuzu
 * Birlikte çalışılan veya bilgisi elimizde olan serbest çalışanlar: kişi · yetkinlik · çalışıldı mı · CV
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (is_stajyer()) { header('Location: index.php'); exit; }

$kisiler = rows("SELECT h.*, a.dosya_yolu cv_yol, a.ad cv_ad, ek.ad ekleyen_ad FROM calisan_havuzu h
    LEFT JOIN arsiv a ON a.id=h.cv_arsiv_id LEFT JOIN users ek ON ek.id=h.ekleyen_id ORDER BY h.calisildi DESC, h.ad");
$yonetir = is_admin() || $u['rol'] === 'pm';

sayfa_basi('Çalışan Havuzu', 'havuz');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Çalışan Havuzu</div><div class="sayfa-alt">Birlikte çalıştığımız veya bilgisi elimizde olan serbest yetenekler</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" onclick="hYeni()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Kişi Ekle</button></div>
</div>

<div class="filtre-bar">
    <div class="pill-filtre" data-pill-grup="#havuzListe tbody tr">
        <button class="pill aktif" data-deger="">Tümü (<?= count($kisiler) ?>)</button>
        <button class="pill" data-deger="1">Çalışıldı</button>
        <button class="pill" data-deger="0">Henüz Çalışılmadı</button>
    </div>
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="İsim veya yetkinlik ara..." data-arama="#havuzListe tbody tr"></div>
</div>

<?php if (!$kisiler): ?>
<div class="bos-durum">
    <div class="bos-ikon"><?= ikon('ekip', 36) ?></div>
    <div class="bos-baslik">Havuz boş</div>
    <div class="bos-metin">Freelance kameraman, editör, tasarımcı... birlikte çalıştığınız ya da CV'si elinizde olan herkesi ekleyin.</div>
    <button class="btn btn-marka" onclick="hYeni()">İlk Kişiyi Ekle</button>
</div>
<?php else: ?>
<div class="tablo-sar"><table class="tablo" id="havuzListe">
    <thead><tr><th>Kişi</th><th>Yetkinlik</th><th>Daha Önce Çalışıldı mı?</th><th>İletişim</th><th>CV</th><th>Not</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($kisiler as $k): ?>
    <tr data-filtre="<?= $k['calisildi'] ?>">
        <td><div class="hucre-ana"><?= e($k['ad']) ?></div></td>
        <td class="kucuk"><?= e($k['yetkinlik'] ?: '—') ?></td>
        <td><?= $k['calisildi'] ? '<span class="rozet r-tamamlandi">Evet</span>' : '<span class="rozet r-bekliyor">Hayır</span>' ?></td>
        <td class="kucuk"><?= e($k['iletisim'] ?: '—') ?></td>
        <td><?= $k['cv_yol'] ? '<a href="uploads/' . e($k['cv_yol']) . '" target="_blank" class="mini-btn">📄 ' . e(mb_substr($k['cv_ad'], 0, 22)) . '</a>' : '<span class="metin-muted kucuk">—</span>' ?></td>
        <td class="kucuk metin-2" style="max-width:220px"><?= e(mb_substr($k['notu'] ?? '', 0, 60)) ?: '—' ?></td>
        <td><div class="satir-esnek" style="gap:4px;justify-content:flex-end">
            <button class="ikon-eylem" onclick='hDuzenle(<?= json_encode($k, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= ikon('kalem', 15) ?></button>
            <?php if ($yonetir): ?><button class="ikon-eylem tehlike" data-eylem="havuz_sil" data-id="<?= $k['id'] ?>" data-onay="<?= e($k['ad']) ?> havuzdan silinsin mi?"><?= ikon('cop', 15) ?></button><?php endif; ?>
        </div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="modal-katman" id="modalHavuz">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="hBaslik">Havuza Kişi Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="havuz_kaydet">
        <input type="hidden" name="id" id="h_id">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Ad Soyad <span class="zorunlu">*</span></label><input name="ad" id="h_ad" class="girdi" required></div>
                <div class="form-grup"><label class="form-etiket">İletişim</label><input name="iletisim" id="h_iletisim" class="girdi" placeholder="Telefon / e-posta / instagram"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Yetkinlik</label><input name="yetkinlik" id="h_yetkinlik" class="girdi" placeholder="Örn. kameraman, video editör, grafik tasarım"></div>
            <div class="form-grup"><label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="calisildi" id="h_calisildi" value="1"> Daha önce birlikte çalışıldı</label></div>
            <div class="form-grup"><label class="form-etiket">CV (PDF/DOC)</label><input type="file" name="cv" class="girdi" accept=".pdf,.doc,.docx"><div class="form-ipucu" id="h_cvBilgi"></div></div>
            <div class="form-grup"><label class="form-etiket">Not</label><textarea name="notu" id="h_notu" class="metin-alani" placeholder="Gözlemler, ücret bilgisi, referans..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
function hYeni() {
    const f = document.querySelector('#modalHavuz form');
    f.reset(); document.getElementById('h_id').value = '';
    document.getElementById('hBaslik').textContent = 'Havuza Kişi Ekle';
    document.getElementById('h_cvBilgi').textContent = '';
    modalAc('modalHavuz');
}
function hDuzenle(k) {
    document.getElementById('h_id').value = k.id;
    document.getElementById('h_ad').value = k.ad;
    document.getElementById('h_iletisim').value = k.iletisim || '';
    document.getElementById('h_yetkinlik').value = k.yetkinlik || '';
    document.getElementById('h_calisildi').checked = k.calisildi == 1;
    document.getElementById('h_notu').value = k.notu || '';
    document.getElementById('h_cvBilgi').textContent = k.cv_ad ? 'Mevcut CV: ' + k.cv_ad + ' (yenisini seçerseniz değişir)' : '';
    document.getElementById('hBaslik').textContent = 'Kişiyi Düzenle';
    modalAc('modalHavuz');
}
</script>
<?php sayfa_sonu(); ?>

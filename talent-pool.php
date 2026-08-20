<?php
/**
 * SADA One — Talent Pool
 * Freelancers we have worked with or have on record: person · skill · worked before · CV
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (is_intern()) { header('Location: index.php'); exit; }

$people = rows("SELECT h.*, a.file_path cv_path, a.name cv_name, ek.name adder_name FROM talent_pool h
    LEFT JOIN archive a ON a.id=h.cv_archive_id LEFT JOIN users ek ON ek.id=h.added_by ORDER BY h.worked_before DESC, h.name");
$can_manage = is_admin() || $u['role'] === 'pm';

page_start('Çalışan Havuzu', 'pool');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Çalışan Havuzu</div><div class="sayfa-alt">Birlikte çalıştığımız veya bilgisi elimizde olan serbest yetenekler</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" onclick="hYeni()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Kişi Ekle</button></div>
</div>

<div class="filtre-bar">
    <div class="pill-filtre" data-pill-grup="#havuzListe tbody tr">
        <button class="pill aktif" data-setting_value="">Tümü (<?= count($people) ?>)</button>
        <button class="pill" data-setting_value="1">Çalışıldı</button>
        <button class="pill" data-setting_value="0">Henüz Çalışılmadı</button>
    </div>
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="İsim veya yetkinlik ara..." data-search="#havuzListe tbody tr"></div>
</div>

<?php if (!$people): ?>
<div class="bos-durum">
    <div class="bos-ikon"><?= icon('team', 36) ?></div>
    <div class="bos-baslik">Havuz boş</div>
    <div class="bos-metin">Freelance kameraman, editör, tasarımcı... birlikte çalıştığınız ya da CV'si elinizde olan herkesi ekleyin.</div>
    <button class="btn btn-marka" onclick="hYeni()">İlk Kişiyi Ekle</button>
</div>
<?php else: ?>
<div class="tablo-sar"><table class="tablo" id="poolList">
    <thead><tr><th>Kişi</th><th>Yetkinlik</th><th>Daha Önce Çalışıldı mı?</th><th>İletişim</th><th>CV</th><th>Not</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $k): ?>
    <tr data-filter="<?= $k['worked_before'] ?>">
        <td><div class="hucre-ana"><?= e($k['name']) ?></div></td>
        <td class="kucuk"><?= e($k['skill'] ?: '—') ?></td>
        <td><?= $k['worked_before'] ? '<span class="rozet r-tamamlandi">Evet</span>' : '<span class="rozet r-bekliyor">Hayır</span>' ?></td>
        <td class="kucuk"><?= e($k['contact'] ?: '—') ?></td>
        <td><?= $k['cv_path'] ? '<a href="uploads/' . e($k['cv_path']) . '" target="_blank" class="mini-btn">📄 ' . e(mb_substr($k['cv_name'], 0, 22)) . '</a>' : '<span class="metin-muted kucuk">—</span>' ?></td>
        <td class="kucuk metin-2" style="max-width:220px"><?= e(mb_substr($k['note'] ?? '', 0, 60)) ?: '—' ?></td>
        <td><div class="satir-esnek" style="gap:4px;justify-content:flex-end">
            <button class="ikon-eylem" onclick='hDuzenle(<?= json_encode($k, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 15) ?></button>
            <?php if ($can_manage): ?><button class="ikon-eylem tehlike" data-action="pool_delete" data-id="<?= $k['id'] ?>" data-approval="<?= e($k['name']) ?> havuzdan silinsin mi?"><?= icon('cop', 15) ?></button><?php endif; ?>
        </div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="modal-katman" id="modalPool">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="hBaslik">Havuza Kişi Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="pool_save">
        <input type="hidden" name="id" id="h_id">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Ad Soyad <span class="zorunlu">*</span></label><input name="name" id="h_name" class="girdi" required></div>
                <div class="form-grup"><label class="form-etiket">İletişim</label><input name="contact" id="h_contact" class="girdi" placeholder="Telefon / e-posta / instagram"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Yetkinlik</label><input name="skill" id="h_skill" class="girdi" placeholder="Örn. kameraman, video editör, grafik tasarım"></div>
            <div class="form-grup"><label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="worked_before" id="h_worked_before" value="1"> Daha önce birlikte çalışıldı</label></div>
            <div class="form-grup"><label class="form-etiket">CV (PDF/DOC)</label><input type="file" name="cv" class="girdi" accept=".pdf,.doc,.docx"><div class="form-ipucu" id="h_cvBilgi"></div></div>
            <div class="form-grup"><label class="form-etiket">Not</label><textarea name="note" id="h_note" class="metin-alani" placeholder="Gözlemler, ücret bilgisi, referans..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
function hYeni() {
    const f = document.querySelector('#modalPool form');
    f.reset(); document.getElementById('h_id').value = '';
    document.getElementById('hBaslik').textContent = 'Havuza Kişi Ekle';
    document.getElementById('h_cvBilgi').textContent = '';
    modalOpen('modalPool');
}
function hDuzenle(k) {
    document.getElementById('h_id').value = k.id;
    document.getElementById('h_name').value = k.name;
    document.getElementById('h_contact').value = k.contact || '';
    document.getElementById('h_skill').value = k.skill || '';
    document.getElementById('h_worked_before').checked = k.worked_before == 1;
    document.getElementById('h_note').value = k.note || '';
    document.getElementById('h_cvBilgi').textContent = k.cv_name ? 'Mevcut CV: ' + k.cv_name + ' (yenisini seçerseniz değişir)' : '';
    document.getElementById('hBaslik').textContent = 'Kişiyi Düzenle';
    modalOpen('modalPool');
}
</script>
<?php page_end(); ?>

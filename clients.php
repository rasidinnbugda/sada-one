<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

if (is_customer()) {
    // Müşteri: yalnızca erişebildiği dosyalar
    [$in, $p] = in_clause(customer_client_ids());
    $clients = rows("SELECT d.*,
        (SELECT COUNT(*) FROM projects pr WHERE pr.client_id=d.id) project_count,
        (SELECT COUNT(*) FROM projects pr WHERE pr.client_id=d.id AND pr.status='aktif') is_active_project
        FROM clients d WHERE d.id IN $in ORDER BY d.name", $p);
} else {
    $clients = rows("SELECT d.*,
        (SELECT COUNT(*) FROM projects p WHERE p.client_id=d.id) project_count,
        (SELECT COUNT(*) FROM projects p WHERE p.client_id=d.id AND p.status='aktif') is_active_project
        FROM clients d ORDER BY d.status='aktif' DESC, d.name");
}

page_start(is_customer() ? 'Dosyalarım' : 'Dosyalar', 'clients');
?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik"><?= is_customer() ? 'Dosyalarım' : 'Dosyalar' ?></div>
        <div class="sayfa-alt"><?= is_customer() ? 'Ajansımızla yürüttüğünüz dosyalar' : "Markalar, şirketler ve STK'lar" ?> — <?= count($clients) ?> dosya</div>
    </div>
    <?php if (permission('dosya_yonet')): ?>
    <div class="sayfa-ust-aksiyon">
        <button class="btn btn-marka" data-modal="modalDosya" onclick="clientFormSifirla()">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Dosya
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="filtre-bar">
    <div class="arama-kutu">
        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
        <input class="girdi" placeholder="Dosya ara..." data-search="#dosyaIzgara .dosya-kart">
    </div>
    <div class="pill-filtre" data-pill-grup="#dosyaIzgara .dosya-kart">
        <button class="pill aktif" data-setting_value="">Tümü</button>
        <?php foreach (DOSYA_TURLERI as $k => $v): ?><button class="pill" data-setting_value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$clients): ?>
<div class="bos-durum">
    <div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg></div>
    <div class="bos-baslik">Henüz dosya yok</div>
    <div class="bos-metin">İlk markanızı, şirketinizi veya STK'nızı ekleyerek başlayın.</div>
    <?php if (permission('dosya_yonet')): ?><button class="btn btn-marka" data-modal="modalDosya">Yeni Dosya Oluştur</button><?php endif; ?>
</div>
<?php else: ?>
<div class="izgara izgara-auto" id="clientIzgara">
    <?php foreach ($clients as $d): ?>
    <a href="client.php?id=<?= $d['id'] ?>" class="kart kart-tik dosya-kart" data-filtre="<?= $d['type'] ?>" data-search="<?= e($d['name']) ?>">
        <div class="satir-esnek arasi mb-2">
            <?= client_logo($d, 40, 15) ?>
            <?php if ($d['status'] === 'pasif'): ?><span class="rozet r-iptal">Pasif</span><?php else: ?><span class="rozet rozet-tur"><?= DOSYA_TURLERI[$d['type']] ?></span><?php endif; ?>
        </div>
        <div class="kart-baslik" style="font-size:16px"><?= e($d['name']) ?></div>
        <?php if ($d['contact_name']): ?><div class="hucre-alt mt-1"><?= e($d['contact_name']) ?></div><?php endif; ?>
        <div class="satir-esnek mt-2" style="gap:16px;color:var(--muted);font-size:12.5px">
            <span><b style="color:var(--text)"><?= $d['is_active_project'] ?></b> aktif proje</span>
            <span><b style="color:var(--text)"><?= $d['project_count'] ?></b> toplam</span>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (permission('dosya_yonet')): ?>
<div class="modal-katman" id="modalClient">
    <div class="modal">
        <div class="modal-ust"><div class="modal-baslik" id="clientModalTitle">Yeni Dosya</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
        <form data-ajax="client_save" id="clientForm">
            <input type="hidden" name="id" id="client_id">
            <div class="modal-govde">
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Dosya Adı <span class="zorunlu">*</span></label><input name="name" id="d_name" class="girdi" required></div>
                    <div class="form-grup"><label class="form-etiket">Tür</label><select name="type" id="d_type" class="secim"><?php foreach (DOSYA_TURLERI as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-grup">
                    <label class="form-etiket">Renk</label>
                    <div class="satir-esnek sarma" id="colorSelect">
                        <?php foreach (['#b1fb01', '#182f5d', '#610714', '#f8f2cb', '#3b9df0', '#35c66b', '#f5a524', '#a58bf0'] as $r): ?>
                        <label style="cursor:pointer"><input type="radio" name="color" value="<?= $r ?>" <?= $r === '#182f5d' ? 'checked' : '' ?> style="display:none" class="renk-radio"><span class="etiket-nokta" style="width:28px;height:28px;background:<?= $r ?>;border:2px solid transparent"></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-grup"><label class="form-etiket">Logo</label><input type="file" name="logo" class="girdi" accept="image/*"><div class="form-ipucu">JPG, PNG veya WebP.</div></div>
                <?php member_picker([], 'Sorumlu Ekip Üyeleri'); ?>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="description" id="d_description" class="metin-alani"></textarea></div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">İletişim Kişisi</label><input name="contact_name" id="d_contact_name" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Telefon</label><input name="contact_phone" id="d_contact_tel" class="girdi"></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">E-posta</label><input type="email" name="contact_email" id="d_contact_email" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Durum</label><select name="status" id="d_status" class="secim"><option value="aktif">Aktif</option><option value="pasif">Pasif</option></select></div>
                </div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
        </form>
    </div>
</div>
<script>
function clientFormSifirla() {
    const f = document.getElementById('clientForm'); f.reset();
    document.getElementById('client_id').value = '';
    document.getElementById('clientModalTitle').textContent = 'Yeni Dosya';
    colorHighlight();
}
// Renk seçim vurgusu
function colorHighlight() {
    document.querySelectorAll('.color-radio').forEach(r => {
        r.nextElementSibling.style.borderColor = r.checked ? 'var(--text)' : 'transparent';
    });
}
document.getElementById('colorSelect').addEventListener('change', colorHighlight);
colorHighlight();
</script>
<?php endif; ?>
<?php page_end(); ?>

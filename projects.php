<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

if (is_staff()) {
    $projects = rows("SELECT p.*, d.name client_name, d.color client_color, uu.name pm_name,
        (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id) task_count,
        (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id AND g.status='tamamlandi') is_done_count
        FROM projects p JOIN clients d ON d.id=p.client_id LEFT JOIN users uu ON uu.id=p.pm_id
        ORDER BY p.status='aktif' DESC, p.created DESC");
} else {
    $projects = rows("SELECT p.*, d.name client_name, d.color client_color, uu.name pm_name,
        (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id) task_count,
        (SELECT COUNT(*) FROM tasks g WHERE g.project_id=p.id AND g.status='tamamlandi') is_done_count
        FROM projects p JOIN clients d ON d.id=p.client_id LEFT JOIN users uu ON uu.id=p.pm_id
        WHERE p.client_id IN " . in_clause(customer_client_ids())[0] . " ORDER BY d.name, p.created DESC", in_clause(customer_client_ids())[1]);
}

page_start(is_staff() ? 'Projeler' : 'Projelerim', 'projects');
?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik"><?= is_staff() ? 'Projeler' : 'Projelerim' ?></div>
        <div class="sayfa-alt"><?= count($projects) ?> proje</div>
    </div>
    <?php if (permission('dosya_yonet')): ?>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalProject"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Proje</button></div>
    <?php endif; ?>
</div>

<div class="filtre-bar">
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="Proje ara..." data-search="#projectIzgara .proje-kart"></div>
    <div class="pill-filtre" data-pill-grup="#projectIzgara .proje-kart">
        <button class="pill aktif" data-setting_value="">Tümü</button>
        <?php foreach (PROJECT_TYPES as $k => $v): ?><button class="pill" data-setting_value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$projects): ?>
<div class="bos-durum">
    <div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg></div>
    <div class="bos-baslik">Henüz proje yok</div>
    <div class="bos-metin"><?= permission('dosya_yonet') ? 'Bir dosya seçip ilk projeyi oluşturun.' : 'Size atanmış bir proje bulunmuyor.' ?></div>
</div>
<?php else: ?>
<div class="izgara izgara-auto" id="projectIzgara">
    <?php foreach ($projects as $p):
        $rate = $p['task_count'] ? round($p['is_done_count'] / $p['task_count'] * 100) : 0; ?>
    <a href="project.php?id=<?= $p['id'] ?>" class="kart kart-tik proje-kart" data-filter="<?= $p['type'] ?>" data-search="<?= e($p['name'] . ' ' . $p['client_name']) ?>">
        <div class="satir-esnek arasi mb-2">
            <span class="rozet rozet-tur"><?= PROJECT_TYPES[$p['type']] ?></span>
            <?= badge($p['status'], PROJECT_STATUSES) ?>
        </div>
        <div class="kart-baslik" style="font-size:16px"><?= e($p['name']) ?></div>
        <div class="satir-esnek mt-1" style="gap:7px">
            <span class="etiket-nokta" style="background:<?= e($p['client_color']) ?>"></span>
            <span class="hucre-alt"><?= e($p['client_name']) ?></span>
        </div>
        <div class="ilerleme mt-2"><div class="ilerleme-dolu" data-rate="<?= $rate ?>" style="width:0"></div></div>
        <div class="satir-esnek arasi mt-1"><span class="hucre-alt"><?= $p['is_done_count'] ?>/<?= $p['task_count'] ?> görev</span><span class="hucre-alt kalin">%<?= $rate ?></span></div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
if (permission('dosya_yonet')) {
    $clients = rows("SELECT id, name FROM clients WHERE status='aktif' ORDER BY name");
    $pmler = rows("SELECT id, name FROM users WHERE role IN ('yonetici','pm') AND is_active=1 ORDER BY name");
?>
<div class="modal-katman" id="modalProject">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Proje</div><button class="modal-kapat" data-modal-close>✕</button></div>
        <form data-ajax="project_save">
            <div class="modal-govde">
                <div class="form-grup"><label class="form-etiket">Proje Adı <span class="zorunlu">*</span></label><input name="name" class="girdi" required></div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Dosya <span class="zorunlu">*</span></label><select name="client_id" class="secim" required><option value="">Seçin...</option><?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-grup"><label class="form-etiket">Hizmet Türü</label><select name="type" class="secim"><?php foreach (PROJECT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Başlangıç</label><input type="date" name="start" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="date" name="end" class="girdi"></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Proje Yöneticisi</label><select name="pm_id" class="secim"><option value="">—</option><?php foreach ($pmler as $pm): ?><option value="<?= $pm['id'] ?>"><?= e($pm['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-grup"><label class="form-etiket">Sözleşme Tutarı (₺)</label><input name="contract_amount" class="girdi" placeholder="0,00"></div>
                </div>
                <div class="form-grup"><label class="form-etiket">Proje Şablonu (opsiyonel)</label><select name="ptemplate_id" class="secim"><option value="">— Boş proje</option><?php foreach (rows("SELECT id, name FROM project_templates ORDER BY name") as $psx): ?><option value="<?= $psx['id'] ?>"><?= e($psx['name']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilirse şablondaki görevler akışlarıyla birlikte kurulur.</div></div>
                <?php member_picker(); ?>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="description" class="metin-alani"></textarea></div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
        </form>
    </div>
</div>
<?php } ?>
<?php page_end(); ?>

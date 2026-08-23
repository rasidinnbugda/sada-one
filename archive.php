<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$clientFiltre = (int)($_GET['client'] ?? 0);

if (is_staff()) {
    $where_sql = $clientFiltre ? "a.client_id=$clientFiltre OR p.client_id=$clientFiltre" : "1=1";
    $archives = rows("SELECT a.*, us.name uploader_name, p.name project_name, d.name client_name FROM archive a LEFT JOIN users us ON us.id=a.uploader_id LEFT JOIN projects p ON p.id=a.project_id LEFT JOIN clients d ON d.id=COALESCE(a.client_id, p.client_id) WHERE $where_sql ORDER BY a.id DESC");
    $clients = rows("SELECT id, name FROM clients ORDER BY name");
} else {
    [$in, $p] = in_clause(customer_client_ids());
    $archives = rows("SELECT a.*, us.name uploader_name, p.name project_name FROM archive a LEFT JOIN users us ON us.id=a.uploader_id LEFT JOIN projects p ON p.id=a.project_id WHERE p.client_id IN $in ORDER BY a.id DESC", $p);
    $clients = [];
}
if (is_staff()) { $projects = rows("SELECT id, name FROM projects WHERE status='aktif' ORDER BY name"); }
else { [$in2, $p2] = in_clause(customer_client_ids()); $projects = rows("SELECT id, name FROM projects WHERE client_id IN $in2 ORDER BY name", $p2); }

page_start('Dosya Arşivi', 'archive');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Dosya Arşivi</div><div class="sayfa-alt">Logo, brief, tasarım ve medya dosyaları</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalUpload"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 15V3m0 0L8 7m4-4l4 4M3 15v4a2 2 0 002 2h14a2 2 0 002-2v-4"/></svg> Dosya Yükle</button></div>
</div>

<div class="filtre-bar">
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="Dosya ara..." data-search="#arsivIzgara .arsiv-kart"></div>
    <?php if ($clients): ?>
    <select class="secim" style="max-width:240px" onchange="location.href='?client='+this.value">
        <option value="0">Tüm Dosyalar</option>
        <?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $clientFiltre == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
</div>

<?php if (!$archives): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg></div><div class="bos-baslik">Arşiv boş</div><div class="bos-metin">İlk dosyanızı yükleyerek arşivi oluşturmaya başlayın.</div></div>
<?php else: ?>
<div class="izgara izgara-auto" id="archiveIzgara">
    <?php foreach ($archives as $a):
        $image = in_array($a['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp']); ?>
    <div class="kart arsiv-kart" data-search="<?= e($a['name']) ?>" style="padding:0;overflow:hidden">
        <a href="uploads/<?= e($a['file_path']) ?>" target="_blank">
            <?php if ($image): ?>
            <div style="height:140px;background:var(--surface-2) url('uploads/<?= e($a['file_path']) ?>') center/cover"></div>
            <?php else: ?>
            <div style="height:140px;background:var(--surface-2);display:flex;align-items:center;justify-content:center"><div class="dosya-avatar" style="width:56px;height:56px;font-size:16px;background:var(--parlak);color:var(--marka)"><?= e(mb_strtoupper($a['extension'] ?: '?')) ?></div></div>
            <?php endif; ?>
        </a>
        <div style="padding:12px">
            <div class="satir-esnek arasi">
                <div style="min-width:0"><div class="hucre-ana kucuk" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($a['name']) ?></div><div class="hucre-alt"><?= format_size($a['size']) ?> · <?= e($a['project_name'] ?? $a['client_name'] ?? 'Genel') ?></div></div>
                <?php if (is_staff()): ?><button class="ikon-eylem tehlike" data-action="arsiv_sil" data-id="<?= $a['id'] ?>" data-approval="Silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalUpload">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Dosya Yükle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="archive_upload" data-refresh="evet">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Dosya <span class="zorunlu">*</span></label><input type="file" name="client" class="girdi" required><div class="form-ipucu">Maksimum 50MB. PHP/HTML/script dosyaları kabul edilmez.</div></div>
            <?php if ($clients): ?>
            <div class="form-grup"><label class="form-etiket">İlgili Dosya</label><select name="client_id" class="secim"><option value="">— Genel</option><?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $clientFiltre == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
            <?php elseif ($clientFiltre): ?><input type="hidden" name="client_id" value="<?= $clientFiltre ?>"><?php endif; ?>
            <div class="form-grup"><label class="form-etiket">Proje (opsiyonel)</label><select name="project_id" class="secim"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Yükle</button></div>
    </form></div>
</div>
<?php page_end(); ?>

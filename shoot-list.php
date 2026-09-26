<?php
/**
 * SADA One — Shoot List
 * Project name · date · people attending the shoot · equipment · shopping list · needs list
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/google-drive.php';
$driveReady = drive_configured();
$u = require_staff();

$historyShow = isset($_GET['history']);
// Default view: upcoming shoots PLUS recent past ones still awaiting the
// "everything uploaded" confirmation — that is exactly where the folder files
// and the confirm button live, so hiding them behind "history" buried the flow
$where_sql = $historyShow ? "1=1"
    : "(e.end IS NULL AND e.start >= CURDATE() - INTERVAL 1 DAY) OR e.end >= NOW() - INTERVAL 1 DAY
       OR (e.drive_status='pending' AND e.start >= NOW() - INTERVAL 30 DAY)";
$shoots = rows("SELECT e.*, p.name project_name, d.name client_name,
    (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ') FROM event_participants ek JOIN users u2 ON u2.id=ek.user_id WHERE ek.event_id=e.id) people,
    (SELECT GROUP_CONCAT(eq.name SEPARATOR ', ') FROM event_equipment ee JOIN equipment eq ON eq.id=ee.equipment_id WHERE ee.event_id=e.id) equipment_names
    FROM events e LEFT JOIN projects p ON p.id=e.project_id LEFT JOIN clients d ON d.id=p.client_id
    WHERE e.type='shoot' AND ($where_sql) ORDER BY e.start");

page_start('Çekim Listesi', 'shoots');
?>
<?php if (!$driveReady && is_admin()): ?>
<div class="card mb-3" style="border-color:var(--warning)"><div class="small">📁 Google Drive bağlı değil — çekim klasörleri, dosya listesi ve otomatik denetim için <a href="settings.php" style="color:var(--brand)">Ayarlar → Drive Entegrasyonu</a>'ndan bağlantı kurun.</div></div>
<?php endif; ?>
<div class="page-top">
    <div><div class="page-title">Çekim Listesi</div><div class="page-bottom"><?= $historyShow ? 'Tüm çekimler' : 'Yaklaşan çekimler' ?> — kim gidiyor, hangi ekipman, ne alınacak</div></div>
    <div class="page-top-action">
        <a href="<?= $historyShow ? 'shoot-list.php' : '?history=1' ?>" class="btn"><?= $historyShow ? 'Yaklaşanlar' : 'Geçmişi de Göster' ?></a>
        <a href="calendar.php" class="btn btn-brand"><?= icon('calendar', 15) ?> Prodüksiyon Takvimi</a>
    </div>
</div>

<?php if (!$shoots): ?>
<div class="empty-state">
    <div class="empty-icon"><?= icon('camera', 36) ?></div>
    <div class="empty-title">Yaklaşan çekim yok</div>
    <div class="empty-text">Prodüksiyon takviminden "çekim" türünde etkinlik oluşturduğunuzda burada listelenir.</div>
</div>
<?php else: ?>
<div class="vertical" style="gap:14px">
    <?php foreach ($shoots as $c): ?>
    <div class="card">
        <div class="row-flex between wrap mb-2" style="gap:10px">
            <div>
                <div class="card-title" style="font-size:16px"><?= e($c['title']) ?></div>
                <div class="cell-bottom mt-1"><?= $c['client_name'] ? e($c['client_name']) . ($c['project_name'] ? ' / ' . e($c['project_name']) : '') : e($c['project_name'] ?? '') ?></div>
            </div>
            <div class="row-flex" style="gap:8px">
                <span class="badge badge-type"><?= icon('calendar', 12) ?> <?= format_date($c['start'], true) ?><?= $c['end'] ? ' → ' . format_date($c['end'], true) : '' ?></span>
                <?php if (permission('budget_view') && $c['cost'] > 0): ?><span class="badge r-pending" title="Çekim maliyeti"><?= number_format((float)$c['cost'], 0, ',', '.') ?> ₺</span><?php endif; ?>
                <?php if ($c['drive_status'] === 'transferred'): ?>
                <span class="badge r-completed" title="Görüntüler Drive'da">📁 Aktarıldı</span>
                <?php if ($c['drive_link']): ?><a href="<?= e($c['drive_link']) ?>" target="_blank" class="mini-btn">Drive ↗</a><?php endif; ?>
                
                <?php elseif (strtotime($c['start']) < time()): ?>
                <span class="badge r-overdue" title="Görüntüler henüz Drive'da görünmüyor">📁 Aktarılmadı</span>
                <?php if ($c['drive_link']): ?><a href="<?= e($c['drive_link']) ?>" target="_blank" class="mini-btn">Klasöre yükle ↗</a>
                <?php elseif ($driveReady): ?><button class="mini-btn" data-action="drive_folder_create" data-id="<?= $c['id'] ?>" data-refresh="yes">Klasör oluştur</button><?php endif; ?>
                <button class="mini-btn" onclick="driveMark(<?= $c['id'] ?>)">Aktarıldı işaretle</button>
                <?php else: ?>
                <?php if ($c['drive_link']): ?><a href="<?= e($c['drive_link']) ?>" target="_blank" class="mini-btn" title="Çekim dosyaları bu klasöre yüklenecek">📁 Drive klasörü ↗</a>
                <?php elseif ($driveReady): ?><button class="mini-btn" data-action="drive_folder_create" data-id="<?= $c['id'] ?>" data-refresh="yes">📁 Klasör oluştur</button><?php endif; ?>
                <?php endif; ?>
                <?php if (permission('calendar_manage')): ?>
                <button class="btn btn-sm" onclick='ckEdit(<?= json_encode(['id' => $c['id'], 'shopping_list' => $c['shopping_list'], 'needs_list' => $c['needs_list']], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 13) ?> Listeyi Düzenle</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="cell-bottom mb-1"><?= icon('team', 13) ?> Çekime Gidecekler</div>
                <div class="small"><?= $c['people'] ? e($c['people']) : '<span class="text-muted">Katılımcı atanmadı</span>' ?></div>
            </div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="cell-bottom mb-1"><?= icon('camera', 13) ?> Ekipmanlar</div>
                <div class="small"><?= $c['equipment_names'] ? e($c['equipment_names']) : '<span class="text-muted">Ekipman bağlanmadı</span>' ?></div>
            </div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="cell-bottom mb-1">🛒 Alınacaklar</div>
                <div class="small" style="white-space:pre-wrap"><?= $c['shopping_list'] ? e($c['shopping_list']) : '<span class="text-muted">—</span>' ?></div>
            </div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="cell-bottom mb-1">📋 İhtiyaç Listesi</div>
                <div class="small" style="white-space:pre-wrap"><?= $c['needs_list'] ? e($c['needs_list']) : '<span class="text-muted">—</span>' ?></div>
            </div>
            <?php $linkedWork = rows("SELECT t.id, t.title FROM event_tasks et JOIN tasks t ON t.id=et.task_id WHERE et.event_id=? ORDER BY t.title", [$c['id']]); ?>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="cell-bottom mb-1">🎯 Bu çekimin işleri</div>
                <div class="small"><?php if ($linkedWork): foreach ($linkedWork as $wi => $lw): ?><?= $wi ? ', ' : '' ?><a href="task.php?id=<?= $lw['id'] ?>"><?= e($lw['title']) ?></a><?php endforeach; else: ?><span class="text-muted">Bağlı iş yok — işin sayfasındaki Çekim kartından bağlanır</span><?php endif; ?></div>
            </div>
        </div>
        <?php if ($c['drive_folder_id'] && $driveReady): ?>
        <div class="drive-section" data-drive-event="<?= $c['id'] ?>" data-drive-status="<?= $c['drive_status'] ?>" data-drive-folder="<?= e($c['drive_link'] ?: 'https://drive.google.com/drive/folders/' . $c['drive_folder_id']) ?>" style="display:none"></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-overlay" id="modalShootList">
    <div class="modal"><div class="modal-top"><div class="modal-title">Çekim Listesini Düzenle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="shoot_list_save">
        <input type="hidden" name="id" id="ck_id">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Alınacaklar</label><textarea name="shopping_list" id="ck_shopping_list" class="text-area" rows="4" placeholder="- Yedek pil&#10;- Gaffer bandı&#10;- Su ve atıştırmalık"></textarea></div>
            <div class="form-group"><label class="form-label">İhtiyaç Listesi</label><textarea name="needs_list" id="ck_needs" class="text-area" rows="4" placeholder="- Mekan izni teyidi&#10;- Prompter metni&#10;- Ek ışık kiralama"></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<script>
async function driveMark(id) {
    const link = prompt('Drive klasör/dosya linki (opsiyonel — boş bırakılabilir):', '');
    if (link === null) return;
    const j = await api('drive_mark', { id, drive_link: link });
    if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 500); }
}
function ckEdit(c) {
    document.getElementById('ck_id').value = c.id;
    document.getElementById('ck_shopping_list').value = c.shopping_list || '';
    document.getElementById('ck_needs').value = c.needs_list || '';
    modalOpen('modalShootList');
}
</script>
<script>
// Drive folder contents render as their own section at the bottom of each card.
// app.js (which defines api/esc) loads at the end of the body, so wait for it.
addEventListener('DOMContentLoaded', async () => {
// One batched request for every card: N parallel calls used to occupy N PHP
// processes on the server and a Google API round trip each, on every visit.
const sections = Array.from(document.querySelectorAll('.drive-section'));
if (!sections.length) return;
const bulk = await api('drive_files_batch', { ids: sections.map(b => b.dataset.driveEvent) }).catch(() => null);
if (!bulk || !bulk.ok) return;
sections.forEach(section => {
    const j = bulk.events[section.dataset.driveEvent];
    if (!j || !j.files.length) return;
    const summaryText = [];
    if (j.counts.video) summaryText.push('🎬 ' + j.counts.video + ' video');
    if (j.counts.image) summaryText.push('🖼️ ' + j.counts.image + ' fotoğraf');
    if (j.counts.other) summaryText.push('📄 ' + j.counts.other + ' dosya');
    let h = `<div class="row-flex between wrap mb-2" style="gap:8px">
        <div class="cell-bottom">📁 Drive Dosyaları — ${summaryText.join(' · ')}</div>
        <div class="row-flex" style="gap:8px">
            <a href="${esc(section.dataset.driveFolder)}" target="_blank" class="mini-btn">Klasörü aç ↗</a>`;
    if (section.dataset.driveStatus === 'pending') {
        h += `<button class="mini-btn" style="border-color:var(--success);color:var(--success)" data-action="drive_mark" data-id="${section.dataset.driveEvent}" data-confirm="Yüklenmesi gereken HER ŞEY klasörde mi? Çekim 'yüklendi' olarak işaretlenecek.">✔ Tümü yüklendi</button>`;
    }
    h += `</div></div><div class="drive-file-grid">`;
    h += j.files.slice(0, 12).map(d => {
        const icon = d.mime.includes('video') ? '🎬' : d.mime.includes('image') ? '🖼️' : d.mime.includes('folder') ? '📁' : '📄';
        const date = d.created ? new Date(d.created).toLocaleDateString('tr-TR') : '';
        return `<a href="${esc(d.link)}" target="_blank" class="drive-file-item" title="${esc(d.name)}">
            <span class="drive-file-icon">${icon}</span>
            <span class="drive-file-meta"><span class="drive-file-name">${esc(d.name)}</span><span class="cell-bottom">${date}</span></span></a>`;
    }).join('');
    h += `</div>`;
    if (j.files.length > 12) h += `<div class="small text-muted mt-1">+${j.files.length - 12} dosya daha — klasörü açın</div>`;
    section.innerHTML = h;
    section.style.display = '';
});
});</script>
<?php page_end(); ?>

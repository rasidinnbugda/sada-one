<?php
/**
 * SADA One — Studio Equipment Inventory
 * Asset tracking, custody, shoot linkage, and SD card lifecycle.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$equipment = rows("SELECT e.*, us.name custody_name, us.color custody_color, us.avatar custody_avatar, et.title event_title, et.start event_date
    FROM equipment e
    LEFT JOIN users us ON us.id=e.custody_user_id
    LEFT JOIN events et ON et.id=e.custody_event_id
    ORDER BY FIELD(e.category,'camera','lens','sd_card','tripod','light','audio','drone','accessory','other'), e.code, e.name");

$counts = ['in_studio' => 0, 'checked_out' => 0, 'on_shoot' => 0, 'faulty' => 0, 'in_maintenance' => 0];
$totalValue = 0;
foreach ($equipment as $ek) { $counts[$ek['status']]++; $totalValue += (float)$ek['price']; }

$team = rows("SELECT id, name FROM users WHERE role IN ('admin','pm','team','finance') AND is_active=1 ORDER BY name");
$can_manage = permission('equipment_manage');

page_start('Ekipman', 'equipment');
?>
<div class="page-top">
    <div><div class="page-title">Stüdyo Ekipmanları</div><div class="page-bottom"><?= count($equipment) ?> demirbaş — zimmet, çekim ve SD kart takibi</div></div>
    <?php if ($can_manage): ?>
    <div class="page-top-action"><button class="btn btn-brand" data-modal="modalEquipment" onclick="equipmentReset()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Ekipman Ekle</button></div>
    <?php endif; ?>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-value" style="color:var(--success)" data-counter="<?= $counts['in_studio'] ?>">0</div><div class="stat-label">Stüdyoda</div></div>
    <div class="stat-card"><div class="stat-value" style="color:var(--info)" data-counter="<?= $counts['checked_out'] ?>">0</div><div class="stat-label">Zimmette</div></div>
    <div class="stat-card"><div class="stat-value" style="color:var(--warning)" data-counter="<?= $counts['on_shoot'] ?>">0</div><div class="stat-label">Çekimde</div></div>
    <div class="stat-card"><div class="stat-value" style="color:var(--danger)" data-counter="<?= $counts['faulty'] + $counts['in_maintenance'] ?>">0</div><div class="stat-label">Arızalı / Bakımda</div></div>
    <?php if (permission('finance') && $totalValue > 0): ?>
    <div class="stat-card"><div class="stat-value" style="font-size:20px"><?= money($totalValue) ?></div><div class="stat-label">Toplam Demirbaş Değeri</div></div>
    <?php endif; ?>
</div>

<div class="filter-bar">
    <div class="search-box"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="input" placeholder="Ekipman ara..." data-search="#equipmentList .equipment-card"></div>
    <div class="pill-filter" data-pill-group="#equipmentList .equipment-card">
        <button class="pill active" data-value="">Tümü</button>
        <?php foreach (EQUIPMENT_CATEGORIES as $k => $v): ?><button class="pill" data-value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$equipment): ?>
<div class="empty-state"><div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/></svg></div><div class="empty-title">Envanter boş</div><div class="empty-text">Kamera, SD kart, tripod gibi demirbaşları ekleyerek stüdyo takibini başlatın.</div><?php if ($can_manage): ?><button class="btn btn-brand" data-modal="modalEquipment" onclick="equipmentReset()">İlk Ekipmanı Ekle</button><?php endif; ?></div>
<?php else: ?>
<div class="grid grid-auto" id="equipmentList">
    <?php foreach ($equipment as $ek):
        $statusColor = ['in_studio' => 'var(--success)', 'checked_out' => 'var(--info)', 'on_shoot' => 'var(--warning)', 'faulty' => 'var(--danger)', 'in_maintenance' => 'var(--danger)'][$ek['status']];
        $sdCard = $ek['category'] === 'sd_card'; ?>
    <div class="card equipment-card" data-filter="<?= $ek['category'] ?>" data-search="<?= e(($ek['code'] ?? '') . ' ' . $ek['name'] . ' ' . ($ek['sd_content'] ?? '') . ' ' . ($ek['custody_name'] ?? '')) ?>" style="padding:16px">
        <div class="row-flex between" style="align-items:flex-start;gap:10px">
            <div class="row-flex" style="gap:11px;min-width:0">
                <?php if ($ek['photo']): ?>
                <span style="width:46px;height:46px;border-radius:11px;background:url('uploads/<?= e($ek['photo']) ?>') center/cover;flex-shrink:0"></span>
                <?php else: ?>
                <span class="file-avatar" style="width:46px;height:46px;background:var(--bright);color:var(--brand)"><?= icon($ek['category'], 22) ?></span>
                <?php endif; ?>
                <div style="min-width:0">
                    <div class="bold" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $ek['code'] ? '<span style="color:var(--brand)">' . e($ek['code']) . '</span> · ' : '' ?><?= e($ek['name']) ?></div>
                    <div class="cell-bottom"><?= EQUIPMENT_CATEGORIES[$ek['category']] ?></div>
                </div>
            </div>
            <span class="badge" style="background:color-mix(in srgb, <?= $statusColor ?> 15%, transparent);color:<?= $statusColor ?>;flex-shrink:0"><?= EQUIPMENT_STATUSES[$ek['status']] ?></span>
        </div>

        <?php if ($ek['status'] === 'checked_out' && $ek['custody_name']): ?>
        <div class="row-flex mt-2" style="gap:8px"><?= avatar(['name' => $ek['custody_name'], 'color' => $ek['custody_color'], 'avatar' => $ek['custody_avatar']], 24) ?><span class="small"><?= e($ek['custody_name']) ?> üzerinde</span></div>
        <?php elseif ($ek['status'] === 'on_shoot'): ?>
        <div class="small mt-2 row-flex" style="gap:6px"><?= icon('video', 13) ?> <b><?= e($ek['event_title'] ?? 'Çekim') ?></b><?= $ek['event_date'] ? ' · ' . format_date(substr($ek['event_date'], 0, 10)) : '' ?><?= $ek['custody_name'] ? ' · ' . e($ek['custody_name']) : '' ?></div>
        <?php elseif (in_array($ek['status'], ['faulty', 'in_maintenance']) && $ek['fault_note']): ?>
        <div class="small mt-2" style="color:var(--danger)"><?= icon('warning', 12) ?> <?= e($ek['fault_note']) ?></div>
        <?php endif; ?>

        <?php if ($sdCard): ?>
        <!-- SD card lifecycle panel -->
        <div class="mt-2" style="padding:10px 12px;background:var(--surface-2);border-radius:10px">
            <div class="row-flex between">
                <span class="small bold row-flex" style="gap:6px"><?= icon('sd_card', 13) ?> <?= SD_STATUSES[$ek['sd_status'] ?: 'empty'] ?></span>
                <span class="row-flex" style="gap:4px">
                    <?php if (($ek['sd_status'] ?: 'empty') === 'empty'): ?>
                    <button class="mini-btn" onclick="sdFull(<?= $ek['id'] ?>)">Dolu işaretle</button>
                    <?php elseif ($ek['sd_status'] === 'full'): ?>
                    <button class="mini-btn" onclick="sdTransfer(<?= $ek['id'] ?>)">Drive'a aktarıldı</button>
                    <?php else: ?>
                    <button class="mini-btn" data-action="sd_update" data-id="<?= $ek['id'] ?>" data-operation="clear" data-confirm="Kart boşaltıldı olarak işaretlensin mi? (İçerik geçmişi hareket kaydında saklanır)">Boşaltıldı ✓</button>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($ek['sd_content']): ?><div class="cell-bottom mt-1 row-flex" style="gap:5px"><?= icon('video', 12) ?> <?= e($ek['sd_content']) ?></div><?php endif; ?>
            <?php if ($ek['sd_drive_link']): ?><div class="cell-bottom mt-1"><a href="<?= e($ek['sd_drive_link']) ?>" target="_blank" style="color:var(--brand)"><?= icon('folder', 12) ?> Drive klasörü →</a></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="row-flex wrap mt-2" style="gap:6px">
            <?php if ($ek['status'] === 'in_studio'): ?>
            <button class="btn btn-sm" data-action="equipment_custody" data-id="<?= $ek['id'] ?>">Zimmet Al</button>
            <?php if ($can_manage): ?><button class="btn btn-sm btn-ghost" onclick="custodyGive(<?= $ek['id'] ?>, '<?= e($ek['name']) ?>')">Başkasına Ver</button><?php endif; ?>
            <?php elseif (in_array($ek['status'], ['checked_out', 'on_shoot']) && ($ek['custody_user_id'] == $u['id'] || $can_manage)): ?>
            <button class="btn btn-sm" style="color:var(--success)" data-action="equipment_return" data-id="<?= $ek['id'] ?>">İade Et</button>
            <?php endif; ?>
            <?php if (!in_array($ek['status'], ['faulty', 'in_maintenance'])): ?>
            <button class="btn btn-sm btn-ghost" onclick="faultNotify(<?= $ek['id'] ?>)"><?= icon('warning', 13) ?> Arıza</button>
            <?php else: ?>
            <button class="btn btn-sm" style="color:var(--success)" data-action="equipment_fault" data-id="<?= $ek['id'] ?>" data-status="in_studio" data-confirm="Ekipman kullanıma dönsün mü?">✓ Düzeldi</button>
            <?php endif; ?>
            <button class="btn btn-sm btn-ghost" onclick="historyShow(<?= $ek['id'] ?>, '<?= e(($ek['code'] ? $ek['code'] . ' — ' : '') . $ek['name']) ?>')">Geçmiş</button>
            <?php if ($can_manage): ?>
            <button class="icon-action" onclick='equipmentEdit(<?= json_encode($ek, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)' title="Düzenle"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($can_manage): ?>
<!-- Add/edit equipment -->
<div class="modal-overlay" id="modalEquipment">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="equipmentTitle">Yeni Ekipman</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="equipment_save" id="equipmentForm">
        <input type="hidden" name="id" id="e_id">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Kod / Etiket No</label><input name="code" id="e_code" class="input" placeholder="Örn. CAM-01, SD-04"></div>
                <div class="form-group"><label class="form-label">Kategori</label><select name="category" id="e_category" class="select"><?php foreach (EQUIPMENT_CATEGORIES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group"><label class="form-label">Ad <span class="required">*</span></label><input name="name" id="e_name" class="input" required placeholder="Örn. Sony A7 IV"></div>
            <div class="form-group"><label class="form-label">Fotoğraf</label><input type="file" name="photo" class="input" accept="image/*"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Satın Alma Tarihi</label><input type="date" name="purchase_date" id="e_purchase" class="input"></div>
                <div class="form-group"><label class="form-label">Fiyat (₺)</label><input name="price" id="e_price" class="input" placeholder="0,00"></div>
            </div>
            <div class="form-group"><label class="form-label">Not</label><input name="description" id="e_description" class="input" placeholder="Seri no, aksesuar bilgisi vb."></div>
        </div>
        <div class="modal-alt">
            <button type="button" class="btn btn-danger hidden" id="equipmentDeleteBtn" style="margin-right:auto">Sil</button>
            <button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button>
        </div>
    </form></div>
</div>
<?php endif; ?>

<!-- Assign custody (to someone else) -->
<div class="modal-overlay" id="modalCustody">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="custodyTitle">Zimmet Ver</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="equipment_custody">
        <input type="hidden" name="id" id="z_id">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Kime?</label><select name="user_id" class="select"><?php foreach ($team as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Not</label><input name="description" class="input" placeholder="Örn. hafta sonu çekimi için"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Zimmetle</button></div>
    </form></div>
</div>

<!-- Report fault -->
<div class="modal-overlay" id="modalFault">
    <div class="modal"><div class="modal-top"><div class="modal-title">Arıza / Bakım Bildir</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="equipment_fault">
        <input type="hidden" name="id" id="a_id">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Durum</label><select name="status" class="select"><option value="faulty">Arızalı</option><option value="in_maintenance">Bakımda</option></select></div>
            <div class="form-group"><label class="form-label">Açıklama <span class="required">*</span></label><textarea name="note" class="text-area" required placeholder="Arıza/bakım detayı..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<!-- SD: mark as full -->
<div class="modal-overlay" id="modalSdFull">
    <div class="modal"><div class="modal-top"><div class="modal-title">Kartı Dolu İşaretle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="sd_update">
        <input type="hidden" name="id" id="sd_id"><input type="hidden" name="operation" value="full">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Hangi çekim / içerik? <span class="required">*</span></label><input name="content" class="input" required placeholder="Örn. Marka X fuar çekimi, 15 Temmuz"><div class="form-hint">Bu bilgi kartın geçmişinde arşivlenir.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<!-- SD: transferred to Drive -->
<div class="modal-overlay" id="modalSdTransfer">
    <div class="modal"><div class="modal-top"><div class="modal-title">Drive'a Aktarıldı</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="sd_update">
        <input type="hidden" name="id" id="sda_id"><input type="hidden" name="operation" value="transferred">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Drive Klasör Linki</label><input name="drive_link" class="input" placeholder="https://drive.google.com/..."><div class="form-hint">Opsiyonel — girilirse kartın üzerinde tıklanabilir link görünür.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<!-- Activity history -->
<div class="modal-overlay" id="modalHistory">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="historyTitle">Hareket Geçmişi</div><button class="modal-close" data-modal-close>✕</button></div>
    <div class="modal-body" id="historyBody"><div class="empty-mini">Yükleniyor...</div></div>
    </div>
</div>

<script>
const logs = <?= json_encode(array_reduce(rows("SELECT h.*, u1.name actor_name, u2.name target, et.title event FROM equipment_logs h LEFT JOIN users u1 ON u1.id=h.user_id LEFT JOIN users u2 ON u2.id=h.target_user_id LEFT JOIN events et ON et.id=h.event_id ORDER BY h.id DESC"), function ($acc, $h) { $acc[$h['equipment_id']][] = $h; return $acc; }, []), JSON_UNESCAPED_UNICODE) ?>;
const logName = <?= json_encode(EQUIPMENT_LOG_TYPES, JSON_UNESCAPED_UNICODE) ?>;

function custodyGive(id, name) { document.getElementById('z_id').value = id; document.getElementById('custodyTitle').textContent = name + ' — Zimmet Ver'; modalOpen('modalCustody'); }
function faultNotify(id) { document.getElementById('a_id').value = id; modalOpen('modalFault'); }
function sdFull(id) { document.getElementById('sd_id').value = id; modalOpen('modalSdFull'); }
function sdTransfer(id) { document.getElementById('sda_id').value = id; modalOpen('modalSdTransfer'); }
function historyShow(id, title) {
    document.getElementById('historyTitle').textContent = title + ' — Geçmiş';
    const records = logs[id] || [];
    let h = records.length ? '<div class="activity-feed">' : '<div class="empty-mini">Henüz hareket yok</div>';
    records.forEach(k => {
        let text = `<b>${k.actor_name || '?'}</b> ${logName[k.type] || k.type}`;
        if (k.target && k.type === 'custody') text = `<b>${k.target}</b> zimmetine verildi (${k.actor_name})`;
        if (k.event) text += ` — ${k.event}`;
        if (k.description) text += `<div class="cell-bottom" style="margin-top:2px">${k.description.replace(/</g, '&lt;')}</div>`;
        h += `<div class="feed-item"><div class="feed-text">${text}</div><div class="feed-time">${new Date(k.created.replace(' ', 'T')).toLocaleString('tr-TR', { dateStyle: 'medium', timeStyle: 'short' })}</div></div>`;
    });
    if (records.length) h += '</div>';
    document.getElementById('historyBody').innerHTML = h;
    modalOpen('modalHistory');
}
<?php if ($can_manage): ?>
function equipmentReset() {
    document.getElementById('equipmentForm').reset();
    document.getElementById('e_id').value = '';
    document.getElementById('equipmentTitle').textContent = 'Yeni Ekipman';
    document.getElementById('equipmentDeleteBtn').classList.add('hidden');
}
function equipmentEdit(ek) {
    document.getElementById('equipmentTitle').textContent = 'Ekipmanı Düzenle';
    document.getElementById('e_id').value = ek.id;
    document.getElementById('e_code').value = ek.code || '';
    document.getElementById('e_category').value = ek.category;
    document.getElementById('e_name').value = ek.name;
    document.getElementById('e_purchase').value = ek.purchase_date || '';
    document.getElementById('e_price').value = ek.price || 0;
    document.getElementById('e_description').value = ek.description || '';
    const deleteBtn = document.getElementById('equipmentDeleteBtn');
    deleteBtn.classList.remove('hidden');
    deleteBtn.onclick = async () => {
        if (!confirm('Ekipman ve tüm hareket geçmişi silinsin mi?')) return;
        const j = await api('equipment_delete', { id: ek.id });
        if (j.ok) { toast(j.message, 'success'); setTimeout(() => location.reload(), 550); }
    };
    modalOpen('modalEquipment');
}
<?php endif; ?>
</script>
<?php page_end(); ?>

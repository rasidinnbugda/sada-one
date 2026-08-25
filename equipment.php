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
    ORDER BY FIELD(e.category,'kamera','lens','sd_kart','tripod','isik','ses','drone','aksesuar','diger'), e.code, e.name");

$counts = ['studyoda' => 0, 'zimmette' => 0, 'cekimde' => 0, 'arizali' => 0, 'bakimda' => 0];
$totalValue = 0;
foreach ($equipment as $ek) { $counts[$ek['status']]++; $totalValue += (float)$ek['price']; }

$team = rows("SELECT id, name FROM users WHERE role IN ('yonetici','pm','ekip','finans') AND is_active=1 ORDER BY name");
$can_manage = permission('ekipman_yonet');

page_start('Ekipman', 'ekipman');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Stüdyo Ekipmanları</div><div class="sayfa-alt"><?= count($equipment) ?> demirbaş — zimmet, çekim ve SD kart takibi</div></div>
    <?php if ($can_manage): ?>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalEquipment" onclick="equipmentSifirla()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Ekipman Ekle</button></div>
    <?php endif; ?>
</div>

<div class="stat-izgara">
    <div class="stat-kart"><div class="stat-deger" style="color:var(--basari)" data-counter="<?= $counts['studyoda'] ?>">0</div><div class="stat-etiket">Stüdyoda</div></div>
    <div class="stat-kart"><div class="stat-deger" style="color:var(--bilgi)" data-counter="<?= $counts['zimmette'] ?>">0</div><div class="stat-etiket">Zimmette</div></div>
    <div class="stat-kart"><div class="stat-deger" style="color:var(--uyari)" data-counter="<?= $counts['cekimde'] ?>">0</div><div class="stat-etiket">Çekimde</div></div>
    <div class="stat-kart"><div class="stat-deger" style="color:var(--tehlike)" data-counter="<?= $counts['arizali'] + $counts['bakimda'] ?>">0</div><div class="stat-etiket">Arızalı / Bakımda</div></div>
    <?php if (permission('finans') && $totalValue > 0): ?>
    <div class="stat-kart"><div class="stat-deger" style="font-size:20px"><?= money($totalValue) ?></div><div class="stat-etiket">Toplam Demirbaş Değeri</div></div>
    <?php endif; ?>
</div>

<div class="filtre-bar">
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="Ekipman ara..." data-search="#ekipmanListe .ekipman-kart"></div>
    <div class="pill-filtre" data-pill-grup="#ekipmanListe .ekipman-kart">
        <button class="pill aktif" data-setting_value="">Tümü</button>
        <?php foreach (EQUIPMENT_CATEGORIES as $k => $v): ?><button class="pill" data-setting_value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$equipment): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/></svg></div><div class="bos-baslik">Envanter boş</div><div class="bos-metin">Kamera, SD kart, tripod gibi demirbaşları ekleyerek stüdyo takibini başlatın.</div><?php if ($can_manage): ?><button class="btn btn-marka" data-modal="modalEquipment" onclick="equipmentSifirla()">İlk Ekipmanı Ekle</button><?php endif; ?></div>
<?php else: ?>
<div class="izgara izgara-auto" id="equipmentList">
    <?php foreach ($equipment as $ek):
        $statusColor = ['studyoda' => 'var(--basari)', 'zimmette' => 'var(--info)', 'cekimde' => 'var(--warning)', 'arizali' => 'var(--tehlike)', 'bakimda' => 'var(--tehlike)'][$ek['status']];
        $sdCard = $ek['category'] === 'sd_kart'; ?>
    <div class="kart ekipman-kart" data-filter="<?= $ek['category'] ?>" data-search="<?= e(($ek['code'] ?? '') . ' ' . $ek['name'] . ' ' . ($ek['sd_content'] ?? '') . ' ' . ($ek['custody_name'] ?? '')) ?>" style="padding:16px">
        <div class="satir-esnek arasi" style="align-items:flex-start;gap:10px">
            <div class="satir-esnek" style="gap:11px;min-width:0">
                <?php if ($ek['photo']): ?>
                <span style="width:46px;height:46px;border-radius:11px;background:url('uploads/<?= e($ek['photo']) ?>') center/cover;flex-shrink:0"></span>
                <?php else: ?>
                <span class="dosya-avatar" style="width:46px;height:46px;background:var(--parlak);color:var(--marka)"><?= icon($ek['category'], 22) ?></span>
                <?php endif; ?>
                <div style="min-width:0">
                    <div class="kalin" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $ek['code'] ? '<span style="color:var(--marka)">' . e($ek['code']) . '</span> · ' : '' ?><?= e($ek['name']) ?></div>
                    <div class="hucre-alt"><?= EQUIPMENT_CATEGORIES[$ek['category']] ?></div>
                </div>
            </div>
            <span class="rozet" style="background:color-mix(in srgb, <?= $statusColor ?> 15%, transparent);color:<?= $statusColor ?>;flex-shrink:0"><?= EKIPMAN_DURUMLARI[$ek['status']] ?></span>
        </div>

        <?php if ($ek['status'] === 'zimmette' && $ek['custody_name']): ?>
        <div class="satir-esnek mt-2" style="gap:8px"><?= avatar(['name' => $ek['custody_name'], 'color' => $ek['custody_color'], 'avatar' => $ek['custody_avatar']], 24) ?><span class="kucuk"><?= e($ek['custody_name']) ?> üzerinde</span></div>
        <?php elseif ($ek['status'] === 'cekimde'): ?>
        <div class="kucuk mt-2 satir-esnek" style="gap:6px"><?= icon('video', 13) ?> <b><?= e($ek['event_title'] ?? 'Çekim') ?></b><?= $ek['event_date'] ? ' · ' . format_date(substr($ek['event_date'], 0, 10)) : '' ?><?= $ek['custody_name'] ? ' · ' . e($ek['custody_name']) : '' ?></div>
        <?php elseif (in_array($ek['status'], ['arizali', 'bakimda']) && $ek['fault_note']): ?>
        <div class="kucuk mt-2" style="color:var(--tehlike)"><?= icon('warning', 12) ?> <?= e($ek['fault_note']) ?></div>
        <?php endif; ?>

        <?php if ($sdCard): ?>
        <!-- SD card lifecycle panel -->
        <div class="mt-2" style="padding:10px 12px;background:var(--surface-2);border-radius:10px">
            <div class="satir-esnek arasi">
                <span class="kucuk kalin satir-esnek" style="gap:6px"><?= icon('sd_kart', 13) ?> <?= SD_DURUMLARI[$ek['sd_status'] ?: 'bos'] ?></span>
                <span class="satir-esnek" style="gap:4px">
                    <?php if (($ek['sd_status'] ?: 'bos') === 'bos'): ?>
                    <button class="mini-btn" onclick="sdFull(<?= $ek['id'] ?>)">Dolu işaretle</button>
                    <?php elseif ($ek['sd_status'] === 'dolu'): ?>
                    <button class="mini-btn" onclick="sdAktar(<?= $ek['id'] ?>)">Drive'a aktarıldı</button>
                    <?php else: ?>
                    <button class="mini-btn" data-action="sd_update_row" data-id="<?= $ek['id'] ?>" data-operation="bosalt" data-approval="Kart boşaltıldı olarak işaretlensin mi? (İçerik geçmişi hareket kaydında saklanır)">Boşaltıldı ✓</button>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($ek['sd_content']): ?><div class="hucre-alt mt-1 satir-esnek" style="gap:5px"><?= icon('video', 12) ?> <?= e($ek['sd_content']) ?></div><?php endif; ?>
            <?php if ($ek['sd_drive_link']): ?><div class="hucre-alt mt-1"><a href="<?= e($ek['sd_drive_link']) ?>" target="_blank" style="color:var(--marka)"><?= icon('klasor', 12) ?> Drive klasörü →</a></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="satir-esnek sarma mt-2" style="gap:6px">
            <?php if ($ek['status'] === 'studyoda'): ?>
            <button class="btn btn-sm" data-action="equipment_custody" data-id="<?= $ek['id'] ?>">Zimmet Al</button>
            <?php if ($can_manage): ?><button class="btn btn-sm btn-hayalet" onclick="custodyGive(<?= $ek['id'] ?>, '<?= e($ek['name']) ?>')">Başkasına Ver</button><?php endif; ?>
            <?php elseif (in_array($ek['status'], ['zimmette', 'cekimde']) && ($ek['custody_user_id'] == $u['id'] || $can_manage)): ?>
            <button class="btn btn-sm" style="color:var(--basari)" data-action="equipment_return" data-id="<?= $ek['id'] ?>">İade Et</button>
            <?php endif; ?>
            <?php if (!in_array($ek['status'], ['arizali', 'bakimda'])): ?>
            <button class="btn btn-sm btn-hayalet" onclick="faultNotify(<?= $ek['id'] ?>)"><?= icon('warning', 13) ?> Arıza</button>
            <?php else: ?>
            <button class="btn btn-sm" style="color:var(--basari)" data-action="equipment_fault" data-id="<?= $ek['id'] ?>" data-status="studyoda" data-approval="Ekipman kullanıma dönsün mü?">✓ Düzeldi</button>
            <?php endif; ?>
            <button class="btn btn-sm btn-hayalet" onclick="historyShow(<?= $ek['id'] ?>, '<?= e(($ek['code'] ? $ek['code'] . ' — ' : '') . $ek['name']) ?>')">Geçmiş</button>
            <?php if ($can_manage): ?>
            <button class="ikon-eylem" onclick='equipmentEdit(<?= json_encode($ek, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)' title="Düzenle"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($can_manage): ?>
<!-- Add/edit equipment -->
<div class="modal-katman" id="modalEquipment">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="equipmentTitle">Yeni Ekipman</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="equipment_save" id="equipmentForm">
        <input type="hidden" name="id" id="e_id">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Kod / Etiket No</label><input name="code" id="e_code" class="girdi" placeholder="Örn. CAM-01, SD-04"></div>
                <div class="form-grup"><label class="form-etiket">Kategori</label><select name="category" id="e_category" class="secim"><?php foreach (EQUIPMENT_CATEGORIES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Ad <span class="zorunlu">*</span></label><input name="name" id="e_name" class="girdi" required placeholder="Örn. Sony A7 IV"></div>
            <div class="form-grup"><label class="form-etiket">Fotoğraf</label><input type="file" name="photo" class="girdi" accept="image/*"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Satın Alma Tarihi</label><input type="date" name="purchase_date" id="e_purchase" class="girdi"></div>
                <div class="form-grup"><label class="form-etiket">Fiyat (₺)</label><input name="price" id="e_price" class="girdi" placeholder="0,00"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Not</label><input name="description" id="e_description" class="girdi" placeholder="Seri no, aksesuar bilgisi vb."></div>
        </div>
        <div class="modal-alt">
            <button type="button" class="btn btn-tehlike gizli" id="equipmentDeleteBtn" style="margin-right:auto">Sil</button>
            <button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button>
        </div>
    </form></div>
</div>
<?php endif; ?>

<!-- Assign custody (to someone else) -->
<div class="modal-katman" id="modalCustody">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="custodyTitle">Zimmet Ver</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="equipment_custody">
        <input type="hidden" name="id" id="z_id">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Kime?</label><select name="user_id" class="secim"><?php foreach ($team as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-grup"><label class="form-etiket">Not</label><input name="description" class="girdi" placeholder="Örn. hafta sonu çekimi için"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Zimmetle</button></div>
    </form></div>
</div>

<!-- Report fault -->
<div class="modal-katman" id="modalFault">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Arıza / Bakım Bildir</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="equipment_fault">
        <input type="hidden" name="id" id="a_id">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Durum</label><select name="status" class="secim"><option value="arizali">Arızalı</option><option value="bakimda">Bakımda</option></select></div>
            <div class="form-grup"><label class="form-etiket">Açıklama <span class="zorunlu">*</span></label><textarea name="not" class="metin-alani" required placeholder="Arıza/bakım detayı..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- SD: mark as full -->
<div class="modal-katman" id="modalSdFull">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Kartı Dolu İşaretle</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="sd_update_row">
        <input type="hidden" name="id" id="sd_id"><input type="hidden" name="operation" value="dolu">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Hangi çekim / içerik? <span class="zorunlu">*</span></label><input name="content" class="girdi" required placeholder="Örn. Marka X fuar çekimi, 15 Temmuz"><div class="form-ipucu">Bu bilgi kartın geçmişinde arşivlenir.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- SD: transferred to Drive -->
<div class="modal-katman" id="modalSdAktar">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Drive'a Aktarıldı</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="sd_update_row">
        <input type="hidden" name="id" id="sda_id"><input type="hidden" name="operation" value="aktarildi">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Drive Klasör Linki</label><input name="drive_link" class="girdi" placeholder="https://drive.google.com/..."><div class="form-ipucu">Opsiyonel — girilirse kartın üzerinde tıklanabilir link görünür.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- Activity history -->
<div class="modal-katman" id="modalHistory">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="historyTitle">Hareket Geçmişi</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <div class="modal-govde" id="historyBody"><div class="bos-mini">Yükleniyor...</div></div>
    </div>
</div>

<script>
const logs = <?= json_encode(array_reduce(rows("SELECT h.*, u1.name yapan, u2.name target, et.title event FROM equipment_logs h LEFT JOIN users u1 ON u1.id=h.user_id LEFT JOIN users u2 ON u2.id=h.target_user_id LEFT JOIN events et ON et.id=h.event_id ORDER BY h.id DESC"), function ($acc, $h) { $acc[$h['equipment_id']][] = $h; return $acc; }, []), JSON_UNESCAPED_UNICODE) ?>;
const logName = <?= json_encode(EKIPMAN_HAREKET_TURLERI, JSON_UNESCAPED_UNICODE) ?>;

function custodyGive(id, name) { document.getElementById('z_id').value = id; document.getElementById('custodyTitle').textContent = name + ' — Zimmet Ver'; modalOpen('modalCustody'); }
function faultNotify(id) { document.getElementById('a_id').value = id; modalOpen('modalFault'); }
function sdFull(id) { document.getElementById('sd_id').value = id; modalOpen('modalSdFull'); }
function sdAktar(id) { document.getElementById('sda_id').value = id; modalOpen('modalSdAktar'); }
function historyShow(id, title) {
    document.getElementById('historyTitle').textContent = title + ' — Geçmiş';
    const kayitlar = logs[id] || [];
    let h = kayitlar.length ? '<div class="zaman-tunel">' : '<div class="bos-mini">Henüz hareket yok</div>';
    kayitlar.forEach(k => {
        let text = `<b>${k.yapan || '?'}</b> ${logName[k.type] || k.type}`;
        if (k.target && k.type === 'zimmet') text = `<b>${k.target}</b> zimmetine verildi (${k.yapan})`;
        if (k.event) text += ` — ${k.event}`;
        if (k.description) text += `<div class="hucre-alt" style="margin-top:2px">${k.description.replace(/</g, '&lt;')}</div>`;
        h += `<div class="tunel-oge"><div class="tunel-metin">${text}</div><div class="tunel-zaman">${new Date(k.created.replace(' ', 'T')).toLocaleString('tr-TR', { dateStyle: 'medium', timeStyle: 'short' })}</div></div>`;
    });
    if (kayitlar.length) h += '</div>';
    document.getElementById('historyBody').innerHTML = h;
    modalOpen('modalHistory');
}
<?php if ($can_manage): ?>
function equipmentSifirla() {
    document.getElementById('equipmentForm').reset();
    document.getElementById('e_id').value = '';
    document.getElementById('equipmentTitle').textContent = 'Yeni Ekipman';
    document.getElementById('equipmentDeleteBtn').classList.add('gizli');
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
    deleteBtn.classList.remove('gizli');
    deleteBtn.onclick = async () => {
        if (!confirm('Ekipman ve tüm hareket geçmişi silinsin mi?')) return;
        const j = await api('equipment_delete', { id: ek.id });
        if (j.ok) { toast(j.message, 'basari'); setTimeout(() => location.reload(), 550); }
    };
    modalOpen('modalEquipment');
}
<?php endif; ?>
</script>
<?php page_end(); ?>

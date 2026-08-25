<?php
/**
 * SADA One — Shoot List
 * Project name · date · people attending the shoot · equipment · shopping list · needs list
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$historyShow = isset($_GET['history']);
$where_sql = $historyShow ? "1=1" : "(e.end IS NULL AND e.start >= CURDATE() - INTERVAL 1 DAY) OR e.end >= NOW() - INTERVAL 1 DAY";
$shoots = rows("SELECT e.*, p.name project_name, d.name client_name,
    (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ') FROM event_participants ek JOIN users u2 ON u2.id=ek.user_id WHERE ek.event_id=e.id) people,
    (SELECT GROUP_CONCAT(eq.name SEPARATOR ', ') FROM event_equipment ee JOIN equipment eq ON eq.id=ee.equipment_id WHERE ee.event_id=e.id) equipment_names
    FROM events e LEFT JOIN projects p ON p.id=e.project_id LEFT JOIN clients d ON d.id=p.client_id
    WHERE e.type='cekim' AND ($where_sql) ORDER BY e.start");

page_start('Çekim Listesi', 'shoots');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Çekim Listesi</div><div class="sayfa-alt"><?= $historyShow ? 'Tüm çekimler' : 'Yaklaşan çekimler' ?> — kim gidiyor, hangi ekipman, ne alınacak</div></div>
    <div class="sayfa-ust-aksiyon">
        <a href="<?= $historyShow ? 'shoot-list.php' : '?history=1' ?>" class="btn"><?= $historyShow ? 'Yaklaşanlar' : 'Geçmişi de Göster' ?></a>
        <a href="calendar.php" class="btn btn-marka"><?= icon('calendar', 15) ?> Prodüksiyon Takvimi</a>
    </div>
</div>

<?php if (!$shoots): ?>
<div class="bos-durum">
    <div class="bos-ikon"><?= icon('kamera', 36) ?></div>
    <div class="bos-baslik">Yaklaşan çekim yok</div>
    <div class="bos-metin">Prodüksiyon takviminden "çekim" türünde etkinlik oluşturduğunuzda burada listelenir.</div>
</div>
<?php else: ?>
<div class="dikey" style="gap:14px">
    <?php foreach ($shoots as $c): ?>
    <div class="kart">
        <div class="satir-esnek arasi sarma mb-2" style="gap:10px">
            <div>
                <div class="kart-baslik" style="font-size:16px"><?= e($c['title']) ?></div>
                <div class="hucre-alt mt-1"><?= $c['client_name'] ? e($c['client_name']) . ($c['project_name'] ? ' / ' . e($c['project_name']) : '') : e($c['project_name'] ?? '') ?></div>
            </div>
            <div class="satir-esnek" style="gap:8px">
                <span class="rozet rozet-tur"><?= icon('calendar', 12) ?> <?= format_date($c['start'], true) ?><?= $c['end'] ? ' → ' . format_date($c['end'], true) : '' ?></span>
                <?php if (permission('butce_gor') && $c['cost'] > 0): ?><span class="rozet r-bekliyor" title="Çekim maliyeti"><?= number_format((float)$c['cost'], 0, ',', '.') ?> ₺</span><?php endif; ?>
                <?php if ($c['drive_status'] === 'aktarildi'): ?>
                <span class="rozet r-tamamlandi" title="Görüntüler Drive'da">📁 Aktarıldı</span>
                <?php if ($c['drive_link']): ?><a href="<?= e($c['drive_link']) ?>" target="_blank" class="mini-btn">Drive ↗</a><?php endif; ?>
                <?php elseif (strtotime($c['start']) < time()): ?>
                <span class="rozet r-gecikti" title="Görüntüler henüz Drive'da görünmüyor">📁 Aktarılmadı</span>
                <button class="mini-btn" onclick="driveMark(<?= $c['id'] ?>)">Aktarıldı işaretle</button>
                <?php endif; ?>
                <?php if (permission('takvim_yonet')): ?>
                <button class="btn btn-sm" onclick='ckDuzenle(<?= json_encode(['id' => $c['id'], 'shopping_list' => $c['shopping_list'], 'needs_list' => $c['needs_list']], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= icon('item', 13) ?> Listeyi Düzenle</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="izgara" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="hucre-alt mb-1"><?= icon('team', 13) ?> Çekime Gidecekler</div>
                <div class="kucuk"><?= $c['people'] ? e($c['people']) : '<span class="metin-muted">Katılımcı atanmadı</span>' ?></div>
            </div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="hucre-alt mb-1"><?= icon('kamera', 13) ?> Ekipmanlar</div>
                <div class="kucuk"><?= $c['equipment_names'] ? e($c['equipment_names']) : '<span class="metin-muted">Ekipman bağlanmadı</span>' ?></div>
            </div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="hucre-alt mb-1">🛒 Alınacaklar</div>
                <div class="kucuk" style="white-space:pre-wrap"><?= $c['shopping_list'] ? e($c['shopping_list']) : '<span class="metin-muted">—</span>' ?></div>
            </div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="hucre-alt mb-1">📋 İhtiyaç Listesi</div>
                <div class="kucuk" style="white-space:pre-wrap"><?= $c['needs_list'] ? e($c['needs_list']) : '<span class="metin-muted">—</span>' ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalShootList">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Çekim Listesini Düzenle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="shoot_list_save">
        <input type="hidden" name="id" id="ck_id">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Alınacaklar</label><textarea name="shopping_list" id="ck_shopping_list" class="metin-alani" rows="4" placeholder="- Yedek pil&#10;- Gaffer bandı&#10;- Su ve atıştırmalık"></textarea></div>
            <div class="form-grup"><label class="form-etiket">İhtiyaç Listesi</label><textarea name="needs_list" id="ck_needs" class="metin-alani" rows="4" placeholder="- Mekan izni teyidi&#10;- Prompter metni&#10;- Ek ışık kiralama"></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
async function driveMark(id) {
    const link = prompt('Drive klasör/dosya linki (opsiyonel — boş bırakılabilir):', '');
    if (link === null) return;
    const j = await api('drive_mark', { id, drive_link: link });
    if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(() => location.reload(), 500); }
}
function ckEdit(c) {
    document.getElementById('ck_id').value = c.id;
    document.getElementById('ck_shopping_list').value = c.shopping_list || '';
    document.getElementById('ck_needs').value = c.needs_list || '';
    modalOpen('modalShootList');
}
</script>
<?php page_end(); ?>

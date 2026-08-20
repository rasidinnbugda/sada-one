<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

// Ratings given by the customer (per approval)
$verilenRatings = is_customer()
    ? array_column(rows("SELECT ref_id, rating FROM ratings WHERE ref_type='onay' AND user_id=?", [$u['id']]), 'rating', 'ref_id')
    : [];

if (is_staff()) {
    $approvals = rows("SELECT o.*, p.name project_name, d.name client_name, ug.name sender_name FROM approvals o JOIN projects p ON p.id=o.project_id JOIN clients d ON d.id=p.client_id LEFT JOIN users ug ON ug.id=o.sender_id ORDER BY FIELD(o.status,'bekliyor','revize','onaylandi','reddedildi'), o.id DESC");
} else {
    [$in, $p] = in_clause(customer_client_ids());
    $approvals = rows("SELECT o.*, p.name project_name, d.name client_name, ug.name sender_name FROM approvals o JOIN projects p ON p.id=o.project_id JOIN clients d ON d.id=p.client_id LEFT JOIN users ug ON ug.id=o.sender_id WHERE p.client_id IN $in ORDER BY FIELD(o.status,'bekliyor','revize','onaylandi','reddedildi'), o.id DESC", $p);
}

page_start('Onaylar', 'approvals');
?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik">Onay Süreçleri</div>
        <div class="sayfa-alt"><?= is_customer() ? 'Onayınızı bekleyen içerikler' : 'Tüm projelerdeki onay süreçleri' ?></div>
    </div>
</div>

<div class="filtre-bar">
    <div class="pill-filtre" data-pill-grup="#onayListe .onay-kart">
        <button class="pill aktif" data-setting_value="">Tümü</button>
        <?php foreach (APPROVAL_STATUSES as $k => $v): ?><button class="pill" data-setting_value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$approvals): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="bos-baslik">Onay süreci yok</div><div class="bos-metin"><?= is_customer() ? 'Şu an onayınızı bekleyen bir içerik bulunmuyor.' : 'Henüz onaya gönderilmiş bir içerik yok.' ?></div></div>
<?php else: ?>
<div id="approvalList">
<?php foreach ($approvals as $o):
    $ar = $o['archive_id'] ? row("SELECT * FROM archive WHERE id=?", [$o['archive_id']]) : null;
    $image = $ar && in_array($ar['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp']); ?>
<div class="kart mb-2 onay-kart" data-filter="<?= $o['status'] ?>">
    <div class="satir-esnek arasi sarma" style="gap:16px;align-items:flex-start">
        <div style="flex:1;min-width:0">
            <div class="satir-esnek sarma" style="gap:9px"><span class="kalin"><?= e($o['title']) ?></span><?= badge($o['status'], APPROVAL_STATUSES) ?></div>
            <div class="hucre-alt mt-1"><?= e($o['client_name']) ?> · <?= e($o['project_name']) ?> · <?= e($o['sender_name']) ?> tarafından <?= time_ago($o['created']) ?></div>
            <?php if ($o['description']): ?><div class="metin-2 kucuk mt-2"><?= nl2br(e($o['description'])) ?></div><?php endif; ?>
            <?php if ($o['drive_link']): ?><a href="<?= e($o['drive_link']) ?>" target="_blank" class="btn btn-sm mt-2" style="margin-right:6px"><?= icon('web', 13) ?> Drive'da Görüntüle</a><?php endif; ?>
            <?php if ($ar): ?>
            <div class="mt-2">
                <?php if ($image): ?><a href="uploads/<?= e($ar['file_path']) ?>" target="_blank"><img src="uploads/<?= e($ar['file_path']) ?>" style="max-width:280px;max-height:200px;border-radius:12px;border:1px solid var(--border)"></a>
                <?php else: ?><a href="uploads/<?= e($ar['file_path']) ?>" target="_blank" class="btn btn-sm"><?= icon('atac', 13) ?> <?= e($ar['name']) ?></a><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($o['reply_note']): ?><div class="mt-2" style="padding:10px 14px;background:var(--surface-2);border-radius:10px;font-size:13px"><b>Not:</b> <?= nl2br(e($o['reply_note'])) ?> <span class="hucre-alt">— <?= format_date($o['reply_date']) ?></span></div><?php endif; ?>
        </div>
        <?php if ($o['status'] === 'bekliyor' && (is_customer() || is_admin())): ?>
        <div class="dikey" style="gap:8px;flex-shrink:0;min-width:130px">
            <button class="btn btn-marka btn-sm btn-blok" style="background:var(--basari);color:#fff" data-action="approval_reply" data-id="<?= $o['id'] ?>" data-status="onaylandi">✓ Onayla</button>
            <button class="btn btn-sm btn-blok" onclick="onayNot(<?= $o['id'] ?>,'revize')">↻ Revize İste</button>
            <button class="btn btn-tehlike btn-sm btn-blok" onclick="onayNot(<?= $o['id'] ?>,'reddedildi')">✕ Reddet</button>
        </div>
        <?php elseif ($o['status'] === 'bekliyor' && is_staff()): ?>
        <span class="rozet r-bekliyor" style="flex-shrink:0">Müşteri onayı bekleniyor</span>
        <?php elseif ($o['status'] === 'onaylandi' && is_customer()): ?>
        <div style="flex-shrink:0">
            <?php if (isset($verilenRatings[$o['id']])): ?>
            <button class="btn btn-sm" onclick="puanVer('onay', <?= $o['id'] ?>, '<?= e($o['title']) ?>')" title="Puanı güncelle"><?= stars((float)$verilenRatings[$o['id']], 13) ?></button>
            <?php else: ?>
            <button class="btn btn-marka btn-sm" onclick="puanVer('onay', <?= $o['id'] ?>, '<?= e($o['title']) ?>')"><?= icon('star', 13) ?> Değerlendir</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalApprovalNot">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="approvalNotTitle">Not Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="approval_reply">
        <input type="hidden" name="id" id="approvalNotId"><input type="hidden" name="status" id="approvalNotStatus">
        <div class="modal-govde"><div class="form-grup"><label class="form-etiket">Notunuz <span class="zorunlu">*</span></label><textarea name="not" class="metin-alani" required placeholder="Değişiklik taleplerinizi veya nedeninizi yazın..."></textarea></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Gönder</button></div>
    </form></div>
</div>
<?php rating_modal(); ?>
<script>
function approvalNot(id, status) {
    document.getElementById('approvalNotId').value = id; document.getElementById('approvalNotStatus').value = status;
    document.getElementById('approvalNotTitle').textContent = status === 'revize' ? 'Revize Talebi' : 'Reddetme Nedeni';
    modalOpen('modalApprovalNot');
}
</script>
<?php page_end(); ?>

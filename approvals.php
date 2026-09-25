<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_login();

// Ratings given by the customer (per approval)
$givenRatings = is_customer()
    ? array_column(rows("SELECT ref_id, rating FROM ratings WHERE ref_type='approval' AND user_id=?", [$u['id']]), 'rating', 'ref_id')
    : [];

if (is_staff()) {
    $approvals = rows("SELECT o.*, p.name project_name, d.name client_name, ug.name sender_name FROM approvals o JOIN projects p ON p.id=o.project_id JOIN clients d ON d.id=p.client_id LEFT JOIN users ug ON ug.id=o.sender_id ORDER BY FIELD(o.status,'pending','revision','approved','rejected'), o.id DESC");
} else {
    [$in, $p] = in_clause(customer_client_ids());
    $approvals = rows("SELECT o.*, p.name project_name, d.name client_name, ug.name sender_name FROM approvals o JOIN projects p ON p.id=o.project_id JOIN clients d ON d.id=p.client_id LEFT JOIN users ug ON ug.id=o.sender_id WHERE p.client_id IN $in ORDER BY FIELD(o.status,'pending','revision','approved','rejected'), o.id DESC", $p);
}

page_start('Onaylar', 'approvals');
?>
<div class="page-top">
    <div>
        <div class="page-title">Onay Süreçleri</div>
        <div class="page-bottom"><?= is_customer() ? 'Onayınızı bekleyen içerikler' : 'Tüm projelerdeki onay süreçleri' ?></div>
    </div>
</div>

<div class="filter-bar">
    <div class="pill-filter" data-pill-group="#approvalList .approval-card">
        <button class="pill active" data-value="">Tümü</button>
        <?php foreach (APPROVAL_STATUSES as $k => $v): ?><button class="pill" data-value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$approvals): ?>
<div class="empty-state"><div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="empty-title">Onay süreci yok</div><div class="empty-text"><?= is_customer() ? 'Şu an onayınızı bekleyen bir içerik bulunmuyor.' : 'Henüz onaya gönderilmiş bir içerik yok.' ?></div></div>
<?php else: ?>
<div id="approvalList">
<?php foreach ($approvals as $o):
    $ar = $o['archive_id'] ? row("SELECT * FROM archive WHERE id=?", [$o['archive_id']]) : null;
    $image = $ar && in_array($ar['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp']); ?>
<div class="card mb-2 approval-card" data-filter="<?= $o['status'] ?>">
    <div class="row-flex between wrap" style="gap:16px;align-items:flex-start">
        <div style="flex:1;min-width:0">
            <div class="row-flex wrap" style="gap:9px"><span class="bold"><?= e($o['title']) ?></span><?= badge($o['status'], APPROVAL_STATUSES) ?></div>
            <div class="cell-bottom mt-1"><?= e($o['client_name']) ?> · <?= e($o['project_name']) ?> · <?= e($o['sender_name']) ?> tarafından <?= time_ago($o['created']) ?></div>
            <?php if ($o['description']): ?><div class="text-2 small mt-2"><?= nl2br(e($o['description'])) ?></div><?php endif; ?>
            <?php if ($o['drive_link']): ?><a href="<?= e($o['drive_link']) ?>" target="_blank" class="btn btn-sm mt-2" style="margin-right:6px"><?= icon('web', 13) ?> Drive'da Görüntüle</a><?php endif; ?>
            <?php if ($ar): ?>
            <div class="mt-2">
                <?php if ($image): ?><a href="uploads/<?= e($ar['file_path']) ?>" target="_blank"><img src="uploads/<?= e($ar['file_path']) ?>" style="max-width:280px;max-height:200px;border-radius:12px;border:1px solid var(--border)"></a>
                <?php else: ?><a href="uploads/<?= e($ar['file_path']) ?>" target="_blank" class="btn btn-sm"><?= icon('paperclip', 13) ?> <?= e($ar['name']) ?></a><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($o['reply_note']): ?><div class="mt-2" style="padding:10px 14px;background:var(--surface-2);border-radius:10px;font-size:13px"><b>Not:</b> <?= nl2br(e($o['reply_note'])) ?> <span class="cell-bottom">— <?= format_date($o['reply_date']) ?></span></div><?php endif; ?>
        </div>
        <?php if ($o['status'] === 'pending' && (is_customer() || is_admin())): ?>
        <div class="vertical" style="gap:8px;flex-shrink:0;min-width:130px">
            <button class="btn btn-brand btn-sm btn-block" style="background:var(--success);color:#fff" data-action="approval_reply" data-id="<?= $o['id'] ?>" data-status="approved">✓ Onayla</button>
            <button class="btn btn-sm btn-block" onclick="approvalNot(<?= $o['id'] ?>,'revision')">↻ Revize İste</button>
            <button class="btn btn-danger btn-sm btn-block" onclick="approvalNot(<?= $o['id'] ?>,'rejected')">✕ Reddet</button>
        </div>
        <?php elseif ($o['status'] === 'pending' && is_staff()): ?>
        <span class="badge r-pending" style="flex-shrink:0">Müşteri onayı bekleniyor</span>
        <?php elseif ($o['status'] === 'approved' && is_customer()): ?>
        <div style="flex-shrink:0">
            <?php if (isset($givenRatings[$o['id']])): ?>
            <button class="btn btn-sm" onclick="ratingGive('approval', <?= $o['id'] ?>, '<?= e($o['title']) ?>')" title="Puanı güncelle"><?= stars((float)$givenRatings[$o['id']], 13) ?></button>
            <?php else: ?>
            <button class="btn btn-brand btn-sm" onclick="ratingGive('approval', <?= $o['id'] ?>, '<?= e($o['title']) ?>')"><?= icon('star', 13) ?> Değerlendir</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-overlay" id="modalApprovalNot">
    <div class="modal"><div class="modal-top"><div class="modal-title" id="approvalNotTitle">Not Ekle</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="approval_reply">
        <input type="hidden" name="id" id="approvalNotId"><input type="hidden" name="status" id="approvalNotStatus">
        <div class="modal-body"><div class="form-group"><label class="form-label">Notunuz <span class="required">*</span></label><textarea name="note" class="text-area" required placeholder="Değişiklik taleplerinizi veya nedeninizi yazın..."></textarea></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Gönder</button></div>
    </form></div>
</div>
<?php rating_modal(); ?>
<script>
function approvalNot(id, status) {
    document.getElementById('approvalNotId').value = id; document.getElementById('approvalNotStatus').value = status;
    document.getElementById('approvalNotTitle').textContent = status === 'revision' ? 'Revize Talebi' : 'Reddetme Nedeni';
    modalOpen('modalApprovalNot');
}
</script>
<?php page_end(); ?>

<?php
/**
 * SADA One — Announcement Board
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$announcements = rows("SELECT d.*, us.name creator_name,
    (SELECT COUNT(*) FROM announcement_readers o WHERE o.announcement_id=d.id) reader_count,
    (SELECT COUNT(*) FROM announcement_readers o WHERE o.announcement_id=d.id AND o.user_id=?) read_by_me
    FROM announcements d LEFT JOIN users us ON us.id=d.created_by ORDER BY d.id DESC", [$u['id']]);
$totalPerson = (int)val("SELECT COUNT(*) FROM users WHERE is_active=1 AND role!='customer'");

page_start('Duyurular', 'announcements');
?>
<div class="page-top">
    <div><div class="page-title">Duyuru Panosu</div><div class="page-bottom">Ekip içi duyurular ve önemli notlar</div></div>
    <?php if (is_pm()): ?>
    <div class="page-top-action"><button class="btn btn-brand" data-modal="modalAnnouncement"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Duyuru Yayınla</button></div>
    <?php endif; ?>
</div>

<?php if (!$announcements): ?>
<div class="empty-state"><div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M11 5.88V19.24a1.76 1.76 0 01-3.42.6L5.44 14M18.7 4a9 9 0 01.3 13.3M5.44 14A2 2 0 015 10h1a8 8 0 005-2l3-2v12l-3-2a8 8 0 00-5-2H5.44z"/></svg></div><div class="empty-title">Duyuru yok</div><div class="empty-text">İlk duyuruyu yayınlayarak ekibi bilgilendirin.</div></div>
<?php else: foreach ($announcements as $dy): ?>
<div class="card mb-2" style="<?= $dy['is_important'] ? 'border-color:var(--warning)' : '' ?>">
    <div class="row-flex between wrap" style="gap:12px;align-items:flex-start">
        <div class="row-flex" style="gap:12px;min-width:0;align-items:flex-start">
            <span class="file-avatar" style="width:40px;height:40px;background:var(--bright);color:<?= $dy['is_important'] ? 'var(--warning)' : 'var(--brand)' ?>;flex-shrink:0"><?= icon($dy['is_important'] ? 'megaphone' : 'pin', 20) ?></span>
            <div style="min-width:0">
                <div class="row-flex wrap" style="gap:8px"><span class="bold" style="font-size:15px"><?= e($dy['title']) ?></span><?php if ($dy['is_important']): ?><span class="badge r-pending">Önemli</span><?php endif; ?></div>
                <?php if ($dy['text']): ?><div class="text-2 small mt-1" style="white-space:pre-wrap"><?= e($dy['text']) ?></div><?php endif; ?>
                <div class="cell-bottom mt-2"><?= e($dy['creator_name']) ?> · <?= format_date($dy['created'], true) ?> · <?= $dy['reader_count'] ?>/<?= $totalPerson ?> kişi okudu</div>
            </div>
        </div>
        <div class="row-flex" style="gap:6px;flex-shrink:0">
            <?php if (!$dy['read_by_me']): ?><button class="btn btn-sm" data-action="announcement_read" data-id="<?= $dy['id'] ?>">Okudum ✓</button><?php else: ?><span class="badge r-approved">✓ Okundu</span><?php endif; ?>
            <?php if (is_pm()): ?><button class="icon-action danger" data-action="announcement_delete" data-id="<?= $dy['id'] ?>" data-confirm="Duyuru silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button><?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>

<?php if (is_pm()): ?>
<div class="modal-overlay" id="modalAnnouncement">
    <div class="modal"><div class="modal-top"><div class="modal-title">Yeni Duyuru</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="announcement_save">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık <span class="required">*</span></label><input name="title" class="input" required></div>
            <div class="form-group"><label class="form-label">Metin</label><textarea name="text" class="text-area"></textarea></div>
            <div class="form-group"><label class="row-flex" style="gap:9px;cursor:pointer"><input type="checkbox" name="is_important" value="1"> <span class="small"><b>Önemli duyuru</b> — tüm ekibe bildirim gönderilir</span></label></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Yayınla</button></div>
    </form></div>
</div>
<?php endif; ?>
<?php page_end(); ?>

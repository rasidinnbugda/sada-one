<?php
/**
 * SADA One — Duyuru Panosu
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$announcements = rows("SELECT d.*, us.name creator_name,
    (SELECT COUNT(*) FROM announcement_readers o WHERE o.announcement_id=d.id) reader_count,
    (SELECT COUNT(*) FROM announcement_readers o WHERE o.announcement_id=d.id AND o.user_id=?) ben_okudum
    FROM announcements d LEFT JOIN users us ON us.id=d.created_by ORDER BY d.id DESC", [$u['id']]);
$totalPerson = (int)val("SELECT COUNT(*) FROM users WHERE is_active=1 AND role!='musteri'");

page_start('Duyurular', 'duyurular');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Duyuru Panosu</div><div class="sayfa-alt">Ekip içi duyurular ve önemli notlar</div></div>
    <?php if (is_pm()): ?>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalDuyuru"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Duyuru Yayınla</button></div>
    <?php endif; ?>
</div>

<?php if (!$announcements): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M11 5.88V19.24a1.76 1.76 0 01-3.42.6L5.44 14M18.7 4a9 9 0 01.3 13.3M5.44 14A2 2 0 015 10h1a8 8 0 005-2l3-2v12l-3-2a8 8 0 00-5-2H5.44z"/></svg></div><div class="bos-baslik">Duyuru yok</div><div class="bos-metin">İlk duyuruyu yayınlayarak ekibi bilgilendirin.</div></div>
<?php else: foreach ($announcements as $dy): ?>
<div class="kart mb-2" style="<?= $dy['is_important'] ? 'border-color:var(--warning)' : '' ?>">
    <div class="satir-esnek arasi sarma" style="gap:12px;align-items:flex-start">
        <div class="satir-esnek" style="gap:12px;min-width:0;align-items:flex-start">
            <span class="dosya-avatar" style="width:40px;height:40px;background:var(--parlak);color:<?= $dy['is_important'] ? 'var(--warning)' : 'var(--marka)' ?>;flex-shrink:0"><?= icon($dy['is_important'] ? 'megafon' : 'pin', 20) ?></span>
            <div style="min-width:0">
                <div class="satir-esnek sarma" style="gap:8px"><span class="kalin" style="font-size:15px"><?= e($dy['title']) ?></span><?php if ($dy['is_important']): ?><span class="rozet r-bekliyor">Önemli</span><?php endif; ?></div>
                <?php if ($dy['text']): ?><div class="metin-2 kucuk mt-1" style="white-space:pre-wrap"><?= e($dy['text']) ?></div><?php endif; ?>
                <div class="hucre-alt mt-2"><?= e($dy['creator_name']) ?> · <?= format_date($dy['created'], true) ?> · <?= $dy['reader_count'] ?>/<?= $totalPerson ?> kişi okudu</div>
            </div>
        </div>
        <div class="satir-esnek" style="gap:6px;flex-shrink:0">
            <?php if (!$dy['ben_okudum']): ?><button class="btn btn-sm" data-action="announcement_read" data-id="<?= $dy['id'] ?>">Okudum ✓</button><?php else: ?><span class="rozet r-onaylandi">✓ Okundu</span><?php endif; ?>
            <?php if (is_pm()): ?><button class="ikon-eylem tehlike" data-action="announcement_delete" data-id="<?= $dy['id'] ?>" data-approval="Duyuru silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button><?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>

<?php if (is_pm()): ?>
<div class="modal-katman" id="modalAnnouncement">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Duyuru</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="announcement_save">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="title" class="girdi" required></div>
            <div class="form-grup"><label class="form-etiket">Metin</label><textarea name="text" class="metin-alani"></textarea></div>
            <div class="form-grup"><label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="is_important" value="1"> <span class="kucuk"><b>Önemli duyuru</b> — tüm ekibe bildirim gönderilir</span></label></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Yayınla</button></div>
    </form></div>
</div>
<?php endif; ?>
<?php page_end(); ?>

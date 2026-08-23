<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$forms = rows("SELECT * FROM form_templates WHERE is_active=1 ORDER BY name");

if (is_staff()) {
    $requests = rows("SELECT t.*, f.name form_name, ug.name sender_name, d.name client_name, p.name project_name FROM requests t JOIN form_templates f ON f.id=t.template_id LEFT JOIN users ug ON ug.id=t.sender_id LEFT JOIN clients d ON d.id=t.client_id LEFT JOIN projects p ON p.id=t.project_id ORDER BY FIELD(t.status,'yeni','inceleniyor','gorev_olusturuldu','tamamlandi','reddedildi'), t.id DESC");
} else {
    $requests = rows("SELECT t.*, f.name form_name, ug.name sender_name, p.name project_name FROM requests t JOIN form_templates f ON f.id=t.template_id LEFT JOIN users ug ON ug.id=t.sender_id LEFT JOIN projects p ON p.id=t.project_id WHERE t.sender_id=? ORDER BY t.id DESC", [$u['id']]);
}

// Project list for the customer (to pick in the request form)
$customerProjects = [];
if (is_customer()) {
    [$mdIn, $mdP] = in_clause(customer_client_ids());
    $customerProjects = rows("SELECT id, name FROM projects WHERE client_id IN $mdIn AND status='aktif' ORDER BY name", $mdP);
}
$tumClients = is_staff() ? rows("SELECT id, name FROM clients WHERE status='aktif' ORDER BY name") : [];

page_start('Talepler', 'requests');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik"><?= is_staff() ? 'Gelen Talepler' : 'Taleplerim' ?></div><div class="sayfa-alt"><?= is_staff() ? 'Müşteri ve ekip talepleri' : 'Oluşturduğunuz talepler ve durumları' ?></div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalNewRequest"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Talep</button></div>
</div>

<?php if (is_staff()): ?>
<div class="filtre-bar">
    <div class="pill-filtre" data-pill-grup="#talepListe .talep-sat">
        <button class="pill aktif" data-setting_value="">Tümü</button>
        <?php foreach (REQUEST_STATUSES as $k => $v): ?><button class="pill" data-setting_value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$requests): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 10h8m-8 4h4m9-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="bos-baslik">Talep yok</div><div class="bos-metin"><?= is_staff() ? 'Henüz gelen bir talep bulunmuyor.' : 'Bir iş, revizyon veya çekim talebi oluşturmak için başlayın.' ?></div><button class="btn btn-marka" data-modal="modalNewRequest">Yeni Talep Oluştur</button></div>
<?php else: ?>
<div class="tablo-sar"><table class="tablo" id="requestList"><thead><tr><th>Talep</th><?php if (is_staff()): ?><th>Gönderen</th><th>Dosya/Proje</th><?php endif; ?><th>Tarih</th><th>Durum</th><th></th></tr></thead><tbody>
    <?php foreach ($requests as $t): ?>
    <tr class="tik talep-sat" data-filter="<?= $t['status'] ?>" onclick="location.href='request.php?id=<?= $t['id'] ?>'">
        <td><div class="hucre-ana"><?= e($t['title']) ?></div><div class="hucre-alt"><?= e($t['form_name']) ?></div></td>
        <?php if (is_staff()): ?>
        <td><?= e($t['sender_name']) ?></td>
        <td class="kucuk"><?= e($t['client_name'] ?? '—') ?><?= $t['project_name'] ? ' / ' . e($t['project_name']) : '' ?></td>
        <?php endif; ?>
        <td class="kucuk"><?= format_date($t['created']) ?></td>
        <td><?= badge($t['status'], REQUEST_STATUSES) ?></td>
        <td><svg width="16" fill="none" stroke="var(--muted)" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></td>
    </tr>
    <?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<!-- New request modal: pick form type → fill in the fields -->
<div class="modal-katman" id="modalNewRequest">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Talep</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <div class="modal-govde">
        <div id="talepAdim1">
            <div class="hucre-alt mb-3">Ne tür bir talep oluşturmak istiyorsunuz?</div>
            <div class="izgara izgara-2">
                <?php foreach ($forms as $f): ?>
                <button class="kart kart-tik" style="text-align:left;padding:16px" onclick="talepFormAc(<?= $f['id'] ?>)">
                    <div class="kalin"><?= e($f['name']) ?></div>
                    <?php if ($f['description']): ?><div class="hucre-alt mt-1"><?= e($f['description']) ?></div><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div id="talepAdim2" style="display:none">
            <button class="btn btn-sm btn-hayalet mb-3" onclick="requestGeri()">← Geri</button>
            <form data-ajax="request_send" id="requestForm">
                <input type="hidden" name="template_id" id="requestTemplateId">
                <?php if ($customerProjects): ?>
                <div class="form-grup"><label class="form-etiket">İlgili Proje</label><select name="project_id" class="secim"><option value="">— Genel</option><?php foreach ($customerProjects as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
                <?php elseif ($tumClients): ?>
                <div class="form-grup"><label class="form-etiket">Dosya</label><select name="client_id" class="secim"><option value="">—</option><?php foreach ($tumClients as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div id="requestFields"></div>
                <button type="submit" class="btn btn-marka btn-blok mt-2">Talebi Gönder</button>
            </form>
        </div>
    </div>
    </div>
</div>

<script>
const formFields = <?= json_encode(array_reduce($forms, function ($acc, $f) {
    $acc[$f['id']] = ['name' => $f['name'], 'fields' => rows("SELECT * FROM form_fields WHERE template_id=? ORDER BY sort_order", [$f['id']])];
    return $acc;
}, []), JSON_UNESCAPED_UNICODE) ?>;

function requestFormOpen(id) {
    const f = formFields[id]; if (!f) return;
    document.getElementById('requestTemplateId').value = id;
    let h = '';
    f.fields.forEach(a => {
        const is_required = a.is_required == 1 ? ' required' : '';
        const star = a.is_required == 1 ? ' <span class="zorunlu">*</span>' : '';
        h += `<div class="form-grup"><label class="form-etiket">${esc(a.tag)}${star}</label>`;
        if (a.type === 'uzun_metin') h += `<textarea name="alan_${a.id}" class="metin-alani"${is_required}></textarea>`;
        else if (a.type === 'secim') { h += `<select name="alan_${a.id}" class="secim"${is_required}>`; (a.options||'').split('\n').forEach(s => { if(s.trim()) h += `<option>${s.trim()}</option>`; }); h += `</select>`; }
        else if (a.type === 'tarih') h += `<input type="date" name="alan_${a.id}" class="girdi"${is_required}>`;
        else if (a.type === 'sayi') h += `<input type="number" name="alan_${a.id}" class="girdi"${is_required}>`;
        else if (a.type === 'dosya') h += `<input type="file" name="alan_${a.id}" class="girdi"${is_required}>`;
        else h += `<input type="text" name="alan_${a.id}" class="girdi"${is_required}>`;
        h += `</div>`;
    });
    document.getElementById('requestFields').innerHTML = h;
    if (window.ozelPickerRefresh) ozelPickerRefresh();
    document.getElementById('talepAdim1').style.display = 'none';
    document.getElementById('talepAdim2').style.display = 'block';
}
function requestGeri() { document.getElementById('talepAdim2').style.display = 'none'; document.getElementById('talepAdim1').style.display = 'block'; }
</script>
<?php page_end(); ?>

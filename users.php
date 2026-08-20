<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$users = rows("SELECT us.*, d.name client_name,
    (SELECT GROUP_CONCAT(md.client_id) FROM customer_clients md WHERE md.user_id=us.id) md_ids,
    (SELECT COUNT(*) FROM customer_clients md WHERE md.user_id=us.id) md_count
    FROM users us LEFT JOIN clients d ON d.id=us.client_id ORDER BY us.is_active DESC, FIELD(us.role,'yonetici','pm','ekip','finans','stajyer','musteri'), us.name");
$clients = rows("SELECT id, name FROM clients ORDER BY name");

page_start('Kullanıcılar', 'users');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Kullanıcılar</div><div class="sayfa-alt"><?= count($users) ?> kullanıcı · ekip ve müşteri hesapları</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalKullanici" onclick="userSifirla()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Kullanıcı</button></div>
</div>

<div class="filtre-bar">
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="İsim veya e-posta ara..." data-search="#kullaniciListe tr"></div>
    <div class="pill-filtre" data-pill-grup="#kullaniciListe tr">
        <button class="pill aktif" data-setting_value="">Tümü</button>
        <?php foreach (ROLES as $k => $v): ?><button class="pill" data-setting_value="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<div class="tablo-sar"><table class="tablo"><tbody id="userList">
    <?php foreach ($users as $k): ?>
    <tr data-filter="<?= $k['role'] ?>" data-search="<?= e($k['name'] . ' ' . $k['email']) ?>" style="<?= $k['is_active'] ? '' : 'opacity:.5' ?>">
        <td style="width:44px"><?= avatar($k, 38) ?></td>
        <td><div class="hucre-ana"><?= e($k['name']) ?><?php if ($k['id'] == $u['id']): ?> <span class="hucre-alt">(siz)</span><?php endif; ?></div><div class="hucre-alt"><?= e($k['email']) ?></div></td>
        <td><span class="rozet rozet-tur"><?= ROLES[$k['role']] ?></span></td>
        <td class="kucuk"><?= $k['job_title'] ? e($k['job_title']) : '' ?><?= $k['md_count'] > 1 ? ' · ' . $k['md_count'] . ' dosya' : ($k['client_name'] ? ' · ' . e($k['client_name']) : '') ?></td>
        <td class="kucuk metin-muted"><?= $k['last_login'] ? 'Son giriş ' . time_ago($k['last_login']) : 'Hiç girmedi' ?></td>
        <td style="width:120px;text-align:right">
            <button class="ikon-eylem" onclick='kullaniciDuzenle(<?= json_encode($k, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
            <?php if ($k['id'] != $u['id']): ?><button class="ikon-eylem <?= $k['is_active'] ? 'tehlike' : '' ?>" data-action="user_status" data-id="<?= $k['id'] ?>" data-is_active="<?= $k['is_active'] ? 0 : 1 ?>" data-approval="<?= $k['is_active'] ? 'Kullanıcı pasifleştirilsin mi?' : 'Kullanıcı aktifleştirilsin mi?' ?>" title="<?= $k['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?>"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="<?= $k['is_active'] ? 'M18.36 6.64a9 9 0 11-12.73 0M12 2v10' : 'M5 13l4 4L19 7' ?>"/></svg></button><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</tbody></table></div>

<div class="modal-katman" id="modalUser">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="userTitle">Yeni Kullanıcı</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="user_save" id="userForm">
        <input type="hidden" name="id" id="k_id">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Ad Soyad <span class="zorunlu">*</span></label><input name="name" id="k_name" class="girdi" required></div>
                <div class="form-grup"><label class="form-etiket">E-posta <span class="zorunlu">*</span></label><input type="email" name="email" id="k_email" class="girdi" required></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Rol</label><select name="role" id="k_role" class="secim" onchange="roleDegisti()"><?php foreach (ROLES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Ünvan</label><input name="job_title" id="k_job_title" class="girdi" placeholder="Örn. Sosyal Medya Uzmanı"></div>
            </div>
            <div class="form-grup" id="clientGrup" style="display:none">
                <label class="form-etiket">Erişebileceği Dosyalar (müşteri için) <span class="zorunlu">*</span> <span class="metin-muted" style="font-weight:400">— birden fazla seçilebilir</span></label>
                <input type="hidden" name="customer_clients" id="k_customer_clients">
                <div class="izgara izgara-2" style="gap:6px;max-height:160px;overflow-y:auto;padding:2px">
                    <?php foreach ($clients as $d): ?>
                    <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="mdosya-kutu" value="<?= $d['id'] ?>"> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($d['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Şifre <span id="passwordRequired" class="zorunlu">*</span></label><input type="password" name="password" id="k_password" class="girdi"><div class="form-ipucu" id="passwordIpucu">En az 6 karakter.</div></div>
                <div class="form-grup" id="capacityGrup"><label class="form-etiket">Haftalık Kapasite (saat)</label><input type="number" name="weekly_capacity" id="k_capacity" class="girdi" value="45" min="0" max="100"><div class="form-ipucu">Doluluk raporu bu hedefe göre hesaplanır.</div></div>
            </div>
            <div class="form-grup" id="salaryGrup"><label class="form-etiket">Aylık Maaş (₺)</label><input name="salary" id="k_salary" class="girdi" value="0" placeholder="0,00"><div class="form-ipucu">Girilirse her ay başında otomatik gider kaydı oluşur. Yalnızca yönetici ve finans rolü görür.</div></div>
            <div class="form-grup" id="permissionGrup" style="display:none">
                <label class="form-etiket">Özel İzinler <span class="metin-muted" style="font-weight:400">(rol varsayılanlarını geçersiz kılar)</span></label>
                <input type="hidden" name="permissions" id="k_permissions">
                <div class="izgara izgara-2" style="gap:8px">
                    <?php foreach (PERMISSION_KEYS as $setting_key => $tag): ?>
                    <label class="satir-esnek kucuk" style="gap:9px;padding:9px 12px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="izin-kutu" data-setting_key="<?= $setting_key ?>"> <?= $tag ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
// Role default permissions (same as init.php)
const roleDefault = {
    pm:      { finance: 1, report: 1, capacity: 1, client_manage: 1, task_create: 1, task_delete: 1, content_manage: 1, equipment_manage: 1, approval_send: 1, announcement_yayinla: 1, calendar_manage: 1, channel_setup: 1, document_create: 1, archive_delete: 1, request_manage: 1 },
    team:    { finance: 0, report: 0, capacity: 0, client_manage: 0, task_create: 1, task_delete: 0, content_manage: 1, equipment_manage: 0, approval_send: 1, announcement_yayinla: 0, calendar_manage: 1, channel_setup: 1, document_create: 0, archive_delete: 0, request_manage: 0 },
    finance:  { finance: 1, report: 1, capacity: 1, client_manage: 0, task_create: 0, task_delete: 0, content_manage: 0, equipment_manage: 0, approval_send: 0, announcement_yayinla: 0, calendar_manage: 0, channel_setup: 1, document_create: 1, archive_delete: 0, request_manage: 0 },
    intern: { finance: 0, report: 0, capacity: 0, client_manage: 0, task_create: 0, task_delete: 0, content_manage: 0, equipment_manage: 0, approval_send: 0, announcement_yayinla: 0, calendar_manage: 0, channel_setup: 0, document_create: 0, archive_delete: 0, request_manage: 0 },
};
function roleDegisti() {
    const role = document.getElementById('k_role').value;
    document.getElementById('clientGrup').style.display = role === 'musteri' ? 'block' : 'none';
    document.getElementById('permissionGrup').style.display = roleDefault[role] ? 'block' : 'none';
    document.getElementById('capacityGrup').style.display = role === 'musteri' ? 'none' : 'block';
    // Reflect role defaults onto the checkboxes (if there is no custom override)
    if (roleDefault[role] && !window.permissionOzel) {
        document.querySelectorAll('.permission-box').forEach(c => { c.checked = !!roleDefault[role][c.dataset.setting_key]; });
    }
}
function userSifirla() {
    document.getElementById('userForm').reset();
    document.getElementById('k_id').value = '';
    document.getElementById('userTitle').textContent = 'Yeni Kullanıcı';
    document.getElementById('passwordRequired').style.display = 'inline';
    document.getElementById('passwordIpucu').textContent = 'En az 6 karakter.';
    document.getElementById('k_password').required = true;
    document.getElementById('k_capacity').value = 45;
    window.permissionOzel = false;
    roleDegisti();
}
function userEdit(k) {
    document.getElementById('userTitle').textContent = 'Kullanıcıyı Düzenle';
    document.getElementById('k_id').value = k.id;
    document.getElementById('k_name').value = k.name;
    document.getElementById('k_email').value = k.email;
    document.getElementById('k_role').value = k.role;
    document.getElementById('k_job_title').value = k.job_title || '';
    // Customer clients: junction table + primary client
    const selected = new Set((k.md_ids ? String(k.md_ids).split(',') : []).concat(k.client_id ? [String(k.client_id)] : []));
    document.querySelectorAll('.mclient-box').forEach(c => { c.checked = selected.has(c.value); });
    document.getElementById('k_capacity').value = k.weekly_capacity || 45;
    document.getElementById('k_salary').value = k.salary || 0;
    document.getElementById('k_password').value = '';
    document.getElementById('k_password').required = false;
    document.getElementById('passwordRequired').style.display = 'none';
    document.getElementById('passwordIpucu').textContent = 'Değiştirmek istemiyorsanız boş bırakın.';
    // Current permissions: show the custom override if present, otherwise the role default
    let ozel = null;
    try { ozel = JSON.parse(k.permissions || 'null'); } catch (e) {}
    window.permissionOzel = !!ozel;
    const taban = Object.assign({}, roleDefault[k.role] || {}, ozel || {});
    document.querySelectorAll('.permission-box').forEach(c => { c.checked = !!taban[c.dataset.setting_key]; });
    roleDegisti();
    modalOpen('modalUser');
}
// On submit, convert permission checkboxes + customer clients to JSON
document.getElementById('userForm').addEventListener('submit', () => {
    const role = document.getElementById('k_role').value;
    if (roleDefault[role]) {
        const permissions = {};
        document.querySelectorAll('.permission-box').forEach(c => { permissions[c.dataset.setting_key] = c.checked ? 1 : 0; });
        document.getElementById('k_permissions').value = JSON.stringify(permissions);
    } else document.getElementById('k_permissions').value = '';
    document.getElementById('k_customer_clients').value = role === 'musteri'
        ? JSON.stringify(Array.from(document.querySelectorAll('.mclient-box:checked')).map(c => c.value))
        : '';
});
</script>
<?php page_end(); ?>

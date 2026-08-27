<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
$request = row("SELECT t.*, f.name form_name, ug.name sender_name, ug.color sender_color, d.name client_name, p.name project_name, ua.name assignee_name
    FROM requests t JOIN form_templates f ON f.id=t.template_id LEFT JOIN users ug ON ug.id=t.sender_id
    LEFT JOIN clients d ON d.id=t.client_id LEFT JOIN projects p ON p.id=t.project_id LEFT JOIN users ua ON ua.id=t.assignee_id WHERE t.id=?", [$id]);
if (!$request) { header('Location: requests.php'); exit; }
if (is_customer() && $request['sender_id'] != $u['id']) { header('Location: requests.php'); exit; }

$replies = rows("SELECT tc.*, fa.tag, fa.type FROM request_replies tc JOIN form_fields fa ON fa.id=tc.field_id WHERE tc.request_id=? ORDER BY fa.sort_order", [$id]);
$projects = rows("SELECT id, name FROM projects WHERE " . ($request['client_id'] ? "client_id=" . (int)$request['client_id'] : "status='is_active'") . " ORDER BY name");
$team = rows("SELECT id, name FROM users WHERE role IN ('yonetici','pm','ekip') AND is_active=1 ORDER BY name");

page_start('Talep Detayı', 'requests');
?>
<div class="satir-esnek mb-3" style="gap:10px">
    <a href="requests.php" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="metin-muted kucuk">Talepler / #<?= $id ?></span>
</div>

<div class="sayfa-ust">
    <div>
        <div class="satir-esnek" style="gap:9px"><span class="rozet rozet-tur"><?= e($request['form_name']) ?></span><?= badge($request['status'], REQUEST_STATUSES) ?></div>
        <div class="sayfa-baslik mt-1"><?= e($request['title']) ?></div>
        <div class="sayfa-alt"><?= e($request['sender_name']) ?> · <?= format_date($request['created'], true) ?></div>
    </div>
</div>

<div class="izgara" style="grid-template-columns:1fr 300px">
    <div class="kart">
        <div class="kart-baslik mb-3">Talep Bilgileri</div>
        <div class="dikey" style="gap:16px">
            <?php foreach ($replies as $c): ?>
            <div><div class="hucre-alt mb-2"><?= e($c['tag']) ?></div><div class="metin-2" style="white-space:pre-wrap"><?php if (in_array($c['type'], ['dosya', 'coklu_dosya']) && $c['setting_value']): ?><span class="satir-esnek sarma" style="gap:6px"><?php foreach (array_filter(explode(',', $c['setting_value'])) as $di => $dp): ?><a href="uploads/<?= e($dp) ?>" target="_blank" class="btn btn-sm"><?= icon('atac', 13) ?> Dosya <?= $di + 1 ?></a><?php endforeach; ?></span><?php else: ?><?= $c['setting_value'] ? e($c['setting_value']) : '<span class="metin-muted">—</span>' ?><?php endif; ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <?php if (is_pm()): ?>
        <div class="kart mb-2">
            <div class="kart-baslik mb-3" style="font-size:14px">Yönetim</div>
            <div class="form-grup">
                <label class="form-etiket">Durum</label>
                <select class="secim" onchange="requestStatus(this.value)">
                    <?php foreach (REQUEST_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $request['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if (!$request['project_id']): ?>
            <div class="form-grup">
                <label class="form-etiket">Projeye Bağla</label>
                <select class="secim" onchange="requestProject(this.value)">
                    <option value="">Seçin...</option>
                    <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($request['status'] !== 'gorev_olusturuldu' && $request['task_id'] === null): ?>
            <button class="btn btn-marka btn-blok mt-2" data-action="request_to_task" data-id="<?= $id ?>" data-approval="Bu talep bir göreve dönüştürülsün mü?">Göreve Dönüştür</button>
            <div class="form-ipucu">Not: Önce bir proje bağlamanız gerekir.</div>
            <?php elseif ($request['task_id']): ?>
            <a href="task.php?id=<?= $request['task_id'] ?>" class="btn btn-blok mt-2">Oluşturulan Görevi Aç →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="kart">
            <div class="dikey" style="gap:12px">
                <div class="satir-esnek arasi"><span class="hucre-alt">Dosya</span><span class="kucuk"><?= e($request['client_name'] ?? '—') ?></span></div>
                <div class="satir-esnek arasi"><span class="hucre-alt">Proje</span><span class="kucuk"><?= e($request['project_name'] ?? '—') ?></span></div>
                <div class="satir-esnek arasi"><span class="hucre-alt">Atanan</span><span class="kucuk"><?= e($request['assignee_name'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>
</div>

<script>
async function requestStatus(status) { const j = await api('request_status', {id:<?= $id ?>, status}); if (j.ok) toast('Güncellendi', 'basari'); }
async function requestProject(pid) { if (!pid) return; const j = await api('request_project', {id:<?= $id ?>, project_id:pid}); if (j.ok) { toast('Proje bağlandı', 'basari'); setTimeout(()=>location.reload(),600); } }
</script>
<?php page_end(); ?>

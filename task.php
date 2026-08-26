<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_staff();

$id = (int)($_GET['id'] ?? 0);
$task = row("SELECT g.*, p.name project_name, p.client_id, d.name client_name, uu.name assignee_name, uu.color assignee_color, ol.name creator_name
    FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id
    LEFT JOIN users uu ON uu.id=g.assignee_id LEFT JOIN users ol ON ol.id=g.created_by WHERE g.id=?", [$id]);
if (!$task) { header('Location: tasks.php'); exit; }

$steps = rows("SELECT ga.*, u.name owner_name, u.color owner_color FROM task_steps ga LEFT JOIN users u ON u.id=ga.owner_id WHERE ga.task_id=? ORDER BY ga.sort_order", [$id]);
$zamanlar = rows("SELECT z.*, u.name FROM time_entries z JOIN users u ON u.id=z.user_id WHERE z.task_id=? ORDER BY z.date DESC, z.id DESC", [$id]);
$totalMin = (int)val("SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE task_id=?", [$id]);
$team = rows("SELECT id, name, color FROM users WHERE role IN ('yonetici','pm','ekip') AND is_active=1 ORDER BY name");
$checks = rows("SELECT * FROM task_checklist WHERE task_id=? ORDER BY sort_order", [$id]);
$bagimli = $task['bagimli_id'] ? row("SELECT id, title, status FROM tasks WHERE id=?", [$task['bagimli_id']]) : null;
$projectTasks = rows("SELECT id, title FROM tasks WHERE project_id=? AND id!=? AND status!='tamamlandi' ORDER BY title", [$task['project_id'], $id]);
$attachments = rows("SELECT a.*, us.name uploader_name FROM archive a LEFT JOIN users us ON us.id=a.uploader_id WHERE a.task_id=? ORDER BY a.id DESC", [$id]);
$assignees = rows("SELECT us.id, us.name, us.color, us.avatar FROM task_assignees ga JOIN users us ON us.id=ga.user_id WHERE ga.task_id=? ORDER BY us.name", [$id]);
if (!$assignees && $task['assignee_id'] && $task['assignee_name']) $assignees = [['id' => $task['assignee_id'], 'name' => $task['assignee_name'], 'color' => $task['assignee_color'], 'avatar' => null]];
$assigneeIds = array_column($assignees, 'id');
$watchers = rows("SELECT us.id, us.name, us.color, us.avatar FROM task_watchers gi JOIN users us ON us.id=gi.user_id WHERE gi.task_id=? ORDER BY us.name", [$id]);
$watcherIds = array_column($watchers, 'id');

$activeStepIndex = -1;
foreach ($steps as $i => $a) { if ($a['status'] === 'aktif') { $activeStepIndex = $i; break; } }

page_start($task['title'], 'tasks');
?>
<div class="satir-esnek mb-3" style="gap:10px">
    <a href="project.php?id=<?= $task['project_id'] ?>#gorevler" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="metin-muted kucuk"><?= e($task['client_name']) ?> / <a href="project.php?id=<?= $task['project_id'] ?>" style="color:inherit"><?= e($task['project_name']) ?></a></span>
</div>

<div class="sayfa-ust">
    <div>
        <div class="satir-esnek sarma" style="gap:9px">
            <?= badge($task['status'], TASK_STATUSES) ?>
            <?= badge($task['priority'], PRIORITIES, 'priority') ?>
            <?php if ($task['repeat'] !== 'yok'): ?><span class="rozet rozet-tur"><?= icon('repeat', 12) ?> <?= REPEAT_OPTIONS[$task['repeat']] ?></span><?php endif; ?>
            <?php if ($bagimli && $bagimli['status'] !== 'tamamlandi' && !$task['lock_bypassed']): ?>
            <span class="kilit-rozet" title="Bağlı olduğu görev tamamlanmadan ilerleyemez"><?= icon('lock', 12) ?> <a href="task.php?id=<?= $bagimli['id'] ?>" style="color:inherit;text-decoration:underline"><?= e(mb_substr($bagimli['title'], 0, 34)) ?></a> bekleniyor</span>
            <?php elseif ($task['lock_bypassed']): ?>
            <span class="rozet r-bekliyor" title="Yönetici kilidi devre dışı bıraktı"><?= icon('lock-open', 12) ?> Kilit devre dışı</span>
            <?php endif; ?>
            <?= tag_chips($task['tags']) ?>
        </div>
        <div class="sayfa-baslik mt-1"><?= e($task['title']) ?></div>
    </div>
    <div class="sayfa-ust-aksiyon">
        <select class="secim" style="width:auto;min-width:160px" id="statusPicker" onchange="statusChange(this.value)">
            <?php foreach (TASK_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $task['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
        <button class="btn" title="Görevi ve tartışmayı AI ile özetle" onclick="aiSummary(<?= $id ?>)">🪄</button>
        <button class="btn" onclick="modalOpen('modalTaskDuzen')"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
    </div>
</div>

<?php if ($steps): ?>
<!-- TASK WORKFLOW RAIL -->
<div class="kart mb-3">
    <div class="satir-esnek arasi mb-3"><div class="kart-baslik">İş Akışı</div><span class="metin-muted kucuk" id="stepCounter"><?= count(array_filter($steps, fn($a) => $a['status'] === 'tamam')) ?>/<?= count($steps) ?> adım tamamlandı</span></div>
    <div class="akis-ray">
        <?php foreach ($steps as $i => $a): ?>
        <div class="akis-adim <?= $a['status'] === 'tamam' ? 'tamam' : ($a['status'] === 'aktif' ? 'aktif' : '') ?>" data-step="<?= $a['id'] ?>" data-sort_order="<?= $i + 1 ?>">
            <div class="akis-cizgi"></div>
            <div class="akis-adim-ic">
                <button class="akis-yuvarlak" onclick="stepComplete(<?= $a['id'] ?>)" title="Tamamla / geri al">
                    <?php if ($a['status'] === 'tamam'): ?><svg width="20" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg><?php else: ?><?= $i + 1 ?><?php endif; ?>
                </button>
                <div class="akis-ad"><?= e($a['name']) ?></div>
                <button class="akis-sorumlu" onclick="stepOwner(<?= $a['id'] ?>)" style="cursor:pointer"><?= $a['owner_name'] ? e(explode(' ', $a['owner_name'])[0]) : '+ sorumlu' ?></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="izgara" style="grid-template-columns:1fr 300px">
    <div>
        <div class="kart mb-3">
            <div class="kart-baslik mb-2">Açıklama</div>
            <div class="metin-2" style="white-space:pre-wrap"><?= $task['description'] ? e($task['description']) : '<span class="metin-muted kucuk">Açıklama eklenmemiş.</span>' ?></div>
        </div>

        <!-- Checklist -->
        <div class="kart mb-3">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik">Kontrol Listesi</div>
                <span class="metin-muted kucuk" id="checkCounter"><?= $checks ? count(array_filter($checks, fn($k) => $k['is_done'])) . '/' . count($checks) : '' ?></span>
            </div>
            <div class="ilerleme mb-2" <?= $checks ? '' : 'style="display:none"' ?>><div class="ilerleme-dolu" id="checkBar" data-rate="<?= $checks ? round(count(array_filter($checks, fn($k) => $k['is_done'])) / count($checks) * 100) : 0 ?>" style="width:0"></div></div>
            <div class="dikey" style="gap:2px" id="checkList">
                <?php foreach ($checks as $k): ?>
                <div class="kontrol-oge <?= $k['is_done'] ? 'tamam' : '' ?>">
                    <input type="checkbox" <?= $k['is_done'] ? 'checked' : '' ?> onchange="checkToggle(<?= $k['id'] ?>, this)">
                    <span class="kontrol-metin"><?= e($k['name']) ?></span>
                    <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-action="check_delete" data-id="<?= $k['id'] ?>" data-approval="Madde silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <?php endforeach; ?>
                <?php if (!$checks): ?><div class="metin-muted kucuk" style="padding:6px 0">Henüz madde yok. Görevi küçük adımlara bölün.</div><?php endif; ?>
            </div>
            <form class="satir-esnek mt-2" style="gap:8px" onsubmit="return checkAdd(event)">
                <input class="girdi" id="checkNew" placeholder="Yeni madde ekle...">
                <button type="submit" class="btn btn-sm">Ekle</button>
            </form>
        </div>

        <!-- Attachments -->
        <div class="kart mb-3">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik">Ekler <?php if ($attachments): ?><span class="rozet" style="padding:1px 8px"><?= count($attachments) ?></span><?php endif; ?></div>
                <span class="ekler-aksiyon">
                <button class="btn btn-sm" onclick="modalOpen('modalDriveLink')" type="button"><?= icon('web', 13) ?> Drive Linki</button>
                <form data-ajax="archive_upload" style="display:inline">
                    <input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
                    <label class="btn btn-sm" style="cursor:pointer"><?= icon('atac', 14) ?> Dosya Ekle<input type="file" name="client" style="display:none" onchange="this.closest('form').requestSubmit()"></label>
                </form>
                </span>
            </div>
            <?php if (!$attachments): ?><div class="metin-muted kucuk">Henüz ek yok. Brief, görsel veya video ekleyin.</div>
            <?php else: ?>
            <div class="izgara izgara-2" style="gap:8px">
                <?php foreach ($attachments as $ek):
                    $imageMi = in_array($ek['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                    $linkMi = !empty($ek['url']);
                    $ekHref = $linkMi ? $ek['url'] : 'uploads/' . $ek['file_path']; ?>
                <div class="satir-esnek arasi" style="padding:8px 10px;background:var(--surface-2);border-radius:10px">
                    <a href="<?= e($ekHref) ?>" target="_blank" class="satir-esnek" style="gap:9px;min-width:0">
                        <?php if ($imageMi): ?><span style="width:34px;height:34px;border-radius:8px;background:url('uploads/<?= e($ek['file_path']) ?>') center/cover;flex-shrink:0"></span>
                        <?php elseif ($linkMi): ?><span class="dosya-avatar" style="width:34px;height:34px;background:var(--parlak);color:var(--marka)"><?= icon('web', 16) ?></span>
                        <?php else: ?><span class="dosya-avatar" style="width:34px;height:34px;font-size:10px;background:var(--parlak);color:var(--marka)"><?= e(mb_strtoupper($ek['extension'] ?: '?')) ?></span><?php endif; ?>
                        <div style="min-width:0"><div class="kucuk kalin" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($ek['name']) ?></div><div class="hucre-alt"><?= $linkMi ? 'Drive bağlantısı' : format_size($ek['size']) ?></div></div>
                    </a>
                    <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-action="arsiv_sil" data-id="<?= $ek['id'] ?>" data-approval="Ek silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="kart">
            <div class="kart-baslik mb-2">Yorumlar</div>
            <?php comment_feed('gorev', $id); ?>
        </div>
    </div>

    <div>
        <div class="kart mb-2">
            <div class="dikey" style="gap:14px">
                <div><div class="hucre-alt mb-2">Atananlar</div>
                    <?php if (!$assignees): ?><span class="metin-muted kucuk">Atanmamış</span>
                    <?php else: foreach ($assignees as $at): ?>
                    <div class="satir-esnek mt-1" style="gap:9px"><?= avatar($at, 28) ?><span class="kucuk kalin"><?= e($at['name']) ?></span></div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="satir-esnek arasi"><span class="hucre-alt">Başlangıç</span><span class="kucuk"><?= format_date($task['start_date']) ?></span></div>
                <div class="satir-esnek arasi"><span class="hucre-alt">Son Tarih</span><span class="kucuk kalin" style="<?= $task['due_date'] && $task['due_date'] < date('Y-m-d') && $task['status'] !== 'tamamlandi' ? 'color:var(--tehlike)' : '' ?>"><?= format_date($task['due_date']) ?></span></div>
                <?php if ($task['estimated_minutes'] > 0): ?>
                <div class="satir-esnek arasi"><span class="hucre-alt">Tahmin / Gerçek</span><span class="kucuk kalin" style="<?= $totalMin > $task['estimated_minutes'] ? 'color:var(--tehlike)' : '' ?>"><?= format_minutes((int)$task['estimated_minutes']) ?> / <?= format_minutes($totalMin) ?></span></div>
                <?php endif; ?>
                <div class="satir-esnek arasi"><span class="hucre-alt">Oluşturan</span><span class="kucuk"><?= e($task['creator_name'] ?? '—') ?></span></div>
                <div class="satir-esnek arasi"><span class="hucre-alt">Oluşturulma</span><span class="kucuk"><?= format_date($task['created']) ?></span></div>
            </div>
        </div>

        <?php if ($task['content_id']):
            $bagliContent = row("SELECT * FROM contents WHERE id=?", [$task['content_id']]);
            if ($bagliContent): ?>
        <!-- Linked content -->
        <div class="kart mb-2">
            <div class="kart-baslik mb-2" style="font-size:14px"><?= icon('calendar', 15) ?> Bağlı İçerik</div>
            <div class="kucuk kalin"><?= e($bagliContent['title']) ?></div>
            <div class="satir-esnek sarma mt-1" style="gap:5px"><?= platform_badges($bagliContent['platform']) ?></div>
            <div class="satir-esnek arasi mt-2">
                <span class="hucre-alt">Yayın: <?= format_date($bagliContent['date']) ?></span>
                <?= badge($bagliContent['status'], CONTENT_STATUSES) ?>
            </div>
            <a href="content-calendar.php?month=<?= date('n', strtotime($bagliContent['date'])) ?>&year=<?= date('Y', strtotime($bagliContent['date'])) ?>" class="mini-btn mt-2" style="display:inline-block">İçerik takviminde gör →</a>
        </div>
        <?php endif; endif; ?>

        <!-- Watchers -->
        <div class="kart mb-2">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik" style="font-size:14px">İzleyiciler</div>
                <div class="acilir" data-acilir>
                    <button class="mini-btn" data-acilir-btn>+ Ekle</button>
                    <div class="acilir-panel">
                        <?php foreach ($team as $k): if (in_array($k['id'], $watcherIds)) continue; ?>
                        <button class="acilir-oge" style="width:100%;text-align:left" data-action="watcher_toggle" data-task_id="<?= $id ?>" data-user_id="<?= $k['id'] ?>"><?= e($k['name']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php if (!$watchers): ?><div class="metin-muted kucuk">İzleyici yok. Eklenenler görevdeki her gelişmede bildirim alır.</div>
            <?php else: foreach ($watchers as $iz): ?>
            <div class="satir-esnek arasi mt-1" style="padding:5px 0">
                <div class="satir-esnek" style="gap:9px"><?= avatar($iz, 26) ?><span class="kucuk"><?= e($iz['name']) ?></span></div>
                <button class="ikon-eylem tehlike" style="width:24px;height:24px" data-action="watcher_toggle" data-task_id="<?= $id ?>" data-user_id="<?= $iz['id'] ?>" title="Çıkar"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Time tracking -->
        <div class="kart">
            <div class="satir-esnek arasi mb-2"><div class="kart-baslik" style="font-size:14px">Zaman Takibi</div><button class="btn btn-sm" data-modal="modalTime"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button></div>
            <div class="orta" style="padding:8px 0"><div class="stat-deger" style="font-size:26px"><?= format_minutes($totalMin) ?></div><div class="hucre-alt">toplam kayıtlı süre</div></div>
            <?php if ($zamanlar): ?><div class="dikey mt-2" style="gap:8px;max-height:200px;overflow-y:auto">
                <?php foreach ($zamanlar as $z): ?>
                <div class="satir-esnek arasi kucuk" style="padding:7px 0;border-bottom:1px solid var(--border)"><div><div class="kalin"><?= format_minutes($z['minutes']) ?></div><div class="hucre-alt"><?= e($z['name']) ?> · <?= format_date($z['date']) ?></div></div></div>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Drive link -->
<div class="modal-katman" id="modalDriveLink">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Drive Linki Ekle</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="archive_link_add">
        <input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Bağlantı Adı</label><input name="name" class="girdi" placeholder="Örn. Kurgu v2 — final klasörü"></div>
            <div class="form-grup"><label class="form-etiket">Drive Linki <span class="zorunlu">*</span></label><input name="url" class="girdi" required placeholder="https://drive.google.com/..."><div class="form-ipucu">İş teslimlerinde dosya yüklemek yerine Drive klasör/dosya linki bırakın.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Ekle</button></div>
    </form></div>
</div>

<!-- Modals -->
<div class="modal-katman" id="modalTime">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Zaman Kaydı Ekle</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="time_add" data-refresh="evet">
        <input type="hidden" name="task_id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Saat</label><input type="number" name="time" class="girdi" min="0" value="0"></div>
                <div class="form-grup"><label class="form-etiket">Dakika</label><input type="number" name="minutes" class="girdi" min="0" max="59" value="30"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Tarih</label><input type="date" name="date" class="girdi" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="description" class="girdi" placeholder="Ne üzerinde çalıştınız?"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<div class="modal-katman" id="modalTaskDuzen">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Görevi Düzenle</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="task_save">
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık</label><input name="title" class="girdi" value="<?= e($task['title']) ?>" required></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="description" class="metin-alani"><?= e($task['description']) ?></textarea></div>
            <div class="form-grup">
                <label class="form-etiket">Atanan Kişiler <span class="metin-muted" style="font-weight:400">(birden fazla seçilebilir)</span></label>
                <input type="hidden" name="assignees" class="atananlar-json">
                <div class="izgara izgara-2" style="gap:6px;max-height:150px;overflow-y:auto;padding:2px">
                    <?php foreach ($team as $k): ?>
                    <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="atanan-kutu" value="<?= $k['id'] ?>" <?= in_array($k['id'], $assigneeIds) ? 'checked' : '' ?>> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($k['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-grup"><label class="form-etiket">Öncelik</label><select name="priority" class="secim"><?php foreach (PRIORITIES as $k => $v): ?><option value="<?= $k ?>" <?= $task['priority'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Başlangıç Tarihi</label><input type="date" name="start_date" class="girdi" value="<?= e($task['start_date']) ?>"></div>
                <div class="form-grup"><label class="form-etiket">Son Tarih</label><input type="date" name="due_date" class="girdi" value="<?= e($task['due_date']) ?>"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tahmini Süre (saat)</label><input name="estimated_time" class="girdi" value="<?= $task['estimated_minutes'] ? round($task['estimated_minutes'] / 60, 1) : '' ?>" placeholder="Örn. 4,5"></div>
                <div class="form-grup"><label class="form-etiket">Tekrar</label><select name="repeat" class="secim"><?php foreach (REPEAT_OPTIONS as $k => $v): ?><option value="<?= $k ?>" <?= $task['repeat'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Etiketler</label><input name="tags" class="girdi" value="<?= e($task['tags']) ?>" placeholder="video, instagram, acil-revize (virgülle ayırın)"></div>
            <div class="form-grup"><label class="form-etiket">Bağlı Olduğu Görev</label><select name="bagimli_id" class="secim"><option value="">— Bağımsız</option><?php foreach ($projectTasks as $pg): ?><option value="<?= $pg['id'] ?>" <?= $pg['id'] == $task['bagimli_id'] ? 'selected' : '' ?>><?= e($pg['title']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilen görev tamamlanmadan bu görev ilerleyemez.</div></div>
            <?php if (is_pm()): ?>
            <div class="form-grup">
                <label class="satir-esnek anahtar" style="gap:10px;cursor:pointer">
                    <input type="checkbox" <?= $task['lock_bypassed'] ? 'checked' : '' ?> onchange="event.preventDefault();lockToggle()">
                    <span class="kucuk"><b>Kilidi devre dışı bırak</b> — akış ve bağımlılık kuralları bu görev için uygulanmaz (loglanır)</span>
                </label>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-alt">
            <button type="button" class="btn btn-tehlike" data-action="gorev_sil" data-id="<?= $id ?>" data-approval="Görev silinsin mi?" data-redirect="project.php?id=<?= $task['project_id'] ?>" style="margin-right:auto">Sil</button>
            <button type="button" class="btn" data-action="task_archive" data-id="<?= $id ?>" data-approval="<?= $task['is_archived'] ? 'Görev arşivden çıkarılsın mı?' : 'Görev arşive taşınsın mı?' ?>"><?= icon('box', 14) ?> <?= $task['is_archived'] ? 'Arşivden Çıkar' : 'Arşivle' ?></button>
            <button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button>
        </div>
    </form></div>
</div>

<!-- Step owner assignment -->
<div class="modal-katman" id="modalStepOwner">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Adım Sorumlusu</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <form data-ajax="step_owner" data-refresh="evet">
        <input type="hidden" name="id" id="stepOwnerId">
        <div class="modal-govde"><div class="form-grup"><label class="form-etiket">Sorumlu Kişi</label><select name="owner_id" class="secim"><option value="">— Kaldır</option><?php foreach ($team as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['name']) ?></option><?php endforeach; ?></select></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-close>İptal</button><button type="submit" class="btn btn-marka">Ata</button></div>
    </form></div>
</div>

<script>
// Live sync: if someone else changes this task, the page refreshes
window.sadaLive = { context: 'task', id: <?= $id ?>, hash: '<?= live_hash_task($id) ?>' };
const CHECK_SVG = '<svg width="20" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>';

async function statusChange(status) {
    const j = await api('task_status', { id: <?= $id ?>, status });
    if (j.ok) { toast('Durum güncellendi', 'basari'); liveRefresh(); setTimeout(() => location.reload(), 450); }
    else setTimeout(() => location.reload(), 1600); // if the lock rejected it, revert to the old value
}
function stepOwner(id) { document.getElementById('stepOwnerId').value = id; modalOpen('modalStepOwner'); }

/* Workflow step: update without a page reload */
async function stepComplete(id) {
    const j = await api('step_complete', { id });
    if (!j.ok) return;
    toast(j.message, 'basari', 1800);
    j.steps.forEach(a => {
        const el = document.querySelector(`[data-adim="${a.id}"]`);
        if (!el) return;
        el.classList.toggle('tamam', a.status === 'tamam');
        el.classList.toggle('aktif', a.status === 'aktif');
        el.querySelector('.akis-yuvarlak').innerHTML = a.status === 'tamam' ? CHECK_SVG : el.dataset.sort_order;
    });
    document.getElementById('stepCounter').textContent = j.is_done_adet + '/' + j.total + ' adım tamamlandı';
    // If the task is completed, sync the status picker and badge
    const picker = document.getElementById('statusPicker');
    if (picker && picker.value !== j.task_status) { picker.value = j.task_status; toast('Görev durumu: ' + j.task_status_tag, 'basari', 2400); }
    liveRefresh();
}

/* Checklist: update without a page reload */
function checkSummary() {
    const hepsi = document.querySelectorAll('#checkList .kontrol-oge').length;
    const is_done = document.querySelectorAll('#checkList .kontrol-oge.tamam').length;
    document.getElementById('checkCounter').textContent = hepsi ? is_done + '/' + hepsi : '';
    const bar = document.getElementById('checkBar');
    bar.parentElement.style.display = hepsi ? '' : 'none';
    bar.style.width = (hepsi ? Math.round(is_done / hepsi * 100) : 0) + '%';
}
async function checkAdd(e) {
    e.preventDefault();
    const girdi = document.getElementById('checkNew');
    const name = girdi.value.trim(); if (!name) return false;
    const j = await api('check_add', { task_id: <?= $id ?>, name });
    if (j.ok) {
        girdi.value = '';
        const list = document.getElementById('checkList');
        const bos = list.querySelector('.metin-muted'); if (bos) bos.remove();
        const div = document.createElement('div');
        div.className = 'kontrol-oge';
        div.innerHTML = `<input type="checkbox" onchange="checkToggle(${j.id}, this)"><span class="kontrol-metin"></span><button class="ikon-eylem tehlike" style="width:26px;height:26px" data-action="check_delete" data-id="${j.id}" data-onay="Madde silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
        div.querySelector('.kontrol-metin').textContent = j.name;
        list.appendChild(div);
        checkSummary();
        liveRefresh();
    }
    return false;
}
async function checkToggle(id, box) {
    const j = await api('check_toggle', { id });
    if (j.ok) { box.closest('.kontrol-oge').classList.toggle('tamam', box.checked); checkSummary(); liveRefresh(); }
    else box.checked = !box.checked;
}
async function lockToggle() {
    const j = await api('lock_toggle', { id: <?= $id ?> });
    if (j.ok) { toast(j.message, 'basari'); liveRefresh(); setTimeout(() => location.reload(), 650); }
}
</script>
<div class="modal-katman" id="modalAiSummary">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">🪄 Görev Özeti</div><button class="modal-kapat" data-modal-close>✕</button></div>
    <div class="modal-govde"><div class="kucuk metin-2" id="aiSummaryText" style="white-space:pre-wrap;line-height:1.7">Özet hazırlanıyor...</div></div></div>
</div>
<script>
async function aiSummary(taskId) {
    modalOpen('modalAiSummary');
    const box = document.getElementById('aiSummaryText');
    box.textContent = 'Özet hazırlanıyor... (~15 sn)';
    const j = await api('ai_summarize', { task_id: taskId });
    box.textContent = j.ok ? j.summary : (j.error || 'Özet üretilemedi.');
}
</script>
<?php page_end(); ?>

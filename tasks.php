<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/components.php';
$u = require_staff();

$projectFiltre = (int)($_GET['project'] ?? 0);
$periodFiltre = (int)($_GET['period'] ?? 0);
$filtre = $_GET['filtre'] ?? '';
$view = in_array($_GET['view'] ?? '', ['kanban', 'tablo']) ? $_GET['view'] : ($u['task_view'] ?: 'kanban');

$where_sql = $filtre === 'archive' ? "g.is_archived=1" : "g.is_archived=0";
$params = [];
// Interns only see tasks assigned to them
if (is_intern()) {
    $where_sql .= " AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees gas WHERE gas.task_id=g.id AND gas.user_id=?))";
    $params[] = $u['id']; $params[] = $u['id'];
}
if ($projectFiltre) { $where_sql .= " AND g.project_id=?"; $params[] = $projectFiltre; }
if ($periodFiltre) { $where_sql .= " AND g.period_id=?"; $params[] = $periodFiltre; }
if ($filtre === 'benim') { $where_sql .= " AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees gax WHERE gax.task_id=g.id AND gax.user_id=?))"; $params[] = $u['id']; $params[] = $u['id']; }
if ($filtre === 'overdue') { $where_sql .= " AND g.due_date<CURDATE() AND g.status!='tamamlandi'"; }

$tasks = rows("SELECT g.*, p.name project_name, d.color client_color, uu.name assignee_name, uu.color assignee_color, uu.avatar assignee_avatar,
    bg.status bagimli_status, bg.title bagimli_title,
    (SELECT COUNT(*) FROM task_checklist k WHERE k.task_id=g.id) check_total,
    (SELECT COUNT(*) FROM task_checklist k WHERE k.task_id=g.id AND k.is_done=1) check_is_done,
    (SELECT COUNT(*) FROM task_steps ga WHERE ga.task_id=g.id) step_total,
    (SELECT COUNT(*) FROM task_steps ga WHERE ga.task_id=g.id AND ga.status='tamam') step_is_done,
    (SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z WHERE z.task_id=g.id) harcanan_min,
    (SELECT COUNT(*) FROM task_assignees gaa WHERE gaa.task_id=g.id) assignee_count,
    (SELECT GROUP_CONCAT(u3.name SEPARATOR ', ') FROM task_assignees ga3 JOIN users u3 ON u3.id=ga3.user_id WHERE ga3.task_id=g.id) assignee_names
    FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id
    LEFT JOIN users uu ON uu.id=g.assignee_id LEFT JOIN tasks bg ON bg.id=g.bagimli_id
    WHERE $where_sql ORDER BY g.sort_order, g.due_date IS NULL, g.due_date", $params);

$activeProject = $projectFiltre ? row("SELECT name FROM projects WHERE id=?", [$projectFiltre]) : null;
$team = rows("SELECT id, name, color FROM users WHERE role IN ('yonetici','pm','ekip') AND is_active=1 ORDER BY name");
$templates = rows("SELECT * FROM workflow_templates ORDER BY name");

// Active workflow steps I am responsible for
$stepKosulSql = only_own_steps()
    ? "ga.owner_id=?"
    : "(ga.owner_id=? OR (ga.owner_id IS NULL AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees gat WHERE gat.task_id=g.id AND gat.user_id=?))))";
$stepParam = only_own_steps() ? [$u['id']] : [$u['id'], $u['id'], $u['id']];
$my_steps = rows("SELECT ga.id step_id, ga.name step_name, ga.status step_status, g.id task_id, g.title, p.name project_name
    FROM task_steps ga JOIN tasks g ON g.id=ga.task_id JOIN projects p ON p.id=g.project_id
    WHERE ga.status IN ('aktif','bekliyor') AND g.is_archived=0 AND g.status!='tamamlandi' AND $stepKosulSql
    ORDER BY ga.status='aktif' DESC, g.due_date IS NULL, g.due_date LIMIT 12", $stepParam);
$my_steps = array_filter($my_steps, fn($a2) => $a2['step_status'] === 'aktif' || count($my_steps) < 8);

page_start('Görevler', 'tasks');
?>
<?php if ($my_steps):
    $activeStepCount = count(array_filter($my_steps, fn($a3) => $a3['step_status'] === 'aktif')); ?>
<div class="kart mb-3 katla kapali" data-collapse="my_steps" style="border-color:var(--marka)">
    <button class="kart-baslik" data-collapse-btn type="button" style="display:flex;align-items:center;gap:9px;margin:0">
        <span class="katla-ok"><svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" width="14"><path d="M19 9l-7 7-7-7"/></svg></span>
        <?= icon('roket', 16) ?> Adımlarım <span class="rozet r-devam" style="padding:1px 9px"><?= $activeStepCount ?> sıra sende</span><span class="hucre-alt">· <?= count($my_steps) ?> adım</span>
    </button>
    <div class="dikey katla-icerik mt-2" style="gap:6px">
        <?php foreach ($my_steps as $adm): ?>
        <div class="satir-esnek arasi" style="padding:9px 12px;background:var(--surface-2);border-radius:10px;gap:10px">
            <a href="task.php?id=<?= $adm['task_id'] ?>" class="kucuk" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><b><?= e($adm['step_name']) ?></b> · <?= e($adm['title']) ?> <span class="metin-muted">(<?= e($adm['project_name']) ?>)</span></a>
            <?php if ($adm['step_status'] === 'aktif'): ?><button class="btn btn-sm btn-marka" data-action="step_complete" data-id="<?= $adm['step_id'] ?>" style="flex-shrink:0">Tamamla</button><?php else: ?><span class="rozet" style="flex-shrink:0">Sırada</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik">Görevler<?= $activeProject ? ' · ' . e($activeProject['name']) : '' ?></div>
        <div class="sayfa-alt"><?= count($tasks) ?> görev — <?= $view === 'tablo' ? 'hücrelere tıklayıp doğrudan düzenleyin' : 'panoda sürükleyerek durum değiştirin' ?></div>
    </div>
    <div class="sayfa-ust-aksiyon">
        <div class="gorunum-degistir">
            <button class="gorunum-btn <?= $view === 'kanban' ? 'aktif' : '' ?>" onclick="viewSec('kanban')">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 5h4v14H4zM10 5h4v9h-4zM16 5h4v6h-4z"/></svg> Kanban
            </button>
            <button class="gorunum-btn <?= $view === 'tablo' ? 'aktif' : '' ?>" onclick="viewSec('tablo')">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18M3 4h18v16H3z"/></svg> Tablo
            </button>
        </div>
        <?php if (!is_intern()): ?>
        <button class="btn btn-marka" data-modal="modalTask"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Görev</button>
        <?php endif; ?>
    </div>
</div>

<div class="filtre-bar">
    <div class="pill-filtre">
        <a href="?<?= http_build_query(array_filter(['project' => $projectFiltre, 'view' => $view])) ?>" class="pill <?= !$filtre ? 'aktif' : '' ?>">Tümü</a>
        <a href="?<?= http_build_query(array_filter(['filtre' => 'benim', 'project' => $projectFiltre, 'view' => $view])) ?>" class="pill <?= $filtre === 'benim' ? 'aktif' : '' ?>">Bana Atanan</a>
        <a href="?<?= http_build_query(array_filter(['filtre' => 'overdue', 'project' => $projectFiltre, 'view' => $view])) ?>" class="pill <?= $filtre === 'overdue' ? 'aktif' : '' ?>">Geciken</a>
        <a href="?<?= http_build_query(array_filter(['filtre' => 'archive', 'project' => $projectFiltre, 'view' => $view])) ?>" class="pill <?= $filtre === 'archive' ? 'aktif' : '' ?>">Arşiv</a>
    </div>
    <?php if ($view === 'tablo'): ?>
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="Görev ara..." data-search="#gorevTablo tbody tr"></div>
    <?php endif; ?>
    <?php if ($projectFiltre): ?><a href="tasks.php?view=<?= $view ?>" class="btn btn-sm btn-hayalet">Filtreyi Temizle ✕</a><?php endif; ?>
</div>

<?php if (!$tasks): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg></div><div class="bos-baslik">Görev bulunamadı</div><div class="bos-metin">Bu filtreye uygun görev yok. Yeni bir görev oluşturabilirsiniz.</div></div>

<?php elseif ($view === 'kanban'): ?>
<?php task_kanban($tasks, $projectFiltre); ?>

<?php else: /* ---------- TABLE VIEW ---------- */ ?>
<div class="tablo-sar">
<table class="tablo" id="taskTable">
    <thead><tr>
        <th class="siralanir">Görev <span class="sira-isaret">↕</span></th>
        <th class="siralanir">Proje <span class="sira-isaret">↕</span></th>
        <th>Atanan</th>
        <th>Durum</th>
        <th>Öncelik</th>
        <th class="siralanir">Başlangıç <span class="sira-isaret">↕</span></th>
        <th class="siralanir">Son Tarih <span class="sira-isaret">↕</span></th>
        <th class="siralanir">Tahmin/Gerçek <span class="sira-isaret">↕</span></th>
        <th class="siralanir">Akış <span class="sira-isaret">↕</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($tasks as $gr):
        $locked = !empty($gr['bagimli_status']) && $gr['bagimli_status'] !== 'tamamlandi' && empty($gr['lock_bypassed']);
        $workflowRate = $gr['step_total'] ? round($gr['step_is_done'] / $gr['step_total'] * 100) : null; ?>
    <tr data-search="<?= e($gr['title'] . ' ' . $gr['project_name'] . ' ' . ($gr['tags'] ?? '')) ?>">
        <td style="min-width:220px">
            <a href="task.php?id=<?= $gr['id'] ?>" class="hucre-ana" style="display:block"><?= $locked ? icon('lock', 12) . ' ' : '' ?><?= $gr['repeat'] !== 'yok' ? icon('repeat', 12) . ' ' : '' ?><?= e($gr['title']) ?></a>
            <div class="satir-esnek sarma mt-1" style="gap:4px"><?= tag_chips($gr['tags']) ?><?php if ($gr['check_total']): ?><span class="kanban-etiket"><?= icon('approval', 12) ?> <?= $gr['check_is_done'] ?>/<?= $gr['check_total'] ?></span><?php endif; ?></div>
        </td>
        <td class="kucuk" data-sort="<?= e($gr['project_name']) ?>"><span class="etiket-nokta" style="width:8px;height:8px;background:<?= e($gr['client_color']) ?>;margin-right:5px"></span><?= e($gr['project_name']) ?></td>
        <td class="hucre-duzen">
            <select class="secim" data-old="<?= $gr['assignee_id'] ?>" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'atanan_id')">
                <option value="">—</option>
                <?php foreach ($team as $k): ?><option value="<?= $k['id'] ?>" <?= $k['id'] == $gr['assignee_id'] ? 'selected' : '' ?>><?= e($k['name']) ?></option><?php endforeach; ?>
            </select>
        </td>
        <td class="hucre-duzen">
            <select class="secim" data-old="<?= $gr['status'] ?>" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'durum')">
                <?php foreach (TASK_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $gr['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </td>
        <td class="hucre-duzen">
            <select class="secim" data-old="<?= $gr['priority'] ?>" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'oncelik')">
                <?php foreach (PRIORITIES as $k => $v): ?><option value="<?= $k ?>" <?= $gr['priority'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </td>
        <td class="hucre-duzen" data-sort="<?= e($gr['start_date'] ?? '9999') ?>">
            <input type="date" class="girdi" value="<?= e($gr['start_date']) ?>" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'baslangic_tarihi')">
        </td>
        <td class="hucre-duzen" data-sort="<?= e($gr['due_date'] ?? '9999') ?>">
            <input type="date" class="girdi" value="<?= e($gr['due_date']) ?>" style="<?= $gr['due_date'] && $gr['due_date'] < date('Y-m-d') && $gr['status'] !== 'tamamlandi' ? 'color:var(--tehlike)' : '' ?>" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'son_tarih')">
        </td>
        <td class="kucuk" data-sort="<?= $gr['estimated_minutes'] ?>">
            <span class="hucre-duzen"><input class="girdi" style="width:56px" value="<?= $gr['estimated_minutes'] ? round($gr['estimated_minutes'] / 60, 1) : '' ?>" placeholder="sa" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'tahmini_dakika')"></span>
            <span class="metin-muted">/ <?= $gr['harcanan_min'] ? format_minutes((int)$gr['harcanan_min']) : '—' ?></span>
        </td>
        <td data-sort="<?= $workflowRate ?? -1 ?>">
            <?php if ($workflowRate !== null): ?>
            <div class="satir-esnek" style="gap:8px"><div class="ilerleme" style="width:56px"><div class="ilerleme-dolu" style="width:<?= $workflowRate ?>%"></div></div><span class="kucuk"><?= $gr['step_is_done'] ?>/<?= $gr['step_total'] ?></span></div>
            <?php else: ?><span class="metin-muted kucuk">—</span><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="form-ipucu mt-2">💡 Hücrelere tıklayarak doğrudan düzenleyin; sütun başlıklarına tıklayarak sıralayın. Kilitli görevlerde durum değişikliği kurallara takılırsa eski değere döner.</div>
<?php endif; ?>

<?php task_modal($projectFiltre, $team, $templates); ?>
<script>
// Live sync: if someone else adds/moves a task, the list refreshes
window.sadaLive = { context: 'list', hash: '<?= live_hash_list() ?>' };
async function viewSec(g) {
    await api('view_preference', { gorunum: g });
    const url = new URL(location.href);
    url.searchParams.set('view', g);
    location.href = url.toString();
}
</script>
<?php page_end(); ?>

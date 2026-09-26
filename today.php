<?php
/**
 * SADA One — Today
 * Everything that is yours today on one page: the steps you hold, the pools of your skills, today's shoots and
 * publishing, your to-dos and what just happened on your work.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/now.php';
$u = require_staff();

$steps = now_my_steps($u, 30);
$plain = now_my_plain_tasks($u, 15);
$pool = now_pool($u, 15);
$shoots = now_shoots_today($u);
$publish = now_publish_today();
$recent = now_recent($u, 8);
$todos = rows("SELECT id, name FROM personal_todos WHERE user_id=? AND is_done=0 ORDER BY sort_order LIMIT 8", [$u['id']]);

page_start('Bugün', 'today');
?>
<div class="page-top">
    <div>
        <div class="page-title">Bugün</div>
        <div class="page-bottom"><?= DAYS[(int)date('N') - 1] ?>, <?= format_date(date('Y-m-d')) ?> — <?= $steps || $plain ? 'sende ' . (count($steps) + count($plain)) . ' iş var' : 'sende bekleyen iş yok' ?><?= $pool ? ', havuzunda ' . count($pool) . ' sahipsiz adım' : '' ?></div>
    </div>
</div>

<div class="today-layout">
    <div>
        <div class="card mb-3">
            <div class="card-title mb-2" style="font-size:15px">Sıra sende <span class="badge" style="padding:1px 8px"><?= count($steps) + count($plain) ?></span></div>
            <?php if (!$steps && !$plain): ?>
            <div class="text-muted small" style="padding:12px 0">Sende bekleyen adım yok. Havuza göz atabilir ya da yapılacaklarını toparlayabilirsin.</div>
            <?php endif; ?>
            <?php foreach ($steps as $s): ?>
            <a href="task.php?id=<?= $s['task_id'] ?>" class="now-row">
                <span class="now-kind"><?= STEP_KINDS[$s['step_kind']] ?></span>
                <span class="now-main"><span class="cell-main"><?= e($s['step_name']) ?> — <?= e($s['title']) ?></span><span class="cell-bottom"><?= e($s['client_name']) ?> · <?= e($s['project_name']) ?><?= ($d = now_due($s)) ? ' · ' . $d : '' ?></span></span>
                <span class="now-when cell-bottom"><?= now_waiting($s['activated_at']) ?></span>
            </a>
            <?php endforeach; ?>
            <?php foreach ($plain as $t): ?>
            <a href="task.php?id=<?= $t['task_id'] ?>" class="now-row">
                <span class="now-kind">İş</span>
                <span class="now-main"><span class="cell-main"><?= e($t['title']) ?></span><span class="cell-bottom"><?= e($t['project_name']) ?><?= ($d = now_due($t)) ? ' · ' . $d : '' ?></span></span>
                <span class="now-when"><?= badge($t['status'], TASK_STATUSES) ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="card mb-3">
            <div class="card-title mb-2" style="font-size:15px">Havuz <span class="badge" style="padding:1px 8px"><?= count($pool) ?></span> <span class="cell-bottom" style="font-weight:400">· uzmanlıklarına düşen sahipsiz adımlar</span></div>
            <?php if (!$pool): ?>
            <div class="text-muted small" style="padding:12px 0"><?= user_skill_ids((int)$u['id']) ? 'Uzmanlıklarının havuzunda bekleyen adım yok.' : 'Sana tanımlı uzmanlık yok; yöneticin Kullanıcılar sayfasından ekleyebilir.' ?></div>
            <?php else: foreach ($pool as $p): ?>
            <div class="now-row">
                <span class="now-kind"><?= e($p['skill_name']) ?></span>
                <a href="task.php?id=<?= $p['task_id'] ?>" class="now-main"><span class="cell-main"><?= e($p['step_name']) ?> — <?= e($p['title']) ?></span><span class="cell-bottom"><?= e($p['project_name']) ?><?= ($d = now_due($p)) ? ' · ' . $d : '' ?> · <?= now_waiting($p['activated_at']) ?></span></a>
                <span class="now-when"><button class="btn btn-sm btn-brand" data-action="step_claim" data-id="<?= $p['step_id'] ?>">Ben alıyorum</button></span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div>
        <div class="card mb-2">
            <div class="card-title mb-2" style="font-size:14px"><?= icon('camera', 15) ?> Bugünün çekimleri</div>
            <?php if (!$shoots): ?><div class="text-muted small">Bugün çekim yok.</div>
            <?php else: foreach ($shoots as $sh): ?>
            <a href="shoot-list.php" class="row-flex between" style="padding:7px 0;border-bottom:1px solid var(--border);gap:8px;color:inherit">
                <span style="min-width:0"><span class="small bold"><?= substr($sh['start'], 11, 5) ?> · <?= e($sh['title']) ?></span><br><span class="cell-bottom"><?= e($sh['client_name'] ?? '') ?><?= $sh['place'] ? ' · ' . e($sh['place']) : '' ?></span></span>
                <?php if ($sh['mine']): ?><span class="badge badge-type">sen de</span><?php endif; ?>
            </a>
            <?php endforeach; endif; ?>
        </div>

        <div class="card mb-2">
            <div class="card-title mb-2" style="font-size:14px"><?= icon('rocket', 15) ?> Bugün yayında</div>
            <?php if (!$publish): ?><div class="text-muted small">Bugün yayına çıkacak iş yok.</div>
            <?php else: foreach ($publish as $pb): ?>
            <a href="task.php?id=<?= $pb['task_id'] ?>" class="row-flex between" style="padding:7px 0;border-bottom:1px solid var(--border);gap:8px;color:inherit">
                <span style="min-width:0"><span class="small bold"><?= $pb['publish_time'] ? substr($pb['publish_time'], 0, 5) . ' · ' : '' ?><?= e($pb['title']) ?></span><br><span class="cell-bottom"><?= e($pb['client_name']) ?></span></span>
                <?= badge($pb['status'], TASK_STATUSES) ?>
            </a>
            <?php endforeach; endif; ?>
        </div>

        <div class="card mb-2">
            <div class="row-flex between mb-2"><div class="card-title" style="font-size:14px">Yapılacaklarım</div><a href="my-space.php" class="mini-btn">Alanım →</a></div>
            <?php if (!$todos): ?><div class="text-muted small">Açık madde yok. Sağ alttaki kalem düğmesinden hızlıca ekleyebilirsin.</div>
            <?php else: foreach ($todos as $td): ?>
            <label class="check-item"><input type="checkbox" onchange="api('personal_todo_toggle', { id: <?= $td['id'] ?> }); this.closest('.check-item').style.opacity = '.4'"><span class="check-text small"><?= e($td['name']) ?></span></label>
            <?php endforeach; endif; ?>
        </div>

        <div class="card mb-2">
            <div class="card-title mb-2" style="font-size:14px">Son olanlar</div>
            <?php if (!$recent): ?><div class="text-muted small">İşlerinde okunmamış yeni bir şey yok.</div>
            <?php else: foreach ($recent as $n): ?>
            <a href="<?= e($n['link'] ?: '#') ?>" style="display:block;padding:7px 0;border-bottom:1px solid var(--border);color:inherit">
                <span class="small bold"><?= e($n['title']) ?></span><?php if ($n['message']): ?><br><span class="cell-bottom"><?= e(mb_strimwidth($n['message'], 0, 90, '…')) ?></span><?php endif; ?>
                <br><span class="cell-bottom"><?= time_ago($n['created']) ?></span>
            </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?php page_end(); ?>

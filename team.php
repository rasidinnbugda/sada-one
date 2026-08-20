<?php
/**
 * SADA One — Ekip Panosu
 * Kim ne üzerinde çalışıyor, kim boşta — anlık meşguliyet görünümü.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_staff();

$weekHead = date('Y-m-d', strtotime('monday this week'));
$members = rows("SELECT us.id, us.name, us.color, us.avatar, us.job_title, us.role, us.weekly_capacity,
    (SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z WHERE z.user_id=us.id AND z.date=CURDATE()) today_min,
    (SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z WHERE z.user_id=us.id AND z.date>=?) week_min
    FROM users us WHERE us.role IN ('yonetici','pm','ekip','finans') AND us.is_active=1 ORDER BY us.name", [$weekHead]);

// Her üyenin devam eden görevleri (atanan_id VEYA çoklu atama üzerinden)
foreach ($members as &$member) {
    $member['ongoing_edenler'] = rows("SELECT g.id, g.title, g.status, g.due_date, p.name project_name, d.color client_color
        FROM tasks g JOIN projects p ON p.id=g.project_id JOIN clients d ON d.id=p.client_id
        WHERE g.is_archived=0 AND g.status IN ('devam','incelemede','onayda')
        AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees ga WHERE ga.task_id=g.id AND ga.user_id=?))
        ORDER BY FIELD(g.status,'devam','incelemede','onayda'), g.due_date IS NULL, g.due_date LIMIT 6", [$member['id'], $member['id']]);
    $member['pending'] = (int)val("SELECT COUNT(*) FROM tasks g WHERE g.is_archived=0 AND g.status='yapilacak'
        AND (g.assignee_id=? OR EXISTS(SELECT 1 FROM task_assignees ga WHERE ga.task_id=g.id AND ga.user_id=?))", [$member['id'], $member['id']]);
}
unset($member);

$mesgulCount = count(array_filter($members, fn($m) => $m['ongoing_edenler']));
$bostaCount = count($members) - $mesgulCount;

page_start('Ekip', 'team');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Ekip Panosu</div><div class="sayfa-alt">Şu an kim ne üzerinde çalışıyor — <?= $mesgulCount ?> meşgul, <?= $bostaCount ?> boşta</div></div>
</div>

<div class="izgara izgara-auto">
    <?php foreach ($members as $member):
        $bosta = !$member['ongoing_edenler'];
        $targetMin = (int)$member['weekly_capacity'] * 60;
        $rate = $targetMin > 0 ? min(100, round($member['week_min'] / $targetMin * 100)) : 0; ?>
    <div class="kart" style="<?= $bosta ? 'border-color:rgba(53,198,107,.35)' : '' ?>">
        <div class="satir-esnek arasi mb-2">
            <div class="satir-esnek" style="gap:11px">
                <?= avatar($member, 44) ?>
                <div>
                    <div class="kalin"><?= e($member['name']) ?></div>
                    <div class="hucre-alt"><?= $member['job_title'] ? e($member['job_title']) : ROLLER[$member['role']] ?></div>
                </div>
            </div>
            <?php if ($bosta): ?>
            <span class="rozet r-onaylandi">Boşta</span>
            <?php else: ?>
            <span class="rozet r-devam"><?= count($member['ongoing_edenler']) ?> aktif iş</span>
            <?php endif; ?>
        </div>

        <?php if ($bosta): ?>
        <div class="metin-muted kucuk" style="padding:10px 0">
            Devam eden işi yok<?= $member['pending'] ? " — sırada {$member['pending']} bekleyen görev var" : '. Yeni görev atanabilir.' ?>
        </div>
        <?php else: ?>
        <div class="dikey mt-1" style="gap:6px">
            <?php foreach ($member['ongoing_edenler'] as $dg):
                $overdue = $dg['due_date'] && $dg['due_date'] < date('Y-m-d'); ?>
            <a href="task.php?id=<?= $dg['id'] ?>" class="satir-esnek arasi" style="padding:7px 10px;background:var(--surface-2);border-radius:9px;gap:8px">
                <span class="satir-esnek kucuk" style="gap:7px;min-width:0">
                    <span class="etiket-nokta" style="width:7px;height:7px;background:<?= e($dg['client_color']) ?>;flex-shrink:0"></span>
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($dg['title']) ?></span>
                </span>
                <?= badge($dg['status'], GOREV_DURUMLARI) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="satir-esnek arasi mt-2" style="gap:10px">
            <span class="hucre-alt">Bugün: <b style="color:var(--text)"><?= $member['today_min'] ? format_minutes((int)$member['today_min']) : '—' ?></b></span>
            <div class="satir-esnek" style="gap:8px;flex:1;max-width:150px">
                <div class="ilerleme" style="flex:1"><div class="ilerleme-dolu <?= $rate > 100 ? 'asiri' : ($rate > 80 ? 'yogun' : '') ?>" data-rate="<?= $rate ?>" style="width:0"></div></div>
                <span class="hucre-alt">%<?= $rate ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="form-ipucu mt-2 orta">Haftalık doluluk çubuğu, kayıtlı süre ÷ haftalık kapasite hedefine göre hesaplanır.</div>
<?php page_end(); ?>

<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_permission('rapor');

// General metrics
$totalTask = (int)val("SELECT COUNT(*) FROM tasks");
$doneTask = (int)val("SELECT COUNT(*) FROM tasks WHERE status='tamamlandi'");
$overdueTask = (int)val("SELECT COUNT(*) FROM tasks WHERE due_date<CURDATE() AND status!='tamamlandi'");
$doneRate = $totalTask ? round($doneTask / $totalTask * 100) : 0;

// Status distribution
$statusDagilim = [];
foreach (TASK_STATUSES as $k => $v) $statusDagilim[$k] = (int)val("SELECT COUNT(*) FROM tasks WHERE status=?", [$k]);
$maxStatus = max(1, max($statusDagilim));

// Per-person performance
$people = rows("SELECT u.id, u.name, u.color,
    (SELECT COUNT(*) FROM tasks g WHERE g.assignee_id=u.id) total,
    (SELECT COUNT(*) FROM tasks g WHERE g.assignee_id=u.id AND g.status='tamamlandi') is_done,
    (SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z WHERE z.user_id=u.id) minutes
    FROM users u WHERE u.role IN ('yonetici','pm','ekip') AND u.is_active=1 ORDER BY total DESC");

// Project count per client file
$clientDagilim = rows("SELECT d.name, d.color, COUNT(p.id) project FROM clients d LEFT JOIN projects p ON p.client_id=d.id GROUP BY d.id ORDER BY project DESC LIMIT 8");
$maxClient = max(1, max(array_column($clientDagilim, 'project') ?: [1]));

// Approval statistics
$approvalTotal = (int)val("SELECT COUNT(*) FROM approvals");
$approvalApprovedItems = (int)val("SELECT COUNT(*) FROM approvals WHERE status='onaylandi'");
$approvalPending = (int)val("SELECT COUNT(*) FROM approvals WHERE status='bekliyor'");

page_start('Raporlar', 'reports');
$statusColor = ['yapilacak' => 'var(--muted)', 'devam' => 'var(--info)', 'incelemede' => 'var(--warning)', 'onayda' => '#a58bf0', 'tamamlandi' => 'var(--basari)'];
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Raporlar & Analiz</div><div class="sayfa-alt">Performans ve iş yükü özeti</div></div>
    <div class="sayfa-ust-aksiyon">
        <a href="export.php?type=gorevler" class="btn btn-sm"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M12 15V3m0 12l-4-4m4 4l4-4M3 17v2a2 2 0 002 2h14a2 2 0 002-2v-2"/></svg> Görevler CSV</a>
        <a href="export.php?type=zaman" class="btn btn-sm"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M12 15V3m0 12l-4-4m4 4l4-4M3 17v2a2 2 0 002 2h14a2 2 0 002-2v-2"/></svg> Zaman CSV</a>
    </div>
</div>

<div class="stat-izgara">
    <div class="stat-kart"><div class="stat-deger"><?= $doneRate ?>%</div><div class="stat-etiket">Genel Tamamlanma</div><div class="ilerleme mt-2"><div class="ilerleme-dolu" data-rate="<?= $doneRate ?>" style="width:0"></div></div></div>
    <div class="stat-kart"><div class="stat-deger" data-counter="<?= $totalTask ?>">0</div><div class="stat-etiket">Toplam Görev</div></div>
    <div class="stat-kart"><div class="stat-deger" data-counter="<?= $doneTask ?>">0</div><div class="stat-etiket">Tamamlanan</div></div>
    <div class="stat-kart"><div class="stat-deger" style="color:var(--tehlike)" data-counter="<?= $overdueTask ?>">0</div><div class="stat-etiket">Geciken</div></div>
</div>

<div class="izgara izgara-2">
    <!-- Status distribution -->
    <div class="kart">
        <div class="kart-baslik mb-3">Görev Durum Dağılımı</div>
        <div class="dikey" style="gap:14px">
            <?php foreach ($statusDagilim as $k => $count): ?>
            <div>
                <div class="satir-esnek arasi mb-2"><span class="satir-esnek kucuk" style="gap:7px"><span class="etiket-nokta" style="background:<?= $statusColor[$k] ?>"></span><?= TASK_STATUSES[$k] ?></span><span class="kucuk kalin"><?= $count ?></span></div>
                <div class="ilerleme"><div class="ilerleme-dolu" data-rate="<?= round($count / $maxStatus * 100) ?>" style="width:0;background:<?= $statusColor[$k] ?>"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Projects per client file -->
    <div class="kart">
        <div class="kart-baslik mb-3">Dosya Başına Proje</div>
        <div class="dikey" style="gap:14px">
            <?php foreach ($clientDagilim as $d): ?>
            <div>
                <div class="satir-esnek arasi mb-2"><span class="satir-esnek kucuk" style="gap:7px"><span class="etiket-nokta" style="background:<?= e($d['color']) ?>"></span><?= e($d['name']) ?></span><span class="kucuk kalin"><?= $d['project'] ?></span></div>
                <div class="ilerleme"><div class="ilerleme-dolu" data-rate="<?= round($d['project'] / $maxClient * 100) ?>" style="width:0;background:<?= e($d['color']) ?>"></div></div>
            </div>
            <?php endforeach; ?>
            <?php if (!$clientDagilim): ?><div class="metin-muted kucuk">Veri yok</div><?php endif; ?>
        </div>
    </div>
</div>

<div class="kart mt-3">
    <div class="kart-baslik mb-3">Ekip Performansı</div>
    <div class="tablo-sar"><table class="tablo"><thead><tr><th>Kişi</th><th>Toplam Görev</th><th>Tamamlanan</th><th>Tamamlanma</th><th>Kayıtlı Süre</th></tr></thead><tbody>
        <?php foreach ($people as $k):
            $rate = $k['total'] ? round($k['is_done'] / $k['total'] * 100) : 0; ?>
        <tr>
            <td><div class="satir-esnek" style="gap:9px"><?= avatar(['name' => $k['name'], 'color' => $k['color']], 30) ?><span class="hucre-ana"><?= e($k['name']) ?></span></div></td>
            <td><?= $k['total'] ?></td>
            <td><?= $k['is_done'] ?></td>
            <td><div class="satir-esnek" style="gap:10px"><div class="ilerleme" style="flex:1;max-width:120px"><div class="ilerleme-dolu" data-rate="<?= $rate ?>" style="width:0"></div></div><span class="kucuk kalin">%<?= $rate ?></span></div></td>
            <td class="kucuk"><?= format_minutes((int)$k['minutes']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="izgara izgara-3 mt-3">
    <div class="kart orta"><div class="stat-deger" data-counter="<?= $approvalTotal ?>">0</div><div class="stat-etiket">Toplam Onay Süreci</div></div>
    <div class="kart orta"><div class="stat-deger" style="color:var(--basari)" data-counter="<?= $approvalApprovedItems ?>">0</div><div class="stat-etiket">Onaylanan</div></div>
    <div class="kart orta"><div class="stat-deger" style="color:var(--uyari)" data-counter="<?= $approvalPending ?>">0</div><div class="stat-etiket">Bekleyen Onay</div></div>
</div>

<!-- CLIENT SATISFACTION -->
<?php
$genelRating = row("SELECT AVG(rating) ort, COUNT(*) adet FROM ratings");
if ((int)$genelRating['adet'] > 0):
    $dosyaPuanlari = rows("SELECT d.name, d.color, AVG(pu.rating) ort, COUNT(*) adet
        FROM ratings pu JOIN projects p ON p.id=pu.project_id JOIN clients d ON d.id=p.client_id
        GROUP BY d.id ORDER BY ort DESC");
    $lastComments = rows("SELECT pu.*, us.name customer_name, p.name project_name FROM ratings pu JOIN users us ON us.id=pu.user_id JOIN projects p ON p.id=pu.project_id WHERE pu.comment_box IS NOT NULL AND pu.comment_box!='' ORDER BY pu.id DESC LIMIT 6"); ?>
<div class="kart mt-3">
    <div class="satir-esnek arasi mb-3 sarma" style="gap:10px">
        <div class="kart-baslik">😊 Müşteri Memnuniyeti</div>
        <div class="satir-esnek" style="gap:10px">
            <?= stars((float)$genelRating['ort'], 18) ?>
            <span class="kalin" style="font-family:'Space Grotesk',sans-serif;font-size:20px"><?= number_format((float)$genelRating['ort'], 1, ',', '') ?></span>
            <span class="hucre-alt"><?= $genelRating['adet'] ?> değerlendirme</span>
        </div>
    </div>
    <div class="izgara izgara-2">
        <div>
            <div class="hucre-alt mb-2">Dosya bazında ortalama</div>
            <?php foreach ($dosyaPuanlari as $dp): ?>
            <div class="satir-esnek arasi" style="padding:9px 0;border-bottom:1px solid var(--border)">
                <span class="satir-esnek kucuk" style="gap:7px"><span class="etiket-nokta" style="background:<?= e($dp['color']) ?>"></span><?= e($dp['name']) ?></span>
                <span class="satir-esnek" style="gap:8px"><?= stars((float)$dp['ort']) ?><span class="kucuk kalin" style="<?= $dp['ort'] < 3 ? 'color:var(--tehlike)' : '' ?>"><?= number_format((float)$dp['ort'], 1, ',', '') ?></span><span class="hucre-alt">(<?= $dp['adet'] ?>)</span></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div>
            <div class="hucre-alt mb-2">Son yorumlar</div>
            <?php if (!$lastComments): ?><div class="metin-muted kucuk">Henüz yorumlu değerlendirme yok.</div>
            <?php else: foreach ($lastComments as $sy): ?>
            <div style="padding:9px 12px;background:var(--surface-2);border-radius:10px;margin-bottom:6px">
                <div class="satir-esnek arasi"><span class="kucuk kalin"><?= e($sy['customer_name']) ?></span><?= stars((float)$sy['rating'], 12) ?></div>
                <div class="kucuk metin-2 mt-1">"<?= e(mb_substr($sy['comment_box'], 0, 140)) ?>"</div>
                <div class="hucre-alt mt-1"><?= e($sy['project_name']) ?> · <?= time_ago($sy['created']) ?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php page_end(); ?>

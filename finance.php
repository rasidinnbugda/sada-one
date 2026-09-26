<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_permission('finance');

// Capacity data (this week)
$weekHead = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$capacities = permission('capacity') ? rows("SELECT us.id, us.name, us.color, us.avatar, us.job_title, us.weekly_capacity,
    (SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z WHERE z.user_id=us.id AND z.date BETWEEN ? AND ?) week_minutes,
    (SELECT COUNT(*) FROM tasks g WHERE g.is_archived=0 AND " . task_open_sql('g') . " AND (g.assignee_id=us.id OR EXISTS(SELECT 1 FROM task_assignees ga WHERE ga.task_id=g.id AND ga.user_id=us.id))) open_task
    FROM users us WHERE us.role IN ('admin','pm','team','finance') AND us.is_active=1 ORDER BY us.name", [$weekHead, $weekEnd]) : [];

// Expenses
$expenses = rows("SELECT gd.*, us.name person_name FROM expenses gd LEFT JOIN users us ON us.id=gd.user_id ORDER BY gd.date DESC LIMIT 200");
$expenseTotal = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='paid'");
$expensePending = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='pending'");

// Profit/Loss: last 6 months
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $monthKey = date('Y-m', strtotime("-$i months"));
    $monthlyData[] = [
        'label' => MONTHS[(int)date('n', strtotime("-$i months"))] . ' ' . date('y', strtotime("-$i months")),
        'income' => (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid' AND DATE_FORMAT(date,'%Y-%m')=?", [$monthKey]),
        'expense' => (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='paid' AND DATE_FORMAT(date,'%Y-%m')=?", [$monthKey]),
    ];
}
$maxAmount = max(1, max(array_merge(array_column($monthlyData, 'income'), array_column($monthlyData, 'expense'))));

// Quote & invoice documents
$documents = rows("SELECT b.*, d.name client_name FROM documents b LEFT JOIN clients d ON d.id=b.client_id ORDER BY b.id DESC LIMIT 100");
foreach ($documents as &$bg) {
    $lineItems = json_decode($bg['items'], true) ?: [];
    $bg['search'] = array_sum(array_map(fn($k) => $k['qty'] * $k['price'], $lineItems));
    $bg['total'] = $bg['search'] * (1 + $bg['vat_rate'] / 100);
}
unset($bg);
$allClients = rows("SELECT id, name FROM clients WHERE status='active' ORDER BY name");

// Budget target + this month's actuals
$budgetTarget = (float)setting('budget_target', '0');
$thisMonthIncome = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid' AND DATE_FORMAT(date,'%Y-%m')=?", [date('Y-m')]);

// Cash flow projection: next 3 months
$projection = [];
$monthlyContract = (float)val("SELECT COALESCE(SUM(contract_amount),0) FROM projects WHERE status='active' AND type='monthly'");
$monthlySalary = (float)val("SELECT COALESCE(SUM(salary),0) FROM users WHERE is_active=1 AND salary>0");
$monthlyRepeatExpense = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE `repeat`='monthly'");
for ($i = 1; $i <= 3; $i++) {
    $monthKey = date('Y-m', strtotime("+$i months"));
    $pendingCollection = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='pending' AND DATE_FORMAT(date,'%Y-%m')=?", [$monthKey]);
    $plannedExpense = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='pending' AND `repeat`='none' AND DATE_FORMAT(date,'%Y-%m')=?", [$monthKey]);
    $income = $monthlyContract + $pendingCollection;
    $expense = $monthlySalary + $monthlyRepeatExpense + $plannedExpense;
    $projection[] = ['label' => MONTHS[(int)date('n', strtotime("+$i months"))] . ' ' . date('y', strtotime("+$i months")), 'income' => $income, 'expense' => $expense];
}
$projMax = max(1, max(array_merge(array_column($projection, 'income'), array_column($projection, 'expense'))));

// Account balances: per-client debit/credit
$accounts = rows("SELECT d.id, d.name, d.color,
    COALESCE((SELECT SUM(o.amount) FROM payments o JOIN projects p ON p.id=o.project_id WHERE p.client_id=d.id AND o.type='invoice'),0) debt,
    COALESCE((SELECT SUM(o.amount) FROM payments o JOIN projects p ON p.id=o.project_id WHERE p.client_id=d.id AND o.type='collection' AND o.status='paid'),0) collect
    FROM clients d HAVING debt>0 OR collect>0 ORDER BY (debt-collect) DESC");

// Project profitability: contract amount − labor cost (logged time × person's hourly cost; hourly cost = salary/172)
$profitability = rows("SELECT p.id, p.name, p.contract_amount, d.name client_name, d.color client_color,
    COALESCE((SELECT SUM(z.minutes/60 * (us.salary/172)) FROM time_entries z JOIN tasks g ON g.id=z.task_id JOIN users us ON us.id=z.user_id WHERE g.project_id=p.id AND us.salary>0), 0) labor
    FROM projects p JOIN clients d ON d.id=p.client_id WHERE p.status IN ('active','completed') AND p.contract_amount>0 ORDER BY p.contract_amount DESC LIMIT 20");

$projectFilter = (int)($_GET['project'] ?? 0);
$where_sql = $projectFilter ? "o.project_id=$projectFilter" : "1=1";

$payments = rows("SELECT o.*, p.name project_name, d.name client_name FROM payments o JOIN projects p ON p.id=o.project_id JOIN clients d ON d.id=p.client_id WHERE $where_sql ORDER BY o.date DESC");
$projects = rows("SELECT id, name FROM projects ORDER BY name");

$totalInvoice = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE type='invoice'");
$collected = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid'");
$pending = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='pending'");
$overdue = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='overdue'");
$contractTotal = (float)val("SELECT COALESCE(SUM(contract_amount),0) FROM projects WHERE status='active'");

page_start('Finans', 'finance');
?>
<div class="page-top">
    <div><div class="page-title">Finans & Kapasite</div><div class="page-bottom">Fatura, tahsilat ve ekip çalışma kapasitesi</div></div>
    <div class="page-top-action">
        <a href="export.php?type=finance" class="btn"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 15V3m0 12l-4-4m4 4l4-4M3 17v2a2 2 0 002 2h14a2 2 0 002-2v-2"/></svg> CSV İndir</a>
        <button class="btn btn-brand" data-modal="modalPayment"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Kayıt Ekle</button>
    </div>
</div>

<div class="tab-container">
<div class="tabs">
    <button class="tab active" data-tab="records">Gelirler</button>
    <button class="tab" data-tab="expenses">Giderler</button>
    <button class="tab" data-tab="documents">Teklif & Fatura</button>
    <button class="tab" data-tab="account">Cari Hesap</button>
    <button class="tab" data-tab="profit_loss">Kâr / Zarar</button>
    <?php if (permission('capacity')): ?><button class="tab" data-tab="capacity">Ekip Kapasitesi</button><?php endif; ?>
</div>
<div class="tab-content active" id="tab-records">

<div class="stat-grid">
    <div class="stat-card"><div class="stat-icon"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 7h6m-6 4h6m-6 4h4M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"/></svg></div><div class="stat-value" style="font-size:22px"><?= money($totalInvoice) ?></div><div class="stat-label">Toplam Fatura</div></div>
    <div class="stat-card"><div class="stat-icon" style="background:rgba(53,198,107,.14);color:var(--success)"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="stat-value" style="font-size:22px;color:var(--success)"><?= money($collected) ?></div><div class="stat-label">Tahsil Edilen</div></div>
    <div class="stat-card"><div class="stat-icon" style="background:rgba(245,165,36,.14);color:var(--warning)"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="stat-value" style="font-size:22px;color:var(--warning)"><?= money($pending) ?></div><div class="stat-label">Bekleyen</div></div>
    <div class="stat-card"><div class="stat-icon" style="background:rgba(240,79,79,.14);color:var(--danger)"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/></svg></div><div class="stat-value" style="font-size:22px;color:var(--danger)"><?= money($overdue) ?></div><div class="stat-label">Geciken</div></div>
</div>

<div class="filter-bar">
    <select class="select" style="max-width:280px" onchange="location.href='?project='+this.value">
        <option value="0">Tüm Projeler</option>
        <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" <?= $projectFilter == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
    </select>
</div>

<?php if (!$payments): ?>
<div class="empty-state"><div class="empty-icon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 8c-2.21 0-4 .9-4 2s1.79 2 4 2 4 .9 4 2-1.79 2-4 2m0-8V6m0 12v-2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="empty-title">Finans kaydı yok</div><div class="empty-text">Fatura veya tahsilat kaydı ekleyerek başlayın.</div></div>
<?php else: ?>
<div class="table-wrap"><table class="table"><thead><tr><th>Kayıt</th><th>Proje</th><th>Tür</th><th>Tutar</th><th>Tarih</th><th>Durum</th><th></th></tr></thead><tbody>
    <?php foreach ($payments as $o): ?>
    <tr>
        <td class="cell-main"><?= e($o['title']) ?><?php if ($o['description']): ?><div class="cell-bottom"><?= e($o['description']) ?></div><?php endif; ?></td>
        <td class="small"><?= e($o['project_name']) ?></td>
        <td><span class="badge"><?= PAYMENT_TYPES[$o['type']] ?? e($o['type']) ?></span></td>
        <td class="bold"><?= money($o['amount']) ?></td>
        <td class="small"><?= format_date($o['date']) ?></td>
        <td>
            <select class="select" style="padding:5px 28px 5px 10px;font-size:12px;width:auto" onchange="paymentStatus(<?= $o['id'] ?>,this.value)">
                <?php foreach (PAYMENT_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $o['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </td>
        <td><button class="icon-action danger" data-action="payment_delete" data-id="<?= $o['id'] ?>" data-confirm="Silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/></svg></button></td>
    </tr>
    <?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div><!-- /tab-records -->

<!-- EXPENSES -->
<div class="tab-content" id="tab-expenses">
    <div class="row-flex between mb-3 wrap" style="gap:10px">
        <div class="row-flex wrap" style="gap:14px">
            <span class="small">Ödenen: <b style="color:var(--danger)"><?= money($expenseTotal) ?></b></span>
            <span class="small">Bekleyen: <b style="color:var(--warning)"><?= money($expensePending) ?></b></span>
        </div>
        <button class="btn btn-brand btn-sm" data-modal="modalExpense"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Gider Ekle</button>
    </div>
    <?php if (!$expenses): ?>
    <div class="text-muted small orta card" style="padding:30px">Henüz gider kaydı yok. Maaş tanımlı kullanıcılar için her ay başında otomatik oluşur.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Gider</th><th>Tür</th><th>Tutar</th><th>Tarih</th><th>Durum</th><th></th></tr></thead><tbody>
        <?php foreach ($expenses as $gd): ?>
        <tr>
            <td class="cell-main"><?= e($gd['title']) ?><?php if ($gd['repeat'] === 'monthly'): ?> <span class="badge badge-type" title="Her ay otomatik yinelenir"><?= icon('repeat', 11) ?> Aylık</span><?php endif; ?><?php if ($gd['description']): ?><div class="cell-bottom"><?= e($gd['description']) ?></div><?php endif; ?></td>
            <td><span class="badge"><?= EXPENSE_TYPES[$gd['type']] ?></span></td>
            <td class="bold" style="color:var(--danger)">−<?= money($gd['amount']) ?></td>
            <td class="small"><?= format_date($gd['date']) ?></td>
            <td><select class="select" style="padding:5px 28px 5px 10px;font-size:12px;width:auto" onchange="expenseStatus(<?= $gd['id'] ?>,this.value)"><?php foreach (EXPENSE_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $gd['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></td>
            <td><button class="icon-action danger" data-action="expense_delete" data-id="<?= $gd['id'] ?>" data-confirm="Gider silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<!-- QUOTES & INVOICES -->
<div class="tab-content" id="tab-documents">
    <div class="row-flex between mb-3">
        <div class="cell-bottom">Numaralı teklif/fatura belgeleri — yazdırıp PDF olarak müşteriye gönderin</div>
        <button class="btn btn-brand btn-sm" data-modal="modalDocument"><?= icon('document', 14) ?> Yeni Belge</button>
    </div>
    <?php if (!$documents): ?>
    <div class="text-muted small orta card" style="padding:30px">Henüz belge yok. İlk teklifinizi oluşturun.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>No</th><th>Başlık</th><th>Dosya</th><th>Toplam (KDV dahil)</th><th>Durum</th><th></th></tr></thead><tbody>
        <?php foreach ($documents as $bg): ?>
        <tr>
            <td class="bold" style="color:var(--brand)"><?= e($bg['doc_no']) ?></td>
            <td><a href="document.php?id=<?= $bg['id'] ?>" class="cell-main"><?= e($bg['title']) ?></a><div class="cell-bottom"><?= DOCUMENT_TYPES[$bg['type']] ?? e($bg['type']) ?> · <?= format_date($bg['created']) ?></div></td>
            <td class="small"><?= e($bg['client_name'] ?? '—') ?></td>
            <td class="bold"><?= money($bg['total']) ?></td>
            <td>
                <select class="select native-select" style="padding:5px 28px 5px 10px;font-size:12px;width:auto" onchange="documentStatus(<?= $bg['id'] ?>,this.value)">
                    <?php foreach (DOCUMENT_STATUSES as $k => $v): ?><option value="<?= $k ?>" <?= $bg['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                </select>
            </td>
            <td class="row-flex" style="gap:4px">
                <a href="document.php?id=<?= $bg['id'] ?>" target="_blank" class="icon-action" title="Yazdır/PDF"><?= icon('document', 16) ?></a>
                <button class="icon-action danger" data-action="document_delete" data-id="<?= $bg['id'] ?>" data-confirm="Belge silinsin mi?"><?= icon('cop', 16) ?></button>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<!-- ACCOUNT BALANCES -->
<div class="tab-content" id="tab-account">
    <div class="cell-bottom mb-3">Dosya bazında borç (kesilen faturalar) − tahsilat = güncel bakiye. Satıra tıklayarak yazdırılabilir ekstre alın.</div>
    <?php if (!$accounts): ?>
    <div class="text-muted small orta card" style="padding:30px">Henüz finansal hareket yok.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Dosya</th><th>Toplam Fatura</th><th>Tahsil Edilen</th><th>Bakiye</th><th></th></tr></thead><tbody>
        <?php foreach ($accounts as $cr): $balance = $cr['debt'] - $cr['collect']; ?>
        <tr class="tick" onclick="location.href='statement.php?client=<?= $cr['id'] ?>'">
            <td><span class="label-dot" style="width:9px;height:9px;background:<?= e($cr['color']) ?>;margin-right:6px"></span><span class="cell-main"><?= e($cr['name']) ?></span></td>
            <td><?= money($cr['debt']) ?></td>
            <td style="color:var(--success)"><?= money($cr['collect']) ?></td>
            <td class="bold" style="color:<?= $balance > 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= money($balance) ?></td>
            <td><a href="statement.php?client=<?= $cr['id'] ?>" class="mini-btn" onclick="event.stopPropagation()">Ekstre →</a></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<!-- PROFIT / LOSS -->
<div class="tab-content" id="tab-profit_loss">
    <?php
    $lastMonth = end($monthlyData);
    $netBuMonth = $lastMonth['income'] - $lastMonth['expense'];
    $total6Income = array_sum(array_column($monthlyData, 'income'));
    $total6Expense = array_sum(array_column($monthlyData, 'expense')); ?>
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-value" style="font-size:22px;color:var(--success)"><?= money($lastMonth['income']) ?></div><div class="stat-label">Bu Ay Gelir (tahsil edilen)</div></div>
        <div class="stat-card"><div class="stat-value" style="font-size:22px;color:var(--danger)"><?= money($lastMonth['expense']) ?></div><div class="stat-label">Bu Ay Gider (ödenen)</div></div>
        <div class="stat-card"><div class="stat-value" style="font-size:22px;color:<?= $netBuMonth >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= ($netBuMonth >= 0 ? '+' : '') . money($netBuMonth) ?></div><div class="stat-label">Bu Ay Net</div></div>
        <div class="stat-card"><div class="stat-value" style="font-size:22px"><?= money($total6Income - $total6Expense) ?></div><div class="stat-label">6 Aylık Net</div></div>
    </div>
    <div class="card mb-3">
        <div class="card-title mb-3">Son 6 Ay — Gelir / Gider</div>
        <div style="display:flex;gap:14px;align-items:flex-end;height:200px;padding:0 6px">
            <?php foreach ($monthlyData as $av): ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%">
                <div style="flex:1;display:flex;gap:5px;align-items:flex-end;width:100%;justify-content:center">
                    <div title="Gelir: <?= money($av['income']) ?>" style="width:26px;border-radius:6px 6px 0 0;background:var(--success);height:<?= max(2, round($av['income'] / $maxAmount * 100)) ?>%;transition:height .6s"></div>
                    <div title="Gider: <?= money($av['expense']) ?>" style="width:26px;border-radius:6px 6px 0 0;background:var(--danger);opacity:.75;height:<?= max(2, round($av['expense'] / $maxAmount * 100)) ?>%;transition:height .6s"></div>
                </div>
                <span class="cell-bottom"><?= $av['label'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="row-flex mt-2" style="gap:16px;justify-content:center">
            <span class="row-flex small" style="gap:6px"><span class="label-dot" style="background:var(--success)"></span>Gelir</span>
            <span class="row-flex small" style="gap:6px"><span class="label-dot" style="background:var(--danger)"></span>Gider</span>
        </div>
    </div>
    <!-- Budget target -->
    <div class="card mb-3">
        <div class="row-flex between wrap mb-2" style="gap:10px">
            <div class="card-title">Aylık Gelir Hedefi</div>
            <form data-ajax="budget_save" data-refresh="yes" class="row-flex" style="gap:8px">
                <input name="target" class="input" style="width:150px" value="<?= $budgetTarget ? number_format($budgetTarget, 0, ',', '.') : '' ?>" placeholder="Örn. 250.000">
                <button type="submit" class="btn btn-sm">Kaydet</button>
            </form>
        </div>
        <?php if ($budgetTarget > 0): $targetRate = min(100, round($thisMonthIncome / $budgetTarget * 100)); ?>
        <div class="row-flex between mb-2"><span class="small"><?= MONTHS[(int)date('n')] ?> gerçekleşme: <b><?= money($thisMonthIncome) ?></b> / <?= money($budgetTarget) ?></span><span class="bold" style="color:<?= $targetRate >= 100 ? 'var(--success)' : 'var(--text)' ?>">%<?= round($thisMonthIncome / $budgetTarget * 100) ?></span></div>
        <div class="progress" style="height:10px"><div class="progress-full <?= $targetRate >= 100 ? '' : ($targetRate >= 70 ? '' : 'busy') ?>" data-rate="<?= $targetRate ?>" style="width:0;<?= $targetRate >= 100 ? 'background:var(--success)' : '' ?>"></div></div>
        <?php else: ?><div class="cell-bottom">Hedef girin — bu ayın tahsilatları hedefe oranla izlenir.</div><?php endif; ?>
    </div>

    <!-- Cash flow projection -->
    <div class="card mb-3">
        <div class="card-title mb-2">Nakit Akış Projeksiyonu — önümüzdeki 3 ay</div>
        <div class="cell-bottom mb-3">Beklenen gelir = aktif aylık sözleşmeler + planlanan tahsilatlar · Gider = maaşlar + tekrarlayan ve planlı giderler</div>
        <div style="display:flex;gap:20px;align-items:flex-end;height:150px;padding:0 6px">
            <?php foreach ($projection as $pj): $net = $pj['income'] - $pj['expense']; ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%">
                <span class="small bold" style="color:<?= $net >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= ($net >= 0 ? '+' : '') . money($net) ?></span>
                <div style="flex:1;display:flex;gap:6px;align-items:flex-end;width:100%;justify-content:center">
                    <div title="Beklenen gelir: <?= money($pj['income']) ?>" style="width:30px;border-radius:6px 6px 0 0;background:var(--success);height:<?= max(2, round($pj['income'] / $projMax * 100)) ?>%"></div>
                    <div title="Planlı gider: <?= money($pj['expense']) ?>" style="width:30px;border-radius:6px 6px 0 0;background:var(--danger);opacity:.75;height:<?= max(2, round($pj['expense'] / $projMax * 100)) ?>%"></div>
                </div>
                <span class="cell-bottom"><?= $pj['label'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-title mb-2">Proje Kârlılığı</div>
        <div class="cell-bottom mb-3">İşçilik maliyeti = kayıtlı süre × kişinin saat maliyeti (maaş ÷ 172 saat). Maaş girilmeyen kişilerin süresi maliyete katılmaz.</div>
        <?php if (!$profitability): ?><div class="text-muted small">Sözleşme tutarı girilmiş proje yok.</div>
        <?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Proje</th><th>Sözleşme</th><th>İşçilik Maliyeti</th><th>Tahmini Kâr</th><th>Marj</th></tr></thead><tbody>
            <?php foreach ($profitability as $kr):
                $profit = $kr['contract_amount'] - $kr['labor'];
                $margin = $kr['contract_amount'] > 0 ? round($profit / $kr['contract_amount'] * 100) : 0; ?>
            <tr>
                <td><span class="label-dot" style="width:8px;height:8px;background:<?= e($kr['client_color']) ?>;margin-right:6px"></span><a href="project.php?id=<?= $kr['id'] ?>" class="cell-main"><?= e($kr['name']) ?></a><div class="cell-bottom"><?= e($kr['client_name']) ?></div></td>
                <td class="bold"><?= money($kr['contract_amount']) ?></td>
                <td style="color:var(--danger)">−<?= money($kr['labor']) ?></td>
                <td class="bold" style="color:<?= $profit >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= money($profit) ?></td>
                <td><span class="badge <?= $margin >= 40 ? 'r-approved' : ($margin >= 15 ? 'r-pending' : 'r-rejected') ?>">%<?= $margin ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<?php if (permission('capacity')): ?>
<div class="tab-content" id="tab-capacity">
    <div class="card">
        <div class="row-flex between mb-3">
            <div>
                <div class="card-title">Haftalık Doluluk — <?= format_date($weekHead) ?> / <?= format_date($weekEnd) ?></div>
                <div class="cell-bottom mt-1">Kayıtlı çalışma süresi, kişinin haftalık kapasite hedefine oranlanır.</div>
            </div>
            <a href="export.php?type=time" class="btn btn-sm">Zaman Raporu CSV</a>
        </div>
        <?php if (!$capacities): ?><div class="text-muted small">Ekip üyesi yok.</div>
        <?php else: foreach ($capacities as $kp):
            $targetMin = (int)$kp['weekly_capacity'] * 60;
            $rate = $targetMin > 0 ? round($kp['week_minutes'] / $targetMin * 100) : 0;
            $class = $rate > 100 ? 'over' : ($rate > 80 ? 'busy' : ''); ?>
        <div class="capacity-row">
            <?= avatar($kp, 38) ?>
            <div style="min-width:150px">
                <div class="cell-main small"><?= e($kp['name']) ?></div>
                <div class="cell-bottom"><?= $kp['open_task'] ?> açık iş · hedef <?= $kp['weekly_capacity'] ?> sa/hafta</div>
            </div>
            <div class="capacity-bar">
                <div class="progress"><div class="progress-full <?= $class ?>" data-rate="<?= min(100, $rate) ?>" style="width:0"></div></div>
                <div class="cell-bottom mt-1"><?= format_minutes((int)$kp['week_minutes']) ?> kayıtlı</div>
            </div>
            <div class="capacity-percent" style="<?= $rate > 100 ? 'color:var(--danger)' : ($rate > 80 ? 'color:var(--warning)' : '') ?>">%<?= $rate ?></div>
        </div>
        <?php endforeach; endif; ?>
        <div class="form-hint mt-2">Kapasite hedefleri <b>Yönetim → Kullanıcılar</b>'dan kişi bazında ayarlanır. %80 üzeri sarı, %100 üzeri kırmızı gösterilir.</div>
    </div>
</div>
<?php endif; ?>
</div><!-- /tab-container -->

<div class="modal-overlay" id="modalPayment">
    <div class="modal"><div class="modal-top"><div class="modal-title">Finans Kaydı</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="payment_save">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık <span class="required">*</span></label><input name="title" class="input" required placeholder="Örn. Ekim ayı hizmet bedeli"></div>
            <div class="form-group"><label class="form-label">Proje <span class="required">*</span></label><select name="project_id" class="select" required><option value="">Seçin...</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" <?= $projectFilter == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tür</label><select name="type" class="select"><?php foreach (PAYMENT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Tutar (₺) <span class="required">*</span></label><input name="amount" class="input" required placeholder="0,00"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tarih</label><input type="date" name="date" class="input" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label class="form-label">Durum</label><select name="status" class="select"><?php foreach (PAYMENT_STATUSES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group"><label class="form-label">Açıklama</label><input name="description" class="input"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>
<!-- Add expense modal -->
<div class="modal-overlay" id="modalExpense">
    <div class="modal"><div class="modal-top"><div class="modal-title">Gider Kaydı</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="expense_save">
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Başlık <span class="required">*</span></label><input name="title" class="input" required placeholder="Örn. Ofis kirası"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tür</label><select name="type" class="select"><?php foreach (EXPENSE_TYPES as $k => $v): if ($k === 'salary') continue; ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Tutar (₺) <span class="required">*</span></label><input name="amount" class="input" required placeholder="0,00"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tarih</label><input type="date" name="date" class="input" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label class="form-label">Durum</label><select name="status" class="select"><?php foreach (EXPENSE_STATUSES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group"><label class="row-flex" style="gap:9px;cursor:pointer"><input type="checkbox" name="repeat" value="monthly"> <span class="small"><b>Her ay tekrarla</b> — kira/abonelik gibi giderler her ay başında otomatik oluşur</span></label></div>
            <div class="form-group"><label class="form-label">Açıklama</label><input name="description" class="input"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Kaydet</button></div>
    </form></div>
</div>

<!-- Create quote/invoice modal -->
<div class="modal-overlay" id="modalDocument">
    <div class="modal modal-wide"><div class="modal-top"><div class="modal-title">Yeni Teklif / Fatura</div><button class="modal-close" data-modal-close>✕</button></div>
    <form data-ajax="document_save" id="documentForm">
        <input type="hidden" name="items" id="b_items">
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Belge Türü</label><select name="type" class="select"><?php foreach (DOCUMENT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Dosya (müşteri)</label><select name="client_id" class="select"><option value="">—</option><?php foreach ($allClients as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group"><label class="form-label">Başlık <span class="required">*</span></label><input name="title" class="input" required placeholder="Örn. 2026 Sosyal Medya Yönetimi Teklifi"></div>
            <div class="form-group">
                <label class="form-label">Kalemler</label>
                <div class="vertical" id="itemList" style="gap:8px"></div>
                <button type="button" class="btn btn-sm btn-ghost mt-2" onclick="itemAdd()">+ Kalem Ekle</button>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">KDV Oranı (%)</label><input type="number" name="vat_rate" class="input" value="20" min="0" max="50"></div>
                <div class="form-group"><label class="form-label">Geçerlilik Tarihi</label><input type="date" name="valid_until" class="input"></div>
            </div>
            <div class="form-group"><label class="form-label">Notlar</label><textarea name="notes" class="text-area" placeholder="Ödeme koşulları, teslim süresi vb."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-ghost" data-modal-close>İptal</button><button type="submit" class="btn btn-brand">Oluştur</button></div>
    </form></div>
</div>

<script>
async function paymentStatus(id, status) { const j = await api('payment_status', {id, status}); if (j.ok) { toast('Güncellendi', 'success'); setTimeout(()=>location.reload(),500); } }
async function expenseStatus(id, status) { const j = await api('expense_status', {id, status}); if (j.ok) toast('Güncellendi', 'success'); }
async function documentStatus(id, status) { const j = await api('document_status', {id, status}); if (j.ok) { toast(j.message, 'success'); setTimeout(()=>location.reload(),700); } }
function itemAdd(k = {}) {
    const div = document.createElement('div');
    div.className = 'row-flex item-row';
    div.style.gap = '8px';
    div.innerHTML = `<input class="input k-ad" placeholder="Hizmet/ürün adı" style="flex:2" value="${(k.name||'').replace(/"/g,'&quot;')}">
        <input class="input k-qty" placeholder="Adet" style="width:70px" value="${k.qty||1}">
        <input class="input k-price" placeholder="Birim ₺" style="width:110px" value="${k.price||''}">
        <button type="button" class="icon-action danger" onclick="this.parentElement.remove()">✕</button>`;
    document.getElementById('itemList').appendChild(div);
}
itemAdd();
document.getElementById('documentForm').addEventListener('submit', () => {
    const items = Array.from(document.querySelectorAll('.item-row')).map(s => ({
        name: s.querySelector('.k-ad').value.trim(),
        qty: s.querySelector('.k-qty').value,
        price: s.querySelector('.k-price').value,
    })).filter(k => k.name);
    document.getElementById('b_items').value = JSON.stringify(items);
});
</script>
<?php page_end(); ?>

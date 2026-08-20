<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_permission('finans');

// Kapasite verileri (bu hafta)
$weekHead = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$capacities = permission('kapasite') ? rows("SELECT us.id, us.name, us.color, us.avatar, us.job_title, us.weekly_capacity,
    (SELECT COALESCE(SUM(z.minutes),0) FROM time_entries z WHERE z.user_id=us.id AND z.date BETWEEN ? AND ?) week_minutes,
    (SELECT COUNT(*) FROM tasks g WHERE g.is_archived=0 AND g.status!='tamamlandi' AND (g.assignee_id=us.id OR EXISTS(SELECT 1 FROM task_assignees ga WHERE ga.task_id=g.id AND ga.user_id=us.id))) open_task
    FROM users us WHERE us.role IN ('yonetici','pm','ekip','finans') AND us.is_active=1 ORDER BY us.name", [$weekHead, $weekEnd]) : [];

// Giderler
$expenses = rows("SELECT gd.*, us.name person_name FROM expenses gd LEFT JOIN users us ON us.id=gd.user_id ORDER BY gd.date DESC LIMIT 200");
$expenseTotal = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='odendi'");
$expensePending = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='bekliyor'");

// Kâr/Zarar: son 6 ay
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $monthKey = date('Y-m', strtotime("-$i months"));
    $monthlyData[] = [
        'tag' => AYLAR[(int)date('n', strtotime("-$i months"))] . ' ' . date('y', strtotime("-$i months")),
        'gelir' => (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='odendi' AND DATE_FORMAT(date,'%Y-%m')=?", [$monthKey]),
        'expense' => (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='odendi' AND DATE_FORMAT(date,'%Y-%m')=?", [$monthKey]),
    ];
}
$maxAmount = max(1, max(array_merge(array_column($monthlyData, 'gelir'), array_column($monthlyData, 'expense'))));

// Teklif & Fatura belgeleri
$documents = rows("SELECT b.*, d.name client_name FROM documents b LEFT JOIN clients d ON d.id=b.client_id ORDER BY b.id DESC LIMIT 100");
foreach ($documents as &$bg) {
    $kls = json_decode($bg['items'], true) ?: [];
    $bg['search'] = array_sum(array_map(fn($k) => $k['adet'] * $k['price'], $kls));
    $bg['total'] = $bg['search'] * (1 + $bg['vat_rate'] / 100);
}
unset($bg);
$tumClients = rows("SELECT id, name FROM clients WHERE status='aktif' ORDER BY name");

// Bütçe hedefi + bu ay gerçekleşme
$budgetTarget = (float)setting('budget_target', '0');
$buMonthGelir = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='odendi' AND DATE_FORMAT(date,'%Y-%m')=?", [date('Y-m')]);

// Nakit akış projeksiyonu: önümüzdeki 3 ay
$projection = [];
$monthlyContract = (float)val("SELECT COALESCE(SUM(contract_amount),0) FROM projects WHERE status='aktif' AND type='aylik'");
$monthlySalary = (float)val("SELECT COALESCE(SUM(salary),0) FROM users WHERE is_active=1 AND salary>0");
$monthlyRepeatExpense = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE `repeat`='aylik'");
for ($i = 1; $i <= 3; $i++) {
    $monthKey = date('Y-m', strtotime("+$i months"));
    $pendingTahsilat = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='bekliyor' AND DATE_FORMAT(date,'%Y-%m')=?", [$monthKey]);
    $planliExpense = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='bekliyor' AND `repeat`='yok' AND DATE_FORMAT(date,'%Y-%m')=?", [$monthKey]);
    $gelir = $monthlyContract + $pendingTahsilat;
    $expense = $monthlySalary + $monthlyRepeatExpense + $planliExpense;
    $projection[] = ['tag' => AYLAR[(int)date('n', strtotime("+$i months"))] . ' ' . date('y', strtotime("+$i months")), 'gelir' => $gelir, 'expense' => $expense];
}
$projMax = max(1, max(array_merge(array_column($projection, 'gelir'), array_column($projection, 'expense'))));

// Cari hesap: dosya bazlı borç/alacak
$cariler = rows("SELECT d.id, d.name, d.color,
    COALESCE((SELECT SUM(o.amount) FROM payments o JOIN projects p ON p.id=o.project_id WHERE p.client_id=d.id AND o.type='fatura'),0) borc,
    COALESCE((SELECT SUM(o.amount) FROM payments o JOIN projects p ON p.id=o.project_id WHERE p.client_id=d.id AND o.type='tahsilat' AND o.status='odendi'),0) tahsil
    FROM clients d HAVING borc>0 OR tahsil>0 ORDER BY (borc-tahsil) DESC");

// Proje kârlılığı: sözleşme tutarı − işçilik maliyeti (kayıtlı süre × kişi saat maliyeti; saat maliyeti = maaş/172)
$karlilik = rows("SELECT p.id, p.name, p.contract_amount, d.name client_name, d.color client_color,
    COALESCE((SELECT SUM(z.minutes/60 * (us.salary/172)) FROM time_entries z JOIN tasks g ON g.id=z.task_id JOIN users us ON us.id=z.user_id WHERE g.project_id=p.id AND us.salary>0), 0) iscilik
    FROM projects p JOIN clients d ON d.id=p.client_id WHERE p.status IN ('aktif','tamamlandi') AND p.contract_amount>0 ORDER BY p.contract_amount DESC LIMIT 20");

$projectFiltre = (int)($_GET['project'] ?? 0);
$where_sql = $projectFiltre ? "o.project_id=$projectFiltre" : "1=1";

$payments = rows("SELECT o.*, p.name project_name, d.name client_name FROM payments o JOIN projects p ON p.id=o.project_id JOIN clients d ON d.id=p.client_id WHERE $where_sql ORDER BY o.date DESC");
$projects = rows("SELECT id, name FROM projects ORDER BY name");

$totalFatura = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE type='fatura'");
$tahsilEdilen = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='odendi'");
$pending = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='bekliyor'");
$overdue = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='gecikti'");
$contractTotal = (float)val("SELECT COALESCE(SUM(contract_amount),0) FROM projects WHERE status='aktif'");

page_start('Finans', 'finance');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Finans & Kapasite</div><div class="sayfa-alt">Fatura, tahsilat ve ekip çalışma kapasitesi</div></div>
    <div class="sayfa-ust-aksiyon">
        <a href="export.php?type=finans" class="btn"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 15V3m0 12l-4-4m4 4l4-4M3 17v2a2 2 0 002 2h14a2 2 0 002-2v-2"/></svg> CSV İndir</a>
        <button class="btn btn-marka" data-modal="modalOdeme"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Kayıt Ekle</button>
    </div>
</div>

<div class="sekme-kap">
<div class="sekmeler">
    <button class="sekme aktif" data-sekme="kayitlar">Gelirler</button>
    <button class="sekme" data-sekme="expenses">Giderler</button>
    <button class="sekme" data-sekme="documents">Teklif & Fatura</button>
    <button class="sekme" data-sekme="cari">Cari Hesap</button>
    <button class="sekme" data-sekme="karzarar">Kâr / Zarar</button>
    <?php if (permission('kapasite')): ?><button class="sekme" data-sekme="kapasite">Ekip Kapasitesi</button><?php endif; ?>
</div>
<div class="sekme-icerik aktif" id="sekme-kayitlar">

<div class="stat-izgara">
    <div class="stat-kart"><div class="stat-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 7h6m-6 4h6m-6 4h4M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"/></svg></div><div class="stat-deger" style="font-size:22px"><?= money($totalFatura) ?></div><div class="stat-etiket">Toplam Fatura</div></div>
    <div class="stat-kart"><div class="stat-ikon" style="background:rgba(53,198,107,.14);color:var(--basari)"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="stat-deger" style="font-size:22px;color:var(--basari)"><?= money($tahsilEdilen) ?></div><div class="stat-etiket">Tahsil Edilen</div></div>
    <div class="stat-kart"><div class="stat-ikon" style="background:rgba(245,165,36,.14);color:var(--uyari)"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="stat-deger" style="font-size:22px;color:var(--uyari)"><?= money($pending) ?></div><div class="stat-etiket">Bekleyen</div></div>
    <div class="stat-kart"><div class="stat-ikon" style="background:rgba(240,79,79,.14);color:var(--tehlike)"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/></svg></div><div class="stat-deger" style="font-size:22px;color:var(--tehlike)"><?= money($overdue) ?></div><div class="stat-etiket">Geciken</div></div>
</div>

<div class="filtre-bar">
    <select class="secim" style="max-width:280px" onchange="location.href='?project='+this.value">
        <option value="0">Tüm Projeler</option>
        <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" <?= $projectFiltre == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
    </select>
</div>

<?php if (!$payments): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 8c-2.21 0-4 .9-4 2s1.79 2 4 2 4 .9 4 2-1.79 2-4 2m0-8V6m0 12v-2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="bos-baslik">Finans kaydı yok</div><div class="bos-metin">Fatura veya tahsilat kaydı ekleyerek başlayın.</div></div>
<?php else: ?>
<div class="tablo-sar"><table class="tablo"><thead><tr><th>Kayıt</th><th>Proje</th><th>Tür</th><th>Tutar</th><th>Tarih</th><th>Durum</th><th></th></tr></thead><tbody>
    <?php foreach ($payments as $o): ?>
    <tr>
        <td class="hucre-ana"><?= e($o['title']) ?><?php if ($o['description']): ?><div class="hucre-alt"><?= e($o['description']) ?></div><?php endif; ?></td>
        <td class="kucuk"><?= e($o['project_name']) ?></td>
        <td><span class="rozet"><?= $o['type'] === 'fatura' ? 'Fatura' : 'Tahsilat' ?></span></td>
        <td class="kalin"><?= money($o['amount']) ?></td>
        <td class="kucuk"><?= format_date($o['date']) ?></td>
        <td>
            <select class="secim" style="padding:5px 28px 5px 10px;font-size:12px;width:auto" onchange="odemeDurum(<?= $o['id'] ?>,this.value)">
                <?php foreach (['bekliyor' => 'Bekliyor', 'odendi' => 'Ödendi', 'overdue' => 'Gecikti'] as $k => $v): ?><option value="<?= $k ?>" <?= $o['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </td>
        <td><button class="ikon-eylem tehlike" data-action="payment_delete" data-id="<?= $o['id'] ?>" data-approval="Silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/></svg></button></td>
    </tr>
    <?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div><!-- /sekme-kayitlar -->

<!-- GİDERLER -->
<div class="sekme-icerik" id="sekme-giderler">
    <div class="satir-esnek arasi mb-3 sarma" style="gap:10px">
        <div class="satir-esnek sarma" style="gap:14px">
            <span class="kucuk">Ödenen: <b style="color:var(--tehlike)"><?= money($expenseTotal) ?></b></span>
            <span class="kucuk">Bekleyen: <b style="color:var(--uyari)"><?= money($expensePending) ?></b></span>
        </div>
        <button class="btn btn-marka btn-sm" data-modal="modalGider"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Gider Ekle</button>
    </div>
    <?php if (!$expenses): ?>
    <div class="metin-muted kucuk orta kart" style="padding:30px">Henüz gider kaydı yok. Maaş tanımlı kullanıcılar için her ay başında otomatik oluşur.</div>
    <?php else: ?>
    <div class="tablo-sar"><table class="tablo"><thead><tr><th>Gider</th><th>Tür</th><th>Tutar</th><th>Tarih</th><th>Durum</th><th></th></tr></thead><tbody>
        <?php foreach ($expenses as $gd): ?>
        <tr>
            <td class="hucre-ana"><?= e($gd['title']) ?><?php if ($gd['repeat'] === 'aylik'): ?> <span class="rozet rozet-tur" title="Her ay otomatik yinelenir"><?= icon('repeat', 11) ?> Aylık</span><?php endif; ?><?php if ($gd['description']): ?><div class="hucre-alt"><?= e($gd['description']) ?></div><?php endif; ?></td>
            <td><span class="rozet"><?= GIDER_TURLERI[$gd['type']] ?></span></td>
            <td class="kalin" style="color:var(--tehlike)">−<?= money($gd['amount']) ?></td>
            <td class="kucuk"><?= format_date($gd['date']) ?></td>
            <td><select class="secim" style="padding:5px 28px 5px 10px;font-size:12px;width:auto" onchange="giderDurum(<?= $gd['id'] ?>,this.value)"><option value="bekliyor" <?= $gd['status'] === 'bekliyor' ? 'selected' : '' ?>>Bekliyor</option><option value="odendi" <?= $gd['status'] === 'odendi' ? 'selected' : '' ?>>Ödendi</option></select></td>
            <td><button class="ikon-eylem tehlike" data-action="expense_delete" data-id="<?= $gd['id'] ?>" data-approval="Gider silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m14 0H5m5 4v6m4-6v6"/></svg></button></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<!-- TEKLİF & FATURA -->
<div class="sekme-icerik" id="sekme-belgeler">
    <div class="satir-esnek arasi mb-3">
        <div class="hucre-alt">Numaralı teklif/fatura belgeleri — yazdırıp PDF olarak müşteriye gönderin</div>
        <button class="btn btn-marka btn-sm" data-modal="modalBelge"><?= icon('document', 14) ?> Yeni Belge</button>
    </div>
    <?php if (!$documents): ?>
    <div class="metin-muted kucuk orta kart" style="padding:30px">Henüz belge yok. İlk teklifinizi oluşturun.</div>
    <?php else: ?>
    <div class="tablo-sar"><table class="tablo"><thead><tr><th>No</th><th>Başlık</th><th>Dosya</th><th>Toplam (KDV dahil)</th><th>Durum</th><th></th></tr></thead><tbody>
        <?php foreach ($documents as $bg): ?>
        <tr>
            <td class="kalin" style="color:var(--marka)"><?= e($bg['doc_no']) ?></td>
            <td><a href="document.php?id=<?= $bg['id'] ?>" class="hucre-ana"><?= e($bg['title']) ?></a><div class="hucre-alt"><?= $bg['type'] === 'teklif' ? 'Teklif' : 'Fatura' ?> · <?= format_date($bg['created']) ?></div></td>
            <td class="kucuk"><?= e($bg['client_name'] ?? '—') ?></td>
            <td class="kalin"><?= money($bg['total']) ?></td>
            <td>
                <select class="secim native-kal" style="padding:5px 28px 5px 10px;font-size:12px;width:auto" onchange="belgeDurum(<?= $bg['id'] ?>,this.value)">
                    <?php foreach (['taslak' => 'Taslak', 'gonderildi' => 'Gönderildi', 'onaylandi' => 'Onaylandı', 'reddedildi' => 'Reddedildi'] as $k => $v): ?><option value="<?= $k ?>" <?= $bg['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                </select>
            </td>
            <td class="satir-esnek" style="gap:4px">
                <a href="document.php?id=<?= $bg['id'] ?>" target="_blank" class="ikon-eylem" title="Yazdır/PDF"><?= icon('document', 16) ?></a>
                <button class="ikon-eylem tehlike" data-action="document_delete" data-id="<?= $bg['id'] ?>" data-approval="Belge silinsin mi?"><?= icon('cop', 16) ?></button>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<!-- CARİ HESAP -->
<div class="sekme-icerik" id="sekme-cari">
    <div class="hucre-alt mb-3">Dosya bazında borç (kesilen faturalar) − tahsilat = güncel bakiye. Satıra tıklayarak yazdırılabilir ekstre alın.</div>
    <?php if (!$cariler): ?>
    <div class="metin-muted kucuk orta kart" style="padding:30px">Henüz finansal hareket yok.</div>
    <?php else: ?>
    <div class="tablo-sar"><table class="tablo"><thead><tr><th>Dosya</th><th>Toplam Fatura</th><th>Tahsil Edilen</th><th>Bakiye</th><th></th></tr></thead><tbody>
        <?php foreach ($cariler as $cr): $bakiye = $cr['borc'] - $cr['tahsil']; ?>
        <tr class="tik" onclick="location.href='statement.php?client=<?= $cr['id'] ?>'">
            <td><span class="etiket-nokta" style="width:9px;height:9px;background:<?= e($cr['color']) ?>;margin-right:6px"></span><span class="hucre-ana"><?= e($cr['name']) ?></span></td>
            <td><?= money($cr['borc']) ?></td>
            <td style="color:var(--basari)"><?= money($cr['tahsil']) ?></td>
            <td class="kalin" style="color:<?= $bakiye > 0 ? 'var(--tehlike)' : 'var(--basari)' ?>"><?= money($bakiye) ?></td>
            <td><a href="statement.php?client=<?= $cr['id'] ?>" class="mini-btn" onclick="event.stopPropagation()">Ekstre →</a></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<!-- KÂR / ZARAR -->
<div class="sekme-icerik" id="sekme-karzarar">
    <?php
    $lastMonth = end($monthlyData);
    $netBuMonth = $lastMonth['gelir'] - $lastMonth['expense'];
    $toplam6Gelir = array_sum(array_column($monthlyData, 'gelir'));
    $toplam6Expense = array_sum(array_column($monthlyData, 'expense')); ?>
    <div class="stat-izgara">
        <div class="stat-kart"><div class="stat-deger" style="font-size:22px;color:var(--basari)"><?= money($lastMonth['gelir']) ?></div><div class="stat-etiket">Bu Ay Gelir (tahsil edilen)</div></div>
        <div class="stat-kart"><div class="stat-deger" style="font-size:22px;color:var(--tehlike)"><?= money($lastMonth['expense']) ?></div><div class="stat-etiket">Bu Ay Gider (ödenen)</div></div>
        <div class="stat-kart"><div class="stat-deger" style="font-size:22px;color:<?= $netBuMonth >= 0 ? 'var(--basari)' : 'var(--tehlike)' ?>"><?= ($netBuMonth >= 0 ? '+' : '') . money($netBuMonth) ?></div><div class="stat-etiket">Bu Ay Net</div></div>
        <div class="stat-kart"><div class="stat-deger" style="font-size:22px"><?= money($toplam6Gelir - $toplam6Expense) ?></div><div class="stat-etiket">6 Aylık Net</div></div>
    </div>
    <div class="kart mb-3">
        <div class="kart-baslik mb-3">Son 6 Ay — Gelir / Gider</div>
        <div style="display:flex;gap:14px;align-items:flex-end;height:200px;padding:0 6px">
            <?php foreach ($monthlyData as $av): ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%">
                <div style="flex:1;display:flex;gap:5px;align-items:flex-end;width:100%;justify-content:center">
                    <div title="Gelir: <?= money($av['gelir']) ?>" style="width:26px;border-radius:6px 6px 0 0;background:var(--basari);height:<?= max(2, round($av['gelir'] / $maxAmount * 100)) ?>%;transition:height .6s"></div>
                    <div title="Gider: <?= money($av['expense']) ?>" style="width:26px;border-radius:6px 6px 0 0;background:var(--tehlike);opacity:.75;height:<?= max(2, round($av['expense'] / $maxAmount * 100)) ?>%;transition:height .6s"></div>
                </div>
                <span class="hucre-alt"><?= $av['tag'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="satir-esnek mt-2" style="gap:16px;justify-content:center">
            <span class="satir-esnek kucuk" style="gap:6px"><span class="etiket-nokta" style="background:var(--basari)"></span>Gelir</span>
            <span class="satir-esnek kucuk" style="gap:6px"><span class="etiket-nokta" style="background:var(--tehlike)"></span>Gider</span>
        </div>
    </div>
    <!-- Bütçe hedefi -->
    <div class="kart mb-3">
        <div class="satir-esnek arasi sarma mb-2" style="gap:10px">
            <div class="kart-baslik">Aylık Gelir Hedefi</div>
            <form data-ajax="budget_save" data-refresh="evet" class="satir-esnek" style="gap:8px">
                <input name="target" class="girdi" style="width:150px" value="<?= $budgetTarget ? number_format($budgetTarget, 0, ',', '.') : '' ?>" placeholder="Örn. 250.000">
                <button type="submit" class="btn btn-sm">Kaydet</button>
            </form>
        </div>
        <?php if ($budgetTarget > 0): $targetRate = min(100, round($buMonthGelir / $budgetTarget * 100)); ?>
        <div class="satir-esnek arasi mb-2"><span class="kucuk"><?= AYLAR[(int)date('n')] ?> gerçekleşme: <b><?= money($buMonthGelir) ?></b> / <?= money($budgetTarget) ?></span><span class="kalin" style="color:<?= $targetRate >= 100 ? 'var(--basari)' : 'var(--text)' ?>">%<?= round($buMonthGelir / $budgetTarget * 100) ?></span></div>
        <div class="ilerleme" style="height:10px"><div class="ilerleme-dolu <?= $targetRate >= 100 ? '' : ($targetRate >= 70 ? '' : 'yogun') ?>" data-rate="<?= $targetRate ?>" style="width:0;<?= $targetRate >= 100 ? 'background:var(--basari)' : '' ?>"></div></div>
        <?php else: ?><div class="hucre-alt">Hedef girin — bu ayın tahsilatları hedefe oranla izlenir.</div><?php endif; ?>
    </div>

    <!-- Nakit akış projeksiyonu -->
    <div class="kart mb-3">
        <div class="kart-baslik mb-2">Nakit Akış Projeksiyonu — önümüzdeki 3 ay</div>
        <div class="hucre-alt mb-3">Beklenen gelir = aktif aylık sözleşmeler + planlanan tahsilatlar · Gider = maaşlar + tekrarlayan ve planlı giderler</div>
        <div style="display:flex;gap:20px;align-items:flex-end;height:150px;padding:0 6px">
            <?php foreach ($projection as $pj): $net = $pj['gelir'] - $pj['expense']; ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%">
                <span class="kucuk kalin" style="color:<?= $net >= 0 ? 'var(--basari)' : 'var(--tehlike)' ?>"><?= ($net >= 0 ? '+' : '') . money($net) ?></span>
                <div style="flex:1;display:flex;gap:6px;align-items:flex-end;width:100%;justify-content:center">
                    <div title="Beklenen gelir: <?= money($pj['gelir']) ?>" style="width:30px;border-radius:6px 6px 0 0;background:var(--basari);height:<?= max(2, round($pj['gelir'] / $projMax * 100)) ?>%"></div>
                    <div title="Planlı gider: <?= money($pj['expense']) ?>" style="width:30px;border-radius:6px 6px 0 0;background:var(--tehlike);opacity:.75;height:<?= max(2, round($pj['expense'] / $projMax * 100)) ?>%"></div>
                </div>
                <span class="hucre-alt"><?= $pj['tag'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kart">
        <div class="kart-baslik mb-2">Proje Kârlılığı</div>
        <div class="hucre-alt mb-3">İşçilik maliyeti = kayıtlı süre × kişinin saat maliyeti (maaş ÷ 172 saat). Maaş girilmeyen kişilerin süresi maliyete katılmaz.</div>
        <?php if (!$karlilik): ?><div class="metin-muted kucuk">Sözleşme tutarı girilmiş proje yok.</div>
        <?php else: ?>
        <div class="tablo-sar"><table class="tablo"><thead><tr><th>Proje</th><th>Sözleşme</th><th>İşçilik Maliyeti</th><th>Tahmini Kâr</th><th>Marj</th></tr></thead><tbody>
            <?php foreach ($karlilik as $kr):
                $kar = $kr['contract_amount'] - $kr['iscilik'];
                $marj = $kr['contract_amount'] > 0 ? round($kar / $kr['contract_amount'] * 100) : 0; ?>
            <tr>
                <td><span class="etiket-nokta" style="width:8px;height:8px;background:<?= e($kr['client_color']) ?>;margin-right:6px"></span><a href="project.php?id=<?= $kr['id'] ?>" class="hucre-ana"><?= e($kr['name']) ?></a><div class="hucre-alt"><?= e($kr['client_name']) ?></div></td>
                <td class="kalin"><?= money($kr['contract_amount']) ?></td>
                <td style="color:var(--tehlike)">−<?= money($kr['iscilik']) ?></td>
                <td class="kalin" style="color:<?= $kar >= 0 ? 'var(--basari)' : 'var(--tehlike)' ?>"><?= money($kar) ?></td>
                <td><span class="rozet <?= $marj >= 40 ? 'r-onaylandi' : ($marj >= 15 ? 'r-bekliyor' : 'r-reddedildi') ?>">%<?= $marj ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<?php if (permission('kapasite')): ?>
<div class="sekme-icerik" id="sekme-kapasite">
    <div class="kart">
        <div class="satir-esnek arasi mb-3">
            <div>
                <div class="kart-baslik">Haftalık Doluluk — <?= format_date($weekHead) ?> / <?= format_date($weekEnd) ?></div>
                <div class="hucre-alt mt-1">Kayıtlı çalışma süresi, kişinin haftalık kapasite hedefine oranlanır.</div>
            </div>
            <a href="export.php?type=zaman" class="btn btn-sm">Zaman Raporu CSV</a>
        </div>
        <?php if (!$capacities): ?><div class="metin-muted kucuk">Ekip üyesi yok.</div>
        <?php else: foreach ($capacities as $kp):
            $targetMin = (int)$kp['weekly_capacity'] * 60;
            $rate = $targetMin > 0 ? round($kp['week_minutes'] / $targetMin * 100) : 0;
            $sinif = $rate > 100 ? 'asiri' : ($rate > 80 ? 'yogun' : ''); ?>
        <div class="kapasite-satir">
            <?= avatar($kp, 38) ?>
            <div style="min-width:150px">
                <div class="hucre-ana kucuk"><?= e($kp['name']) ?></div>
                <div class="hucre-alt"><?= $kp['open_task'] ?> açık görev · hedef <?= $kp['weekly_capacity'] ?> sa/hafta</div>
            </div>
            <div class="kapasite-bar">
                <div class="ilerleme"><div class="ilerleme-dolu <?= $sinif ?>" data-rate="<?= min(100, $rate) ?>" style="width:0"></div></div>
                <div class="hucre-alt mt-1"><?= format_minutes((int)$kp['week_minutes']) ?> kayıtlı</div>
            </div>
            <div class="kapasite-yuzde" style="<?= $rate > 100 ? 'color:var(--tehlike)' : ($rate > 80 ? 'color:var(--warning)' : '') ?>">%<?= $rate ?></div>
        </div>
        <?php endforeach; endif; ?>
        <div class="form-ipucu mt-2">Kapasite hedefleri <b>Yönetim → Kullanıcılar</b>'dan kişi bazında ayarlanır. %80 üzeri sarı, %100 üzeri kırmızı gösterilir.</div>
    </div>
</div>
<?php endif; ?>
</div><!-- /sekme-kap -->

<div class="modal-katman" id="modalPayment">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Finans Kaydı</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="payment_save">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="title" class="girdi" required placeholder="Örn. Ekim ayı hizmet bedeli"></div>
            <div class="form-grup"><label class="form-etiket">Proje <span class="zorunlu">*</span></label><select name="project_id" class="secim" required><option value="">Seçin...</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" <?= $projectFiltre == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tür</label><select name="type" class="secim"><option value="fatura">Fatura</option><option value="tahsilat">Tahsilat</option></select></div>
                <div class="form-grup"><label class="form-etiket">Tutar (₺) <span class="zorunlu">*</span></label><input name="amount" class="girdi" required placeholder="0,00"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tarih</label><input type="date" name="date" class="girdi" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-grup"><label class="form-etiket">Durum</label><select name="status" class="secim"><option value="bekliyor">Bekliyor</option><option value="odendi">Ödendi</option><option value="gecikti">Gecikti</option></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="description" class="girdi"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>
<!-- Gider ekleme modalı -->
<div class="modal-katman" id="modalExpense">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Gider Kaydı</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="expense_save">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="title" class="girdi" required placeholder="Örn. Ofis kirası"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tür</label><select name="type" class="secim"><?php foreach (GIDER_TURLERI as $k => $v): if ($k === 'maas') continue; ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Tutar (₺) <span class="zorunlu">*</span></label><input name="amount" class="girdi" required placeholder="0,00"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tarih</label><input type="date" name="date" class="girdi" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-grup"><label class="form-etiket">Durum</label><select name="status" class="secim"><option value="bekliyor">Bekliyor</option><option value="odendi">Ödendi</option></select></div>
            </div>
            <div class="form-grup"><label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="repeat" value="aylik"> <span class="kucuk"><b>Her ay tekrarla</b> — kira/abonelik gibi giderler her ay başında otomatik oluşur</span></label></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="description" class="girdi"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- Teklif/Fatura oluşturma modalı -->
<div class="modal-katman" id="modalDocument">
    <div class="modal modal-genis"><div class="modal-ust"><div class="modal-baslik">Yeni Teklif / Fatura</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="document_save" id="documentForm">
        <input type="hidden" name="items" id="b_items">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Belge Türü</label><select name="type" class="secim"><option value="teklif">Teklif</option><option value="fatura">Fatura</option></select></div>
                <div class="form-grup"><label class="form-etiket">Dosya (müşteri)</label><select name="client_id" class="secim"><option value="">—</option><?php foreach ($tumClients as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="title" class="girdi" required placeholder="Örn. 2026 Sosyal Medya Yönetimi Teklifi"></div>
            <div class="form-grup">
                <label class="form-etiket">Kalemler</label>
                <div class="dikey" id="itemList" style="gap:8px"></div>
                <button type="button" class="btn btn-sm btn-hayalet mt-2" onclick="itemAdd()">+ Kalem Ekle</button>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">KDV Oranı (%)</label><input type="number" name="vat_rate" class="girdi" value="20" min="0" max="50"></div>
                <div class="form-grup"><label class="form-etiket">Geçerlilik Tarihi</label><input type="date" name="valid_until" class="girdi"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Notlar</label><textarea name="notes" class="metin-alani" placeholder="Ödeme koşulları, teslim süresi vb."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
    </form></div>
</div>

<script>
async function paymentStatus(id, status) { const j = await api('payment_status', {id, status}); if (j.ok) { toast('Güncellendi', 'basari'); setTimeout(()=>location.reload(),500); } }
async function expenseStatus(id, status) { const j = await api('expense_status', {id, status}); if (j.ok) toast('Güncellendi', 'basari'); }
async function documentStatus(id, status) { const j = await api('document_status', {id, status}); if (j.ok) { toast(j.message, 'basari'); setTimeout(()=>location.reload(),700); } }
function itemAdd(k = {}) {
    const div = document.createElement('div');
    div.className = 'satir-esnek kalem-satir';
    div.style.gap = '8px';
    div.innerHTML = `<input class="girdi k-ad" placeholder="Hizmet/ürün adı" style="flex:2" value="${(k.name||'').replace(/"/g,'&quot;')}">
        <input class="girdi k-adet" placeholder="Adet" style="width:70px" value="${k.adet||1}">
        <input class="girdi k-fiyat" placeholder="Birim ₺" style="width:110px" value="${k.price||''}">
        <button type="button" class="ikon-eylem tehlike" onclick="this.parentElement.remove()">✕</button>`;
    document.getElementById('itemList').appendChild(div);
}
itemAdd();
document.getElementById('documentForm').addEventListener('submit', () => {
    const items = Array.from(document.querySelectorAll('.item-row_item')).map(s => ({
        name: s.querySelector('.k-name').value.trim(),
        adet: s.querySelector('.k-adet').value,
        price: s.querySelector('.k-price').value,
    })).filter(k => k.name);
    document.getElementById('b_items').value = JSON.stringify(items);
});
</script>
<?php page_end(); ?>

<?php
/**
 * SADA One — Monthly Reports
 * Monthly work reports per client file are filled in on the panel.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (is_intern()) { header('Location: index.php'); exit; }

$clients = rows("SELECT c.id, c.name, c.manager_id, u.name manager_name FROM clients c LEFT JOIN users u ON u.id=c.manager_id WHERE c.status='aktif' ORDER BY c.name");
$currentPeriod = date('Y-m');
// Fill-status of the current period per client (for the tracking grid)
$periodStatus = [];
foreach (rows("SELECT client_id, status FROM monthly_reports WHERE period=?", [$currentPeriod]) as $ps) $periodStatus[$ps['client_id']] = $ps['status'];
$reports = rows("SELECT r.*, d.name client_name, y.name author_name FROM monthly_reports r JOIN clients d ON d.id=r.client_id JOIN users y ON y.id=r.author_id ORDER BY r.period DESC, d.name");

// Report to edit (if client file + period are selected)
$secClient = (int)($_GET['client'] ?? 0);
$secPeriod = preg_match('/^\d{4}-\d{2}$/', $_GET['period'] ?? '') ? $_GET['period'] : date('Y-m');
$is_active = $secClient ? row("SELECT * FROM monthly_reports WHERE client_id=? AND period=?", [$secClient, $secPeriod]) : null;

$periodName = function (string $d): string {
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    [$y, $a] = explode('-', $d);
    return $aylar[(int)$a] . ' ' . $y;
};

page_start('Aylık Raporlar', 'mreports');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Aylık Raporlar</div><div class="sayfa-alt">Müşteri dosyaları için dönem raporları — özet, yapılanlar, metrikler, gelecek plan</div></div>
</div>

<!-- This month at a glance: who has filled in, who has not -->
<div class="kart mb-3">
    <div class="satir-esnek arasi mb-2">
        <div class="kart-baslik" style="font-size:15px">Bu Ay (<?= e($currentPeriod) ?>) Doldurma Durumu</div>
        <span class="kucuk metin-muted"><?= count(array_filter($periodStatus, fn($s) => $s === 'tamamlandi')) ?>/<?= count($clients) ?> tamamlandı</span>
    </div>
    <div class="izgara" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px">
        <?php foreach ($clients as $cl):
            $st = $periodStatus[$cl['id']] ?? null;
            $rozet = $st === 'tamamlandi' ? '<span class="rozet r-tamamlandi">Tamamlandı</span>' : ($st === 'taslak' ? '<span class="rozet r-devam">Taslak</span>' : '<span class="rozet r-gecikti">Boş</span>'); ?>
        <a href="?client=<?= $cl['id'] ?>&period=<?= $currentPeriod ?>" class="satir-esnek arasi kucuk" style="padding:9px 12px;background:var(--surface-2);border-radius:10px;gap:8px">
            <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><b><?= e($cl['name']) ?></b><br><span class="metin-muted" style="font-size:11px"><?= $cl['manager_name'] ? e($cl['manager_name']) : 'sorumlu atanmadı' ?></span></span>
            <?= $rozet ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="izgara" style="grid-template-columns:340px 1fr;align-items:start">
    <div class="kart">
        <div class="kart-baslik mb-2">Rapor Seç / Başlat</div>
        <form method="get">
            <div class="form-grup"><label class="form-etiket">Dosya</label>
                <select name="client" class="secim" onchange="this.form.submit()">
                    <option value="">Seçin...</option>
                    <?php foreach ($clients as $d): ?><option value="<?= $d['id'] ?>" <?= $secClient === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-grup"><label class="form-etiket">Dönem</label>
                <input type="month" name="period" class="girdi native-kal" value="<?= e($secPeriod) ?>" onchange="this.form.submit()"></div>
        </form>

        <div class="kart-baslik mb-2 mt-3" style="font-size:14px">Doldurulan Raporlar</div>
        <div class="dikey" style="gap:5px;max-height:420px;overflow-y:auto">
            <?php if (!$reports): ?><div class="metin-muted kucuk">Henüz rapor yok.</div><?php endif; ?>
            <?php foreach ($reports as $r): ?>
            <a href="?client=<?= $r['client_id'] ?>&period=<?= $r['period'] ?>" class="satir-esnek arasi kucuk" style="padding:9px 11px;background:var(--surface-2);border-radius:9px">
                <span><b><?= e($r['client_name']) ?></b> · <?= $periodName($r['period']) ?></span>
                <?= $r['status'] === 'tamamlandi' ? '<span class="rozet r-tamamlandi" style="padding:1px 8px">Tamam</span>' : '<span class="rozet r-bekliyor" style="padding:1px 8px">Taslak</span>' ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kart">
        <?php if (!$secClient): ?>
        <div class="bos-durum" style="padding:60px 20px">
            <div class="bos-ikon">📊</div>
            <div class="bos-baslik">Rapor seçin</div>
            <div class="bos-metin">Soldan dosya ve dönem seçerek yeni rapor başlatın ya da mevcut raporu açın.</div>
        </div>
        <?php else:
            $clientName = val("SELECT name FROM clients WHERE id=?", [$secClient]); ?>
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik"><?= e($clientName) ?> — <?= $periodName($secPeriod) ?> Raporu</div>
            <?php if ($is_active): ?><span class="kucuk metin-muted">Son güncelleme: <?= e($is_active['author_name'] ?? '') ?: '' ?> <?= format_date($is_active['updated'] ?? $is_active['created'], true) ?></span><?php endif; ?>
        </div>
        <?php
        // Automatic financial summary for the selected client + period (live, not stored)
        [$pYil, $pAy] = explode('-', $secPeriod);
        $pBas = "$secPeriod-01"; $pSon = date('Y-m-t', strtotime($pBas));
        $finBudget = (float)val("SELECT COALESCE(SUM(budget),0) FROM projects WHERE client_id=? AND status='aktif'", [$secClient]);
        $finExtra = (float)val("SELECT COALESCE(SUM(t.amount),0) FROM project_extra_requests t JOIN projects p ON p.id=t.project_id WHERE p.client_id=? AND t.status='onaylandi' AND t.created BETWEEN ? AND ?", [$secClient, "$pBas 00:00:00", "$pSon 23:59:59"]);
        $finIncome = (float)val("SELECT COALESCE(SUM(o.amount),0) FROM payments o JOIN projects p ON p.id=o.project_id WHERE p.client_id=? AND o.date BETWEEN ? AND ?", [$secClient, $pBas, $pSon]);
        $finShoot = (float)val("SELECT COALESCE(SUM(e.cost),0) FROM events e LEFT JOIN projects p ON p.id=e.project_id WHERE (e.client_id=? OR p.client_id=?) AND e.start BETWEEN ? AND ?", [$secClient, $secClient, "$pBas 00:00:00", "$pSon 23:59:59"]);
        ?>
        <div class="izgara mb-3" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="hucre-alt">Aktif Proje Bütçesi</div><div class="kalin"><?= number_format($finBudget, 0, ',', '.') ?> ₺</div></div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="hucre-alt">Bu Ay Onaylı Ek Talep</div><div class="kalin">+<?= number_format($finExtra, 0, ',', '.') ?> ₺</div></div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="hucre-alt">Bu Ay Tahsilat</div><div class="kalin" style="color:var(--basari)"><?= number_format($finIncome, 0, ',', '.') ?> ₺</div></div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px"><div class="hucre-alt">Bu Ay Çekim Maliyeti</div><div class="kalin" style="color:var(--tehlike)"><?= number_format($finShoot, 0, ',', '.') ?> ₺</div></div>
        </div>
        <div class="form-ipucu mb-2">Finansal özet panel verilerinden otomatik hesaplanır; rapora elle geçirmenize gerek yok.</div>
        <div class="satir-esnek mb-2" style="gap:10px">
            <button type="button" class="btn btn-sm" id="aiDraftBtn" onclick="aiDraft(<?= $secClient ?>, '<?= e($secPeriod) ?>')">🪄 AI ile Taslak Doldur</button>
            <span class="kucuk metin-muted" id="aiDraftDurum"></span>
        </div>
        <form data-ajax="monthly_report_save" data-refresh="hayir" id="reportForm">
            <input type="hidden" name="client_id" value="<?= $secClient ?>">
            <input type="hidden" name="period" value="<?= e($secPeriod) ?>">
            <div class="form-grup"><label class="form-etiket">Genel Özet</label><textarea name="summary" class="metin-alani" rows="3" placeholder="Bu ay genel olarak..."><?= e($is_active['summary'] ?? '') ?></textarea></div>
            <div class="form-grup"><label class="form-etiket">Yapılan Çalışmalar</label><textarea name="work_done" class="metin-alani" rows="5" placeholder="- 12 içerik üretildi ve yayınlandı&#10;- 2 çekim gerçekleştirildi..."><?= e($is_active['work_done'] ?? '') ?></textarea></div>
            <div class="form-grup"><label class="form-etiket">Metrikler & Sonuçlar</label><textarea name="metrics" class="metin-alani" rows="4" placeholder="Erişim, etkileşim, takipçi değişimi, öne çıkan içerikler..."><?= e($is_active['metrics'] ?? '') ?></textarea></div>
            <div class="form-grup"><label class="form-etiket">Gelecek Ay Planı</label><textarea name="plan" class="metin-alani" rows="3" placeholder="Önümüzdeki dönem hedefleri..."><?= e($is_active['plan'] ?? '') ?></textarea></div>
            <div class="satir-esnek" style="gap:10px">
                <button type="submit" class="btn" onclick="this.form.querySelectorAll('input[name=status]').forEach(x => x.remove())">Taslak Kaydet</button>
                <button type="submit" class="btn btn-marka" onclick="this.form.querySelectorAll('input[name=status]').forEach(x => x.remove()); const i = document.createElement('input'); i.type = 'hidden'; i.name = 'status'; i.value = 'tamamlandi'; this.form.appendChild(i)">Tamamlandı Olarak Kaydet</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<script>
async function aiDraft(clientId, period) {
    const btn = document.getElementById('aiDraftBtn'), st = document.getElementById('aiDraftDurum');
    btn.disabled = true; st.textContent = 'Panel verileri derleniyor, taslak yazılıyor... (~20 sn)';
    const j = await api('ai_report_draft', { client_id: clientId, period });
    btn.disabled = false;
    if (!j.ok) { st.textContent = ''; toast(j.error || 'Taslak üretilemedi', 'hata'); return; }
    const f = document.getElementById('reportForm');
    for (const [alan, deger] of Object.entries(j.draft)) {
        const el = f.querySelector(`[name="${alan}"]`);
        if (el && deger) el.value = deger;
    }
    st.textContent = 'Taslak dolduruldu — kontrol edip kaydedin.';
    toast('AI taslağı hazır. Düzenleyip kaydetmeyi unutmayın.', 'basari');
}
</script>
<?php page_end(); ?>

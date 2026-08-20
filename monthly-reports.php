<?php
/**
 * SADA One — Aylık Raporlar
 * Dosya (müşteri) bazlı aylık çalışma raporları panelde doldurulur.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (is_intern()) { header('Location: index.php'); exit; }

$clients = rows("SELECT id, name FROM clients WHERE status='aktif' ORDER BY name");
$reports = rows("SELECT r.*, d.name client_name, y.name author_name FROM monthly_reports r JOIN clients d ON d.id=r.client_id JOIN users y ON y.id=r.author_id ORDER BY r.period DESC, d.name");

// Düzenlenecek rapor (dosya+donem seçiliyse)
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
        <form data-ajax="monthly_report_save" data-refresh="hayir">
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
<?php page_end(); ?>

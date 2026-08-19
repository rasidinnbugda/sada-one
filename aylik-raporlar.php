<?php
/**
 * SADA One — Aylık Raporlar
 * Dosya (müşteri) bazlı aylık çalışma raporları panelde doldurulur.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (is_stajyer()) { header('Location: index.php'); exit; }

$dosyalar = rows("SELECT id, ad FROM dosyalar WHERE durum='aktif' ORDER BY ad");
$raporlar = rows("SELECT r.*, d.ad dosya_ad, y.ad yazan_ad FROM aylik_raporlar r JOIN dosyalar d ON d.id=r.dosya_id JOIN users y ON y.id=r.yazan_id ORDER BY r.donem DESC, d.ad");

// Düzenlenecek rapor (dosya+donem seçiliyse)
$secDosya = (int)($_GET['dosya'] ?? 0);
$secDonem = preg_match('/^\d{4}-\d{2}$/', $_GET['donem'] ?? '') ? $_GET['donem'] : date('Y-m');
$aktif = $secDosya ? row("SELECT * FROM aylik_raporlar WHERE dosya_id=? AND donem=?", [$secDosya, $secDonem]) : null;

$donemAd = function (string $d): string {
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    [$y, $a] = explode('-', $d);
    return $aylar[(int)$a] . ' ' . $y;
};

sayfa_basi('Aylık Raporlar', 'araporlar');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Aylık Raporlar</div><div class="sayfa-alt">Müşteri dosyaları için dönem raporları — özet, yapılanlar, metrikler, gelecek plan</div></div>
</div>

<div class="izgara" style="grid-template-columns:340px 1fr;align-items:start">
    <div class="kart">
        <div class="kart-baslik mb-2">Rapor Seç / Başlat</div>
        <form method="get">
            <div class="form-grup"><label class="form-etiket">Dosya</label>
                <select name="dosya" class="secim" onchange="this.form.submit()">
                    <option value="">Seçin...</option>
                    <?php foreach ($dosyalar as $d): ?><option value="<?= $d['id'] ?>" <?= $secDosya === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['ad']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-grup"><label class="form-etiket">Dönem</label>
                <input type="month" name="donem" class="girdi native-kal" value="<?= e($secDonem) ?>" onchange="this.form.submit()"></div>
        </form>

        <div class="kart-baslik mb-2 mt-3" style="font-size:14px">Doldurulan Raporlar</div>
        <div class="dikey" style="gap:5px;max-height:420px;overflow-y:auto">
            <?php if (!$raporlar): ?><div class="metin-muted kucuk">Henüz rapor yok.</div><?php endif; ?>
            <?php foreach ($raporlar as $r): ?>
            <a href="?dosya=<?= $r['dosya_id'] ?>&donem=<?= $r['donem'] ?>" class="satir-esnek arasi kucuk" style="padding:9px 11px;background:var(--surface-2);border-radius:9px">
                <span><b><?= e($r['dosya_ad']) ?></b> · <?= $donemAd($r['donem']) ?></span>
                <?= $r['durum'] === 'tamamlandi' ? '<span class="rozet r-tamamlandi" style="padding:1px 8px">Tamam</span>' : '<span class="rozet r-bekliyor" style="padding:1px 8px">Taslak</span>' ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kart">
        <?php if (!$secDosya): ?>
        <div class="bos-durum" style="padding:60px 20px">
            <div class="bos-ikon">📊</div>
            <div class="bos-baslik">Rapor seçin</div>
            <div class="bos-metin">Soldan dosya ve dönem seçerek yeni rapor başlatın ya da mevcut raporu açın.</div>
        </div>
        <?php else:
            $dosyaAd = val("SELECT ad FROM dosyalar WHERE id=?", [$secDosya]); ?>
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik"><?= e($dosyaAd) ?> — <?= $donemAd($secDonem) ?> Raporu</div>
            <?php if ($aktif): ?><span class="kucuk metin-muted">Son güncelleme: <?= e($aktif['yazan_ad'] ?? '') ?: '' ?> <?= tarih($aktif['updated'] ?? $aktif['created'], true) ?></span><?php endif; ?>
        </div>
        <form data-ajax="aylikrapor_kaydet" data-yenile="hayir">
            <input type="hidden" name="dosya_id" value="<?= $secDosya ?>">
            <input type="hidden" name="donem" value="<?= e($secDonem) ?>">
            <div class="form-grup"><label class="form-etiket">Genel Özet</label><textarea name="ozet" class="metin-alani" rows="3" placeholder="Bu ay genel olarak..."><?= e($aktif['ozet'] ?? '') ?></textarea></div>
            <div class="form-grup"><label class="form-etiket">Yapılan Çalışmalar</label><textarea name="yapilanlar" class="metin-alani" rows="5" placeholder="- 12 içerik üretildi ve yayınlandı&#10;- 2 çekim gerçekleştirildi..."><?= e($aktif['yapilanlar'] ?? '') ?></textarea></div>
            <div class="form-grup"><label class="form-etiket">Metrikler & Sonuçlar</label><textarea name="metrikler" class="metin-alani" rows="4" placeholder="Erişim, etkileşim, takipçi değişimi, öne çıkan içerikler..."><?= e($aktif['metrikler'] ?? '') ?></textarea></div>
            <div class="form-grup"><label class="form-etiket">Gelecek Ay Planı</label><textarea name="plan" class="metin-alani" rows="3" placeholder="Önümüzdeki dönem hedefleri..."><?= e($aktif['plan'] ?? '') ?></textarea></div>
            <div class="satir-esnek" style="gap:10px">
                <button type="submit" class="btn" onclick="this.form.querySelectorAll('input[name=durum]').forEach(x => x.remove())">Taslak Kaydet</button>
                <button type="submit" class="btn btn-marka" onclick="this.form.querySelectorAll('input[name=durum]').forEach(x => x.remove()); const i = document.createElement('input'); i.type = 'hidden'; i.name = 'durum'; i.value = 'tamamlandi'; this.form.appendChild(i)">Tamamlandı Olarak Kaydet</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php sayfa_sonu(); ?>

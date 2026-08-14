<?php
/**
 * SADA Dijital — Zaman Çizelgesi (Gantt görünümü)
 * Projeler ve son tarihli görevler 6 haftalık pencerede yatay çubuklarla gösterilir.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_staff();

// Görünüm penceresi: bu haftanın pazartesisi + kaydırma
$kaydir = (int)($_GET['kaydir'] ?? 0);
$baslangic = strtotime('monday this week') + $kaydir * 7 * 86400;
$gunSayisi = 42; // 6 hafta
$bitis = $baslangic + ($gunSayisi - 1) * 86400;
$bas = date('Y-m-d', $baslangic);
$son = date('Y-m-d', $bitis);

// Pencereyle kesişen projeler + görevleri
$projeler = rows("SELECT p.*, d.ad dosya_ad, d.renk dosya_renk FROM projeler p JOIN dosyalar d ON d.id=p.dosya_id
    WHERE p.durum IN ('aktif','beklemede') ORDER BY d.ad, p.ad");

function gantt_konum(int $basTs, int $gunSayisi, string $t1, string $t2): ?array {
    $a = strtotime($t1); $b = strtotime($t2);
    if ($b < $basTs || $a > $basTs + ($gunSayisi - 1) * 86400) return null;
    $solGun = max(0, intdiv($a - $basTs, 86400));
    $sagGun = min($gunSayisi - 1, intdiv($b - $basTs, 86400));
    return [round($solGun / $gunSayisi * 100, 2), round(($sagGun - $solGun + 1) / $gunSayisi * 100, 2)];
}

$bugunKonum = null;
if (time() >= $baslangic && time() <= $bitis + 86400) {
    $bugunKonum = round((intdiv(time() - $baslangic, 86400) + .5) / $gunSayisi * 100, 2);
}

sayfa_basi('Zaman Çizelgesi', 'cizelge');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Zaman Çizelgesi</div><div class="sayfa-alt"><?= tarih($bas) ?> — <?= tarih($son) ?> · projeler ve son tarihli görevler</div></div>
    <div class="sayfa-ust-aksiyon">
        <a href="?kaydir=<?= $kaydir - 2 ?>" class="btn btn-sm">← Geri</a>
        <a href="?kaydir=0" class="btn btn-sm <?= $kaydir === 0 ? 'btn-marka' : '' ?>">Bugün</a>
        <a href="?kaydir=<?= $kaydir + 2 ?>" class="btn btn-sm">İleri →</a>
    </div>
</div>

<div class="kart gantt-sar" style="padding:0">
    <div class="gantt">
        <!-- Gün başlıkları (hafta bazlı) -->
        <div class="gantt-gunler">
            <div class="gantt-etiket kalin" style="padding:10px 14px">Proje / Görev</div>
            <div class="gantt-gun-izgara" style="grid-template-columns:repeat(<?= intdiv($gunSayisi, 7) ?>, 1fr)">
                <?php for ($h = 0; $h < intdiv($gunSayisi, 7); $h++):
                    $hBas = $baslangic + $h * 7 * 86400;
                    $buHafta = date('o-W', $hBas) === date('o-W'); ?>
                <div class="gantt-gun <?= $buHafta ? 'bugun' : '' ?>"><?= date('j', $hBas) ?> <?= AYLAR[(int)date('n', $hBas)] ?></div>
                <?php endfor; ?>
            </div>
        </div>

        <?php
        $satirVar = false;
        foreach ($projeler as $gi => $p):
            // Proje çubuğu (başlangıç-bitiş varsa)
            $projeKonum = ($p['baslangic'] && $p['bitis']) ? gantt_konum($baslangic, $gunSayisi, $p['baslangic'], $p['bitis']) : null;
            // Penceredeki görevler
            $gorevler = rows("SELECT g.*, u.ad atanan_ad FROM gorevler g LEFT JOIN users u ON u.id=g.atanan_id
                WHERE g.proje_id=? AND g.son_tarih IS NOT NULL AND g.son_tarih BETWEEN ? AND ? ORDER BY g.son_tarih", [$p['id'], $bas, $son]);
            if (!$projeKonum && !$gorevler) continue;
            $satirVar = true;
        ?>
        <div class="gantt-satir">
            <div class="gantt-etiket proje-basligi">
                <span class="etiket-nokta" style="background:<?= e($p['dosya_renk']) ?>;margin-right:6px"></span>
                <a href="proje.php?id=<?= $p['id'] ?>" style="color:inherit"><?= e($p['ad']) ?></a>
            </div>
            <div class="gantt-alan">
                <?php if ($bugunKonum !== null): ?><div class="gantt-bugun-cizgi" style="left:<?= $bugunKonum ?>%"></div><?php endif; ?>
                <?php if ($projeKonum): ?>
                <div class="gantt-cubuk" style="left:<?= $projeKonum[0] ?>%;width:<?= $projeKonum[1] ?>%;animation-delay:<?= $gi * 60 ?>ms" onclick="location.href='proje.php?id=<?= $p['id'] ?>'" title="<?= e($p['ad']) ?>: <?= tarih($p['baslangic']) ?> → <?= tarih($p['bitis']) ?>"><?= e($p['dosya_ad']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php foreach ($gorevler as $ti => $gr):
            $baslangicTarihi = max($gr['created'] ? substr($gr['created'], 0, 10) : $gr['son_tarih'], $bas);
            $konum = gantt_konum($baslangic, $gunSayisi, $baslangicTarihi, $gr['son_tarih']);
            if (!$konum) continue;
            $sinif = $gr['durum'] === 'tamamlandi' ? 'tamamlandi' : ($gr['son_tarih'] < date('Y-m-d') ? 'gecikti' : ''); ?>
        <div class="gantt-satir">
            <div class="gantt-etiket" style="padding-left:32px">└ <?= e($gr['baslik']) ?></div>
            <div class="gantt-alan">
                <?php if ($bugunKonum !== null): ?><div class="gantt-bugun-cizgi" style="left:<?= $bugunKonum ?>%"></div><?php endif; ?>
                <div class="gantt-cubuk <?= $sinif ?>" style="left:<?= $konum[0] ?>%;width:<?= max(3, $konum[1]) ?>%;animation-delay:<?= 100 + $ti * 50 ?>ms" onclick="location.href='gorev.php?id=<?= $gr['id'] ?>'" title="<?= e($gr['baslik']) ?> · <?= GOREV_DURUMLARI[$gr['durum']] ?> · Son: <?= tarih($gr['son_tarih']) ?>"><?= $gr['atanan_ad'] ? e(explode(' ', $gr['atanan_ad'])[0]) : '' ?></div>
            </div>
        </div>
        <?php endforeach; endforeach; ?>

        <?php if (!$satirVar): ?>
        <div class="bos-durum">
            <div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M4 6h6m-6 6h10M4 18h14M20 6v12"/></svg></div>
            <div class="bos-baslik">Bu pencerede planlı iş yok</div>
            <div class="bos-metin">Projelere başlangıç/bitiş tarihi, görevlere son tarih ekleyin — burada otomatik görünürler.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="satir-esnek sarma mt-3" style="gap:16px;justify-content:center">
    <span class="satir-esnek kucuk" style="gap:6px"><span class="etiket-nokta" style="background:var(--marka)"></span>Devam eden</span>
    <span class="satir-esnek kucuk" style="gap:6px"><span class="etiket-nokta" style="background:var(--basari)"></span>Tamamlanan</span>
    <span class="satir-esnek kucuk" style="gap:6px"><span class="etiket-nokta" style="background:var(--tehlike)"></span>Geciken</span>
    <span class="satir-esnek kucuk" style="gap:6px"><span style="width:2px;height:14px;background:var(--marka);display:inline-block"></span>Bugün</span>
</div>
<?php sayfa_sonu(); ?>

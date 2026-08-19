<?php
/**
 * SADA One — Ekip Panosu
 * Kim ne üzerinde çalışıyor, kim boşta — anlık meşguliyet görünümü.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_staff();

$haftaBasi = date('Y-m-d', strtotime('monday this week'));
$uyeler = rows("SELECT us.id, us.ad, us.renk, us.avatar, us.unvan, us.rol, us.haftalik_kapasite,
    (SELECT COALESCE(SUM(z.dakika),0) FROM zaman_kayitlari z WHERE z.user_id=us.id AND z.tarih=CURDATE()) bugun_dk,
    (SELECT COALESCE(SUM(z.dakika),0) FROM zaman_kayitlari z WHERE z.user_id=us.id AND z.tarih>=?) hafta_dk
    FROM users us WHERE us.rol IN ('yonetici','pm','ekip','finans') AND us.aktif=1 ORDER BY us.ad", [$haftaBasi]);

// Her üyenin devam eden görevleri (atanan_id VEYA çoklu atama üzerinden)
foreach ($uyeler as &$uye) {
    $uye['devam_edenler'] = rows("SELECT g.id, g.baslik, g.durum, g.son_tarih, p.ad proje_ad, d.renk dosya_renk
        FROM gorevler g JOIN projeler p ON p.id=g.proje_id JOIN dosyalar d ON d.id=p.dosya_id
        WHERE g.arsivlendi=0 AND g.durum IN ('devam','incelemede','onayda')
        AND (g.atanan_id=? OR EXISTS(SELECT 1 FROM gorev_atananlar ga WHERE ga.gorev_id=g.id AND ga.user_id=?))
        ORDER BY FIELD(g.durum,'devam','incelemede','onayda'), g.son_tarih IS NULL, g.son_tarih LIMIT 6", [$uye['id'], $uye['id']]);
    $uye['bekleyen'] = (int)val("SELECT COUNT(*) FROM gorevler g WHERE g.arsivlendi=0 AND g.durum='yapilacak'
        AND (g.atanan_id=? OR EXISTS(SELECT 1 FROM gorev_atananlar ga WHERE ga.gorev_id=g.id AND ga.user_id=?))", [$uye['id'], $uye['id']]);
}
unset($uye);

$mesgulSayi = count(array_filter($uyeler, fn($m) => $m['devam_edenler']));
$bostaSayi = count($uyeler) - $mesgulSayi;

sayfa_basi('Ekip', 'ekip');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Ekip Panosu</div><div class="sayfa-alt">Şu an kim ne üzerinde çalışıyor — <?= $mesgulSayi ?> meşgul, <?= $bostaSayi ?> boşta</div></div>
</div>

<div class="izgara izgara-auto">
    <?php foreach ($uyeler as $uye):
        $bosta = !$uye['devam_edenler'];
        $hedefDk = (int)$uye['haftalik_kapasite'] * 60;
        $oran = $hedefDk > 0 ? min(100, round($uye['hafta_dk'] / $hedefDk * 100)) : 0; ?>
    <div class="kart" style="<?= $bosta ? 'border-color:rgba(53,198,107,.35)' : '' ?>">
        <div class="satir-esnek arasi mb-2">
            <div class="satir-esnek" style="gap:11px">
                <?= avatar($uye, 44) ?>
                <div>
                    <div class="kalin"><?= e($uye['ad']) ?></div>
                    <div class="hucre-alt"><?= $uye['unvan'] ? e($uye['unvan']) : ROLLER[$uye['rol']] ?></div>
                </div>
            </div>
            <?php if ($bosta): ?>
            <span class="rozet r-onaylandi">Boşta</span>
            <?php else: ?>
            <span class="rozet r-devam"><?= count($uye['devam_edenler']) ?> aktif iş</span>
            <?php endif; ?>
        </div>

        <?php if ($bosta): ?>
        <div class="metin-muted kucuk" style="padding:10px 0">
            Devam eden işi yok<?= $uye['bekleyen'] ? " — sırada {$uye['bekleyen']} bekleyen görev var" : '. Yeni görev atanabilir.' ?>
        </div>
        <?php else: ?>
        <div class="dikey mt-1" style="gap:6px">
            <?php foreach ($uye['devam_edenler'] as $dg):
                $gecikti = $dg['son_tarih'] && $dg['son_tarih'] < date('Y-m-d'); ?>
            <a href="gorev.php?id=<?= $dg['id'] ?>" class="satir-esnek arasi" style="padding:7px 10px;background:var(--surface-2);border-radius:9px;gap:8px">
                <span class="satir-esnek kucuk" style="gap:7px;min-width:0">
                    <span class="etiket-nokta" style="width:7px;height:7px;background:<?= e($dg['dosya_renk']) ?>;flex-shrink:0"></span>
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($dg['baslik']) ?></span>
                </span>
                <?= rozet($dg['durum'], GOREV_DURUMLARI) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="satir-esnek arasi mt-2" style="gap:10px">
            <span class="hucre-alt">Bugün: <b style="color:var(--text)"><?= $uye['bugun_dk'] ? dakika_format((int)$uye['bugun_dk']) : '—' ?></b></span>
            <div class="satir-esnek" style="gap:8px;flex:1;max-width:150px">
                <div class="ilerleme" style="flex:1"><div class="ilerleme-dolu <?= $oran > 100 ? 'asiri' : ($oran > 80 ? 'yogun' : '') ?>" data-oran="<?= $oran ?>" style="width:0"></div></div>
                <span class="hucre-alt">%<?= $oran ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="form-ipucu mt-2 orta">Haftalık doluluk çubuğu, kayıtlı süre ÷ haftalık kapasite hedefine göre hesaplanır.</div>
<?php sayfa_sonu(); ?>

<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
$talep = row("SELECT t.*, f.ad form_ad, ug.ad gonderen_ad, ug.renk gonderen_renk, d.ad dosya_ad, p.ad proje_ad, ua.ad atanan_ad
    FROM talepler t JOIN form_sablonlari f ON f.id=t.sablon_id LEFT JOIN users ug ON ug.id=t.gonderen_id
    LEFT JOIN dosyalar d ON d.id=t.dosya_id LEFT JOIN projeler p ON p.id=t.proje_id LEFT JOIN users ua ON ua.id=t.atanan_id WHERE t.id=?", [$id]);
if (!$talep) { header('Location: talepler.php'); exit; }
if (is_musteri() && $talep['gonderen_id'] != $u['id']) { header('Location: talepler.php'); exit; }

$cevaplar = rows("SELECT tc.*, fa.etiket, fa.tip FROM talep_cevaplari tc JOIN form_alanlari fa ON fa.id=tc.alan_id WHERE tc.talep_id=? ORDER BY fa.sira", [$id]);
$projeler = rows("SELECT id, ad FROM projeler WHERE " . ($talep['dosya_id'] ? "dosya_id=" . (int)$talep['dosya_id'] : "durum='aktif'") . " ORDER BY ad");
$ekip = rows("SELECT id, ad FROM users WHERE rol IN ('yonetici','pm','ekip') AND aktif=1 ORDER BY ad");

sayfa_basi('Talep Detayı', 'talepler');
?>
<div class="satir-esnek mb-3" style="gap:10px">
    <a href="talepler.php" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="metin-muted kucuk">Talepler / #<?= $id ?></span>
</div>

<div class="sayfa-ust">
    <div>
        <div class="satir-esnek" style="gap:9px"><span class="rozet rozet-tur"><?= e($talep['form_ad']) ?></span><?= rozet($talep['durum'], TALEP_DURUMLARI) ?></div>
        <div class="sayfa-baslik mt-1"><?= e($talep['baslik']) ?></div>
        <div class="sayfa-alt"><?= e($talep['gonderen_ad']) ?> · <?= tarih($talep['created'], true) ?></div>
    </div>
</div>

<div class="izgara" style="grid-template-columns:1fr 300px">
    <div class="kart">
        <div class="kart-baslik mb-3">Talep Bilgileri</div>
        <div class="dikey" style="gap:16px">
            <?php foreach ($cevaplar as $c): ?>
            <div><div class="hucre-alt mb-2"><?= e($c['etiket']) ?></div><div class="metin-2" style="white-space:pre-wrap"><?php if ($c['tip'] === 'dosya' && $c['deger']): ?><a href="uploads/<?= e($c['deger']) ?>" target="_blank" class="btn btn-sm"><?= ikon('atac', 13) ?> Yüklenen Dosya</a><?php else: ?><?= $c['deger'] ? e($c['deger']) : '<span class="metin-muted">—</span>' ?><?php endif; ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <?php if (is_pm()): ?>
        <div class="kart mb-2">
            <div class="kart-baslik mb-3" style="font-size:14px">Yönetim</div>
            <div class="form-grup">
                <label class="form-etiket">Durum</label>
                <select class="secim" onchange="talepDurum(this.value)">
                    <?php foreach (TALEP_DURUMLARI as $k => $v): ?><option value="<?= $k ?>" <?= $talep['durum'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if (!$talep['proje_id']): ?>
            <div class="form-grup">
                <label class="form-etiket">Projeye Bağla</label>
                <select class="secim" onchange="talepProje(this.value)">
                    <option value="">Seçin...</option>
                    <?php foreach ($projeler as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['ad']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($talep['durum'] !== 'gorev_olusturuldu' && $talep['gorev_id'] === null): ?>
            <button class="btn btn-marka btn-blok mt-2" data-eylem="talep_goreve" data-id="<?= $id ?>" data-onay="Bu talep bir göreve dönüştürülsün mü?">Göreve Dönüştür</button>
            <div class="form-ipucu">Not: Önce bir proje bağlamanız gerekir.</div>
            <?php elseif ($talep['gorev_id']): ?>
            <a href="gorev.php?id=<?= $talep['gorev_id'] ?>" class="btn btn-blok mt-2">Oluşturulan Görevi Aç →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="kart">
            <div class="dikey" style="gap:12px">
                <div class="satir-esnek arasi"><span class="hucre-alt">Dosya</span><span class="kucuk"><?= e($talep['dosya_ad'] ?? '—') ?></span></div>
                <div class="satir-esnek arasi"><span class="hucre-alt">Proje</span><span class="kucuk"><?= e($talep['proje_ad'] ?? '—') ?></span></div>
                <div class="satir-esnek arasi"><span class="hucre-alt">Atanan</span><span class="kucuk"><?= e($talep['atanan_ad'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>
</div>

<script>
async function talepDurum(durum) { const j = await api('talep_durum', {id:<?= $id ?>, durum}); if (j.ok) toast('Güncellendi', 'basari'); }
async function talepProje(pid) { if (!pid) return; const j = await api('talep_proje', {id:<?= $id ?>, proje_id:pid}); if (j.ok) { toast('Proje bağlandı', 'basari'); setTimeout(()=>location.reload(),600); } }
</script>
<?php sayfa_sonu(); ?>

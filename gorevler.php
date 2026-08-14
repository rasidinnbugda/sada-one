<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bilesenler.php';
$u = require_staff();

$projeFiltre = (int)($_GET['proje'] ?? 0);
$donemFiltre = (int)($_GET['donem'] ?? 0);
$filtre = $_GET['filtre'] ?? '';
$gorunum = in_array($_GET['gorunum'] ?? '', ['kanban', 'tablo']) ? $_GET['gorunum'] : ($u['gorev_gorunum'] ?: 'kanban');

$kosul = $filtre === 'arsiv' ? "g.arsivlendi=1" : "g.arsivlendi=0";
$params = [];
// Stajyer yalnızca kendine atanan görevleri görür
if (is_stajyer()) {
    $kosul .= " AND (g.atanan_id=? OR EXISTS(SELECT 1 FROM gorev_atananlar gas WHERE gas.gorev_id=g.id AND gas.user_id=?))";
    $params[] = $u['id']; $params[] = $u['id'];
}
if ($projeFiltre) { $kosul .= " AND g.proje_id=?"; $params[] = $projeFiltre; }
if ($donemFiltre) { $kosul .= " AND g.donem_id=?"; $params[] = $donemFiltre; }
if ($filtre === 'benim') { $kosul .= " AND (g.atanan_id=? OR EXISTS(SELECT 1 FROM gorev_atananlar gax WHERE gax.gorev_id=g.id AND gax.user_id=?))"; $params[] = $u['id']; $params[] = $u['id']; }
if ($filtre === 'geciken') { $kosul .= " AND g.son_tarih<CURDATE() AND g.durum!='tamamlandi'"; }

$gorevler = rows("SELECT g.*, p.ad proje_ad, d.renk dosya_renk, uu.ad atanan_ad, uu.renk atanan_renk, uu.avatar atanan_avatar,
    bg.durum bagimli_durum, bg.baslik bagimli_baslik,
    (SELECT COUNT(*) FROM gorev_kontrol k WHERE k.gorev_id=g.id) kontrol_toplam,
    (SELECT COUNT(*) FROM gorev_kontrol k WHERE k.gorev_id=g.id AND k.tamam=1) kontrol_tamam,
    (SELECT COUNT(*) FROM gorev_adimlari ga WHERE ga.gorev_id=g.id) adim_toplam,
    (SELECT COUNT(*) FROM gorev_adimlari ga WHERE ga.gorev_id=g.id AND ga.durum='tamam') adim_tamam,
    (SELECT COALESCE(SUM(z.dakika),0) FROM zaman_kayitlari z WHERE z.gorev_id=g.id) harcanan_dk,
    (SELECT COUNT(*) FROM gorev_atananlar gaa WHERE gaa.gorev_id=g.id) atanan_sayi,
    (SELECT GROUP_CONCAT(u3.ad SEPARATOR ', ') FROM gorev_atananlar ga3 JOIN users u3 ON u3.id=ga3.user_id WHERE ga3.gorev_id=g.id) atanan_adlar
    FROM gorevler g JOIN projeler p ON p.id=g.proje_id JOIN dosyalar d ON d.id=p.dosya_id
    LEFT JOIN users uu ON uu.id=g.atanan_id LEFT JOIN gorevler bg ON bg.id=g.bagimli_id
    WHERE $kosul ORDER BY g.sira, g.son_tarih IS NULL, g.son_tarih", $params);

$aktifProje = $projeFiltre ? row("SELECT ad FROM projeler WHERE id=?", [$projeFiltre]) : null;
$ekip = rows("SELECT id, ad, renk FROM users WHERE rol IN ('yonetici','pm','ekip') AND aktif=1 ORDER BY ad");
$sablonlar = rows("SELECT * FROM akis_sablonlari ORDER BY ad");

// Sorumlusu olduğum aktif akış adımları
$adimKosulSql = sadece_kendi_adimlarim()
    ? "ga.sorumlu_id=?"
    : "(ga.sorumlu_id=? OR (ga.sorumlu_id IS NULL AND (g.atanan_id=? OR EXISTS(SELECT 1 FROM gorev_atananlar gat WHERE gat.gorev_id=g.id AND gat.user_id=?))))";
$adimParam = sadece_kendi_adimlarim() ? [$u['id']] : [$u['id'], $u['id'], $u['id']];
$adimlarim = rows("SELECT ga.id adim_id, ga.ad adim_ad, ga.durum adim_durum, g.id gorev_id, g.baslik, p.ad proje_ad
    FROM gorev_adimlari ga JOIN gorevler g ON g.id=ga.gorev_id JOIN projeler p ON p.id=g.proje_id
    WHERE ga.durum IN ('aktif','bekliyor') AND g.arsivlendi=0 AND g.durum!='tamamlandi' AND $adimKosulSql
    ORDER BY ga.durum='aktif' DESC, g.son_tarih IS NULL, g.son_tarih LIMIT 12", $adimParam);
$adimlarim = array_filter($adimlarim, fn($a2) => $a2['adim_durum'] === 'aktif' || count($adimlarim) < 8);

sayfa_basi('Görevler', 'gorevler');
?>
<?php if ($adimlarim):
    $aktifAdimSayisi = count(array_filter($adimlarim, fn($a3) => $a3['adim_durum'] === 'aktif')); ?>
<div class="kart mb-3 katla kapali" data-katla="adimlarim" style="border-color:var(--marka)">
    <button class="kart-baslik" data-katla-btn type="button" style="display:flex;align-items:center;gap:9px;margin:0">
        <span class="katla-ok"><svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" width="14"><path d="M19 9l-7 7-7-7"/></svg></span>
        <?= ikon('roket', 16) ?> Adımlarım <span class="rozet r-devam" style="padding:1px 9px"><?= $aktifAdimSayisi ?> sıra sende</span><span class="hucre-alt">· <?= count($adimlarim) ?> adım</span>
    </button>
    <div class="dikey katla-icerik mt-2" style="gap:6px">
        <?php foreach ($adimlarim as $adm): ?>
        <div class="satir-esnek arasi" style="padding:9px 12px;background:var(--surface-2);border-radius:10px;gap:10px">
            <a href="gorev.php?id=<?= $adm['gorev_id'] ?>" class="kucuk" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><b><?= e($adm['adim_ad']) ?></b> · <?= e($adm['baslik']) ?> <span class="metin-muted">(<?= e($adm['proje_ad']) ?>)</span></a>
            <?php if ($adm['adim_durum'] === 'aktif'): ?><button class="btn btn-sm btn-marka" data-eylem="adim_tamamla" data-id="<?= $adm['adim_id'] ?>" style="flex-shrink:0">Tamamla</button><?php else: ?><span class="rozet" style="flex-shrink:0">Sırada</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<div class="sayfa-ust">
    <div>
        <div class="sayfa-baslik">Görevler<?= $aktifProje ? ' · ' . e($aktifProje['ad']) : '' ?></div>
        <div class="sayfa-alt"><?= count($gorevler) ?> görev — <?= $gorunum === 'tablo' ? 'hücrelere tıklayıp doğrudan düzenleyin' : 'panoda sürükleyerek durum değiştirin' ?></div>
    </div>
    <div class="sayfa-ust-aksiyon">
        <div class="gorunum-degistir">
            <button class="gorunum-btn <?= $gorunum === 'kanban' ? 'aktif' : '' ?>" onclick="gorunumSec('kanban')">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 5h4v14H4zM10 5h4v9h-4zM16 5h4v6h-4z"/></svg> Kanban
            </button>
            <button class="gorunum-btn <?= $gorunum === 'tablo' ? 'aktif' : '' ?>" onclick="gorunumSec('tablo')">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18M3 4h18v16H3z"/></svg> Tablo
            </button>
        </div>
        <?php if (!is_stajyer()): ?>
        <button class="btn btn-marka" data-modal="modalGorev"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Görev</button>
        <?php endif; ?>
    </div>
</div>

<div class="filtre-bar">
    <div class="pill-filtre">
        <a href="?<?= http_build_query(array_filter(['proje' => $projeFiltre, 'gorunum' => $gorunum])) ?>" class="pill <?= !$filtre ? 'aktif' : '' ?>">Tümü</a>
        <a href="?<?= http_build_query(array_filter(['filtre' => 'benim', 'proje' => $projeFiltre, 'gorunum' => $gorunum])) ?>" class="pill <?= $filtre === 'benim' ? 'aktif' : '' ?>">Bana Atanan</a>
        <a href="?<?= http_build_query(array_filter(['filtre' => 'geciken', 'proje' => $projeFiltre, 'gorunum' => $gorunum])) ?>" class="pill <?= $filtre === 'geciken' ? 'aktif' : '' ?>">Geciken</a>
        <a href="?<?= http_build_query(array_filter(['filtre' => 'arsiv', 'proje' => $projeFiltre, 'gorunum' => $gorunum])) ?>" class="pill <?= $filtre === 'arsiv' ? 'aktif' : '' ?>">Arşiv</a>
    </div>
    <?php if ($gorunum === 'tablo'): ?>
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="Görev ara..." data-arama="#gorevTablo tbody tr"></div>
    <?php endif; ?>
    <?php if ($projeFiltre): ?><a href="gorevler.php?gorunum=<?= $gorunum ?>" class="btn btn-sm btn-hayalet">Filtreyi Temizle ✕</a><?php endif; ?>
</div>

<?php if (!$gorevler): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg></div><div class="bos-baslik">Görev bulunamadı</div><div class="bos-metin">Bu filtreye uygun görev yok. Yeni bir görev oluşturabilirsiniz.</div></div>

<?php elseif ($gorunum === 'kanban'): ?>
<?php gorev_kanban($gorevler, $projeFiltre); ?>

<?php else: /* ---------- TABLO GÖRÜNÜMÜ ---------- */ ?>
<div class="tablo-sar">
<table class="tablo" id="gorevTablo">
    <thead><tr>
        <th class="siralanir">Görev <span class="sira-isaret">↕</span></th>
        <th class="siralanir">Proje <span class="sira-isaret">↕</span></th>
        <th>Atanan</th>
        <th>Durum</th>
        <th>Öncelik</th>
        <th class="siralanir">Başlangıç <span class="sira-isaret">↕</span></th>
        <th class="siralanir">Son Tarih <span class="sira-isaret">↕</span></th>
        <th class="siralanir">Tahmin/Gerçek <span class="sira-isaret">↕</span></th>
        <th class="siralanir">Akış <span class="sira-isaret">↕</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($gorevler as $gr):
        $kilitli = !empty($gr['bagimli_durum']) && $gr['bagimli_durum'] !== 'tamamlandi' && empty($gr['kilit_acik']);
        $akisOran = $gr['adim_toplam'] ? round($gr['adim_tamam'] / $gr['adim_toplam'] * 100) : null; ?>
    <tr data-ara="<?= e($gr['baslik'] . ' ' . $gr['proje_ad'] . ' ' . ($gr['etiketler'] ?? '')) ?>">
        <td style="min-width:220px">
            <a href="gorev.php?id=<?= $gr['id'] ?>" class="hucre-ana" style="display:block"><?= $kilitli ? ikon('kilit', 12) . ' ' : '' ?><?= $gr['tekrar'] !== 'yok' ? ikon('tekrar', 12) . ' ' : '' ?><?= e($gr['baslik']) ?></a>
            <div class="satir-esnek sarma mt-1" style="gap:4px"><?= etiket_cipleri($gr['etiketler']) ?><?php if ($gr['kontrol_toplam']): ?><span class="kanban-etiket"><?= ikon('onay', 12) ?> <?= $gr['kontrol_tamam'] ?>/<?= $gr['kontrol_toplam'] ?></span><?php endif; ?></div>
        </td>
        <td class="kucuk" data-sirala="<?= e($gr['proje_ad']) ?>"><span class="etiket-nokta" style="width:8px;height:8px;background:<?= e($gr['dosya_renk']) ?>;margin-right:5px"></span><?= e($gr['proje_ad']) ?></td>
        <td class="hucre-duzen">
            <select class="secim" data-eski="<?= $gr['atanan_id'] ?>" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'atanan_id')">
                <option value="">—</option>
                <?php foreach ($ekip as $k): ?><option value="<?= $k['id'] ?>" <?= $k['id'] == $gr['atanan_id'] ? 'selected' : '' ?>><?= e($k['ad']) ?></option><?php endforeach; ?>
            </select>
        </td>
        <td class="hucre-duzen">
            <select class="secim" data-eski="<?= $gr['durum'] ?>" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'durum')">
                <?php foreach (GOREV_DURUMLARI as $k => $v): ?><option value="<?= $k ?>" <?= $gr['durum'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </td>
        <td class="hucre-duzen">
            <select class="secim" data-eski="<?= $gr['oncelik'] ?>" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'oncelik')">
                <?php foreach (ONCELIKLER as $k => $v): ?><option value="<?= $k ?>" <?= $gr['oncelik'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </td>
        <td class="hucre-duzen" data-sirala="<?= e($gr['baslangic_tarihi'] ?? '9999') ?>">
            <input type="date" class="girdi" value="<?= e($gr['baslangic_tarihi']) ?>" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'baslangic_tarihi')">
        </td>
        <td class="hucre-duzen" data-sirala="<?= e($gr['son_tarih'] ?? '9999') ?>">
            <input type="date" class="girdi" value="<?= e($gr['son_tarih']) ?>" style="<?= $gr['son_tarih'] && $gr['son_tarih'] < date('Y-m-d') && $gr['durum'] !== 'tamamlandi' ? 'color:var(--tehlike)' : '' ?>" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'son_tarih')">
        </td>
        <td class="kucuk" data-sirala="<?= $gr['tahmini_dakika'] ?>">
            <span class="hucre-duzen"><input class="girdi" style="width:56px" value="<?= $gr['tahmini_dakika'] ? round($gr['tahmini_dakika'] / 60, 1) : '' ?>" placeholder="sa" onchange="hucreKaydet(this, <?= $gr['id'] ?>, 'tahmini_dakika')"></span>
            <span class="metin-muted">/ <?= $gr['harcanan_dk'] ? dakika_format((int)$gr['harcanan_dk']) : '—' ?></span>
        </td>
        <td data-sirala="<?= $akisOran ?? -1 ?>">
            <?php if ($akisOran !== null): ?>
            <div class="satir-esnek" style="gap:8px"><div class="ilerleme" style="width:56px"><div class="ilerleme-dolu" style="width:<?= $akisOran ?>%"></div></div><span class="kucuk"><?= $gr['adim_tamam'] ?>/<?= $gr['adim_toplam'] ?></span></div>
            <?php else: ?><span class="metin-muted kucuk">—</span><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="form-ipucu mt-2">💡 Hücrelere tıklayarak doğrudan düzenleyin; sütun başlıklarına tıklayarak sıralayın. Kilitli görevlerde durum değişikliği kurallara takılırsa eski değere döner.</div>
<?php endif; ?>

<?php gorev_modali($projeFiltre, $ekip, $sablonlar); ?>
<script>
// Canlı senkron: başka biri görev eklerse/taşırsa liste tazelenir
window.sadaCanli = { baglam: 'liste', hash: '<?= canli_hash_liste() ?>' };
async function gorunumSec(g) {
    await api('gorunum_tercih', { gorunum: g });
    const url = new URL(location.href);
    url.searchParams.set('gorunum', g);
    location.href = url.toString();
}
</script>
<?php sayfa_sonu(); ?>

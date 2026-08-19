<?php
/**
 * SADA One — Yönetici Takip Sistemi
 * Görev · sahibi · durum · dosya + her yönetici/PM için ayrı not kolonu
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();
if (!is_admin() && $u['rol'] !== 'pm') { header('Location: index.php'); exit; }

$yoneticiler = rows("SELECT id, ad FROM users WHERE rol IN ('yonetici','pm') AND aktif=1 ORDER BY ad");
$gorevler = rows("SELECT g.id, g.baslik, g.durum, g.son_tarih, p.ad proje_ad, d.ad dosya_ad,
    uu.ad atanan_ad,
    (SELECT GROUP_CONCAT(u3.ad SEPARATOR ', ') FROM gorev_atananlar ga JOIN users u3 ON u3.id=ga.user_id WHERE ga.gorev_id=g.id) atananlar
    FROM gorevler g JOIN projeler p ON p.id=g.proje_id JOIN dosyalar d ON d.id=p.dosya_id
    LEFT JOIN users uu ON uu.id=g.atanan_id
    WHERE g.arsivlendi=0 ORDER BY g.durum='tamamlandi', g.son_tarih IS NULL, g.son_tarih");

// Tüm notları tek sorguda çek: [gorev_id][user_id] => not
$notlar = [];
foreach (rows("SELECT * FROM gorev_yonetici_notlari") as $n) $notlar[$n['gorev_id']][$n['user_id']] = $n['notu'];

sayfa_basi('Yönetici Takip', 'ytakip');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Yönetici Takip Sistemi</div><div class="sayfa-alt">Tüm görevler tek tabloda — her yönetici kendi not kolonunu doldurur</div></div>
</div>

<div class="filtre-bar">
    <div class="pill-filtre" data-pill-grup="#ytTablo tbody tr">
        <button class="pill aktif" data-deger="">Tümü</button>
        <?php foreach (GOREV_DURUMLARI as $dk => $dv): ?><button class="pill" data-deger="<?= $dk ?>"><?= $dv ?></button><?php endforeach; ?>
    </div>
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="Görev ara..." data-arama="#ytTablo tbody tr"></div>
</div>

<div class="tablo-sar"><table class="tablo" id="ytTablo">
    <thead><tr>
        <th>Görev</th><th>Sahibi</th><th>Durum</th><th>Dosya</th>
        <?php foreach ($yoneticiler as $y): ?><th><?= e(explode(' ', $y['ad'])[0]) ?> Not</th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($gorevler as $gr): ?>
    <tr data-filtre="<?= $gr['durum'] ?>">
        <td><a href="gorev.php?id=<?= $gr['id'] ?>" class="hucre-ana"><?= e($gr['baslik']) ?></a><div class="hucre-alt"><?= e($gr['proje_ad']) ?><?= $gr['son_tarih'] ? ' · ' . tarih($gr['son_tarih']) : '' ?></div></td>
        <td class="kucuk"><?= e($gr['atananlar'] ?: $gr['atanan_ad'] ?: '—') ?></td>
        <td><?= rozet($gr['durum'], GOREV_DURUMLARI) ?></td>
        <td class="kucuk"><?= e($gr['dosya_ad']) ?></td>
        <?php foreach ($yoneticiler as $y):
            $notMetin = $notlar[$gr['id']][$y['id']] ?? '';
            $benim = $y['id'] == $u['id']; ?>
        <td style="max-width:200px;min-width:140px">
            <?php if ($benim): ?>
            <div class="yt-not <?= $notMetin ? '' : 'bos' ?>" data-gorev="<?= $gr['id'] ?>" tabindex="0" title="Tıklayıp not yazın"><?= $notMetin ? e($notMetin) : '+ not ekle' ?></div>
            <?php else: ?>
            <div class="kucuk metin-2" style="white-space:pre-wrap"><?= $notMetin ? e($notMetin) : '<span class="metin-muted">—</span>' ?></div>
            <?php endif; ?>
        </td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<style>
.yt-not { font-size: 12.5px; padding: 7px 9px; border-radius: 8px; border: 1px dashed var(--border-2); cursor: text; white-space: pre-wrap; transition: border-color var(--gecis); }
.yt-not.bos { color: var(--muted); }
.yt-not:hover, .yt-not:focus { border-color: var(--marka); outline: none; }
</style>
<script>
document.querySelectorAll('.yt-not').forEach(kutu => {
    kutu.addEventListener('click', () => {
        if (kutu.querySelector('textarea')) return;
        const mevcut = kutu.classList.contains('bos') ? '' : kutu.textContent;
        kutu.innerHTML = '';
        const ta = document.createElement('textarea');
        ta.className = 'metin-alani'; ta.style.minHeight = '70px'; ta.style.fontSize = '12.5px';
        ta.value = mevcut;
        kutu.appendChild(ta); ta.focus();
        const kaydet = async () => {
            const j = await api('ynot_kaydet', { gorev_id: kutu.dataset.gorev, notu: ta.value.trim() });
            if (j.ok) {
                kutu.classList.toggle('bos', !ta.value.trim());
                kutu.textContent = ta.value.trim() || '+ not ekle';
                toast(j.mesaj, 'basari');
            }
        };
        ta.addEventListener('blur', kaydet);
        ta.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) ta.blur(); });
    });
});
</script>
<?php sayfa_sonu(); ?>

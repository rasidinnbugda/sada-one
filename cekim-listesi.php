<?php
/**
 * SADA One — Çekim Listesi
 * Proje adı · tarih · çekime gidecek kişiler · ekipmanlar · alınacaklar · ihtiyaç listesi
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$gecmisGoster = isset($_GET['gecmis']);
$kosul = $gecmisGoster ? "1=1" : "(e.bitis IS NULL AND e.baslangic >= CURDATE() - INTERVAL 1 DAY) OR e.bitis >= NOW() - INTERVAL 1 DAY";
$cekimler = rows("SELECT e.*, p.ad proje_ad, d.ad dosya_ad,
    (SELECT GROUP_CONCAT(u2.ad SEPARATOR ', ') FROM etkinlik_katilimcilari ek JOIN users u2 ON u2.id=ek.user_id WHERE ek.etkinlik_id=e.id) kisiler,
    (SELECT GROUP_CONCAT(eq.ad SEPARATOR ', ') FROM etkinlik_ekipmanlari ee JOIN ekipmanlar eq ON eq.id=ee.ekipman_id WHERE ee.etkinlik_id=e.id) ekipman_adlari
    FROM etkinlikler e LEFT JOIN projeler p ON p.id=e.proje_id LEFT JOIN dosyalar d ON d.id=p.dosya_id
    WHERE e.tur='cekim' AND ($kosul) ORDER BY e.baslangic");

sayfa_basi('Çekim Listesi', 'cekimler');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Çekim Listesi</div><div class="sayfa-alt"><?= $gecmisGoster ? 'Tüm çekimler' : 'Yaklaşan çekimler' ?> — kim gidiyor, hangi ekipman, ne alınacak</div></div>
    <div class="sayfa-ust-aksiyon">
        <a href="<?= $gecmisGoster ? 'cekim-listesi.php' : '?gecmis=1' ?>" class="btn"><?= $gecmisGoster ? 'Yaklaşanlar' : 'Geçmişi de Göster' ?></a>
        <a href="takvim.php" class="btn btn-marka"><?= ikon('takvim', 15) ?> Prodüksiyon Takvimi</a>
    </div>
</div>

<?php if (!$cekimler): ?>
<div class="bos-durum">
    <div class="bos-ikon"><?= ikon('kamera', 36) ?></div>
    <div class="bos-baslik">Yaklaşan çekim yok</div>
    <div class="bos-metin">Prodüksiyon takviminden "çekim" türünde etkinlik oluşturduğunuzda burada listelenir.</div>
</div>
<?php else: ?>
<div class="dikey" style="gap:14px">
    <?php foreach ($cekimler as $c): ?>
    <div class="kart">
        <div class="satir-esnek arasi sarma mb-2" style="gap:10px">
            <div>
                <div class="kart-baslik" style="font-size:16px"><?= e($c['baslik']) ?></div>
                <div class="hucre-alt mt-1"><?= $c['dosya_ad'] ? e($c['dosya_ad']) . ($c['proje_ad'] ? ' / ' . e($c['proje_ad']) : '') : e($c['proje_ad'] ?? '') ?></div>
            </div>
            <div class="satir-esnek" style="gap:8px">
                <span class="rozet rozet-tur"><?= ikon('takvim', 12) ?> <?= tarih($c['baslangic'], true) ?><?= $c['bitis'] ? ' → ' . tarih($c['bitis'], true) : '' ?></span>
                <?php if (yetki('takvim_yonet')): ?>
                <button class="btn btn-sm" onclick='ckDuzenle(<?= json_encode(['id' => $c['id'], 'alinacaklar' => $c['alinacaklar'], 'ihtiyac_listesi' => $c['ihtiyac_listesi']], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= ikon('kalem', 13) ?> Listeyi Düzenle</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="izgara" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="hucre-alt mb-1"><?= ikon('ekip', 13) ?> Çekime Gidecekler</div>
                <div class="kucuk"><?= $c['kisiler'] ? e($c['kisiler']) : '<span class="metin-muted">Katılımcı atanmadı</span>' ?></div>
            </div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="hucre-alt mb-1"><?= ikon('kamera', 13) ?> Ekipmanlar</div>
                <div class="kucuk"><?= $c['ekipman_adlari'] ? e($c['ekipman_adlari']) : '<span class="metin-muted">Ekipman bağlanmadı</span>' ?></div>
            </div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="hucre-alt mb-1">🛒 Alınacaklar</div>
                <div class="kucuk" style="white-space:pre-wrap"><?= $c['alinacaklar'] ? e($c['alinacaklar']) : '<span class="metin-muted">—</span>' ?></div>
            </div>
            <div style="padding:11px 13px;background:var(--surface-2);border-radius:11px">
                <div class="hucre-alt mb-1">📋 İhtiyaç Listesi</div>
                <div class="kucuk" style="white-space:pre-wrap"><?= $c['ihtiyac_listesi'] ? e($c['ihtiyac_listesi']) : '<span class="metin-muted">—</span>' ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-katman" id="modalCekimListe">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Çekim Listesini Düzenle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="cekim_liste_kaydet">
        <input type="hidden" name="id" id="ck_id">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Alınacaklar</label><textarea name="alinacaklar" id="ck_alinacaklar" class="metin-alani" rows="4" placeholder="- Yedek pil&#10;- Gaffer bandı&#10;- Su ve atıştırmalık"></textarea></div>
            <div class="form-grup"><label class="form-etiket">İhtiyaç Listesi</label><textarea name="ihtiyac_listesi" id="ck_ihtiyac" class="metin-alani" rows="4" placeholder="- Mekan izni teyidi&#10;- Prompter metni&#10;- Ek ışık kiralama"></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
function ckDuzenle(c) {
    document.getElementById('ck_id').value = c.id;
    document.getElementById('ck_alinacaklar').value = c.alinacaklar || '';
    document.getElementById('ck_ihtiyac').value = c.ihtiyac_listesi || '';
    modalAc('modalCekimListe');
}
</script>
<?php sayfa_sonu(); ?>

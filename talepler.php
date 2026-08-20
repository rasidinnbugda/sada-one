<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$formlar = rows("SELECT * FROM form_sablonlari WHERE aktif=1 ORDER BY ad");

if (is_staff()) {
    $talepler = rows("SELECT t.*, f.ad form_ad, ug.ad gonderen_ad, d.ad dosya_ad, p.ad proje_ad FROM talepler t JOIN form_sablonlari f ON f.id=t.sablon_id LEFT JOIN users ug ON ug.id=t.gonderen_id LEFT JOIN dosyalar d ON d.id=t.dosya_id LEFT JOIN projeler p ON p.id=t.proje_id ORDER BY FIELD(t.durum,'yeni','inceleniyor','gorev_olusturuldu','tamamlandi','reddedildi'), t.id DESC");
} else {
    $talepler = rows("SELECT t.*, f.ad form_ad, ug.ad gonderen_ad, p.ad proje_ad FROM talepler t JOIN form_sablonlari f ON f.id=t.sablon_id LEFT JOIN users ug ON ug.id=t.gonderen_id LEFT JOIN projeler p ON p.id=t.proje_id WHERE t.gonderen_id=? ORDER BY t.id DESC", [$u['id']]);
}

// Müşteri için proje listesi (talep formunda seçmek üzere)
$musteriProjeler = [];
if (is_musteri()) {
    [$mdIn, $mdP] = in_sorgu(musteri_dosya_idler());
    $musteriProjeler = rows("SELECT id, ad FROM projeler WHERE dosya_id IN $mdIn AND durum='aktif' ORDER BY ad", $mdP);
}
$tumDosyalar = is_staff() ? rows("SELECT id, ad FROM dosyalar WHERE durum='aktif' ORDER BY ad") : [];

sayfa_basi('Talepler', 'talepler');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik"><?= is_staff() ? 'Gelen Talepler' : 'Taleplerim' ?></div><div class="sayfa-alt"><?= is_staff() ? 'Müşteri ve ekip talepleri' : 'Oluşturduğunuz talepler ve durumları' ?></div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalYeniTalep"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Talep</button></div>
</div>

<?php if (is_staff()): ?>
<div class="filtre-bar">
    <div class="pill-filtre" data-pill-grup="#talepListe .talep-sat">
        <button class="pill aktif" data-deger="">Tümü</button>
        <?php foreach (TALEP_DURUMLARI as $k => $v): ?><button class="pill" data-deger="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$talepler): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 10h8m-8 4h4m9-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="bos-baslik">Talep yok</div><div class="bos-metin"><?= is_staff() ? 'Henüz gelen bir talep bulunmuyor.' : 'Bir iş, revizyon veya çekim talebi oluşturmak için başlayın.' ?></div><button class="btn btn-marka" data-modal="modalYeniTalep">Yeni Talep Oluştur</button></div>
<?php else: ?>
<div class="tablo-sar"><table class="tablo" id="talepListe"><thead><tr><th>Talep</th><?php if (is_staff()): ?><th>Gönderen</th><th>Dosya/Proje</th><?php endif; ?><th>Tarih</th><th>Durum</th><th></th></tr></thead><tbody>
    <?php foreach ($talepler as $t): ?>
    <tr class="tik talep-sat" data-filtre="<?= $t['durum'] ?>" onclick="location.href='talep.php?id=<?= $t['id'] ?>'">
        <td><div class="hucre-ana"><?= e($t['baslik']) ?></div><div class="hucre-alt"><?= e($t['form_ad']) ?></div></td>
        <?php if (is_staff()): ?>
        <td><?= e($t['gonderen_ad']) ?></td>
        <td class="kucuk"><?= e($t['dosya_ad'] ?? '—') ?><?= $t['proje_ad'] ? ' / ' . e($t['proje_ad']) : '' ?></td>
        <?php endif; ?>
        <td class="kucuk"><?= tarih($t['created']) ?></td>
        <td><?= rozet($t['durum'], TALEP_DURUMLARI) ?></td>
        <td><svg width="16" fill="none" stroke="var(--muted)" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></td>
    </tr>
    <?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<!-- Yeni talep modalı: form tipi seç → alanları doldur -->
<div class="modal-katman" id="modalYeniTalep">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Talep</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <div class="modal-govde">
        <div id="talepAdim1">
            <div class="hucre-alt mb-3">Ne tür bir talep oluşturmak istiyorsunuz?</div>
            <div class="izgara izgara-2">
                <?php foreach ($formlar as $f): ?>
                <button class="kart kart-tik" style="text-align:left;padding:16px" onclick="talepFormAc(<?= $f['id'] ?>)">
                    <div class="kalin"><?= e($f['ad']) ?></div>
                    <?php if ($f['aciklama']): ?><div class="hucre-alt mt-1"><?= e($f['aciklama']) ?></div><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div id="talepAdim2" style="display:none">
            <button class="btn btn-sm btn-hayalet mb-3" onclick="talepGeri()">← Geri</button>
            <form data-ajax="talep_gonder" id="talepForm">
                <input type="hidden" name="sablon_id" id="talepSablonId">
                <?php if ($musteriProjeler): ?>
                <div class="form-grup"><label class="form-etiket">İlgili Proje</label><select name="proje_id" class="secim"><option value="">— Genel</option><?php foreach ($musteriProjeler as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['ad']) ?></option><?php endforeach; ?></select></div>
                <?php elseif ($tumDosyalar): ?>
                <div class="form-grup"><label class="form-etiket">Dosya</label><select name="dosya_id" class="secim"><option value="">—</option><?php foreach ($tumDosyalar as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['ad']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div id="talepAlanlar"></div>
                <button type="submit" class="btn btn-marka btn-blok mt-2">Talebi Gönder</button>
            </form>
        </div>
    </div>
    </div>
</div>

<script>
const formAlanlari = <?= json_encode(array_reduce($formlar, function ($acc, $f) {
    $acc[$f['id']] = ['ad' => $f['ad'], 'alanlar' => rows("SELECT * FROM form_alanlari WHERE sablon_id=? ORDER BY sira", [$f['id']])];
    return $acc;
}, []), JSON_UNESCAPED_UNICODE) ?>;

function talepFormAc(id) {
    const f = formAlanlari[id]; if (!f) return;
    document.getElementById('talepSablonId').value = id;
    let h = '';
    f.alanlar.forEach(a => {
        const zorunlu = a.zorunlu == 1 ? ' required' : '';
        const yildiz = a.zorunlu == 1 ? ' <span class="zorunlu">*</span>' : '';
        h += `<div class="form-grup"><label class="form-etiket">${esc(a.etiket)}${yildiz}</label>`;
        if (a.tip === 'uzun_metin') h += `<textarea name="alan_${a.id}" class="metin-alani"${zorunlu}></textarea>`;
        else if (a.tip === 'secim') { h += `<select name="alan_${a.id}" class="secim"${zorunlu}>`; (a.secenekler||'').split('\n').forEach(s => { if(s.trim()) h += `<option>${s.trim()}</option>`; }); h += `</select>`; }
        else if (a.tip === 'tarih') h += `<input type="date" name="alan_${a.id}" class="girdi"${zorunlu}>`;
        else if (a.tip === 'sayi') h += `<input type="number" name="alan_${a.id}" class="girdi"${zorunlu}>`;
        else if (a.tip === 'dosya') h += `<input type="file" name="alan_${a.id}" class="girdi"${zorunlu}>`;
        else h += `<input type="text" name="alan_${a.id}" class="girdi"${zorunlu}>`;
        h += `</div>`;
    });
    document.getElementById('talepAlanlar').innerHTML = h;
    if (window.ozelSeciciYenile) ozelSeciciYenile();
    document.getElementById('talepAdim1').style.display = 'none';
    document.getElementById('talepAdim2').style.display = 'block';
}
function talepGeri() { document.getElementById('talepAdim2').style.display = 'none'; document.getElementById('talepAdim1').style.display = 'block'; }
</script>
<?php sayfa_sonu(); ?>

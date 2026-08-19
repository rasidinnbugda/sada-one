<?php
/**
 * SADA One — Ekip İçi Gelişim ve Mentörlük Takip Programı
 * Üye · gelişim istenen alan · atanan mentör · uygulama sahası/proje · çıktı & değerlendirme
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$yonetir = is_admin() || $u['rol'] === 'pm';
$kayitlar = rows("SELECT m.*, uu.ad uye_ad, uu.renk uye_renk, uu.avatar uye_avatar, mm.ad mentor_ad, p.ad proje_ad
    FROM mentorluk m JOIN users uu ON uu.id=m.uye_id LEFT JOIN users mm ON mm.id=m.mentor_id LEFT JOIN projeler p ON p.id=m.proje_id
    ORDER BY FIELD(m.durum,'devam','planlandi','tamamlandi'), m.created DESC");
$ekip = rows("SELECT id, ad FROM users WHERE rol IN ('yonetici','pm','ekip','stajyer') AND aktif=1 ORDER BY ad");
$projeler = rows("SELECT id, ad FROM projeler WHERE durum='aktif' ORDER BY ad");

$MDURUM = ['planlandi' => 'Planlandı', 'devam' => 'Devam Ediyor', 'tamamlandi' => 'Tamamlandı'];
$mRozet = fn($d) => '<span class="rozet ' . ['planlandi' => 'r-bekliyor', 'devam' => 'r-devam', 'tamamlandi' => 'r-tamamlandi'][$d] . '">' . $MDURUM[$d] . '</span>';

sayfa_basi('Gelişim & Mentörlük', 'gelisim');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Gelişim & Mentörlük</div><div class="sayfa-alt">Ekip içi yetkinlik gelişimi ve mentörlük eşleşmeleri</div></div>
    <?php if ($yonetir): ?><div class="sayfa-ust-aksiyon"><button class="btn btn-marka" onclick="mYeni()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Eşleşme</button></div><?php endif; ?>
</div>

<div class="stat-izgara">
    <div class="stat-kart"><div class="stat-etiket">Aktif Gelişim Süreci</div><div class="stat-deger"><?= count(array_filter($kayitlar, fn($k) => $k['durum'] === 'devam')) ?></div></div>
    <div class="stat-kart"><div class="stat-etiket">Planlanan</div><div class="stat-deger"><?= count(array_filter($kayitlar, fn($k) => $k['durum'] === 'planlandi')) ?></div></div>
    <div class="stat-kart"><div class="stat-etiket">Tamamlanan</div><div class="stat-deger"><?= count(array_filter($kayitlar, fn($k) => $k['durum'] === 'tamamlandi')) ?></div></div>
    <div class="stat-kart"><div class="stat-etiket">Gelişimdeki Kişi</div><div class="stat-deger"><?= count(array_unique(array_column($kayitlar, 'uye_id'))) ?></div></div>
</div>

<?php if (!$kayitlar): ?>
<div class="bos-durum">
    <div class="bos-ikon"><?= ikon('roket', 36) ?></div>
    <div class="bos-baslik">Henüz mentörlük kaydı yok</div>
    <div class="bos-metin">Örn: "İmran → video edit gelişimi, mentör Ömer, uygulama sahası: 1 Ağustos podcast çekimi"</div>
    <?php if ($yonetir): ?><button class="btn btn-marka" onclick="mYeni()">İlk Eşleşmeyi Oluştur</button><?php endif; ?>
</div>
<?php else: ?>
<div class="izgara izgara-2">
    <?php foreach ($kayitlar as $k): ?>
    <div class="kart">
        <div class="satir-esnek arasi mb-2">
            <div class="satir-esnek" style="gap:10px">
                <?= avatar(['ad' => $k['uye_ad'], 'renk' => $k['uye_renk'], 'avatar' => $k['uye_avatar']], 38) ?>
                <div><div class="kalin"><?= e($k['uye_ad']) ?></div><div class="hucre-alt"><?= e($k['alan']) ?></div></div>
            </div>
            <div class="satir-esnek" style="gap:6px">
                <?= $mRozet($k['durum']) ?>
                <?php if ($yonetir): ?>
                <button class="ikon-eylem" onclick='mDuzenle(<?= json_encode($k, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= ikon('kalem', 15) ?></button>
                <button class="ikon-eylem tehlike" data-eylem="mentorluk_sil" data-id="<?= $k['id'] ?>" data-onay="Mentörlük kaydı silinsin mi?"><?= ikon('cop', 15) ?></button>
                <?php endif; ?>
            </div>
        </div>
        <div class="dikey kucuk" style="gap:7px">
            <div class="satir-esnek arasi"><span class="metin-muted">Mentör</span><span class="kalin"><?= $k['mentor_ad'] ? e($k['mentor_ad']) : '— belirlenmedi' ?></span></div>
            <div class="satir-esnek arasi"><span class="metin-muted">Uygulama Sahası</span><span><?= $k['proje_ad'] ? e($k['proje_ad']) : e($k['saha'] ?: '—') ?></span></div>
        </div>
        <div class="mt-2" style="padding:10px 12px;background:var(--surface-2);border-radius:10px">
            <div class="hucre-alt mb-1">Çıktı & Değerlendirme Notu <?php if ($k['uye_id'] == $u['id'] || $yonetir): ?><button class="mini-btn" onclick="mCikti(<?= $k['id'] ?>, this)">Düzenle</button><?php endif; ?></div>
            <div class="kucuk metin-2 m-cikti" style="white-space:pre-wrap"><?= $k['cikti'] ? e($k['cikti']) : '<span class="metin-muted">Henüz not girilmedi.</span>' ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($yonetir): ?>
<div class="modal-katman" id="modalMentor">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="mBaslik">Yeni Mentörlük Eşleşmesi</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="mentorluk_kaydet">
        <input type="hidden" name="id" id="m_id">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Ekip Üyesi <span class="zorunlu">*</span></label>
                    <select name="uye_id" id="m_uye" class="secim" required><option value="">Seçin...</option><?php foreach ($ekip as $e2): ?><option value="<?= $e2['id'] ?>"><?= e($e2['ad']) ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Atanan Mentör</label>
                    <select name="mentor_id" id="m_mentor" class="secim"><option value="">— Belirlenmedi</option><?php foreach ($ekip as $e2): ?><option value="<?= $e2['id'] ?>"><?= e($e2['ad']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Gelişim İstenen Alan <span class="zorunlu">*</span></label><input name="alan" id="m_alan" class="girdi" required placeholder="Örn. video edit ve içerik üretimi, çekim"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Uygulama Projesi</label>
                    <select name="proje_id" id="m_proje" class="secim"><option value="">—</option><?php foreach ($projeler as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['ad']) ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">veya Serbest Saha</label><input name="saha" id="m_saha" class="girdi" placeholder="Örn. 1 Ağustos podcast tek başına kurulum"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Durum</label>
                <select name="durum" id="m_durum" class="secim"><?php foreach ($MDURUM as $dk => $dv): ?><option value="<?= $dk ?>"><?= $dv ?></option><?php endforeach; ?></select></div>
            <div class="form-grup"><label class="form-etiket">Çıktı & Değerlendirme Notu</label><textarea name="cikti" id="m_cikti" class="metin-alani" placeholder="Süreç sonunda gözlemler, değerlendirme..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
function mYeni() {
    const f = document.querySelector('#modalMentor form'); if (!f) return;
    f.reset(); document.getElementById('m_id').value = '';
    document.getElementById('mBaslik').textContent = 'Yeni Mentörlük Eşleşmesi';
    if (window.ozelSeciciYenile) ozelSeciciYenile();
    modalAc('modalMentor');
}
function mDuzenle(k) {
    document.getElementById('m_id').value = k.id;
    document.getElementById('m_uye').value = k.uye_id;
    document.getElementById('m_mentor').value = k.mentor_id || '';
    document.getElementById('m_alan').value = k.alan;
    document.getElementById('m_proje').value = k.proje_id || '';
    document.getElementById('m_saha').value = k.saha || '';
    document.getElementById('m_durum').value = k.durum;
    document.getElementById('m_cikti').value = k.cikti || '';
    document.getElementById('mBaslik').textContent = 'Eşleşmeyi Düzenle';
    ['m_uye', 'm_mentor', 'm_proje', 'm_durum'].forEach(id => document.getElementById(id).dispatchEvent(new Event('change')));
    modalAc('modalMentor');
}
async function mCikti(id, btn) {
    const kutu = btn.closest('div').nextElementSibling;
    const mevcut = kutu.querySelector('.metin-muted') ? '' : kutu.textContent.trim();
    const yeni = prompt('Çıktı & değerlendirme notu:', mevcut);
    if (yeni === null) return;
    const j = await api('mentorluk_cikti', { id, cikti: yeni });
    if (j.ok) { kutu.textContent = yeni || 'Henüz not girilmedi.'; toast(j.mesaj, 'basari'); }
}
</script>
<?php sayfa_sonu(); ?>

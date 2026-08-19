<?php
/**
 * SADA One — Stüdyo Ekipman Envanteri
 * Demirbaş takibi, zimmet, çekim bağlantısı ve SD kart yaşam döngüsü.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_staff();

$ekipmanlar = rows("SELECT e.*, us.ad zimmet_ad, us.renk zimmet_renk, us.avatar zimmet_avatar, et.baslik etkinlik_baslik, et.baslangic etkinlik_tarih
    FROM ekipmanlar e
    LEFT JOIN users us ON us.id=e.zimmet_user_id
    LEFT JOIN etkinlikler et ON et.id=e.zimmet_etkinlik_id
    ORDER BY FIELD(e.kategori,'kamera','lens','sd_kart','tripod','isik','ses','drone','aksesuar','diger'), e.kod, e.ad");

$sayilar = ['studyoda' => 0, 'zimmette' => 0, 'cekimde' => 0, 'arizali' => 0, 'bakimda' => 0];
$toplamDeger = 0;
foreach ($ekipmanlar as $ek) { $sayilar[$ek['durum']]++; $toplamDeger += (float)$ek['fiyat']; }

$ekip = rows("SELECT id, ad FROM users WHERE rol IN ('yonetici','pm','ekip','finans') AND aktif=1 ORDER BY ad");
$yonetebilir = yetki('ekipman_yonet');

sayfa_basi('Ekipman', 'ekipman');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Stüdyo Ekipmanları</div><div class="sayfa-alt"><?= count($ekipmanlar) ?> demirbaş — zimmet, çekim ve SD kart takibi</div></div>
    <?php if ($yonetebilir): ?>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalEkipman" onclick="ekipmanSifirla()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Ekipman Ekle</button></div>
    <?php endif; ?>
</div>

<div class="stat-izgara">
    <div class="stat-kart"><div class="stat-deger" style="color:var(--basari)" data-sayac="<?= $sayilar['studyoda'] ?>">0</div><div class="stat-etiket">Stüdyoda</div></div>
    <div class="stat-kart"><div class="stat-deger" style="color:var(--bilgi)" data-sayac="<?= $sayilar['zimmette'] ?>">0</div><div class="stat-etiket">Zimmette</div></div>
    <div class="stat-kart"><div class="stat-deger" style="color:var(--uyari)" data-sayac="<?= $sayilar['cekimde'] ?>">0</div><div class="stat-etiket">Çekimde</div></div>
    <div class="stat-kart"><div class="stat-deger" style="color:var(--tehlike)" data-sayac="<?= $sayilar['arizali'] + $sayilar['bakimda'] ?>">0</div><div class="stat-etiket">Arızalı / Bakımda</div></div>
    <?php if (yetki('finans') && $toplamDeger > 0): ?>
    <div class="stat-kart"><div class="stat-deger" style="font-size:20px"><?= para($toplamDeger) ?></div><div class="stat-etiket">Toplam Demirbaş Değeri</div></div>
    <?php endif; ?>
</div>

<div class="filtre-bar">
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="Ekipman ara..." data-arama="#ekipmanListe .ekipman-kart"></div>
    <div class="pill-filtre" data-pill-grup="#ekipmanListe .ekipman-kart">
        <button class="pill aktif" data-deger="">Tümü</button>
        <?php foreach (EKIPMAN_KATEGORILERI as $k => $v): ?><button class="pill" data-deger="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<?php if (!$ekipmanlar): ?>
<div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M15 10l4.55-2.27A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.89L15 14v-4zM3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/></svg></div><div class="bos-baslik">Envanter boş</div><div class="bos-metin">Kamera, SD kart, tripod gibi demirbaşları ekleyerek stüdyo takibini başlatın.</div><?php if ($yonetebilir): ?><button class="btn btn-marka" data-modal="modalEkipman" onclick="ekipmanSifirla()">İlk Ekipmanı Ekle</button><?php endif; ?></div>
<?php else: ?>
<div class="izgara izgara-auto" id="ekipmanListe">
    <?php foreach ($ekipmanlar as $ek):
        $durumRenk = ['studyoda' => 'var(--basari)', 'zimmette' => 'var(--bilgi)', 'cekimde' => 'var(--uyari)', 'arizali' => 'var(--tehlike)', 'bakimda' => 'var(--tehlike)'][$ek['durum']];
        $sdKart = $ek['kategori'] === 'sd_kart'; ?>
    <div class="kart ekipman-kart" data-filtre="<?= $ek['kategori'] ?>" data-ara="<?= e(($ek['kod'] ?? '') . ' ' . $ek['ad'] . ' ' . ($ek['sd_icerik'] ?? '') . ' ' . ($ek['zimmet_ad'] ?? '')) ?>" style="padding:16px">
        <div class="satir-esnek arasi" style="align-items:flex-start;gap:10px">
            <div class="satir-esnek" style="gap:11px;min-width:0">
                <?php if ($ek['foto']): ?>
                <span style="width:46px;height:46px;border-radius:11px;background:url('uploads/<?= e($ek['foto']) ?>') center/cover;flex-shrink:0"></span>
                <?php else: ?>
                <span class="dosya-avatar" style="width:46px;height:46px;background:var(--parlak);color:var(--marka)"><?= ikon($ek['kategori'], 22) ?></span>
                <?php endif; ?>
                <div style="min-width:0">
                    <div class="kalin" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $ek['kod'] ? '<span style="color:var(--marka)">' . e($ek['kod']) . '</span> · ' : '' ?><?= e($ek['ad']) ?></div>
                    <div class="hucre-alt"><?= EKIPMAN_KATEGORILERI[$ek['kategori']] ?></div>
                </div>
            </div>
            <span class="rozet" style="background:color-mix(in srgb, <?= $durumRenk ?> 15%, transparent);color:<?= $durumRenk ?>;flex-shrink:0"><?= EKIPMAN_DURUMLARI[$ek['durum']] ?></span>
        </div>

        <?php if ($ek['durum'] === 'zimmette' && $ek['zimmet_ad']): ?>
        <div class="satir-esnek mt-2" style="gap:8px"><?= avatar(['ad' => $ek['zimmet_ad'], 'renk' => $ek['zimmet_renk'], 'avatar' => $ek['zimmet_avatar']], 24) ?><span class="kucuk"><?= e($ek['zimmet_ad']) ?> üzerinde</span></div>
        <?php elseif ($ek['durum'] === 'cekimde'): ?>
        <div class="kucuk mt-2 satir-esnek" style="gap:6px"><?= ikon('video', 13) ?> <b><?= e($ek['etkinlik_baslik'] ?? 'Çekim') ?></b><?= $ek['etkinlik_tarih'] ? ' · ' . tarih(substr($ek['etkinlik_tarih'], 0, 10)) : '' ?><?= $ek['zimmet_ad'] ? ' · ' . e($ek['zimmet_ad']) : '' ?></div>
        <?php elseif (in_array($ek['durum'], ['arizali', 'bakimda']) && $ek['ariza_notu']): ?>
        <div class="kucuk mt-2" style="color:var(--tehlike)"><?= ikon('uyari', 12) ?> <?= e($ek['ariza_notu']) ?></div>
        <?php endif; ?>

        <?php if ($sdKart): ?>
        <!-- SD kart yaşam döngüsü paneli -->
        <div class="mt-2" style="padding:10px 12px;background:var(--surface-2);border-radius:10px">
            <div class="satir-esnek arasi">
                <span class="kucuk kalin satir-esnek" style="gap:6px"><?= ikon('sd_kart', 13) ?> <?= SD_DURUMLARI[$ek['sd_durum'] ?: 'bos'] ?></span>
                <span class="satir-esnek" style="gap:4px">
                    <?php if (($ek['sd_durum'] ?: 'bos') === 'bos'): ?>
                    <button class="mini-btn" onclick="sdDolu(<?= $ek['id'] ?>)">Dolu işaretle</button>
                    <?php elseif ($ek['sd_durum'] === 'dolu'): ?>
                    <button class="mini-btn" onclick="sdAktar(<?= $ek['id'] ?>)">Drive'a aktarıldı</button>
                    <?php else: ?>
                    <button class="mini-btn" data-eylem="sd_guncelle" data-id="<?= $ek['id'] ?>" data-islem="bosalt" data-onay="Kart boşaltıldı olarak işaretlensin mi? (İçerik geçmişi hareket kaydında saklanır)">Boşaltıldı ✓</button>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($ek['sd_icerik']): ?><div class="hucre-alt mt-1 satir-esnek" style="gap:5px"><?= ikon('video', 12) ?> <?= e($ek['sd_icerik']) ?></div><?php endif; ?>
            <?php if ($ek['sd_drive_link']): ?><div class="hucre-alt mt-1"><a href="<?= e($ek['sd_drive_link']) ?>" target="_blank" style="color:var(--marka)"><?= ikon('klasor', 12) ?> Drive klasörü →</a></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="satir-esnek sarma mt-2" style="gap:6px">
            <?php if ($ek['durum'] === 'studyoda'): ?>
            <button class="btn btn-sm" data-eylem="ekipman_zimmet" data-id="<?= $ek['id'] ?>">Zimmet Al</button>
            <?php if ($yonetebilir): ?><button class="btn btn-sm btn-hayalet" onclick="zimmetVer(<?= $ek['id'] ?>, '<?= e($ek['ad']) ?>')">Başkasına Ver</button><?php endif; ?>
            <?php elseif (in_array($ek['durum'], ['zimmette', 'cekimde']) && ($ek['zimmet_user_id'] == $u['id'] || $yonetebilir)): ?>
            <button class="btn btn-sm" style="color:var(--basari)" data-eylem="ekipman_iade" data-id="<?= $ek['id'] ?>">İade Et</button>
            <?php endif; ?>
            <?php if (!in_array($ek['durum'], ['arizali', 'bakimda'])): ?>
            <button class="btn btn-sm btn-hayalet" onclick="arizaBildir(<?= $ek['id'] ?>)"><?= ikon('uyari', 13) ?> Arıza</button>
            <?php else: ?>
            <button class="btn btn-sm" style="color:var(--basari)" data-eylem="ekipman_ariza" data-id="<?= $ek['id'] ?>" data-durum="studyoda" data-onay="Ekipman kullanıma dönsün mü?">✓ Düzeldi</button>
            <?php endif; ?>
            <button class="btn btn-sm btn-hayalet" onclick="gecmisGoster(<?= $ek['id'] ?>, '<?= e(($ek['kod'] ? $ek['kod'] . ' — ' : '') . $ek['ad']) ?>')">Geçmiş</button>
            <?php if ($yonetebilir): ?>
            <button class="ikon-eylem" onclick='ekipmanDuzenle(<?= json_encode($ek, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)' title="Düzenle"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($yonetebilir): ?>
<!-- Ekipman ekle/düzenle -->
<div class="modal-katman" id="modalEkipman">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="ekipmanBaslik">Yeni Ekipman</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="ekipman_kaydet" id="ekipmanForm">
        <input type="hidden" name="id" id="e_id">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Kod / Etiket No</label><input name="kod" id="e_kod" class="girdi" placeholder="Örn. CAM-01, SD-04"></div>
                <div class="form-grup"><label class="form-etiket">Kategori</label><select name="kategori" id="e_kategori" class="secim"><?php foreach (EKIPMAN_KATEGORILERI as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Ad <span class="zorunlu">*</span></label><input name="ad" id="e_ad" class="girdi" required placeholder="Örn. Sony A7 IV"></div>
            <div class="form-grup"><label class="form-etiket">Fotoğraf</label><input type="file" name="foto" class="girdi" accept="image/*"></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Satın Alma Tarihi</label><input type="date" name="satin_alma" id="e_satin" class="girdi"></div>
                <div class="form-grup"><label class="form-etiket">Fiyat (₺)</label><input name="fiyat" id="e_fiyat" class="girdi" placeholder="0,00"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Not</label><input name="aciklama" id="e_aciklama" class="girdi" placeholder="Seri no, aksesuar bilgisi vb."></div>
        </div>
        <div class="modal-alt">
            <button type="button" class="btn btn-tehlike gizli" id="ekipmanSilBtn" style="margin-right:auto">Sil</button>
            <button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button>
        </div>
    </form></div>
</div>
<?php endif; ?>

<!-- Zimmet ver (başkasına) -->
<div class="modal-katman" id="modalZimmet">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="zimmetBaslik">Zimmet Ver</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="ekipman_zimmet">
        <input type="hidden" name="id" id="z_id">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Kime?</label><select name="user_id" class="secim"><?php foreach ($ekip as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['ad']) ?></option><?php endforeach; ?></select></div>
            <div class="form-grup"><label class="form-etiket">Not</label><input name="aciklama" class="girdi" placeholder="Örn. hafta sonu çekimi için"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Zimmetle</button></div>
    </form></div>
</div>

<!-- Arıza bildir -->
<div class="modal-katman" id="modalAriza">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Arıza / Bakım Bildir</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="ekipman_ariza">
        <input type="hidden" name="id" id="a_id">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Durum</label><select name="durum" class="secim"><option value="arizali">Arızalı</option><option value="bakimda">Bakımda</option></select></div>
            <div class="form-grup"><label class="form-etiket">Açıklama <span class="zorunlu">*</span></label><textarea name="not" class="metin-alani" required placeholder="Arıza/bakım detayı..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- SD: dolu işaretle -->
<div class="modal-katman" id="modalSdDolu">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Kartı Dolu İşaretle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="sd_guncelle">
        <input type="hidden" name="id" id="sd_id"><input type="hidden" name="islem" value="dolu">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Hangi çekim / içerik? <span class="zorunlu">*</span></label><input name="icerik" class="girdi" required placeholder="Örn. Marka X fuar çekimi, 15 Temmuz"><div class="form-ipucu">Bu bilgi kartın geçmişinde arşivlenir.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- SD: Drive'a aktarıldı -->
<div class="modal-katman" id="modalSdAktar">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Drive'a Aktarıldı</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="sd_guncelle">
        <input type="hidden" name="id" id="sda_id"><input type="hidden" name="islem" value="aktarildi">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Drive Klasör Linki</label><input name="drive_link" class="girdi" placeholder="https://drive.google.com/..."><div class="form-ipucu">Opsiyonel — girilirse kartın üzerinde tıklanabilir link görünür.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<!-- Hareket geçmişi -->
<div class="modal-katman" id="modalGecmis">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="gecmisBaslik">Hareket Geçmişi</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <div class="modal-govde" id="gecmisGovde"><div class="bos-mini">Yükleniyor...</div></div>
    </div>
</div>

<script>
const hareketler = <?= json_encode(array_reduce(rows("SELECT h.*, u1.ad yapan, u2.ad hedef, et.baslik etkinlik FROM ekipman_hareketleri h LEFT JOIN users u1 ON u1.id=h.user_id LEFT JOIN users u2 ON u2.id=h.hedef_user_id LEFT JOIN etkinlikler et ON et.id=h.etkinlik_id ORDER BY h.id DESC"), function ($acc, $h) { $acc[$h['ekipman_id']][] = $h; return $acc; }, []), JSON_UNESCAPED_UNICODE) ?>;
const hareketAd = <?= json_encode(EKIPMAN_HAREKET_TURLERI, JSON_UNESCAPED_UNICODE) ?>;

function zimmetVer(id, ad) { document.getElementById('z_id').value = id; document.getElementById('zimmetBaslik').textContent = ad + ' — Zimmet Ver'; modalAc('modalZimmet'); }
function arizaBildir(id) { document.getElementById('a_id').value = id; modalAc('modalAriza'); }
function sdDolu(id) { document.getElementById('sd_id').value = id; modalAc('modalSdDolu'); }
function sdAktar(id) { document.getElementById('sda_id').value = id; modalAc('modalSdAktar'); }
function gecmisGoster(id, baslik) {
    document.getElementById('gecmisBaslik').textContent = baslik + ' — Geçmiş';
    const kayitlar = hareketler[id] || [];
    let h = kayitlar.length ? '<div class="zaman-tunel">' : '<div class="bos-mini">Henüz hareket yok</div>';
    kayitlar.forEach(k => {
        let metin = `<b>${k.yapan || '?'}</b> ${hareketAd[k.tur] || k.tur}`;
        if (k.hedef && k.tur === 'zimmet') metin = `<b>${k.hedef}</b> zimmetine verildi (${k.yapan})`;
        if (k.etkinlik) metin += ` — ${k.etkinlik}`;
        if (k.aciklama) metin += `<div class="hucre-alt" style="margin-top:2px">${k.aciklama.replace(/</g, '&lt;')}</div>`;
        h += `<div class="tunel-oge"><div class="tunel-metin">${metin}</div><div class="tunel-zaman">${new Date(k.created.replace(' ', 'T')).toLocaleString('tr-TR', { dateStyle: 'medium', timeStyle: 'short' })}</div></div>`;
    });
    if (kayitlar.length) h += '</div>';
    document.getElementById('gecmisGovde').innerHTML = h;
    modalAc('modalGecmis');
}
<?php if ($yonetebilir): ?>
function ekipmanSifirla() {
    document.getElementById('ekipmanForm').reset();
    document.getElementById('e_id').value = '';
    document.getElementById('ekipmanBaslik').textContent = 'Yeni Ekipman';
    document.getElementById('ekipmanSilBtn').classList.add('gizli');
}
function ekipmanDuzenle(ek) {
    document.getElementById('ekipmanBaslik').textContent = 'Ekipmanı Düzenle';
    document.getElementById('e_id').value = ek.id;
    document.getElementById('e_kod').value = ek.kod || '';
    document.getElementById('e_kategori').value = ek.kategori;
    document.getElementById('e_ad').value = ek.ad;
    document.getElementById('e_satin').value = ek.satin_alma || '';
    document.getElementById('e_fiyat').value = ek.fiyat || 0;
    document.getElementById('e_aciklama').value = ek.aciklama || '';
    const sil = document.getElementById('ekipmanSilBtn');
    sil.classList.remove('gizli');
    sil.onclick = async () => {
        if (!confirm('Ekipman ve tüm hareket geçmişi silinsin mi?')) return;
        const j = await api('ekipman_sil', { id: ek.id });
        if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(() => location.reload(), 550); }
    };
    modalAc('modalEkipman');
}
<?php endif; ?>
</script>
<?php sayfa_sonu(); ?>

<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

$kullanicilar = rows("SELECT us.*, d.ad dosya_ad,
    (SELECT GROUP_CONCAT(md.dosya_id) FROM musteri_dosyalari md WHERE md.user_id=us.id) md_idler,
    (SELECT COUNT(*) FROM musteri_dosyalari md WHERE md.user_id=us.id) md_sayi
    FROM users us LEFT JOIN dosyalar d ON d.id=us.dosya_id ORDER BY us.aktif DESC, FIELD(us.rol,'yonetici','pm','ekip','finans','stajyer','musteri'), us.ad");
$dosyalar = rows("SELECT id, ad FROM dosyalar ORDER BY ad");

sayfa_basi('Kullanıcılar', 'kullanicilar');
?>
<div class="sayfa-ust">
    <div><div class="sayfa-baslik">Kullanıcılar</div><div class="sayfa-alt"><?= count($kullanicilar) ?> kullanıcı · ekip ve müşteri hesapları</div></div>
    <div class="sayfa-ust-aksiyon"><button class="btn btn-marka" data-modal="modalKullanici" onclick="kullaniciSifirla()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Yeni Kullanıcı</button></div>
</div>

<div class="filtre-bar">
    <div class="arama-kutu"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg><input class="girdi" placeholder="İsim veya e-posta ara..." data-arama="#kullaniciListe tr"></div>
    <div class="pill-filtre" data-pill-grup="#kullaniciListe tr">
        <button class="pill aktif" data-deger="">Tümü</button>
        <?php foreach (ROLLER as $k => $v): ?><button class="pill" data-deger="<?= $k ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
</div>

<div class="tablo-sar"><table class="tablo"><tbody id="kullaniciListe">
    <?php foreach ($kullanicilar as $k): ?>
    <tr data-filtre="<?= $k['rol'] ?>" data-ara="<?= e($k['ad'] . ' ' . $k['eposta']) ?>" style="<?= $k['aktif'] ? '' : 'opacity:.5' ?>">
        <td style="width:44px"><?= avatar($k, 38) ?></td>
        <td><div class="hucre-ana"><?= e($k['ad']) ?><?php if ($k['id'] == $u['id']): ?> <span class="hucre-alt">(siz)</span><?php endif; ?></div><div class="hucre-alt"><?= e($k['eposta']) ?></div></td>
        <td><span class="rozet rozet-tur"><?= ROLLER[$k['rol']] ?></span></td>
        <td class="kucuk"><?= $k['unvan'] ? e($k['unvan']) : '' ?><?= $k['md_sayi'] > 1 ? ' · ' . $k['md_sayi'] . ' dosya' : ($k['dosya_ad'] ? ' · ' . e($k['dosya_ad']) : '') ?></td>
        <td class="kucuk metin-muted"><?= $k['son_giris'] ? 'Son giriş ' . zaman_once($k['son_giris']) : 'Hiç girmedi' ?></td>
        <td style="width:120px;text-align:right">
            <button class="ikon-eylem" onclick='kullaniciDuzenle(<?= json_encode($k, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
            <?php if ($k['id'] != $u['id']): ?><button class="ikon-eylem <?= $k['aktif'] ? 'tehlike' : '' ?>" data-eylem="kullanici_durum" data-id="<?= $k['id'] ?>" data-aktif="<?= $k['aktif'] ? 0 : 1 ?>" data-onay="<?= $k['aktif'] ? 'Kullanıcı pasifleştirilsin mi?' : 'Kullanıcı aktifleştirilsin mi?' ?>" title="<?= $k['aktif'] ? 'Pasifleştir' : 'Aktifleştir' ?>"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="17"><path d="<?= $k['aktif'] ? 'M18.36 6.64a9 9 0 11-12.73 0M12 2v10' : 'M5 13l4 4L19 7' ?>"/></svg></button><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</tbody></table></div>

<div class="modal-katman" id="modalKullanici">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="kullaniciBaslik">Yeni Kullanıcı</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="kullanici_kaydet" id="kullaniciForm">
        <input type="hidden" name="id" id="k_id">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Ad Soyad <span class="zorunlu">*</span></label><input name="ad" id="k_ad" class="girdi" required></div>
                <div class="form-grup"><label class="form-etiket">E-posta <span class="zorunlu">*</span></label><input type="email" name="eposta" id="k_eposta" class="girdi" required></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Rol</label><select name="rol" id="k_rol" class="secim" onchange="rolDegisti()"><?php foreach (ROLLER as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Ünvan</label><input name="unvan" id="k_unvan" class="girdi" placeholder="Örn. Sosyal Medya Uzmanı"></div>
            </div>
            <div class="form-grup" id="dosyaGrup" style="display:none">
                <label class="form-etiket">Erişebileceği Dosyalar (müşteri için) <span class="zorunlu">*</span> <span class="metin-muted" style="font-weight:400">— birden fazla seçilebilir</span></label>
                <input type="hidden" name="musteri_dosyalar" id="k_musteri_dosyalar">
                <div class="izgara izgara-2" style="gap:6px;max-height:160px;overflow-y:auto;padding:2px">
                    <?php foreach ($dosyalar as $d): ?>
                    <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="mdosya-kutu" value="<?= $d['id'] ?>"> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($d['ad']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Şifre <span id="sifreZorunlu" class="zorunlu">*</span></label><input type="password" name="sifre" id="k_sifre" class="girdi"><div class="form-ipucu" id="sifreIpucu">En az 6 karakter.</div></div>
                <div class="form-grup" id="kapasiteGrup"><label class="form-etiket">Haftalık Kapasite (saat)</label><input type="number" name="haftalik_kapasite" id="k_kapasite" class="girdi" value="45" min="0" max="100"><div class="form-ipucu">Doluluk raporu bu hedefe göre hesaplanır.</div></div>
            </div>
            <div class="form-grup" id="maasGrup"><label class="form-etiket">Aylık Maaş (₺)</label><input name="maas" id="k_maas" class="girdi" value="0" placeholder="0,00"><div class="form-ipucu">Girilirse her ay başında otomatik gider kaydı oluşur. Yalnızca yönetici ve finans rolü görür.</div></div>
            <div class="form-grup" id="izinGrup" style="display:none">
                <label class="form-etiket">Özel İzinler <span class="metin-muted" style="font-weight:400">(rol varsayılanlarını geçersiz kılar)</span></label>
                <input type="hidden" name="izinler" id="k_izinler">
                <div class="izgara izgara-2" style="gap:8px">
                    <?php foreach (IZIN_ANAHTARLARI as $anahtar => $etiket): ?>
                    <label class="satir-esnek kucuk" style="gap:9px;padding:9px 12px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="izin-kutu" data-anahtar="<?= $anahtar ?>"> <?= $etiket ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<script>
// Rol varsayılan izinleri (init.php ile aynı)
const rolVarsayilan = {
    pm:      { finans: 1, rapor: 1, kapasite: 1, dosya_yonet: 1, gorev_olustur: 1, gorev_sil: 1, icerik_yonet: 1, ekipman_yonet: 1, onay_gonder: 1, duyuru_yayinla: 1, takvim_yonet: 1, kanal_kur: 1, belge_olustur: 1, arsiv_sil: 1, talep_yonet: 1 },
    ekip:    { finans: 0, rapor: 0, kapasite: 0, dosya_yonet: 0, gorev_olustur: 1, gorev_sil: 0, icerik_yonet: 1, ekipman_yonet: 0, onay_gonder: 1, duyuru_yayinla: 0, takvim_yonet: 1, kanal_kur: 1, belge_olustur: 0, arsiv_sil: 0, talep_yonet: 0 },
    finans:  { finans: 1, rapor: 1, kapasite: 1, dosya_yonet: 0, gorev_olustur: 0, gorev_sil: 0, icerik_yonet: 0, ekipman_yonet: 0, onay_gonder: 0, duyuru_yayinla: 0, takvim_yonet: 0, kanal_kur: 1, belge_olustur: 1, arsiv_sil: 0, talep_yonet: 0 },
    stajyer: { finans: 0, rapor: 0, kapasite: 0, dosya_yonet: 0, gorev_olustur: 0, gorev_sil: 0, icerik_yonet: 0, ekipman_yonet: 0, onay_gonder: 0, duyuru_yayinla: 0, takvim_yonet: 0, kanal_kur: 0, belge_olustur: 0, arsiv_sil: 0, talep_yonet: 0 },
};
function rolDegisti() {
    const rol = document.getElementById('k_rol').value;
    document.getElementById('dosyaGrup').style.display = rol === 'musteri' ? 'block' : 'none';
    document.getElementById('izinGrup').style.display = rolVarsayilan[rol] ? 'block' : 'none';
    document.getElementById('kapasiteGrup').style.display = rol === 'musteri' ? 'none' : 'block';
    // Kutulara rol varsayılanlarını yansıt (özel geçersiz kılma yoksa)
    if (rolVarsayilan[rol] && !window.izinOzel) {
        document.querySelectorAll('.izin-kutu').forEach(c => { c.checked = !!rolVarsayilan[rol][c.dataset.anahtar]; });
    }
}
function kullaniciSifirla() {
    document.getElementById('kullaniciForm').reset();
    document.getElementById('k_id').value = '';
    document.getElementById('kullaniciBaslik').textContent = 'Yeni Kullanıcı';
    document.getElementById('sifreZorunlu').style.display = 'inline';
    document.getElementById('sifreIpucu').textContent = 'En az 6 karakter.';
    document.getElementById('k_sifre').required = true;
    document.getElementById('k_kapasite').value = 45;
    window.izinOzel = false;
    rolDegisti();
}
function kullaniciDuzenle(k) {
    document.getElementById('kullaniciBaslik').textContent = 'Kullanıcıyı Düzenle';
    document.getElementById('k_id').value = k.id;
    document.getElementById('k_ad').value = k.ad;
    document.getElementById('k_eposta').value = k.eposta;
    document.getElementById('k_rol').value = k.rol;
    document.getElementById('k_unvan').value = k.unvan || '';
    // Müşteri dosyaları: junction + birincil dosya
    const secili = new Set((k.md_idler ? String(k.md_idler).split(',') : []).concat(k.dosya_id ? [String(k.dosya_id)] : []));
    document.querySelectorAll('.mdosya-kutu').forEach(c => { c.checked = secili.has(c.value); });
    document.getElementById('k_kapasite').value = k.haftalik_kapasite || 45;
    document.getElementById('k_maas').value = k.maas || 0;
    document.getElementById('k_sifre').value = '';
    document.getElementById('k_sifre').required = false;
    document.getElementById('sifreZorunlu').style.display = 'none';
    document.getElementById('sifreIpucu').textContent = 'Değiştirmek istemiyorsanız boş bırakın.';
    // Mevcut izinler: özel geçersiz kılma varsa onu, yoksa rol varsayılanını göster
    let ozel = null;
    try { ozel = JSON.parse(k.izinler || 'null'); } catch (e) {}
    window.izinOzel = !!ozel;
    const taban = Object.assign({}, rolVarsayilan[k.rol] || {}, ozel || {});
    document.querySelectorAll('.izin-kutu').forEach(c => { c.checked = !!taban[c.dataset.anahtar]; });
    rolDegisti();
    modalAc('modalKullanici');
}
// Gönderirken izin kutularını + müşteri dosyalarını JSON'a çevir
document.getElementById('kullaniciForm').addEventListener('submit', () => {
    const rol = document.getElementById('k_rol').value;
    if (rolVarsayilan[rol]) {
        const izinler = {};
        document.querySelectorAll('.izin-kutu').forEach(c => { izinler[c.dataset.anahtar] = c.checked ? 1 : 0; });
        document.getElementById('k_izinler').value = JSON.stringify(izinler);
    } else document.getElementById('k_izinler').value = '';
    document.getElementById('k_musteri_dosyalar').value = rol === 'musteri'
        ? JSON.stringify(Array.from(document.querySelectorAll('.mdosya-kutu:checked')).map(c => c.value))
        : '';
});
</script>
<?php sayfa_sonu(); ?>

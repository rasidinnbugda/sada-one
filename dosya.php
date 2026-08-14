<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bilesenler.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
$dosya = row("SELECT * FROM dosyalar WHERE id=?", [$id]);
if (!$dosya || !dosya_erisim($id)) { header('Location: dosyalar.php'); exit; }
$musteriGorunumu = is_musteri();
$dosyaUyeleri = rows("SELECT u.id, u.ad, u.renk, u.avatar, u.unvan FROM dosya_uyeleri du JOIN users u ON u.id=du.user_id WHERE du.dosya_id=? AND u.aktif=1 ORDER BY u.ad", [$id]);

$projeler = rows("SELECT p.*, u.ad pm_ad,
    (SELECT COUNT(*) FROM gorevler g WHERE g.proje_id=p.id) gorev_sayi,
    (SELECT COUNT(*) FROM gorevler g WHERE g.proje_id=p.id AND g.durum='tamamlandi') tamam_sayi
    FROM projeler p LEFT JOIN users u ON u.id=p.pm_id WHERE p.dosya_id=? ORDER BY p.created DESC", [$id]);
$musteriler = rows("SELECT * FROM users WHERE dosya_id=? AND rol='musteri'", [$id]);
$arsivSayi = (int)val("SELECT COUNT(*) FROM arsiv WHERE dosya_id=?", [$id]);
$sozlesmeler = rows("SELECT s.*, a.dosya_yolu, a.ad ek_ad FROM sozlesmeler s LEFT JOIN arsiv a ON a.id=s.arsiv_id WHERE s.dosya_id=? ORDER BY s.bitis IS NULL, s.bitis", [$id]);

// Sosyal medya hesapları + metrik geçmişi
$sosyalHesaplar = rows("SELECT * FROM sosyal_hesaplar WHERE dosya_id=? ORDER BY platform, kullanici_adi", [$id]);
foreach ($sosyalHesaplar as &$sh) {
    $sh['metrikler'] = rows("SELECT * FROM sosyal_metrikler WHERE hesap_id=? ORDER BY tarih DESC LIMIT 10", [$sh['id']]);
}
unset($sh);

sayfa_basi($dosya['ad'], 'dosyalar');
?>
<div class="satir-esnek mb-3" style="gap:10px">
    <a href="dosyalar.php" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="metin-muted kucuk">Dosyalar / <?= e($dosya['ad']) ?></span>
</div>

<div class="sayfa-ust">
    <div class="satir-esnek" style="gap:16px">
        <?= dosya_logo($dosya, 56, 22) ?>
        <div>
            <div class="sayfa-baslik" style="font-size:24px"><?= e($dosya['ad']) ?></div>
            <div class="satir-esnek mt-1" style="gap:8px">
                <span class="rozet rozet-tur"><?= DOSYA_TURLERI[$dosya['tur']] ?></span>
                <?= rozet($dosya['durum'], ['aktif' => 'Aktif', 'pasif' => 'Pasif']) ?>
            </div>
        </div>
    </div>
    <?php if (yetki('dosya_yonet')): ?>
    <div class="sayfa-ust-aksiyon">
        <button class="btn btn-marka" data-modal="modalProje"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Proje Ekle</button>
        <button class="btn" onclick="dosyaDuzenle()"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg> Düzenle</button>
    </div>
    <?php endif; ?>
</div>

<div class="izgara" style="grid-template-columns:1fr 320px" id="dosyaDuzen">
    <div>
        <!-- Projeler -->
        <div class="satir-esnek arasi mb-2"><div class="kart-baslik">Projeler (<?= count($projeler) ?>)</div></div>
        <?php if (!$projeler): ?>
        <div class="kart orta metin-muted kucuk" style="padding:30px">Bu dosyada henüz proje yok.</div>
        <?php else: foreach (PROJE_TURLERI as $turK => $turV):
            $grup = array_filter($projeler, fn($p) => $p['tur'] === $turK);
            if (!$grup) continue; ?>
        <div class="nav-bolum" style="padding:14px 0 8px"><?= $turV ?> Hizmetler</div>
        <div class="izgara izgara-2">
            <?php foreach ($grup as $p):
                $oran = $p['gorev_sayi'] ? round($p['tamam_sayi'] / $p['gorev_sayi'] * 100) : 0; ?>
            <a href="proje.php?id=<?= $p['id'] ?>" class="kart kart-tik" style="padding:16px">
                <div class="satir-esnek arasi mb-2">
                    <div class="kart-baslik" style="font-size:15px"><?= e($p['ad']) ?></div>
                    <?= rozet($p['durum'], PROJE_DURUMLARI) ?>
                </div>
                <?php if ($p['pm_ad']): ?><div class="hucre-alt">PM: <?= e($p['pm_ad']) ?></div><?php endif; ?>
                <div class="ilerleme mt-2"><div class="ilerleme-dolu" data-oran="<?= $oran ?>" style="width:0"></div></div>
                <div class="hucre-alt mt-1"><?= $p['tamam_sayi'] ?>/<?= $p['gorev_sayi'] ?> görev · %<?= $oran ?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; endif; ?>

        <!-- Sosyal Medya Takibi -->
        <div class="satir-esnek arasi mb-2 mt-3">
            <div class="kart-baslik"><?= ikon('grafik', 16) ?> Sosyal Medya (<?= count($sosyalHesaplar) ?>)</div>
            <?php if (yetki('icerik_yonet')): ?><button class="btn btn-sm btn-marka" data-modal="modalSosyalHesap"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Hesap Ekle</button><?php endif; ?>
        </div>
        <?php if (!$sosyalHesaplar): ?>
        <div class="kart orta metin-muted kucuk" style="padding:24px">Bu dosya için sosyal medya hesabı eklenmemiş.<?= yetki('icerik_yonet') ? ' Hesap ekleyip takipçi verilerini düzenli girerek büyümeyi izleyin.' : '' ?></div>
        <?php else: ?>
        <div class="izgara izgara-2">
            <?php foreach ($sosyalHesaplar as $sh):
                $son = $sh['metrikler'][0] ?? null;
                $onceki = $sh['metrikler'][1] ?? null;
                $fark = ($son && $onceki) ? (int)$son['takipci'] - (int)$onceki['takipci'] : null;
                $maxTakipci = $sh['metrikler'] ? max(array_column($sh['metrikler'], 'takipci')) : 1; ?>
            <div class="kart" style="padding:16px">
                <div class="satir-esnek arasi">
                    <div class="satir-esnek" style="gap:10px;min-width:0">
                        <span class="dosya-avatar" style="width:40px;height:40px;background:var(--parlak);color:var(--marka)"><?= ikon(isset(IKONLAR[$sh['platform']]) ? $sh['platform'] : 'diger', 20) ?></span>
                        <div style="min-width:0">
                            <div class="kalin kucuk"><?php if ($sh['url']): ?><a href="<?= e($sh['url']) ?>" target="_blank" style="color:var(--marka)">@<?= e(ltrim($sh['kullanici_adi'], '@')) ?></a><?php else: ?>@<?= e(ltrim($sh['kullanici_adi'], '@')) ?><?php endif; ?></div>
                            <div class="hucre-alt"><?= PLATFORMLAR[$sh['platform']] ?? $sh['platform'] ?></div>
                        </div>
                    </div>
                    <?php if (yetki('icerik_yonet')): ?>
                    <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-eylem="sosyal_hesap_sil" data-id="<?= $sh['id'] ?>" data-onay="Hesap ve tüm metrik geçmişi silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                    <?php endif; ?>
                </div>
                <div class="satir-esnek mt-2" style="gap:14px;align-items:baseline">
                    <span class="stat-deger" style="font-size:26px"><?= $son ? number_format((int)$son['takipci'], 0, ',', '.') : '—' ?></span>
                    <span class="hucre-alt">takipçi</span>
                    <?php if ($fark !== null): ?>
                    <span class="kucuk kalin" style="color:<?= $fark >= 0 ? 'var(--basari)' : 'var(--tehlike)' ?>"><?= $fark >= 0 ? '▲ +' : '▼ ' ?><?= number_format($fark, 0, ',', '.') ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($son && ($son['gonderi'] !== null || $son['etkilesim'] !== null)): ?>
                <div class="hucre-alt mt-1">
                    <?= $son['gonderi'] !== null ? $son['gonderi'] . ' gönderi' : '' ?><?= $son['gonderi'] !== null && $son['etkilesim'] !== null ? ' · ' : '' ?><?= $son['etkilesim'] !== null ? number_format((int)$son['etkilesim'], 0, ',', '.') . ' etkileşim' : '' ?>
                </div>
                <?php endif; ?>
                <?php if (count($sh['metrikler']) > 1): ?>
                <!-- Mini geçmiş grafiği (eski→yeni) -->
                <div style="display:flex;gap:3px;align-items:flex-end;height:36px;margin-top:10px" title="Son <?= count($sh['metrikler']) ?> kayıt">
                    <?php foreach (array_reverse($sh['metrikler']) as $m): ?>
                    <div style="flex:1;background:var(--marka);opacity:.75;border-radius:3px 3px 0 0;height:<?= max(8, round((int)$m['takipci'] / max(1, $maxTakipci) * 100)) ?>%" title="<?= tarih($m['tarih']) ?>: <?= number_format((int)$m['takipci'], 0, ',', '.') ?>"></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="satir-esnek arasi mt-2">
                    <span class="hucre-alt"><?= $son ? 'Son veri: ' . tarih($son['tarih']) : 'Henüz veri girilmedi' ?></span>
                    <?php if (is_staff()): ?><button class="mini-btn" onclick="metrikGir(<?= $sh['id'] ?>, '<?= e($sh['kullanici_adi']) ?>')">+ Veri Gir</button><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$musteriGorunumu):
            $bilgiNotlari = rows("SELECT bn.*, us.ad guncelleyen_ad FROM dosya_notlari bn LEFT JOIN users us ON us.id=bn.guncelleyen_id WHERE bn.dosya_id=? ORDER BY bn.sira", [$id]); ?>
        <!-- Bilgi Bankası (yalnızca ekip) -->
        <div class="satir-esnek arasi mb-2 mt-3">
            <div class="kart-baslik"><?= ikon('belge', 16) ?> Bilgi Bankası (<?= count($bilgiNotlari) ?>)</div>
            <button class="btn btn-sm btn-marka" onclick="notYeni()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="14"><path d="M12 5v14M5 12h14"/></svg> Bölüm Ekle</button>
        </div>
        <?php if (!$bilgiNotlari): ?>
        <div class="kart orta metin-muted kucuk" style="padding:22px">Marka rehberi, hedef kitle, yazım dili gibi süreç notlarını buraya ekleyin — müşteri görmez, ekip her zaman ulaşır.</div>
        <?php else: foreach ($bilgiNotlari as $bn): ?>
        <div class="kart mb-2" style="padding:14px 16px">
            <div class="satir-esnek arasi">
                <div class="kalin kucuk"><?= e($bn['baslik']) ?></div>
                <div class="satir-esnek" style="gap:2px">
                    <button class="ikon-eylem" style="width:26px;height:26px" onclick='notDuzenleBB(<?= json_encode($bn, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><?= ikon('kalem', 13) ?></button>
                    <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-eylem="dosyanot_sil" data-id="<?= $bn['id'] ?>" data-onay="Bölüm silinsin mi?"><?= ikon('cop', 13) ?></button>
                </div>
            </div>
            <div class="kucuk metin-2 mt-1" style="white-space:pre-wrap"><?= e($bn['metin']) ?></div>
            <?php if ($bn['guncelleme']): ?><div class="hucre-alt mt-2"><?= e($bn['guncelleyen_ad']) ?> güncelledi · <?= zaman_once($bn['guncelleme']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; endif; ?>

        <div class="modal-katman" id="modalBilgiNot">
            <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="bnBaslikUst">Bilgi Bölümü</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
            <form data-ajax="dosyanot_kaydet">
                <input type="hidden" name="id" id="bn_id"><input type="hidden" name="dosya_id" value="<?= $id ?>">
                <div class="modal-govde">
                    <div class="form-grup"><label class="form-etiket">Bölüm Başlığı <span class="zorunlu">*</span></label><input name="baslik" id="bn_baslik" class="girdi" required placeholder="Örn. Marka Sesi & Yazım Dili"></div>
                    <div class="form-grup"><label class="form-etiket">İçerik</label><textarea name="metin" id="bn_metin" class="metin-alani" style="min-height:150px"></textarea></div>
                </div>
                <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
            </form></div>
        </div>
        <script>
        function notYeni() { document.getElementById('bn_id').value = ''; document.getElementById('bn_baslik').value = ''; document.getElementById('bn_metin').value = ''; document.getElementById('bnBaslikUst').textContent = 'Yeni Bilgi Bölümü'; modalAc('modalBilgiNot'); }
        function notDuzenleBB(n) { document.getElementById('bn_id').value = n.id; document.getElementById('bn_baslik').value = n.baslik; document.getElementById('bn_metin').value = n.metin || ''; document.getElementById('bnBaslikUst').textContent = 'Bölümü Düzenle'; modalAc('modalBilgiNot'); }
        </script>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($musteriGorunumu): ?>
        <!-- Müşteri kısıtlı yan panel: yalnızca arşiv -->
        <a href="arsiv.php?dosya=<?= $id ?>" class="kart kart-tik satir-esnek arasi">
            <div class="satir-esnek" style="gap:10px"><svg width="20" fill="none" stroke="var(--marka)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8"/></svg><span class="kalin kucuk">Paylaşılan Dosyalar</span></div>
            <span class="rozet"><?= $arsivSayi ?></span>
        </a>
        <?php else: ?>
        <!-- İletişim -->
        <div class="kart mb-2">
            <div class="kart-baslik" style="font-size:14px" class="mb-2">İletişim</div>
            <div class="dikey mt-2" style="gap:12px">
                <?php if ($dosya['iletisim_ad']): ?><div><div class="hucre-alt">Kişi</div><div class="hucre-ana"><?= e($dosya['iletisim_ad']) ?></div></div><?php endif; ?>
                <?php if ($dosya['iletisim_eposta']): ?><div><div class="hucre-alt">E-posta</div><a href="mailto:<?= e($dosya['iletisim_eposta']) ?>" class="hucre-ana" style="color:var(--marka)"><?= e($dosya['iletisim_eposta']) ?></a></div><?php endif; ?>
                <?php if ($dosya['iletisim_tel']): ?><div><div class="hucre-alt">Telefon</div><div class="hucre-ana"><?= e($dosya['iletisim_tel']) ?></div></div><?php endif; ?>
                <?php if (!$dosya['iletisim_ad'] && !$dosya['iletisim_eposta']): ?><div class="metin-muted kucuk">İletişim bilgisi eklenmemiş.</div><?php endif; ?>
                <?php if ($dosya['aciklama']): ?><div><div class="hucre-alt">Açıklama</div><div class="kucuk metin-2"><?= nl2br(e($dosya['aciklama'])) ?></div></div><?php endif; ?>
            </div>
        </div>
        <!-- Sorumlu ekip -->
        <div class="kart mb-2">
            <div class="satir-esnek arasi mb-2"><div class="kart-baslik" style="font-size:14px">Sorumlu Ekip</div><?= uye_avatarlari($dosyaUyeleri) ?></div>
            <?php if (!$dosyaUyeleri): ?><div class="metin-muted kucuk">Henüz üye atanmamış.<?php if (yetki('dosya_yonet')): ?> Düzenle penceresinden ekleyin.<?php endif; ?></div>
            <?php else: foreach ($dosyaUyeleri as $du): ?>
            <div class="satir-esnek mt-2" style="gap:10px"><?= avatar($du, 30) ?><div><div class="hucre-ana kucuk"><?= e($du['ad']) ?></div><?php if ($du['unvan']): ?><div class="hucre-alt"><?= e($du['unvan']) ?></div><?php endif; ?></div></div>
            <?php endforeach; endif; ?>
        </div>
        <!-- Müşteri erişimleri -->
        <div class="kart mb-2">
            <div class="satir-esnek arasi mb-2"><div class="kart-baslik" style="font-size:14px">Müşteri Erişimi</div></div>
            <?php if (!$musteriler): ?>
            <div class="metin-muted kucuk mt-2">Bu dosya için müşteri hesabı yok.
                <?php if (is_admin()): ?><br><a href="kullanicilar.php" style="color:var(--marka)">Kullanıcı ekle →</a><?php endif; ?>
            </div>
            <?php else: foreach ($musteriler as $m): ?>
            <div class="satir-esnek mt-2" style="gap:10px"><?= avatar($m, 32) ?><div><div class="hucre-ana kucuk"><?= e($m['ad']) ?></div><div class="hucre-alt"><?= e($m['eposta']) ?></div></div></div>
            <?php endforeach; endif; ?>
        </div>
        <!-- Sözleşmeler -->
        <div class="kart mb-2">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik" style="font-size:14px">Sözleşmeler</div>
                <?php if (yetki('dosya_yonet')): ?><button class="mini-btn" data-modal="modalSozlesme">+ Ekle</button><?php endif; ?>
            </div>
            <?php if (!$sozlesmeler): ?><div class="metin-muted kucuk">Sözleşme kaydı yok. Bitiş tarihine 30 gün kala otomatik hatırlatılır.</div>
            <?php else: foreach ($sozlesmeler as $sz):
                $kalanGun = $sz['bitis'] ? floor((strtotime($sz['bitis']) - time()) / 86400) : null;
                $renk = $kalanGun !== null && $kalanGun < 0 ? 'var(--tehlike)' : ($kalanGun !== null && $kalanGun <= 30 ? 'var(--uyari)' : 'var(--text-2)'); ?>
            <div class="mt-2" style="padding:10px 12px;background:var(--surface-2);border-radius:10px">
                <div class="satir-esnek arasi">
                    <span class="kucuk kalin"><?= e($sz['baslik']) ?></span>
                    <?php if (yetki('dosya_yonet')): ?><button class="ikon-eylem tehlike" style="width:24px;height:24px" data-eylem="sozlesme_sil" data-id="<?= $sz['id'] ?>" data-onay="Sözleşme silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button><?php endif; ?>
                </div>
                <div class="hucre-alt mt-1">
                    <?php if ($sz['tutar'] > 0): ?><?= para($sz['tutar']) ?> · <?php endif; ?>
                    <?= tarih($sz['baslangic']) ?> → <span style="color:<?= $renk ?>"><?= tarih($sz['bitis']) ?><?= $kalanGun !== null && $kalanGun >= 0 && $kalanGun <= 30 ? " ({$kalanGun} gün)" : ($kalanGun !== null && $kalanGun < 0 ? ' (süresi doldu)' : '') ?></span>
                    <?php if ($sz['dosya_yolu']): ?> · <a href="uploads/<?= e($sz['dosya_yolu']) ?>" target="_blank" style="color:var(--marka)"><?= ikon('atac', 11) ?> Belge</a><?php endif; ?>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <a href="arsiv.php?dosya=<?= $id ?>" class="kart kart-tik satir-esnek arasi">
            <div class="satir-esnek" style="gap:10px"><svg width="20" fill="none" stroke="var(--marka)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8"/></svg><span class="kalin kucuk">Dosya Arşivi</span></div>
            <span class="rozet"><?= $arsivSayi ?></span>
        </a>

        <?php if (yetki('dosya_yonet')): ?>
        <!-- Sözleşme ekleme modalı -->
        <div class="modal-katman" id="modalSozlesme">
            <div class="modal"><div class="modal-ust"><div class="modal-baslik">Sözleşme Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
            <form data-ajax="sozlesme_kaydet">
                <input type="hidden" name="dosya_id" value="<?= $id ?>">
                <div class="modal-govde">
                    <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="baslik" class="girdi" required placeholder="Örn. 2026 Sosyal Medya Yönetim Sözleşmesi"></div>
                    <div class="form-satir">
                        <div class="form-grup"><label class="form-etiket">Başlangıç</label><input type="date" name="baslangic" class="girdi"></div>
                        <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="date" name="bitis" class="girdi"><div class="form-ipucu">30 gün kala hatırlatılır.</div></div>
                    </div>
                    <div class="form-grup"><label class="form-etiket">Tutar (₺)</label><input name="tutar" class="girdi" placeholder="0,00"></div>
                    <div class="form-grup"><label class="form-etiket">Sözleşme Belgesi</label><input type="file" name="dosya" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Not</label><input name="aciklama" class="girdi"></div>
                </div>
                <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
            </form></div>
        </div>
        <?php endif; ?>
        <?php endif; /* /müşteri kısıtlı görünüm */ ?>
    </div>
</div>

<?php
// Proje modalı
$dosyalar = rows("SELECT id, ad FROM dosyalar WHERE durum='aktif' ORDER BY ad");
$pmler = rows("SELECT id, ad FROM users WHERE rol IN ('yonetici','pm') AND aktif=1 ORDER BY ad");
if (yetki('dosya_yonet')):
?>
<div class="modal-katman" id="modalProje">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Proje — <?= e($dosya['ad']) ?></div><button class="modal-kapat" data-modal-kapat>✕</button></div>
        <form data-ajax="proje_kaydet">
            <input type="hidden" name="dosya_id" value="<?= $id ?>">
            <div class="modal-govde">
                <div class="form-grup"><label class="form-etiket">Proje Adı <span class="zorunlu">*</span></label><input name="ad" class="girdi" required></div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Hizmet Türü</label><select name="tur" class="secim"><?php foreach (PROJE_TURLERI as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                    <div class="form-grup"><label class="form-etiket">Proje Yöneticisi</label><select name="pm_id" class="secim"><option value="">—</option><?php foreach ($pmler as $pm): ?><option value="<?= $pm['id'] ?>"><?= e($pm['ad']) ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Başlangıç</label><input type="date" name="baslangic" class="girdi"></div>
                    <div class="form-grup"><label class="form-etiket">Sözleşme Tutarı (₺)</label><input name="sozlesme_tutari" class="girdi" placeholder="0,00"></div>
                </div>
                <div class="form-grup"><label class="form-etiket">Proje Şablonu (opsiyonel)</label><select name="psablon_id" class="secim"><option value="">— Boş proje</option><?php foreach (rows("SELECT id, ad FROM proje_sablonlari ORDER BY ad") as $psx): ?><option value="<?= $psx['id'] ?>"><?= e($psx['ad']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilirse şablondaki görevler akışlarıyla birlikte kurulur.</div></div>
                <?php uye_secici(array_column($dosyaUyeleri, 'id')); ?>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="aciklama" class="metin-alani"></textarea></div>
            </div>
            <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
        </form>
    </div>
</div>

<!-- Dosya düzenle modalı -->
<div class="modal-katman" id="modalDosyaDuzen">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Dosyayı Düzenle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
        <form data-ajax="dosya_kaydet">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="modal-govde">
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">Dosya Adı</label><input name="ad" class="girdi" value="<?= e($dosya['ad']) ?>" required></div>
                    <div class="form-grup"><label class="form-etiket">Tür</label><select name="tur" class="secim"><?php foreach (DOSYA_TURLERI as $k => $v): ?><option value="<?= $k ?>" <?= $dosya['tur'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-grup"><label class="form-etiket">Renk</label><div class="satir-esnek sarma" id="renkSecim2"><?php foreach (['#b1fb01', '#182f5d', '#610714', '#f8f2cb', '#3b9df0', '#35c66b', '#f5a524', '#a58bf0'] as $r): ?><label style="cursor:pointer"><input type="radio" name="renk" value="<?= $r ?>" <?= $r === $dosya['renk'] ? 'checked' : '' ?> style="display:none" class="renk-radio2"><span class="etiket-nokta" style="width:28px;height:28px;background:<?= $r ?>;border:2px solid <?= $r === $dosya['renk'] ? 'var(--text)' : 'transparent' ?>"></span></label><?php endforeach; ?></div></div>
                <div class="form-grup"><label class="form-etiket">Logo <?= $dosya['logo'] ? '(mevcut logoyu değiştirir)' : '' ?></label><input type="file" name="logo" class="girdi" accept="image/*"></div>
                <?php uye_secici(array_column($dosyaUyeleri, 'id'), 'Sorumlu Ekip Üyeleri'); ?>
                <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="aciklama" class="metin-alani"><?= e($dosya['aciklama']) ?></textarea></div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">İletişim Kişisi</label><input name="iletisim_ad" class="girdi" value="<?= e($dosya['iletisim_ad']) ?>"></div>
                    <div class="form-grup"><label class="form-etiket">Telefon</label><input name="iletisim_tel" class="girdi" value="<?= e($dosya['iletisim_tel']) ?>"></div>
                </div>
                <div class="form-satir">
                    <div class="form-grup"><label class="form-etiket">E-posta</label><input type="email" name="iletisim_eposta" class="girdi" value="<?= e($dosya['iletisim_eposta']) ?>"></div>
                    <div class="form-grup"><label class="form-etiket">Durum</label><select name="durum" class="secim"><option value="aktif" <?= $dosya['durum'] === 'aktif' ? 'selected' : '' ?>>Aktif</option><option value="pasif" <?= $dosya['durum'] === 'pasif' ? 'selected' : '' ?>>Pasif</option></select></div>
                </div>
            </div>
            <div class="modal-alt">
                <?php if (is_admin()): ?><button type="button" class="btn btn-tehlike" data-eylem="dosya_sil" data-id="<?= $id ?>" data-onay="Bu dosyayı silmek istediğinize emin misiniz?" style="margin-right:auto">Sil</button><?php endif; ?>
                <button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button>
            </div>
        </form>
    </div>
</div>
<script>
function dosyaDuzenle() { modalAc('modalDosyaDuzen'); }
document.getElementById('renkSecim2')?.addEventListener('change', () => {
    document.querySelectorAll('.renk-radio2').forEach(r => r.nextElementSibling.style.borderColor = r.checked ? 'var(--text)' : 'transparent');
});
</script>
<?php endif; /* /dosya_yonet modalları */ ?>

<?php if (yetki('icerik_yonet')): ?>
<!-- Sosyal hesap ekle -->
<div class="modal-katman" id="modalSosyalHesap">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Sosyal Medya Hesabı Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="sosyal_hesap_ekle">
        <input type="hidden" name="dosya_id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Platform</label><select name="platform" class="secim"><?php foreach (PLATFORMLAR as $k => $v): if ($k === 'diger') continue; ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Kullanıcı Adı <span class="zorunlu">*</span></label><input name="kullanici_adi" class="girdi" required placeholder="@markaadi"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Profil Linki</label><input name="url" class="girdi" placeholder="instagram.com/markaadi"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Ekle</button></div>
    </form></div>
</div>
<?php endif; ?>

<?php if (is_staff()): ?>
<!-- Metrik gir -->
<div class="modal-katman" id="modalMetrik">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik" id="metrikBaslik">Veri Gir</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="sosyal_metrik_ekle">
        <input type="hidden" name="hesap_id" id="mt_hesap">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tarih</label><input type="date" name="tarih" class="girdi" value="<?= date('Y-m-d') ?>"><div class="form-ipucu">Aynı güne ikinci giriş, öncekini günceller.</div></div>
                <div class="form-grup"><label class="form-etiket">Takipçi Sayısı <span class="zorunlu">*</span></label><input name="takipci" class="girdi" required placeholder="Örn. 12500"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Gönderi Sayısı</label><input name="gonderi" class="girdi" placeholder="Opsiyonel"></div>
                <div class="form-grup"><label class="form-etiket">Etkileşim</label><input name="etkilesim" class="girdi" placeholder="Beğeni+yorum vb."></div>
            </div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
function metrikGir(hesapId, kadi) {
    document.getElementById('mt_hesap').value = hesapId;
    document.getElementById('metrikBaslik').textContent = kadi + ' — Veri Gir';
    modalAc('modalMetrik');
}
</script>
<?php sayfa_sonu(); ?>

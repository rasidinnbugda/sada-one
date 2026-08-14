<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bilesenler.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
$proje = row("SELECT p.*, d.ad dosya_ad, d.renk dosya_renk, uu.ad pm_ad FROM projeler p JOIN dosyalar d ON d.id=p.dosya_id LEFT JOIN users uu ON uu.id=p.pm_id WHERE p.id=?", [$id]);
if (!$proje || !proje_erisim($id)) { header('Location: projeler.php'); exit; }

$gorevler = rows("SELECT g.*, u.ad atanan_ad, u.renk atanan_renk, u.avatar atanan_avatar,
    bg.durum bagimli_durum, bg.baslik bagimli_baslik,
    (SELECT COUNT(*) FROM gorev_kontrol k WHERE k.gorev_id=g.id) kontrol_toplam,
    (SELECT COUNT(*) FROM gorev_kontrol k WHERE k.gorev_id=g.id AND k.tamam=1) kontrol_tamam,
    (SELECT COUNT(*) FROM gorev_atananlar gaa WHERE gaa.gorev_id=g.id) atanan_sayi,
    (SELECT GROUP_CONCAT(u3.ad SEPARATOR ', ') FROM gorev_atananlar ga3 JOIN users u3 ON u3.id=ga3.user_id WHERE ga3.gorev_id=g.id) atanan_adlar
    FROM gorevler g LEFT JOIN users u ON u.id=g.atanan_id LEFT JOIN gorevler bg ON bg.id=g.bagimli_id
    WHERE g.proje_id=? AND g.arsivlendi=0 ORDER BY g.sira, g.son_tarih IS NULL, g.son_tarih", [$id]);
$tamamGorev = count(array_filter($gorevler, fn($g) => $g['durum'] === 'tamamlandi'));
$oran = count($gorevler) ? round($tamamGorev / count($gorevler) * 100) : 0;

$icerikler = rows("SELECT * FROM icerikler WHERE proje_id=? ORDER BY tarih DESC LIMIT 30", [$id]);
$onaylar = rows("SELECT o.*, u.ad gonderen_ad FROM onaylar o LEFT JOIN users u ON u.id=o.gonderen_id WHERE o.proje_id=? ORDER BY o.id DESC", [$id]);
$arsivler = rows("SELECT a.*, u.ad yukleyen_ad FROM arsiv a LEFT JOIN users u ON u.id=a.yukleyen_id WHERE a.proje_id=? ORDER BY a.id DESC", [$id]);
$aktiviteler = rows("SELECT a.*, u.ad FROM aktiviteler a JOIN users u ON u.id=a.user_id WHERE (a.ref_tur='proje' AND a.ref_id=?) ORDER BY a.id DESC LIMIT 30", [$id]);
$donemler = $proje['tur'] === 'aylik' ? rows("SELECT d.*, (SELECT COUNT(*) FROM gorevler g WHERE g.donem_id=d.id) gorev_sayi FROM donemler d WHERE d.proje_id=? ORDER BY d.yil DESC, d.ay DESC", [$id]) : [];
$ekip = rows("SELECT id, ad, renk FROM users WHERE rol IN ('yonetici','pm','ekip') AND aktif=1 ORDER BY ad");
$sablonlar = rows("SELECT * FROM akis_sablonlari ORDER BY ad");
$projeUyeleri = rows("SELECT u.id, u.ad, u.renk, u.avatar, u.unvan FROM proje_uyeleri pu JOIN users u ON u.id=pu.user_id WHERE pu.proje_id=? AND u.aktif=1 ORDER BY u.ad", [$id]);

sayfa_basi($proje['ad'], 'projeler');
?>
<div class="satir-esnek mb-3" style="gap:10px">
    <a href="dosya.php?id=<?= $proje['dosya_id'] ?>" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="metin-muted kucuk"><a href="dosya.php?id=<?= $proje['dosya_id'] ?>" style="color:inherit"><?= e($proje['dosya_ad']) ?></a> / <?= e($proje['ad']) ?></span>
</div>

<div class="sayfa-ust">
    <div>
        <div class="satir-esnek" style="gap:10px">
            <span class="rozet rozet-tur"><?= PROJE_TURLERI[$proje['tur']] ?></span>
            <?= rozet($proje['durum'], PROJE_DURUMLARI) ?>
        </div>
        <div class="sayfa-baslik mt-1"><?= e($proje['ad']) ?></div>
        <?php if ($proje['pm_ad']): ?><div class="sayfa-alt">Proje Yöneticisi: <?= e($proje['pm_ad']) ?></div><?php endif; ?>
    </div>
    <?php if (is_staff()): ?>
    <div class="sayfa-ust-aksiyon">
        <a href="rapor.php?proje=<?= $id ?>" target="_blank" class="btn"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 17h6M9 13h6M9 9h1m4 12H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6a1 1 0 01.7.3l5.4 5.4a1 1 0 01.3.7V19a2 2 0 01-2 2z"/></svg> Rapor</a>
        <button class="btn btn-marka" data-modal="modalGorev"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Görev Ekle</button>
        <?php if (yetki('dosya_yonet')): ?><button class="btn" onclick="modalAc('modalProjeDuzen')"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="sekme-kap">
    <div class="sekmeler">
        <button class="sekme aktif" data-sekme="ozet">Özet</button>
        <button class="sekme" data-sekme="gorevler">Görevler <span class="rozet" style="padding:1px 7px"><?= count($gorevler) ?></span></button>
        <?php if ($proje['tur'] === 'aylik'): ?><button class="sekme" data-sekme="donemler">Dönemler</button><?php endif; ?>
        <button class="sekme" data-sekme="onaylar">Onaylar <?php if ($b = count(array_filter($onaylar, fn($o) => $o['durum'] === 'bekliyor'))): ?><span class="rozet r-bekliyor" style="padding:1px 7px"><?= $b ?></span><?php endif; ?></button>
        <button class="sekme" data-sekme="icerik">İçerikler</button>
        <button class="sekme" data-sekme="tartisma">Tartışma <?php if ($yorumSayi = (int)val("SELECT COUNT(*) FROM yorumlar WHERE ref_tur='proje' AND ref_id=?", [$id])): ?><span class="rozet" style="padding:1px 7px"><?= $yorumSayi ?></span><?php endif; ?></button>
        <button class="sekme" data-sekme="arsiv">Arşiv</button>
        <button class="sekme" data-sekme="aktivite">Aktivite</button>
    </div>

    <!-- ÖZET -->
    <div class="sekme-icerik aktif" id="sekme-ozet">
        <div class="stat-izgara">
            <div class="stat-kart"><div class="stat-deger"><?= $oran ?>%</div><div class="stat-etiket">Tamamlanma</div><div class="ilerleme mt-2"><div class="ilerleme-dolu" data-oran="<?= $oran ?>" style="width:0"></div></div></div>
            <div class="stat-kart"><div class="stat-deger" data-sayac="<?= count($gorevler) ?>">0</div><div class="stat-etiket">Toplam Görev</div></div>
            <div class="stat-kart"><div class="stat-deger" data-sayac="<?= count(array_filter($gorevler, fn($g) => in_array($g['durum'], ['devam','incelemede','onayda']))) ?>">0</div><div class="stat-etiket">Devam Eden</div></div>
            <?php if (is_pm() && $proje['sozlesme_tutari'] > 0): ?>
            <div class="stat-kart"><div class="stat-deger" style="font-size:22px"><?= para($proje['sozlesme_tutari']) ?></div><div class="stat-etiket">Sözleşme Tutarı</div></div>
            <?php endif; ?>
        </div>
        <div class="izgara izgara-2">
            <div class="kart">
                <div class="kart-baslik mb-2">Proje Bilgileri</div>
                <div class="dikey mt-2" style="gap:12px">
                    <div class="satir-esnek arasi"><span class="hucre-alt">Dosya</span><span class="hucre-ana"><?= e($proje['dosya_ad']) ?></span></div>
                    <div class="satir-esnek arasi"><span class="hucre-alt">Tür</span><span><?= PROJE_TURLERI[$proje['tur']] ?></span></div>
                    <div class="satir-esnek arasi"><span class="hucre-alt">Başlangıç</span><span><?= tarih($proje['baslangic']) ?></span></div>
                    <div class="satir-esnek arasi"><span class="hucre-alt">Bitiş</span><span><?= tarih($proje['bitis']) ?></span></div>
                    <?php if ($projeUyeleri): ?>
                    <div class="satir-esnek arasi"><span class="hucre-alt">Atanan Ekip</span><?= uye_avatarlari($projeUyeleri) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($proje['aciklama']): ?><div class="mt-3"><div class="hucre-alt mb-2">Açıklama</div><div class="kucuk metin-2"><?= nl2br(e($proje['aciklama'])) ?></div></div><?php endif; ?>
            </div>
            <div class="kart">
                <div class="kart-baslik mb-2">Yaklaşan Görevler</div>
                <?php $yaklasan = array_filter($gorevler, fn($g) => $g['durum'] !== 'tamamlandi' && $g['son_tarih']);
                usort($yaklasan, fn($a, $b) => strcmp($a['son_tarih'], $b['son_tarih']));
                $yaklasan = array_slice($yaklasan, 0, 5);
                if (!$yaklasan): ?><div class="metin-muted kucuk mt-2">Tarihi belirlenmiş görev yok.</div>
                <?php else: foreach ($yaklasan as $gr): $gecikti = $gr['son_tarih'] < date('Y-m-d'); ?>
                <a href="gorev.php?id=<?= $gr['id'] ?>" class="satir-esnek arasi" style="padding:10px 0;border-bottom:1px solid var(--border)">
                    <span class="kucuk kalin" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($gr['baslik']) ?></span>
                    <span class="rozet <?= $gecikti ? 'r-acil' : 'r-normal' ?>"><?= tarih($gr['son_tarih']) ?></span>
                </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <?php if (is_musteri()):
        $tamamlananlar = array_filter($gorevler, fn($g) => $g['durum'] === 'tamamlandi');
        $gorevPuanlari = array_column(rows("SELECT ref_id, puan FROM puanlar WHERE ref_tur='gorev' AND user_id=? AND proje_id=?", [$u['id'], $id]), 'puan', 'ref_id');
        if ($tamamlananlar): ?>
    <!-- Müşteri: tamamlanan işleri değerlendir -->
    <div class="kart mt-3" id="sekme-ozet-puanlama">
        <div class="kart-baslik mb-2"><?= ikon('yildiz', 16) ?> Tamamlanan İşleri Değerlendirin</div>
        <div class="hucre-alt mb-3">Görüşleriniz hizmet kalitemizi doğrudan şekillendirir.</div>
        <div class="dikey" style="gap:6px">
            <?php foreach (array_slice($tamamlananlar, 0, 10) as $tg): ?>
            <div class="satir-esnek arasi" style="padding:9px 12px;background:var(--surface-2);border-radius:10px;gap:10px">
                <span class="kucuk kalin" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($tg['baslik']) ?></span>
                <?php if (isset($gorevPuanlari[$tg['id']])): ?>
                <button class="btn btn-sm" onclick="puanVer('gorev', <?= $tg['id'] ?>, '<?= e($tg['baslik']) ?>')" title="Puanı güncelle"><?= yildizlar((float)$gorevPuanlari[$tg['id']], 13) ?></button>
                <?php else: ?>
                <button class="btn btn-marka btn-sm" onclick="puanVer('gorev', <?= $tg['id'] ?>, '<?= e($tg['baslik']) ?>')" style="flex-shrink:0"><?= ikon('yildiz', 13) ?> Değerlendir</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; endif; ?>

    <!-- GÖREVLER -->
    <div class="sekme-icerik" id="sekme-gorevler">
        <div class="satir-esnek arasi mb-2">
            <div class="metin-muted kucuk">Görevleri sürükleyerek durumlarını değiştirebilirsiniz</div>
            <a href="gorevler.php?proje=<?= $id ?>" class="btn btn-sm">Tam Kanban Görünümü →</a>
        </div>
        <?php gorev_kanban($gorevler, $id); ?>
    </div>

    <?php if ($proje['tur'] === 'aylik'): ?>
    <!-- DÖNEMLER -->
    <div class="sekme-icerik" id="sekme-donemler">
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik">Aylık Dönemler</div>
            <?php if (is_staff()): ?><button class="btn btn-marka btn-sm" data-modal="modalDonem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Dönem Aç</button><?php endif; ?>
        </div>
        <?php if (!$donemler): ?>
        <div class="bos-durum"><div class="bos-ikon"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div><div class="bos-baslik">Henüz dönem açılmamış</div><div class="bos-metin">Aylık düzenli hizmet için ilk dönemi açın; şablondan görevler otomatik oluşturulabilir.</div></div>
        <?php else: ?>
        <div class="izgara izgara-3">
            <?php foreach ($donemler as $d): ?>
            <a href="gorevler.php?proje=<?= $id ?>&donem=<?= $d['id'] ?>" class="kart kart-tik">
                <div class="satir-esnek arasi mb-2"><div class="kart-baslik" style="font-size:15px"><?= donem_ad($d) ?></div><?= rozet($d['durum'], ['acik' => 'Açık', 'kapali' => 'Kapalı']) ?></div>
                <div class="hucre-alt"><?= $d['gorev_sayi'] ?> görev</div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ONAYLAR -->
    <div class="sekme-icerik" id="sekme-onaylar">
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik">Onay Süreçleri</div>
            <?php if (is_staff()): ?><button class="btn btn-marka btn-sm" data-modal="modalOnay"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Onaya Gönder</button><?php endif; ?>
        </div>
        <?php if (!$onaylar): ?>
        <div class="metin-muted kucuk orta kart" style="padding:30px">Henüz onay süreci başlatılmamış.</div>
        <?php else: foreach ($onaylar as $o): ?>
        <div class="kart mb-2">
            <div class="satir-esnek arasi">
                <div style="min-width:0">
                    <div class="satir-esnek" style="gap:9px"><span class="kalin"><?= e($o['baslik']) ?></span><?= rozet($o['durum'], ONAY_DURUMLARI) ?></div>
                    <?php if ($o['aciklama']): ?><div class="hucre-alt mt-1"><?= e($o['aciklama']) ?></div><?php endif; ?>
                    <div class="hucre-alt mt-1"><?= e($o['gonderen_ad']) ?> · <?= zaman_once($o['created']) ?><?php if ($o['arsiv_id']): $ar = row("SELECT * FROM arsiv WHERE id=?", [$o['arsiv_id']]); if ($ar): ?> · <a href="uploads/<?= e($ar['dosya_yolu']) ?>" target="_blank" style="color:var(--marka)"><?= ikon('atac', 12) ?> <?= e($ar['ad']) ?></a><?php endif; endif; ?></div>
                    <?php if ($o['cevap_notu']): ?><div class="mt-2" style="padding:10px 14px;background:var(--surface-2);border-radius:10px;font-size:13px"><b>Müşteri notu:</b> <?= nl2br(e($o['cevap_notu'])) ?></div><?php endif; ?>
                </div>
                <?php if ($o['durum'] === 'bekliyor' && (is_musteri() || is_admin())): ?>
                <div class="satir-esnek" style="gap:6px;flex-shrink:0">
                    <button class="btn btn-sm" style="color:var(--basari)" data-eylem="onay_cevap" data-id="<?= $o['id'] ?>" data-durum="onaylandi">Onayla</button>
                    <button class="btn btn-sm" onclick="onayNot(<?= $o['id'] ?>,'revize')">Revize</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- İÇERİKLER -->
    <div class="sekme-icerik" id="sekme-icerik">
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik">İçerikler</div>
            <a href="icerik-takvimi.php?proje=<?= $id ?>" class="btn btn-sm">Takvim Görünümü →</a>
        </div>
        <?php if (!$icerikler): ?>
        <div class="metin-muted kucuk orta kart" style="padding:30px">Bu proje için içerik planlanmamış.</div>
        <?php else: ?>
        <div class="tablo-sar"><table class="tablo"><thead><tr><th>İçerik</th><th>Platform</th><th>Tarih</th><th>Durum</th></tr></thead><tbody>
            <?php foreach ($icerikler as $ic): ?>
            <tr><td class="hucre-ana"><?= e($ic['baslik']) ?></td><td><?= platform_rozetleri($ic['platform']) ?></td><td><?= tarih($ic['tarih']) ?></td><td><?= rozet($ic['durum'], ICERIK_DURUMLARI) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>

    <!-- TARTIŞMA -->
    <div class="sekme-icerik" id="sekme-tartisma">
        <div class="kart">
            <div class="kart-baslik mb-2">Proje Tartışması</div>
            <div class="hucre-alt mb-3">Ekip ve müşteri bu alanda proje hakkında konuşabilir. @ yazarak birini etiketleyin.</div>
            <?php yorum_akisi('proje', $id); ?>
        </div>
    </div>

    <!-- ARŞİV -->
    <div class="sekme-icerik" id="sekme-arsiv">
        <div class="satir-esnek arasi mb-3">
            <div class="kart-baslik">Dosya Arşivi</div>
            <button class="btn btn-marka btn-sm" data-modal="modalYukle"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Dosya Yükle</button>
        </div>
        <?php if (!$arsivler): ?>
        <div class="metin-muted kucuk orta kart" style="padding:30px">Arşivde dosya yok.</div>
        <?php else: ?>
        <div class="izgara izgara-auto">
            <?php foreach ($arsivler as $a): ?>
            <div class="kart" style="padding:14px">
                <div class="satir-esnek arasi">
                    <a href="uploads/<?= e($a['dosya_yolu']) ?>" target="_blank" class="satir-esnek" style="gap:10px;min-width:0">
                        <div class="dosya-avatar" style="width:36px;height:36px;font-size:11px;background:var(--parlak);color:var(--marka)"><?= e(mb_strtoupper($a['uzanti'] ?: '?')) ?></div>
                        <div style="min-width:0"><div class="hucre-ana kucuk" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($a['ad']) ?></div><div class="hucre-alt"><?= boyut_format($a['boyut']) ?> · <?= zaman_once($a['created']) ?></div></div>
                    </a>
                    <?php if (is_staff()): ?><button class="ikon-eylem tehlike" data-eylem="arsiv_sil" data-id="<?= $a['id'] ?>" data-onay="Silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M19 7l-.9 12a2 2 0 01-2 1.9H7.9a2 2 0 01-2-1.9L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/></svg></button><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- AKTİVİTE -->
    <div class="sekme-icerik" id="sekme-aktivite">
        <div class="kart">
            <div class="kart-baslik mb-3">Proje Geçmişi</div>
            <?php if (!$aktiviteler): ?><div class="metin-muted kucuk">Henüz aktivite yok.</div>
            <?php else: ?><div class="zaman-tunel"><?php foreach ($aktiviteler as $a): ?>
            <div class="tunel-oge"><div class="tunel-metin"><b><?= e($a['ad']) ?></b> <?= e($a['aciklama']) ?></div><div class="tunel-zaman"><?= tarih($a['created'], true) ?></div></div>
            <?php endforeach; ?></div><?php endif; ?>
        </div>
    </div>
</div>

<?php
// ---- Modallar ----
if (is_staff()) gorev_modali($id, $ekip, $sablonlar, $donemler);
?>

<!-- Onaya gönder modalı -->
<div class="modal-katman" id="modalOnay">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Müşteri Onayına Gönder</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="onay_gonder" data-yenile="evet">
        <input type="hidden" name="proje_id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık <span class="zorunlu">*</span></label><input name="baslik" class="girdi" required placeholder="Örn. Ekim ayı 1. gönderi tasarımı"></div>
            <div class="form-grup"><label class="form-etiket">Açıklama / Not</label><textarea name="aciklama" class="metin-alani" placeholder="Müşteriye iletmek istedikleriniz..."></textarea></div>
            <div class="form-grup"><label class="form-etiket">Dosya Eki</label><input type="file" name="dosya" class="girdi"><div class="form-ipucu">Görsel, PDF, video vb. (max 50MB)</div></div>
            <div class="form-grup"><label class="form-etiket">veya Drive Linki</label><input name="drive_link" class="girdi" placeholder="https://drive.google.com/..."></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Onaya Gönder</button></div>
    </form></div>
</div>

<!-- Dosya yükle modalı -->
<div class="modal-katman" id="modalYukle">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Dosya Yükle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="arsiv_yukle" data-yenile="evet">
        <input type="hidden" name="proje_id" value="<?= $id ?>">
        <div class="modal-govde"><div class="form-grup"><label class="form-etiket">Dosya Seç <span class="zorunlu">*</span></label><input type="file" name="dosya" class="girdi" required></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Yükle</button></div>
    </form></div>
</div>

<?php if ($proje['tur'] === 'aylik' && is_staff()): ?>
<!-- Dönem aç modalı -->
<div class="modal-katman" id="modalDonem">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Dönem Aç</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="donem_ac" data-yenile="evet">
        <input type="hidden" name="proje_id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Ay</label><select name="ay" class="secim"><?php foreach (AYLAR as $k => $v): ?><option value="<?= $k ?>" <?= $k == date('n') ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Yıl</label><select name="yil" class="secim"><?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?><option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Akış Şablonundan Görev Oluştur</label><select name="sablon_id" class="secim"><option value="">Boş dönem</option><?php foreach ($sablonlar as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['ad']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilen şablonun adımları görev akışı olarak eklenir.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Dönemi Aç</button></div>
    </form></div>
</div>
<?php endif; ?>

<?php if (yetki('dosya_yonet')):
$dosyalar = rows("SELECT id, ad FROM dosyalar WHERE durum='aktif' ORDER BY ad");
$pmler = rows("SELECT id, ad FROM users WHERE rol IN ('yonetici','pm') AND aktif=1 ORDER BY ad"); ?>
<!-- Proje düzenle -->
<div class="modal-katman" id="modalProjeDuzen">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Projeyi Düzenle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="proje_kaydet">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Proje Adı</label><input name="ad" class="girdi" value="<?= e($proje['ad']) ?>" required></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Dosya</label><select name="dosya_id" class="secim"><?php foreach ($dosyalar as $d): ?><option value="<?= $d['id'] ?>" <?= $d['id'] == $proje['dosya_id'] ? 'selected' : '' ?>><?= e($d['ad']) ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">Tür</label><select name="tur" class="secim"><?php foreach (PROJE_TURLERI as $k => $v): ?><option value="<?= $k ?>" <?= $proje['tur'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Durum</label><select name="durum" class="secim"><?php foreach (PROJE_DURUMLARI as $k => $v): ?><option value="<?= $k ?>" <?= $proje['durum'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="form-grup"><label class="form-etiket">PM</label><select name="pm_id" class="secim"><option value="">—</option><?php foreach ($pmler as $pm): ?><option value="<?= $pm['id'] ?>" <?= $pm['id'] == $proje['pm_id'] ? 'selected' : '' ?>><?= e($pm['ad']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Başlangıç</label><input type="date" name="baslangic" class="girdi" value="<?= e($proje['baslangic']) ?>"></div>
                <div class="form-grup"><label class="form-etiket">Bitiş</label><input type="date" name="bitis" class="girdi" value="<?= e($proje['bitis']) ?>"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Sözleşme Tutarı (₺)</label><input name="sozlesme_tutari" class="girdi" value="<?= $proje['sozlesme_tutari'] ?>"></div>
            <?php uye_secici(array_column($projeUyeleri, 'id')); ?>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="aciklama" class="metin-alani"><?= e($proje['aciklama']) ?></textarea></div>
        </div>
        <div class="modal-alt">
            <?php if (is_admin()): ?><button type="button" class="btn btn-tehlike" data-eylem="proje_sil" data-id="<?= $id ?>" data-onay="Proje ve tüm görevleri silinecek. Emin misiniz?" style="margin-right:auto">Sil</button><?php endif; ?>
            <button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button>
        </div>
    </form></div>
</div>
<?php endif; ?>

<?php puan_modali(); ?>

<!-- Onay notu (revize) modalı -->
<div class="modal-katman" id="modalOnayNot">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Revize / Not Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="onay_cevap">
        <input type="hidden" name="id" id="onayNotId"><input type="hidden" name="durum" id="onayNotDurum">
        <div class="modal-govde"><div class="form-grup"><label class="form-etiket">Notunuz</label><textarea name="not" class="metin-alani" required placeholder="Değişiklik taleplerinizi yazın..."></textarea></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Gönder</button></div>
    </form></div>
</div>
<script>
function onayNot(id, durum) { document.getElementById('onayNotId').value = id; document.getElementById('onayNotDurum').value = durum; modalAc('modalOnayNot'); }
</script>

<?php
sayfa_sonu();

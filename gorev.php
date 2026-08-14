<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bilesenler.php';
$u = require_staff();

$id = (int)($_GET['id'] ?? 0);
$gorev = row("SELECT g.*, p.ad proje_ad, p.dosya_id, d.ad dosya_ad, uu.ad atanan_ad, uu.renk atanan_renk, ol.ad olusturan_ad
    FROM gorevler g JOIN projeler p ON p.id=g.proje_id JOIN dosyalar d ON d.id=p.dosya_id
    LEFT JOIN users uu ON uu.id=g.atanan_id LEFT JOIN users ol ON ol.id=g.olusturan_id WHERE g.id=?", [$id]);
if (!$gorev) { header('Location: gorevler.php'); exit; }

$adimlar = rows("SELECT ga.*, u.ad sorumlu_ad, u.renk sorumlu_renk FROM gorev_adimlari ga LEFT JOIN users u ON u.id=ga.sorumlu_id WHERE ga.gorev_id=? ORDER BY ga.sira", [$id]);
$zamanlar = rows("SELECT z.*, u.ad FROM zaman_kayitlari z JOIN users u ON u.id=z.user_id WHERE z.gorev_id=? ORDER BY z.tarih DESC, z.id DESC", [$id]);
$toplamDk = (int)val("SELECT COALESCE(SUM(dakika),0) FROM zaman_kayitlari WHERE gorev_id=?", [$id]);
$ekip = rows("SELECT id, ad, renk FROM users WHERE rol IN ('yonetici','pm','ekip') AND aktif=1 ORDER BY ad");
$kontroller = rows("SELECT * FROM gorev_kontrol WHERE gorev_id=? ORDER BY sira", [$id]);
$bagimli = $gorev['bagimli_id'] ? row("SELECT id, baslik, durum FROM gorevler WHERE id=?", [$gorev['bagimli_id']]) : null;
$projeGorevleri = rows("SELECT id, baslik FROM gorevler WHERE proje_id=? AND id!=? AND durum!='tamamlandi' ORDER BY baslik", [$gorev['proje_id'], $id]);
$ekler = rows("SELECT a.*, us.ad yukleyen_ad FROM arsiv a LEFT JOIN users us ON us.id=a.yukleyen_id WHERE a.gorev_id=? ORDER BY a.id DESC", [$id]);
$atananlar = rows("SELECT us.id, us.ad, us.renk, us.avatar FROM gorev_atananlar ga JOIN users us ON us.id=ga.user_id WHERE ga.gorev_id=? ORDER BY us.ad", [$id]);
if (!$atananlar && $gorev['atanan_id'] && $gorev['atanan_ad']) $atananlar = [['id' => $gorev['atanan_id'], 'ad' => $gorev['atanan_ad'], 'renk' => $gorev['atanan_renk'], 'avatar' => null]];
$atananIdler = array_column($atananlar, 'id');
$izleyiciler = rows("SELECT us.id, us.ad, us.renk, us.avatar FROM gorev_izleyiciler gi JOIN users us ON us.id=gi.user_id WHERE gi.gorev_id=? ORDER BY us.ad", [$id]);
$izleyiciIdler = array_column($izleyiciler, 'id');

$aktifAdimIndex = -1;
foreach ($adimlar as $i => $a) { if ($a['durum'] === 'aktif') { $aktifAdimIndex = $i; break; } }

sayfa_basi($gorev['baslik'], 'gorevler');
?>
<div class="satir-esnek mb-3" style="gap:10px">
    <a href="proje.php?id=<?= $gorev['proje_id'] ?>#gorevler" class="ikon-eylem"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></a>
    <span class="metin-muted kucuk"><?= e($gorev['dosya_ad']) ?> / <a href="proje.php?id=<?= $gorev['proje_id'] ?>" style="color:inherit"><?= e($gorev['proje_ad']) ?></a></span>
</div>

<div class="sayfa-ust">
    <div>
        <div class="satir-esnek sarma" style="gap:9px">
            <?= rozet($gorev['durum'], GOREV_DURUMLARI) ?>
            <?= rozet($gorev['oncelik'], ONCELIKLER, 'oncelik') ?>
            <?php if ($gorev['tekrar'] !== 'yok'): ?><span class="rozet rozet-tur"><?= ikon('tekrar', 12) ?> <?= TEKRARLAR[$gorev['tekrar']] ?></span><?php endif; ?>
            <?php if ($bagimli && $bagimli['durum'] !== 'tamamlandi' && !$gorev['kilit_acik']): ?>
            <span class="kilit-rozet" title="Bağlı olduğu görev tamamlanmadan ilerleyemez"><?= ikon('kilit', 12) ?> <a href="gorev.php?id=<?= $bagimli['id'] ?>" style="color:inherit;text-decoration:underline"><?= e(mb_substr($bagimli['baslik'], 0, 34)) ?></a> bekleniyor</span>
            <?php elseif ($gorev['kilit_acik']): ?>
            <span class="rozet r-bekliyor" title="Yönetici kilidi devre dışı bıraktı"><?= ikon('kilit-acik', 12) ?> Kilit devre dışı</span>
            <?php endif; ?>
            <?= etiket_cipleri($gorev['etiketler']) ?>
        </div>
        <div class="sayfa-baslik mt-1"><?= e($gorev['baslik']) ?></div>
    </div>
    <div class="sayfa-ust-aksiyon">
        <select class="secim" style="width:auto;min-width:160px" id="durumSecici" onchange="durumDegistir(this.value)">
            <?php foreach (GOREV_DURUMLARI as $k => $v): ?><option value="<?= $k ?>" <?= $gorev['durum'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
        <button class="btn" onclick="modalAc('modalGorevDuzen')"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.4a2 2 0 112.8 2.8L12 15l-4 1 1-4 9.6-9.6z"/></svg></button>
    </div>
</div>

<?php if ($adimlar): ?>
<!-- GÖREV AKIŞI RAYI -->
<div class="kart mb-3">
    <div class="satir-esnek arasi mb-3"><div class="kart-baslik">İş Akışı</div><span class="metin-muted kucuk" id="adimSayac"><?= count(array_filter($adimlar, fn($a) => $a['durum'] === 'tamam')) ?>/<?= count($adimlar) ?> adım tamamlandı</span></div>
    <div class="akis-ray">
        <?php foreach ($adimlar as $i => $a): ?>
        <div class="akis-adim <?= $a['durum'] === 'tamam' ? 'tamam' : ($a['durum'] === 'aktif' ? 'aktif' : '') ?>" data-adim="<?= $a['id'] ?>" data-sira="<?= $i + 1 ?>">
            <div class="akis-cizgi"></div>
            <div class="akis-adim-ic">
                <button class="akis-yuvarlak" onclick="adimTamamla(<?= $a['id'] ?>)" title="Tamamla / geri al">
                    <?php if ($a['durum'] === 'tamam'): ?><svg width="20" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg><?php else: ?><?= $i + 1 ?><?php endif; ?>
                </button>
                <div class="akis-ad"><?= e($a['ad']) ?></div>
                <button class="akis-sorumlu" onclick="adimSorumlu(<?= $a['id'] ?>)" style="cursor:pointer"><?= $a['sorumlu_ad'] ? e(explode(' ', $a['sorumlu_ad'])[0]) : '+ sorumlu' ?></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="izgara" style="grid-template-columns:1fr 300px">
    <div>
        <div class="kart mb-3">
            <div class="kart-baslik mb-2">Açıklama</div>
            <div class="metin-2" style="white-space:pre-wrap"><?= $gorev['aciklama'] ? e($gorev['aciklama']) : '<span class="metin-muted kucuk">Açıklama eklenmemiş.</span>' ?></div>
        </div>

        <!-- Kontrol Listesi -->
        <div class="kart mb-3">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik">Kontrol Listesi</div>
                <span class="metin-muted kucuk" id="kontrolSayac"><?= $kontroller ? count(array_filter($kontroller, fn($k) => $k['tamam'])) . '/' . count($kontroller) : '' ?></span>
            </div>
            <div class="ilerleme mb-2" <?= $kontroller ? '' : 'style="display:none"' ?>><div class="ilerleme-dolu" id="kontrolBar" data-oran="<?= $kontroller ? round(count(array_filter($kontroller, fn($k) => $k['tamam'])) / count($kontroller) * 100) : 0 ?>" style="width:0"></div></div>
            <div class="dikey" style="gap:2px" id="kontrolListe">
                <?php foreach ($kontroller as $k): ?>
                <div class="kontrol-oge <?= $k['tamam'] ? 'tamam' : '' ?>">
                    <input type="checkbox" <?= $k['tamam'] ? 'checked' : '' ?> onchange="kontrolToggle(<?= $k['id'] ?>, this)">
                    <span class="kontrol-metin"><?= e($k['ad']) ?></span>
                    <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-eylem="kontrol_sil" data-id="<?= $k['id'] ?>" data-onay="Madde silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <?php endforeach; ?>
                <?php if (!$kontroller): ?><div class="metin-muted kucuk" style="padding:6px 0">Henüz madde yok. Görevi küçük adımlara bölün.</div><?php endif; ?>
            </div>
            <form class="satir-esnek mt-2" style="gap:8px" onsubmit="return kontrolEkle(event)">
                <input class="girdi" id="kontrolYeni" placeholder="Yeni madde ekle...">
                <button type="submit" class="btn btn-sm">Ekle</button>
            </form>
        </div>

        <!-- Ekler -->
        <div class="kart mb-3">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik">Ekler <?php if ($ekler): ?><span class="rozet" style="padding:1px 8px"><?= count($ekler) ?></span><?php endif; ?></div>
                <span class="ekler-aksiyon">
                <button class="btn btn-sm" onclick="modalAc('modalDriveLink')" type="button"><?= ikon('web', 13) ?> Drive Linki</button>
                <form data-ajax="arsiv_yukle" style="display:inline">
                    <input type="hidden" name="gorev_id" value="<?= $id ?>"><input type="hidden" name="proje_id" value="<?= $gorev['proje_id'] ?>">
                    <label class="btn btn-sm" style="cursor:pointer"><?= ikon('atac', 14) ?> Dosya Ekle<input type="file" name="dosya" style="display:none" onchange="this.closest('form').requestSubmit()"></label>
                </form>
                </span>
            </div>
            <?php if (!$ekler): ?><div class="metin-muted kucuk">Henüz ek yok. Brief, görsel veya video ekleyin.</div>
            <?php else: ?>
            <div class="izgara izgara-2" style="gap:8px">
                <?php foreach ($ekler as $ek):
                    $gorselMi = in_array($ek['uzanti'], ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                    $linkMi = !empty($ek['url']);
                    $ekHref = $linkMi ? $ek['url'] : 'uploads/' . $ek['dosya_yolu']; ?>
                <div class="satir-esnek arasi" style="padding:8px 10px;background:var(--surface-2);border-radius:10px">
                    <a href="<?= e($ekHref) ?>" target="_blank" class="satir-esnek" style="gap:9px;min-width:0">
                        <?php if ($gorselMi): ?><span style="width:34px;height:34px;border-radius:8px;background:url('uploads/<?= e($ek['dosya_yolu']) ?>') center/cover;flex-shrink:0"></span>
                        <?php elseif ($linkMi): ?><span class="dosya-avatar" style="width:34px;height:34px;background:var(--parlak);color:var(--marka)"><?= ikon('web', 16) ?></span>
                        <?php else: ?><span class="dosya-avatar" style="width:34px;height:34px;font-size:10px;background:var(--parlak);color:var(--marka)"><?= e(mb_strtoupper($ek['uzanti'] ?: '?')) ?></span><?php endif; ?>
                        <div style="min-width:0"><div class="kucuk kalin" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($ek['ad']) ?></div><div class="hucre-alt"><?= $linkMi ? 'Drive bağlantısı' : boyut_format($ek['boyut']) ?></div></div>
                    </a>
                    <button class="ikon-eylem tehlike" style="width:26px;height:26px" data-eylem="arsiv_sil" data-id="<?= $ek['id'] ?>" data-onay="Ek silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="kart">
            <div class="kart-baslik mb-2">Yorumlar</div>
            <?php yorum_akisi('gorev', $id); ?>
        </div>
    </div>

    <div>
        <div class="kart mb-2">
            <div class="dikey" style="gap:14px">
                <div><div class="hucre-alt mb-2">Atananlar</div>
                    <?php if (!$atananlar): ?><span class="metin-muted kucuk">Atanmamış</span>
                    <?php else: foreach ($atananlar as $at): ?>
                    <div class="satir-esnek mt-1" style="gap:9px"><?= avatar($at, 28) ?><span class="kucuk kalin"><?= e($at['ad']) ?></span></div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="satir-esnek arasi"><span class="hucre-alt">Başlangıç</span><span class="kucuk"><?= tarih($gorev['baslangic_tarihi']) ?></span></div>
                <div class="satir-esnek arasi"><span class="hucre-alt">Son Tarih</span><span class="kucuk kalin" style="<?= $gorev['son_tarih'] && $gorev['son_tarih'] < date('Y-m-d') && $gorev['durum'] !== 'tamamlandi' ? 'color:var(--tehlike)' : '' ?>"><?= tarih($gorev['son_tarih']) ?></span></div>
                <?php if ($gorev['tahmini_dakika'] > 0): ?>
                <div class="satir-esnek arasi"><span class="hucre-alt">Tahmin / Gerçek</span><span class="kucuk kalin" style="<?= $toplamDk > $gorev['tahmini_dakika'] ? 'color:var(--tehlike)' : '' ?>"><?= dakika_format((int)$gorev['tahmini_dakika']) ?> / <?= dakika_format($toplamDk) ?></span></div>
                <?php endif; ?>
                <div class="satir-esnek arasi"><span class="hucre-alt">Oluşturan</span><span class="kucuk"><?= e($gorev['olusturan_ad'] ?? '—') ?></span></div>
                <div class="satir-esnek arasi"><span class="hucre-alt">Oluşturulma</span><span class="kucuk"><?= tarih($gorev['created']) ?></span></div>
            </div>
        </div>

        <?php if ($gorev['icerik_id']):
            $bagliIcerik = row("SELECT * FROM icerikler WHERE id=?", [$gorev['icerik_id']]);
            if ($bagliIcerik): ?>
        <!-- Bağlı içerik -->
        <div class="kart mb-2">
            <div class="kart-baslik mb-2" style="font-size:14px"><?= ikon('takvim', 15) ?> Bağlı İçerik</div>
            <div class="kucuk kalin"><?= e($bagliIcerik['baslik']) ?></div>
            <div class="satir-esnek sarma mt-1" style="gap:5px"><?= platform_rozetleri($bagliIcerik['platform']) ?></div>
            <div class="satir-esnek arasi mt-2">
                <span class="hucre-alt">Yayın: <?= tarih($bagliIcerik['tarih']) ?></span>
                <?= rozet($bagliIcerik['durum'], ICERIK_DURUMLARI) ?>
            </div>
            <a href="icerik-takvimi.php?ay=<?= date('n', strtotime($bagliIcerik['tarih'])) ?>&yil=<?= date('Y', strtotime($bagliIcerik['tarih'])) ?>" class="mini-btn mt-2" style="display:inline-block">İçerik takviminde gör →</a>
        </div>
        <?php endif; endif; ?>

        <!-- İzleyiciler -->
        <div class="kart mb-2">
            <div class="satir-esnek arasi mb-2">
                <div class="kart-baslik" style="font-size:14px">İzleyiciler</div>
                <div class="acilir" data-acilir>
                    <button class="mini-btn" data-acilir-btn>+ Ekle</button>
                    <div class="acilir-panel">
                        <?php foreach ($ekip as $k): if (in_array($k['id'], $izleyiciIdler)) continue; ?>
                        <button class="acilir-oge" style="width:100%;text-align:left" data-eylem="izleyici_toggle" data-gorev_id="<?= $id ?>" data-user_id="<?= $k['id'] ?>"><?= e($k['ad']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php if (!$izleyiciler): ?><div class="metin-muted kucuk">İzleyici yok. Eklenenler görevdeki her gelişmede bildirim alır.</div>
            <?php else: foreach ($izleyiciler as $iz): ?>
            <div class="satir-esnek arasi mt-1" style="padding:5px 0">
                <div class="satir-esnek" style="gap:9px"><?= avatar($iz, 26) ?><span class="kucuk"><?= e($iz['ad']) ?></span></div>
                <button class="ikon-eylem tehlike" style="width:24px;height:24px" data-eylem="izleyici_toggle" data-gorev_id="<?= $id ?>" data-user_id="<?= $iz['id'] ?>" title="Çıkar"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="12"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Zaman takibi -->
        <div class="kart">
            <div class="satir-esnek arasi mb-2"><div class="kart-baslik" style="font-size:14px">Zaman Takibi</div><button class="btn btn-sm" data-modal="modalZaman"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button></div>
            <div class="orta" style="padding:8px 0"><div class="stat-deger" style="font-size:26px"><?= dakika_format($toplamDk) ?></div><div class="hucre-alt">toplam kayıtlı süre</div></div>
            <?php if ($zamanlar): ?><div class="dikey mt-2" style="gap:8px;max-height:200px;overflow-y:auto">
                <?php foreach ($zamanlar as $z): ?>
                <div class="satir-esnek arasi kucuk" style="padding:7px 0;border-bottom:1px solid var(--border)"><div><div class="kalin"><?= dakika_format($z['dakika']) ?></div><div class="hucre-alt"><?= e($z['ad']) ?> · <?= tarih($z['tarih']) ?></div></div></div>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </div>
    </div>
</div>

<!-- Drive linki ekle -->
<div class="modal-katman" id="modalDriveLink">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Drive Linki Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="arsiv_link_ekle">
        <input type="hidden" name="gorev_id" value="<?= $id ?>"><input type="hidden" name="proje_id" value="<?= $gorev['proje_id'] ?>">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Bağlantı Adı</label><input name="ad" class="girdi" placeholder="Örn. Kurgu v2 — final klasörü"></div>
            <div class="form-grup"><label class="form-etiket">Drive Linki <span class="zorunlu">*</span></label><input name="url" class="girdi" required placeholder="https://drive.google.com/..."><div class="form-ipucu">İş teslimlerinde dosya yüklemek yerine Drive klasör/dosya linki bırakın.</div></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Ekle</button></div>
    </form></div>
</div>

<!-- Modallar -->
<div class="modal-katman" id="modalZaman">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Zaman Kaydı Ekle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="zaman_ekle" data-yenile="evet">
        <input type="hidden" name="gorev_id" value="<?= $id ?>">
        <div class="modal-govde">
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Saat</label><input type="number" name="saat" class="girdi" min="0" value="0"></div>
                <div class="form-grup"><label class="form-etiket">Dakika</label><input type="number" name="dakika" class="girdi" min="0" max="59" value="30"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Tarih</label><input type="date" name="tarih" class="girdi" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><input name="aciklama" class="girdi" placeholder="Ne üzerinde çalıştınız?"></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button></div>
    </form></div>
</div>

<div class="modal-katman" id="modalGorevDuzen">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Görevi Düzenle</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="gorev_kaydet">
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="proje_id" value="<?= $gorev['proje_id'] ?>">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Başlık</label><input name="baslik" class="girdi" value="<?= e($gorev['baslik']) ?>" required></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="aciklama" class="metin-alani"><?= e($gorev['aciklama']) ?></textarea></div>
            <div class="form-grup">
                <label class="form-etiket">Atanan Kişiler <span class="metin-muted" style="font-weight:400">(birden fazla seçilebilir)</span></label>
                <input type="hidden" name="atananlar" class="atananlar-json">
                <div class="izgara izgara-2" style="gap:6px;max-height:150px;overflow-y:auto;padding:2px">
                    <?php foreach ($ekip as $k): ?>
                    <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="atanan-kutu" value="<?= $k['id'] ?>" <?= in_array($k['id'], $atananIdler) ? 'checked' : '' ?>> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($k['ad']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-grup"><label class="form-etiket">Öncelik</label><select name="oncelik" class="secim"><?php foreach (ONCELIKLER as $k => $v): ?><option value="<?= $k ?>" <?= $gorev['oncelik'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Başlangıç Tarihi</label><input type="date" name="baslangic_tarihi" class="girdi" value="<?= e($gorev['baslangic_tarihi']) ?>"></div>
                <div class="form-grup"><label class="form-etiket">Son Tarih</label><input type="date" name="son_tarih" class="girdi" value="<?= e($gorev['son_tarih']) ?>"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tahmini Süre (saat)</label><input name="tahmini_saat" class="girdi" value="<?= $gorev['tahmini_dakika'] ? round($gorev['tahmini_dakika'] / 60, 1) : '' ?>" placeholder="Örn. 4,5"></div>
                <div class="form-grup"><label class="form-etiket">Tekrar</label><select name="tekrar" class="secim"><?php foreach (TEKRARLAR as $k => $v): ?><option value="<?= $k ?>" <?= $gorev['tekrar'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Etiketler</label><input name="etiketler" class="girdi" value="<?= e($gorev['etiketler']) ?>" placeholder="video, instagram, acil-revize (virgülle ayırın)"></div>
            <div class="form-grup"><label class="form-etiket">Bağlı Olduğu Görev</label><select name="bagimli_id" class="secim"><option value="">— Bağımsız</option><?php foreach ($projeGorevleri as $pg): ?><option value="<?= $pg['id'] ?>" <?= $pg['id'] == $gorev['bagimli_id'] ? 'selected' : '' ?>><?= e($pg['baslik']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilen görev tamamlanmadan bu görev ilerleyemez.</div></div>
            <?php if (is_pm()): ?>
            <div class="form-grup">
                <label class="satir-esnek anahtar" style="gap:10px;cursor:pointer">
                    <input type="checkbox" <?= $gorev['kilit_acik'] ? 'checked' : '' ?> onchange="event.preventDefault();kilitToggle()">
                    <span class="kucuk"><b>Kilidi devre dışı bırak</b> — akış ve bağımlılık kuralları bu görev için uygulanmaz (loglanır)</span>
                </label>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-alt">
            <button type="button" class="btn btn-tehlike" data-eylem="gorev_sil" data-id="<?= $id ?>" data-onay="Görev silinsin mi?" data-yonlendir="proje.php?id=<?= $gorev['proje_id'] ?>" style="margin-right:auto">Sil</button>
            <button type="button" class="btn" data-eylem="gorev_arsiv" data-id="<?= $id ?>" data-onay="<?= $gorev['arsivlendi'] ? 'Görev arşivden çıkarılsın mı?' : 'Görev arşive taşınsın mı?' ?>"><?= ikon('kutu', 14) ?> <?= $gorev['arsivlendi'] ? 'Arşivden Çıkar' : 'Arşivle' ?></button>
            <button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Kaydet</button>
        </div>
    </form></div>
</div>

<!-- Adım sorumlu atama -->
<div class="modal-katman" id="modalAdimSorumlu">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Adım Sorumlusu</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="adim_sorumlu" data-yenile="evet">
        <input type="hidden" name="id" id="adimSorumluId">
        <div class="modal-govde"><div class="form-grup"><label class="form-etiket">Sorumlu Kişi</label><select name="sorumlu_id" class="secim"><option value="">— Kaldır</option><?php foreach ($ekip as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['ad']) ?></option><?php endforeach; ?></select></div></div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Ata</button></div>
    </form></div>
</div>

<script>
// Canlı senkron: başka biri bu görevi değiştirirse sayfa tazelenir
window.sadaCanli = { baglam: 'gorev', id: <?= $id ?>, hash: '<?= canli_hash_gorev($id) ?>' };
const CHECK_SVG = '<svg width="20" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>';

async function durumDegistir(durum) {
    const j = await api('gorev_durum', { id: <?= $id ?>, durum });
    if (j.ok) { toast('Durum güncellendi', 'basari'); canliYenile(); setTimeout(() => location.reload(), 450); }
    else setTimeout(() => location.reload(), 1600); // kilit reddettiyse eski değere dön
}
function adimSorumlu(id) { document.getElementById('adimSorumluId').value = id; modalAc('modalAdimSorumlu'); }

/* Akış adımı: yenilemesiz güncelleme */
async function adimTamamla(id) {
    const j = await api('adim_tamamla', { id });
    if (!j.ok) return;
    toast(j.mesaj, 'basari', 1800);
    j.adimlar.forEach(a => {
        const el = document.querySelector(`[data-adim="${a.id}"]`);
        if (!el) return;
        el.classList.toggle('tamam', a.durum === 'tamam');
        el.classList.toggle('aktif', a.durum === 'aktif');
        el.querySelector('.akis-yuvarlak').innerHTML = a.durum === 'tamam' ? CHECK_SVG : el.dataset.sira;
    });
    document.getElementById('adimSayac').textContent = j.tamam_adet + '/' + j.toplam + ' adım tamamlandı';
    // Görev tamamlandıysa durum seçicisini ve rozeti eşitle
    const secici = document.getElementById('durumSecici');
    if (secici && secici.value !== j.gorev_durum) { secici.value = j.gorev_durum; toast('Görev durumu: ' + j.gorev_durum_etiket, 'basari', 2400); }
    canliYenile();
}

/* Kontrol listesi: yenilemesiz güncelleme */
function kontrolOzet() {
    const hepsi = document.querySelectorAll('#kontrolListe .kontrol-oge').length;
    const tamam = document.querySelectorAll('#kontrolListe .kontrol-oge.tamam').length;
    document.getElementById('kontrolSayac').textContent = hepsi ? tamam + '/' + hepsi : '';
    const bar = document.getElementById('kontrolBar');
    bar.parentElement.style.display = hepsi ? '' : 'none';
    bar.style.width = (hepsi ? Math.round(tamam / hepsi * 100) : 0) + '%';
}
async function kontrolEkle(e) {
    e.preventDefault();
    const girdi = document.getElementById('kontrolYeni');
    const ad = girdi.value.trim(); if (!ad) return false;
    const j = await api('kontrol_ekle', { gorev_id: <?= $id ?>, ad });
    if (j.ok) {
        girdi.value = '';
        const liste = document.getElementById('kontrolListe');
        const bos = liste.querySelector('.metin-muted'); if (bos) bos.remove();
        const div = document.createElement('div');
        div.className = 'kontrol-oge';
        div.innerHTML = `<input type="checkbox" onchange="kontrolToggle(${j.id}, this)"><span class="kontrol-metin"></span><button class="ikon-eylem tehlike" style="width:26px;height:26px" data-eylem="kontrol_sil" data-id="${j.id}" data-onay="Madde silinsin mi?"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="13"><path d="M6 18L18 6M6 6l12 12"/></svg></button>`;
        div.querySelector('.kontrol-metin').textContent = j.ad;
        liste.appendChild(div);
        kontrolOzet();
        canliYenile();
    }
    return false;
}
async function kontrolToggle(id, kutu) {
    const j = await api('kontrol_toggle', { id });
    if (j.ok) { kutu.closest('.kontrol-oge').classList.toggle('tamam', kutu.checked); kontrolOzet(); canliYenile(); }
    else kutu.checked = !kutu.checked;
}
async function kilitToggle() {
    const j = await api('kilit_toggle', { id: <?= $id ?> });
    if (j.ok) { toast(j.mesaj, 'basari'); canliYenile(); setTimeout(() => location.reload(), 650); }
}
</script>
<?php sayfa_sonu(); ?>

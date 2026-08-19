<?php
/**
 * SADA One — Paylaşımlı görsel bileşenler
 * Birden fazla sayfada kullanılan render fonksiyonları.
 */

/** Kanban panosu render eder */
function gorev_kanban(array $gorevler, int $projeId = 0): void {
    $renkler = ['yapilacak' => 'var(--muted)', 'devam' => 'var(--bilgi)', 'incelemede' => 'var(--uyari)', 'onayda' => '#a58bf0', 'tamamlandi' => 'var(--basari)'];
?>
<div class="kanban">
    <?php foreach (GOREV_DURUMLARI as $durum => $etiket):
        $grup = array_filter($gorevler, fn($g) => $g['durum'] === $durum); ?>
    <div class="kanban-sutun" data-durum="<?= $durum ?>">
        <div class="kanban-sutun-ust"><span class="kanban-nokta" style="background:<?= $renkler[$durum] ?>"></span><span class="kanban-baslik"><?= $etiket ?></span><span class="kanban-sayi"><?= count($grup) ?></span></div>
        <div class="kanban-liste">
            <?php foreach ($grup as $gr):
                // Kilitli mi? (bağımlı görev bitmemiş ve yönetici kilidi açmamış)
                $kilitli = !empty($gr['bagimli_durum']) && $gr['bagimli_durum'] !== 'tamamlandi' && empty($gr['kilit_acik']);
                $surukle = is_staff() ? 'draggable="true"' : ''; ?>
            <div class="kanban-kart <?= $kilitli ? 'kilitli' : '' ?>" <?= $surukle ?> data-gorev="<?= $gr['id'] ?>" data-durum="<?= $durum ?>" <?= $kilitli && !empty($gr['bagimli_baslik']) ? 'title="Kilitli — bağlı olduğu görev: ' . e($gr['bagimli_baslik']) . '"' : '' ?> onclick="if(!event.defaultPrevented)location.href='gorev.php?id=<?= $gr['id'] ?>'">
                <div class="kanban-kart-baslik"><?= e($gr['baslik']) ?></div>
                <?php if (!empty($gr['proje_ad'])): ?><div class="kanban-etiket" style="margin-bottom:6px"><span class="etiket-nokta" style="width:7px;height:7px;background:<?= e($gr['dosya_renk'] ?? 'var(--marka)') ?>"></span><?= e($gr['proje_ad']) ?></div><?php endif; ?>
                <?php if (!empty($gr['etiketler'])): ?><div class="satir-esnek sarma" style="gap:4px;margin-bottom:7px"><?= etiket_cipleri($gr['etiketler']) ?></div><?php endif; ?>
                <div class="kanban-kart-meta">
                    <?php if ($gr['oncelik'] !== 'normal'): ?><?= rozet($gr['oncelik'], ONCELIKLER) ?><?php endif; ?>
                    <?php if (!empty($gr['tekrar']) && $gr['tekrar'] !== 'yok'): ?><span class="kanban-etiket" title="<?= TEKRARLAR[$gr['tekrar']] ?>"><?= ikon('tekrar', 12) ?></span><?php endif; ?>
                    <?php if ($gr['son_tarih']): $gecikti = $gr['son_tarih'] < date('Y-m-d') && $durum !== 'tamamlandi'; ?><span class="kanban-etiket" style="<?= $gecikti ? 'color:var(--tehlike)' : '' ?>"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><?= date('j.n', strtotime($gr['son_tarih'])) ?></span><?php endif; ?>
                    <?php if (!empty($gr['kontrol_toplam'])): ?><span class="kanban-etiket" style="<?= $gr['kontrol_tamam'] == $gr['kontrol_toplam'] ? 'color:var(--basari)' : '' ?>"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2m-6 9l2 2 4-4"/></svg><?= $gr['kontrol_tamam'] ?>/<?= $gr['kontrol_toplam'] ?></span><?php endif; ?>
                </div>
                <?php if (!empty($gr['atanan_ad'])): ?><div class="kanban-kart-alt" <?= !empty($gr['atanan_adlar']) ? 'title="' . e($gr['atanan_adlar']) . '"' : '' ?>><?= avatar(['ad' => $gr['atanan_ad'], 'renk' => $gr['atanan_renk'], 'avatar' => $gr['atanan_avatar'] ?? null], 26) ?><span class="kanban-etiket"><?= e(explode(' ', $gr['atanan_ad'])[0]) ?><?= !empty($gr['atanan_sayi']) && $gr['atanan_sayi'] > 1 ? ' +' . ($gr['atanan_sayi'] - 1) : '' ?></span></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php }

/** Görev ekleme modalı render eder */
function gorev_modali(int $projeId, array $ekip, array $sablonlar, array $donemler = []): void {
?>
<div class="modal-katman" id="modalGorev">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Görev</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="gorev_kaydet">
        <input type="hidden" name="proje_id" value="<?= $projeId ?>" <?= $projeId ? '' : 'disabled' ?> id="gorevProjeId">
        <div class="modal-govde">
            <?php if (!$projeId): ?>
            <div class="form-grup"><label class="form-etiket">Proje <span class="zorunlu">*</span></label><select name="proje_id" class="secim" required id="gorevProjeSecim"><option value="">Seçin...</option><?php foreach (rows("SELECT id, ad FROM projeler WHERE durum='aktif' ORDER BY ad") as $pr): ?><option value="<?= $pr['id'] ?>"><?= e($pr['ad']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="form-grup"><label class="form-etiket">Görev Başlığı <span class="zorunlu">*</span></label><input name="baslik" class="girdi" required></div>
            <div class="form-grup"><label class="form-etiket">Açıklama</label><textarea name="aciklama" class="metin-alani"></textarea></div>
            <div class="form-grup">
                <label class="form-etiket">Atanan Kişiler <span class="metin-muted" style="font-weight:400">(birden fazla seçilebilir)</span></label>
                <input type="hidden" name="atananlar" class="atananlar-json">
                <div class="izgara izgara-2" style="gap:6px;max-height:150px;overflow-y:auto;padding:2px">
                    <?php foreach ($ekip as $k): ?>
                    <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
                        <input type="checkbox" class="atanan-kutu" value="<?= $k['id'] ?>"> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($k['ad']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Öncelik</label><select name="oncelik" class="secim"><?php foreach (ONCELIKLER as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                <?php if ($donemler): ?><div class="form-grup"><label class="form-etiket">Dönem</label><select name="donem_id" class="secim"><option value="">—</option><?php foreach ($donemler as $d): ?><option value="<?= $d['id'] ?>"><?= donem_ad($d) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Başlangıç Tarihi</label><input type="date" name="baslangic_tarihi" class="girdi"></div>
                <div class="form-grup"><label class="form-etiket">Son Tarih</label><input type="date" name="son_tarih" class="girdi"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tahmini Süre (saat)</label><input name="tahmini_saat" class="girdi" placeholder="Örn. 4,5"></div>
                <div class="form-grup"><label class="form-etiket">Etiketler</label><input name="etiketler" class="girdi" placeholder="video, instagram (virgülle)"></div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">Tekrar</label><select name="tekrar" class="secim"><?php foreach (TEKRARLAR as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select><div class="form-ipucu">Her hafta/ay başında taze kopyası oluşturulur.</div></div>
                <?php if ($projeId): $projeGorevleri = rows("SELECT id, baslik FROM gorevler WHERE proje_id=? AND durum!='tamamlandi' ORDER BY baslik", [$projeId]); ?>
                <div class="form-grup"><label class="form-etiket">Bağlı Olduğu Görev</label><select name="bagimli_id" class="secim"><option value="">— Bağımsız</option><?php foreach ($projeGorevleri as $pg): ?><option value="<?= $pg['id'] ?>"><?= e($pg['baslik']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilen görev bitmeden bu görev ilerleyemez.</div></div>
                <?php endif; ?>
            </div>
            <div class="form-grup"><label class="form-etiket">Akış Şablonu (opsiyonel)</label><select name="sablon_id" class="secim"><option value="">Akışsız görev</option><?php foreach ($sablonlar as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['ad']) ?></option><?php endforeach; ?></select><div class="form-ipucu">Seçilirse görev, şablondaki adımlar üzerinden ilerler.</div></div>
            <?php if ($projeId):
                $projeDosyaId = (int)val("SELECT dosya_id FROM projeler WHERE id=?", [$projeId]);
                $planliIcerikler = rows("SELECT i.id, i.baslik, i.tarih FROM icerikler i WHERE COALESCE(i.dosya_id, (SELECT dosya_id FROM projeler p2 WHERE p2.id=i.proje_id))=? AND i.durum!='yayinlandi' AND i.tarih>=CURDATE() AND NOT EXISTS(SELECT 1 FROM gorevler g2 WHERE g2.icerik_id=i.id) ORDER BY i.tarih LIMIT 30", [$projeDosyaId]); ?>
            <div class="form-grup">
                <label class="form-etiket">İçerik Görevi <span class="metin-muted" style="font-weight:400">(sosyal medya içeriğine bağla)</span></label>
                <select name="icerik_secim" class="secim" onchange="document.getElementById('yeniIcerikAlan-<?= $projeId ?>').style.display=this.value==='yeni'?'grid':'none'">
                    <option value="">— İçerik görevi değil</option>
                    <option value="yeni">+ Yeni içerik oluştur ve bağla</option>
                    <?php foreach ($planliIcerikler as $pi): ?><option value="<?= $pi['id'] ?>"><?= e($pi['baslik']) ?> (<?= tarih($pi['tarih']) ?>)</option><?php endforeach; ?>
                </select>
                <div class="form-satir mt-2" id="yeniIcerikAlan-<?= $projeId ?>" style="display:none">
                    <div><label class="form-etiket">Yayın Tarihi</label><input type="date" name="icerik_tarih" class="girdi"></div>
                    <div><label class="form-etiket">Platform</label><select name="icerik_platform" class="secim"><?php foreach (PLATFORMLAR as $pk => $pv): ?><option value="<?= $pk ?>"><?= $pv ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-ipucu">Görev tamamlanınca içerik onaylanır; içerik yayınlanınca görev tamamlanır.</div>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
    </form></div>
</div>
<?php }

/** Çoklu üye seçici: checkbox listesi + gizli JSON alanı (app.js otomatik serileştirir) */
function uye_secici(array $seciliIdler = [], string $etiket = 'Atanan Ekip Üyeleri'): void {
    $ekip = rows("SELECT id, ad, renk, avatar FROM users WHERE rol IN ('yonetici','pm','ekip','finans') AND aktif=1 ORDER BY ad");
?>
<div class="form-grup">
    <label class="form-etiket"><?= e($etiket) ?></label>
    <input type="hidden" name="uyeler" class="uye-json">
    <div class="izgara izgara-2" style="gap:6px;max-height:180px;overflow-y:auto;padding:2px">
        <?php foreach ($ekip as $e): ?>
        <label class="satir-esnek kucuk" style="gap:8px;padding:7px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer">
            <input type="checkbox" class="uye-kutu" value="<?= $e['id'] ?>" <?= in_array($e['id'], $seciliIdler) ? 'checked' : '' ?>>
            <?= avatar($e, 24) ?> <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($e['ad']) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
</div>
<?php }

/** Üye avatarlarını üst üste dizerek gösterir */
function uye_avatarlari(array $uyeler, int $boyut = 28): string {
    if (!$uyeler) return '';
    $h = '<span class="avatar-dizi">';
    foreach (array_slice($uyeler, 0, 5) as $uye) $h .= avatar($uye, $boyut);
    if (count($uyeler) > 5) $h .= '<span class="avatar" style="width:' . $boyut . 'px;height:' . $boyut . 'px;background:var(--surface-3);color:var(--text-2);margin-left:-8px;border:2px solid var(--surface)">+' . (count($uyeler) - 5) . '</span>';
    return $h . '</span>';
}

/** Müşteri puanlama modalı + JS (sayfada bir kez basılır) */
function puan_modali(): void {
    static $basildi = false;
    if ($basildi || !is_musteri()) return;
    $basildi = true;
?>
<div class="modal-katman" id="modalPuan">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">İşi Değerlendir</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="puan_ver">
        <input type="hidden" name="ref_tur" id="p_ref_tur"><input type="hidden" name="ref_id" id="p_ref_id"><input type="hidden" name="puan" id="p_puan" value="5">
        <div class="modal-govde">
            <div class="hucre-alt mb-2" id="p_baslik"></div>
            <div class="orta mb-3" id="puanYildizlar" style="font-size:34px;cursor:pointer;letter-spacing:6px">
                <?php for ($i = 1; $i <= 5; $i++): ?><span data-puan="<?= $i ?>" style="opacity:1">★</span><?php endfor; ?>
            </div>
            <div class="form-grup"><label class="form-etiket">Yorumunuz (opsiyonel)</label><textarea name="yorum" class="metin-alani" placeholder="Bu iş hakkında düşünceleriniz..."></textarea></div>
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>Vazgeç</button><button type="submit" class="btn btn-marka">Gönder</button></div>
    </form></div>
</div>
<script>
function puanVer(refTur, refId, baslik) {
    document.getElementById('p_ref_tur').value = refTur;
    document.getElementById('p_ref_id').value = refId;
    document.getElementById('p_baslik').textContent = baslik;
    puanSec(5);
    modalAc('modalPuan');
}
function puanSec(n) {
    document.getElementById('p_puan').value = n;
    document.querySelectorAll('#puanYildizlar span').forEach(s => {
        s.style.opacity = parseInt(s.dataset.puan) <= n ? '1' : '.25';
        s.style.color = parseInt(s.dataset.puan) <= n ? 'var(--uyari)' : 'inherit';
    });
}
document.querySelectorAll('#puanYildizlar span').forEach(s => {
    s.addEventListener('click', () => puanSec(parseInt(s.dataset.puan)));
    s.addEventListener('mouseenter', () => puanSec(parseInt(s.dataset.puan)));
});
</script>
<?php }

/** Etiketlenebilir kişi listesini sayfaya bir kez gömer (mention autocomplete için) */
function mention_scripti(): void {
    static $basildi = false;
    if ($basildi) return;
    $basildi = true;
    $kisiler = is_musteri()
        ? rows("SELECT id, ad FROM users WHERE aktif=1 AND rol!='musteri' ORDER BY ad")
        : rows("SELECT id, ad FROM users WHERE aktif=1 ORDER BY ad");
    echo '<script>window.sadaKisiler = ' . json_encode($kisiler, JSON_UNESCAPED_UNICODE) . ';</script>';
}

/** Tek bir yorumu render eder (kök veya yanıt) */
function yorum_goster(array $y, array $tepkiler, bool $yanit = false): void {
    $u = user();
    $benimki = $y['user_id'] == $u['id'];
    $silebilir = $benimki || is_admin();
    $ar = $y['arsiv_id'] ? row("SELECT * FROM arsiv WHERE id=?", [$y['arsiv_id']]) : null;
    $gorselMi = $ar && in_array($ar['uzanti'], ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    $yTepkiler = $tepkiler[$y['id']] ?? [];
?>
<div class="yorum <?= $yanit ? 'yorum-yanit' : '' ?>" id="yorum-<?= $y['id'] ?>">
    <div class="satir-esnek" style="gap:11px;align-items:flex-start">
        <?= avatar($y, $yanit ? 28 : 34) ?>
        <div style="flex:1;min-width:0">
            <div class="satir-esnek sarma" style="gap:8px">
                <span class="hucre-ana kucuk"><?= e($y['ad']) ?></span>
                <span class="hucre-alt"><?= zaman_once($y['created']) ?><?= $y['duzenlendi'] ? ' · düzenlendi' : '' ?></span>
            </div>
            <div class="kucuk metin-2 mt-1 yorum-metin" style="white-space:pre-wrap"><?= mention_vurgula(e($y['mesaj'])) ?></div>
            <?php if ($ar): ?>
            <div class="mt-1">
                <?php if ($gorselMi): ?><a href="uploads/<?= e($ar['dosya_yolu']) ?>" target="_blank"><img src="uploads/<?= e($ar['dosya_yolu']) ?>" style="max-width:220px;max-height:150px;border-radius:10px;border:1px solid var(--border)"></a>
                <?php else: ?><a href="uploads/<?= e($ar['dosya_yolu']) ?>" target="_blank" class="btn btn-sm"><?= ikon('atac', 12) ?> <?= e($ar['ad']) ?></a><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="satir-esnek sarma mt-1" style="gap:6px">
                <!-- Tepkiler -->
                <?php foreach ($yTepkiler as $emoji => $bilgi): ?>
                <button class="tepki-cip <?= in_array($u['id'], $bilgi['idler']) ? 'benim' : '' ?>" data-yorum="<?= $y['id'] ?>" data-emoji="<?= e($emoji) ?>" onclick="tepki(<?= $y['id'] ?>,'<?= e($emoji) ?>')" title="<?= e(implode(', ', $bilgi['adlar'])) ?>"><?= e($emoji) ?> <span class="tepki-adet"><?= count($bilgi['idler']) ?></span></button>
                <?php endforeach; ?>
                <div class="acilir" data-acilir style="display:inline-block">
                    <button class="tepki-cip" data-acilir-btn title="Tepki ver">☺+</button>
                    <div class="acilir-panel" style="min-width:auto;display:flex;gap:2px;padding:5px">
                        <?php foreach (['👍', '❤️', '🎉', '🔥', '😂', '👀'] as $em): ?>
                        <button class="tepki-sec" onclick="tepki(<?= $y['id'] ?>,'<?= $em ?>')"><?= $em ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!$yanit): ?><button class="mini-btn" onclick="yanitAc(<?= $y['id'] ?>)">Yanıtla</button><?php endif; ?>
                <?php if ($benimki): ?><button class="mini-btn" onclick="yorumDuzenle(<?= $y['id'] ?>)">Düzenle</button><?php endif; ?>
                <?php if ($silebilir): ?><button class="mini-btn" style="color:var(--tehlike)" data-eylem="yorum_sil" data-id="<?= $y['id'] ?>" data-onay="Yorum silinsin mi?">Sil</button><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php }

/** Yorum akışı: thread + tepki + dosya + mention destekli */
function yorum_akisi(string $refTur, int $refId): void {
    mention_scripti();
    $yorumlar = rows("SELECT y.*, u.ad, u.renk, u.avatar FROM yorumlar y JOIN users u ON u.id=y.user_id WHERE y.ref_tur=? AND y.ref_id=? ORDER BY y.id", [$refTur, $refId]);
    // Tepkileri topla
    $tepkiler = [];
    if ($yorumlar) {
        $idler = implode(',', array_map(fn($y) => (int)$y['id'], $yorumlar));
        foreach (rows("SELECT t.*, u.ad FROM yorum_tepkiler t JOIN users u ON u.id=t.user_id WHERE t.yorum_id IN ($idler)") as $t) {
            $tepkiler[$t['yorum_id']][$t['emoji']]['idler'][] = (int)$t['user_id'];
            $tepkiler[$t['yorum_id']][$t['emoji']]['adlar'][] = $t['ad'];
        }
    }
    $kokler = array_filter($yorumlar, fn($y) => !$y['parent_id']);
    $yanitlar = [];
    foreach ($yorumlar as $y) if ($y['parent_id']) $yanitlar[$y['parent_id']][] = $y;
?>
<div class="dikey" style="gap:16px" id="yorumAkis-<?= e($refTur) ?>-<?= $refId ?>">
    <?php foreach ($kokler as $y): ?>
    <div>
        <?php yorum_goster($y, $tepkiler); ?>
        <?php foreach ($yanitlar[$y['id']] ?? [] as $yy): yorum_goster($yy, $tepkiler, true); endforeach; ?>
        <!-- Yanıt formu (gizli) -->
        <form data-ajax="yorum_ekle" class="yorum-yanit mention-kap gizli mt-1" id="yanitForm-<?= $y['id'] ?>" style="display:flex;gap:8px;align-items:flex-end">
            <input type="hidden" name="ref_tur" value="<?= e($refTur) ?>"><input type="hidden" name="ref_id" value="<?= $refId ?>">
            <input type="hidden" name="parent_id" value="<?= $y['id'] ?>"><input type="hidden" name="mention_idler" class="mention-idler">
            <textarea name="mesaj" class="metin-alani" data-mention style="min-height:40px" placeholder="Yanıt yazın... (@ ile etiketleyin)" required></textarea>
            <button type="submit" class="btn btn-marka btn-sm">Yanıtla</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php if (!$kokler): ?><div class="metin-muted kucuk">Henüz yorum yok. İlk yorumu siz yazın — @ yazarak birini etiketleyebilirsiniz.</div><?php endif; ?>
</div>
<form data-ajax="yorum_ekle" class="mention-kap mt-3" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="ref_tur" value="<?= e($refTur) ?>"><input type="hidden" name="ref_id" value="<?= $refId ?>">
    <input type="hidden" name="mention_idler" class="mention-idler">
    <textarea name="mesaj" class="metin-alani" data-mention style="min-height:44px;flex:1;min-width:200px" placeholder="Yorum yazın... (@ ile etiketleyin)" required></textarea>
    <label class="ikon-eylem" title="Dosya ekle" style="cursor:pointer">
        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="18"><path d="M21.4 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.2-9.19a4 4 0 015.65 5.66l-9.2 9.19a2 2 0 01-2.82-2.83l8.49-8.48"/></svg>
        <input type="file" name="dosya" style="display:none" onchange="this.parentElement.style.color=this.files.length?'var(--marka)':''">
    </label>
    <button type="submit" class="btn btn-marka">Gönder</button>
</form>
<script>
function yanitAc(id) { const f = document.getElementById('yanitForm-' + id); f.classList.toggle('gizli'); if (!f.classList.contains('gizli')) f.querySelector('textarea').focus(); }
async function tepki(yorumId, emoji) {
    const j = await api('tepki_toggle', { yorum_id: yorumId, emoji });
    if (!j.ok) return;
    // Yenilemesiz: mevcut çipi güncelle / oluştur / kaldır
    let cip = document.querySelector(`.tepki-cip[data-yorum="${yorumId}"][data-emoji="${CSS.escape(emoji)}"]`);
    if (j.adet === 0) { if (cip) cip.remove(); }
    else if (cip) {
        cip.querySelector('.tepki-adet').textContent = j.adet;
        cip.classList.toggle('benim', !!j.benim);
    } else {
        cip = document.createElement('button');
        cip.className = 'tepki-cip' + (j.benim ? ' benim' : '');
        cip.dataset.yorum = yorumId; cip.dataset.emoji = emoji;
        cip.onclick = () => tepki(yorumId, emoji);
        cip.innerHTML = emoji + ' <span class="tepki-adet">' + j.adet + '</span>';
        const hedefSatir = document.querySelector('#yorum-' + yorumId + ' .acilir');
        if (hedefSatir) hedefSatir.parentElement.insertBefore(cip, hedefSatir);
    }
    if (window.canliYenile) canliYenile();
}
function yorumDuzenle(id) {
    const kutu = document.querySelector('#yorum-' + id + ' .yorum-metin');
    if (kutu.dataset.duzenleniyor) return;
    kutu.dataset.duzenleniyor = '1';
    const eski = kutu.innerText;
    kutu.innerHTML = '';
    const ta = document.createElement('textarea'); ta.className = 'metin-alani'; ta.value = eski; ta.style.minHeight = '60px';
    const kaydet = document.createElement('button'); kaydet.className = 'btn btn-marka btn-sm mt-1'; kaydet.textContent = 'Kaydet';
    kaydet.onclick = async () => { const j = await api('yorum_duzenle', { id, mesaj: ta.value }); if (j.ok) location.reload(); };
    kutu.append(ta, kaydet);
    ta.focus();
}
</script>
<?php }

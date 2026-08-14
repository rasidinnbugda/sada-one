<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bilesenler.php';
$u = require_login();

// Kullanıcının üye olduğu kanallar (arşivlenenler ayrı bölümde)
$kanallar = rows("SELECT k.*, ku.arsiv,
    (SELECT m.mesaj FROM mesajlar m WHERE m.kanal_id=k.id ORDER BY m.id DESC LIMIT 1) son_mesaj,
    (SELECT m.created FROM mesajlar m WHERE m.kanal_id=k.id ORDER BY m.id DESC LIMIT 1) son_zaman,
    (SELECT COUNT(*) FROM mesajlar m WHERE m.kanal_id=k.id AND m.user_id!=? AND (ku.son_okuma IS NULL OR m.created>ku.son_okuma)) okunmamis
    FROM kanallar k JOIN kanal_uyeleri ku ON ku.kanal_id=k.id AND ku.user_id=?
    ORDER BY ku.arsiv, son_zaman IS NULL, son_zaman DESC", [$u['id'], $u['id']]);

// Özel (DM) kanallarında ad = karşıdaki kişinin adı
foreach ($kanallar as &$k) {
    if ($k['tur'] === 'ozel') {
        $diger = row("SELECT us.ad, us.renk, us.avatar FROM kanal_uyeleri ku JOIN users us ON us.id=ku.user_id WHERE ku.kanal_id=? AND ku.user_id!=? LIMIT 1", [$k['id'], $u['id']]);
        $k['ad'] = $diger ? $diger['ad'] : 'Özel Sohbet';
        $k['dm_kisi'] = $diger;
    }
}
unset($k);

$aktifKanalId = (int)($_GET['kanal'] ?? ($kanallar[0]['id'] ?? 0));
$aktifKanal = null;
foreach ($kanallar as $k) if ($k['id'] == $aktifKanalId) $aktifKanal = $k;
if (!$aktifKanal && $kanallar) { $aktifKanal = $kanallar[0]; $aktifKanalId = $aktifKanal['id']; }

$mesajlar = $aktifKanal ? rows("SELECT m.*, us.ad, us.renk FROM mesajlar m JOIN users us ON us.id=m.user_id WHERE m.kanal_id=? ORDER BY m.id", [$aktifKanalId]) : [];
if ($aktifKanal) guncelle('kanal_uyeleri', ['son_okuma' => date('Y-m-d H:i:s')], 'kanal_id=? AND user_id=?', [$aktifKanalId, $u['id']]);
$sonMesajId = $mesajlar ? end($mesajlar)['id'] : 0;

$ekipUyeler = is_staff() ? rows("SELECT id, ad FROM users WHERE id!=? AND rol IN ('yonetici','pm','ekip','finans') AND aktif=1 ORDER BY ad", [$u['id']]) : [];
// DM açılabilecek kişiler: ekip herkesle, müşteri sadece ekiple
$dmKisiler = is_staff()
    ? rows("SELECT id, ad, renk, avatar, rol FROM users WHERE id!=? AND aktif=1 ORDER BY rol='musteri', ad", [$u['id']])
    : rows("SELECT id, ad, renk, avatar, rol FROM users WHERE id!=? AND aktif=1 AND rol!='musteri' ORDER BY ad", [$u['id']]);
// Aktif kanal üyeleri (yönetim paneli için)
$kanalUyeleri = $aktifKanal ? rows("SELECT us.id, us.ad, us.renk, us.avatar, us.rol FROM kanal_uyeleri ku JOIN users us ON us.id=ku.user_id WHERE ku.kanal_id=? ORDER BY us.ad", [$aktifKanalId]) : [];
$uyeOlmayanlar = ($aktifKanal && is_staff() && $aktifKanal['tur'] !== 'ozel')
    ? rows("SELECT id, ad FROM users WHERE aktif=1 AND id NOT IN (SELECT user_id FROM kanal_uyeleri WHERE kanal_id=?) ORDER BY ad", [$aktifKanalId]) : [];

sayfa_basi('Mesajlar', 'mesajlar');
?>
<div class="mesaj-duzen <?= $aktifKanal ? 'sohbet-acik' : '' ?>" id="mesajDuzen">
    <div class="kanal-liste">
        <div class="kanal-arama satir-esnek" style="gap:8px">
            <input class="girdi" placeholder="Kanal ara..." data-arama=".kanal-oge" style="flex:1">
            <button class="ikon-eylem" data-modal="modalDM" title="Birebir mesaj"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></button>
            <?php if (is_staff()): ?><button class="ikon-eylem" data-modal="modalKanal" title="Yeni kanal"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button><?php endif; ?>
        </div>
        <?php if (!$kanallar): ?>
        <div class="bos-mini">Henüz bir kanalınız yok.</div>
        <?php else:
            $arsivBasladiMi = false;
            foreach ($kanallar as $k):
                if ($k['arsiv'] && !$arsivBasladiMi) { $arsivBasladiMi = true; echo '<div class="nav-bolum" style="padding:12px 14px 6px">Arşivlenmiş</div>'; }
                $ikon = $k['simge'] ?: (['genel' => '#', 'proje' => ikon('klasor', 17), 'musteri' => ikon('el-sikisma', 17), 'ozel' => ikon('sohbet', 17)][$k['tur']] ?? '#'); ?>
        <a href="?kanal=<?= $k['id'] ?>" class="kanal-oge <?= $k['id'] == $aktifKanalId ? 'aktif' : '' ?>" data-ara="<?= e($k['ad']) ?>" style="<?= $k['arsiv'] ? 'opacity:.55' : '' ?>">
            <div class="dosya-avatar" style="width:38px;height:38px;font-size:15px;background:var(--surface-2)"><?= $k['simge'] ? e($k['simge']) : $ikon ?></div>
            <div style="min-width:0;flex:1">
                <div class="kanal-ad"><?= e($k['ad']) ?></div>
                <div class="kanal-son"><?= $k['son_mesaj'] ? e(mb_substr($k['son_mesaj'], 0, 40)) : 'Henüz mesaj yok' ?></div>
            </div>
            <?php if ($k['okunmamis'] && !$k['arsiv']): ?><span class="nav-sayac kanal-rozet"><?= $k['okunmamis'] ?></span><?php endif; ?>
        </a>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($aktifKanal): ?>
    <div class="sohbet">
        <div class="sohbet-ust">
            <button class="menu-btn" onclick="document.getElementById('mesajDuzen').classList.remove('sohbet-acik')" style="display:none" id="geriBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg></button>
            <div class="dosya-avatar" style="width:38px;height:38px;font-size:15px;background:var(--surface-2)"><?= $aktifKanal['simge'] ? e($aktifKanal['simge']) : (['genel' => '#', 'proje' => ikon('klasor', 17), 'musteri' => ikon('el-sikisma', 17), 'ozel' => ikon('sohbet', 17)][$aktifKanal['tur']] ?? '#') ?></div>
            <div><div class="kanal-ad"><?= e($aktifKanal['ad']) ?></div><div class="hucre-alt"><?= count($kanalUyeleri) ?> üye<?= $aktifKanal['arsiv'] ? ' · arşivde' : '' ?></div></div>
            <div class="satir-esnek" style="margin-left:auto;gap:6px">
                <button class="btn btn-sm btn-hayalet" data-modal="modalUyeler">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg>
                    Üyeler
                </button>
                <div class="acilir" data-acilir>
                    <button class="btn btn-sm" data-acilir-btn title="Arşivle, simge/ad değiştir, sohbeti sil">
                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="15"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Sohbet Ayarları
                    </button>
                    <div class="acilir-panel">
                        <div class="acilir-baslik">Sohbet Ayarları</div>
                        <div style="padding:6px 12px">
                            <div class="hucre-alt mb-2">Simge seç:</div>
                            <div class="satir-esnek sarma" style="gap:2px">
                                <?php foreach (['💬', '📁', '🤝', '🎨', '🎬', '📸', '🌐', '⚡', '🔥', '🎯', '📊', '🚀'] as $em): ?>
                                <button class="tepki-sec" data-eylem="kanal_simge" data-kanal_id="<?= $aktifKanalId ?>" data-simge="<?= $em ?>"><?= $em ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($aktifKanal['tur'] !== 'ozel'): ?>
                        <button class="acilir-oge" style="width:100%;text-align:left" onclick="kanalAdDegistir()"><?= ikon('kalem', 13) ?> Adı değiştir</button>
                        <?php endif; ?>
                        <button class="acilir-oge" style="width:100%;text-align:left" data-eylem="kanal_arsiv_toggle" data-kanal_id="<?= $aktifKanalId ?>"><?= ikon('kutu', 13) ?> <?= $aktifKanal['arsiv'] ? 'Arşivden çıkar' : 'Sohbeti arşivle' ?></button>
                        <?php if ($aktifKanal['tur'] === 'ozel' || is_pm()): ?>
                        <button class="acilir-oge tehlike" style="width:100%;text-align:left" data-eylem="kanal_sil" data-kanal_id="<?= $aktifKanalId ?>" data-onay="Bu sohbet ve tüm mesajları kalıcı olarak silinecek. Emin misiniz?"><?= ikon('cop', 13) ?> Sohbeti sil</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="sohbet-govde" id="sohbetGovde">
            <?php foreach ($mesajlar as $m): ?>
            <div class="mesaj-balon <?= $m['user_id'] == $u['id'] ? 'benim' : '' ?>">
                <div class="mesaj-gonderen"><?= e($m['ad']) ?></div>
                <div><?= mention_vurgula(nl2br(e($m['mesaj']))) ?></div>
                <div class="mesaj-zaman"><?= date('H:i', strtotime($m['created'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <form class="sohbet-yaz mention-kap" id="mesajForm">
            <input type="hidden" class="mention-idler" id="mesajMention">
            <textarea class="metin-alani" id="mesajGirdi" data-mention placeholder="Mesaj yazın... (@ ile etiketleyin, Enter ile gönderin)" required></textarea>
            <button type="submit" class="btn btn-marka"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="18"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg></button>
        </form>
        <?php mention_scripti(); ?>
    </div>
    <?php else: ?>
    <div class="sohbet" style="align-items:center;justify-content:center">
        <div class="bos-durum"><div class="bos-baslik">Kanal seçin</div><div class="bos-metin">Mesajlaşmaya başlamak için soldan bir kanal seçin<?= is_staff() ? ' veya yeni bir kanal oluşturun' : '' ?>.</div></div>
    </div>
    <?php endif; ?>
</div>

<!-- Birebir mesaj (DM) modalı -->
<div class="modal-katman" id="modalDM">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Birebir Mesaj</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <div class="modal-govde">
        <div class="form-grup"><input class="girdi" placeholder="Kişi ara..." data-arama="#dmListe .dm-kisi"></div>
        <div class="dikey" style="gap:4px;max-height:340px;overflow-y:auto" id="dmListe">
            <?php foreach ($dmKisiler as $kisi): ?>
            <button class="satir-esnek dm-kisi" style="gap:11px;padding:9px 11px;border-radius:11px;text-align:left;transition:background .2s" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''" data-eylem="dm_ac" data-user_id="<?= $kisi['id'] ?>" data-yenile="hayir" data-ara="<?= e($kisi['ad']) ?>">
                <?= avatar($kisi, 34) ?>
                <div><div class="hucre-ana kucuk"><?= e($kisi['ad']) ?></div><div class="hucre-alt"><?= ROLLER[$kisi['rol']] ?></div></div>
            </button>
            <?php endforeach; ?>
            <?php if (!$dmKisiler): ?><div class="bos-mini">Mesaj atılabilecek kişi yok.</div><?php endif; ?>
        </div>
    </div>
    </div>
</div>

<?php if ($aktifKanal): ?>
<!-- Kanal üyeleri modalı -->
<div class="modal-katman" id="modalUyeler">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik"><?= e($aktifKanal['ad']) ?> — Üyeler</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <div class="modal-govde">
        <?php if ($uyeOlmayanlar && is_staff()): ?>
        <div class="satir-esnek mb-3" style="gap:8px">
            <select class="secim" id="yeniUyeSecim" style="flex:1">
                <option value="">Üye ekle...</option>
                <?php foreach ($uyeOlmayanlar as $uo): ?><option value="<?= $uo['id'] ?>"><?= e($uo['ad']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-marka btn-sm" onclick="uyeEkle()">Ekle</button>
        </div>
        <?php endif; ?>
        <div class="dikey" style="gap:4px;max-height:320px;overflow-y:auto">
            <?php foreach ($kanalUyeleri as $ku): ?>
            <div class="satir-esnek arasi" style="padding:8px 10px;border-radius:10px">
                <div class="satir-esnek" style="gap:10px"><?= avatar($ku, 32) ?><div><div class="hucre-ana kucuk"><?= e($ku['ad']) ?></div><div class="hucre-alt"><?= ROLLER[$ku['rol']] ?></div></div></div>
                <?php if ($aktifKanal['tur'] !== 'ozel' && (is_pm() || $ku['id'] == $u['id'])): ?>
                <button class="ikon-eylem tehlike" data-eylem="kanal_uye_cikar" data-kanal_id="<?= $aktifKanalId ?>" data-user_id="<?= $ku['id'] ?>" data-onay="<?= $ku['id'] == $u['id'] ? 'Kanaldan ayrılmak istiyor musunuz?' : e($ku['ad']) . ' kanaldan çıkarılsın mı?' ?>" title="<?= $ku['id'] == $u['id'] ? 'Kanaldan ayrıl' : 'Çıkar' ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="16"><path d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a7 7 0 00-7 7h11m5-9l5 5m0-5l-5 5"/></svg>
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    </div>
</div>
<script>
async function uyeEkle() {
    const uid = document.getElementById('yeniUyeSecim').value;
    if (!uid) return;
    const j = await api('kanal_uye_ekle', { kanal_id: <?= $aktifKanalId ?>, user_id: uid });
    if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(() => location.reload(), 550); }
}
</script>
<?php endif; ?>

<?php if (is_staff()): ?>
<div class="modal-katman" id="modalKanal">
    <div class="modal"><div class="modal-ust"><div class="modal-baslik">Yeni Kanal</div><button class="modal-kapat" data-modal-kapat>✕</button></div>
    <form data-ajax="kanal_olustur" id="kanalForm">
        <div class="modal-govde">
            <div class="form-grup"><label class="form-etiket">Kanal Adı <span class="zorunlu">*</span></label><input name="ad" class="girdi" required placeholder="Örn. Tasarım Ekibi"></div>
            <div class="form-grup">
                <label class="form-etiket">Üyeler</label>
                <div class="dikey" style="gap:6px;max-height:220px;overflow-y:auto;padding:4px">
                    <?php foreach ($ekipUyeler as $e): ?>
                    <label class="satir-esnek" style="gap:9px;padding:8px 10px;background:var(--surface-2);border-radius:9px;cursor:pointer"><input type="checkbox" value="<?= $e['id'] ?>" class="kanalUye"> <?= e($e['ad']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <input type="hidden" name="uyeler" id="kanalUyeler">
        </div>
        <div class="modal-alt"><button type="button" class="btn btn-hayalet" data-modal-kapat>İptal</button><button type="submit" class="btn btn-marka">Oluştur</button></div>
    </form></div>
</div>
<?php endif; ?>

<script>
const kanalId = <?= $aktifKanalId ?>;
let sonId = <?= $sonMesajId ?>;
const govde = document.getElementById('sohbetGovde');
const benimId = <?= $u['id'] ?>;

function balonEkle(m) {
    const d = document.createElement('div');
    d.className = 'mesaj-balon' + (m.benim ? ' benim' : '');
    d.innerHTML = `<div class="mesaj-gonderen">${m.ad}</div><div>${m.mesaj.replace(/</g,'&lt;').replace(/\n/g,'<br>')}</div><div class="mesaj-zaman">${m.zaman}</div>`;
    govde.appendChild(d);
    govde.scrollTop = govde.scrollHeight;
}

const form = document.getElementById('mesajForm');
const girdi = document.getElementById('mesajGirdi');
if (form) {
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const mesaj = girdi.value.trim(); if (!mesaj) return;
        const mentionAlan = document.getElementById('mesajMention');
        const mentionlar = mentionAlan.value || '[]';
        girdi.value = ''; mentionAlan.value = '';
        const j = await api('mesaj_gonder', { kanal_id: kanalId, mesaj, mention_idler: mentionlar });
        if (j.ok) { balonEkle({ ad: 'Siz', mesaj, zaman: new Date().toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'}), benim: true }); sonId = j.id; }
    });
    girdi.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey && !document.querySelector('.mention-acilir')) { e.preventDefault(); form.requestSubmit(); }
    });
}

// Yeni mesajları çek (polling)
if (kanalId) setInterval(async () => {
    const j = await api('mesaj_getir', { kanal_id: kanalId, son_id: sonId });
    if (j.ok && j.mesajlar.length) {
        j.mesajlar.forEach(m => { if (!m.benim) balonEkle(m); sonId = Math.max(sonId, m.id); });
    }
}, 4000);

// Kanal üye seçimi
const kanalForm = document.getElementById('kanalForm');
if (kanalForm) kanalForm.addEventListener('submit', () => {
    const secili = Array.from(document.querySelectorAll('.kanalUye:checked')).map(c => c.value);
    document.getElementById('kanalUyeler').value = JSON.stringify(secili);
});

// Mobil geri butonu
if (window.innerWidth <= 760) { const gb = document.getElementById('geriBtn'); if (gb) gb.style.display = 'flex'; }

// Sohbet adını değiştir
async function kanalAdDegistir() {
    const yeniAd = prompt('Yeni sohbet adı:', <?= json_encode($aktifKanal['ad'] ?? '', JSON_UNESCAPED_UNICODE) ?>);
    if (!yeniAd || !yeniAd.trim()) return;
    const j = await api('kanal_ad', { kanal_id: kanalId, ad: yeniAd.trim() });
    if (j.ok) { toast(j.mesaj, 'basari'); setTimeout(() => location.reload(), 550); }
}
</script>
<?php sayfa_sonu(); ?>

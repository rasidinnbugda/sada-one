<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$preferences = json_decode($u['notification_preferences'] ?? '', true) ?: [];
$tAc = fn($k) => !isset($preferences[$k]) || $preferences[$k];

page_start('Profil', '');
?>
<div class="sayfa-ust"><div><div class="sayfa-baslik">Profil & Tercihler</div><div class="sayfa-alt">Hesap bilgileriniz, tema ve bildirim ayarları</div></div></div>

<div class="izgara izgara-2">
    <div>
        <!-- Profile info -->
        <div class="kart mb-2">
            <div class="satir-esnek mb-3" style="gap:14px">
                <?= avatar($u, 64) ?>
                <div>
                    <div class="kalin" style="font-size:17px"><?= e($u['name']) ?></div>
                    <div class="hucre-alt"><?= ROLES[$u['role']] ?><?= $u['job_title'] ? ' · ' . e($u['job_title']) : '' ?></div>
                    <?php if ($u['avatar']): ?><button class="mini-btn mt-1" data-action="avatar_delete" data-approval="Profil fotoğrafı kaldırılsın mı?" style="color:var(--tehlike)">Fotoğrafı kaldır</button><?php endif; ?>
                </div>
            </div>
            <form data-ajax="profile_save">
                <div class="form-grup"><label class="form-etiket">Profil Fotoğrafı</label><input type="file" name="avatar" class="girdi" accept="image/*"><div class="form-ipucu">JPG, PNG veya WebP. Kare görseller en iyi sonucu verir.</div></div>
                <div class="form-grup"><label class="form-etiket">Ad Soyad</label><input name="name" class="girdi" value="<?= e($u['name']) ?>" required></div>
                <div class="form-grup"><label class="form-etiket">E-posta</label><input class="girdi" value="<?= e($u['email']) ?>" disabled><div class="form-ipucu">E-posta değişikliği için yöneticinize başvurun.</div></div>
                <div class="form-grup"><label class="form-etiket">Ünvan</label><input name="job_title" class="girdi" value="<?= e($u['job_title']) ?>"></div>
                <div class="form-grup"><label class="form-etiket">Yeni Şifre</label><input type="password" name="password" autocomplete="new-password" class="girdi" placeholder="Değiştirmek için doldurun"></div>
                <button type="submit" class="btn btn-marka mt-1">Kaydet</button>
            </form>
        </div>

        <!-- Notification preferences -->
        <div class="kart">
            <div class="kart-baslik mb-2">Bildirim Tercihleri</div>
            <div class="hucre-alt mb-3">Hangi olaylarda bildirim almak istediğinizi seçin.</div>
            <form data-ajax="preference_save" data-refresh="hayir">
                <div class="dikey" style="gap:10px">
                    <?php foreach (NOTIFICATION_CATEGORIES as $k => $tag): ?>
                    <label class="satir-esnek arasi" style="padding:11px 14px;background:var(--surface-2);border-radius:11px;cursor:pointer">
                        <span class="kucuk"><?= $tag ?></span>
                        <span class="anahtar"><input type="checkbox" name="t_<?= $k ?>" value="1" <?= $tAc($k) ? 'checked' : '' ?>></span>
                    </label>
                    <?php endforeach; ?>
                    <label class="satir-esnek arasi" style="padding:11px 14px;background:var(--surface-2);border-radius:11px;cursor:pointer">
                        <span class="kucuk">Adımlarım'da <b>yalnızca sorumlusu olduğum</b> adımlar görünsün</span>
                        <span class="anahtar"><input type="checkbox" name="t_only_step" value="1" <?= only_own_steps() ? 'checked' : '' ?>></span>
                    </label>
                    <label class="satir-esnek arasi" style="padding:11px 14px;background:var(--parlak);border-radius:11px;cursor:pointer;border:1px solid var(--border-2)">
                        <span class="kucuk"><b>E-posta ile de gönder</b> — açık bildirimler e-postanıza da düşer</span>
                        <span class="anahtar"><input type="checkbox" name="t_email" value="1" <?= $tAc('email') ? 'checked' : '' ?>></span>
                    </label>
                </div>
                <button type="submit" class="btn btn-marka mt-3">Tercihleri Kaydet</button>
            </form>
        </div>
    </div>

    <!-- Theme selection -->
    <div class="kart" style="align-self:start">
        <div class="kart-baslik mb-2">Tema Seçimi</div>
        <div class="hucre-alt mb-3">Panelin renk temasını seçin. Değişiklik anında uygulanır.</div>
        <div class="izgara izgara-2" style="gap:12px">
            <?php foreach (THEMES as $t => $info):
                [$tag, $vurgu, $koyu] = $info;
                $zemin = $koyu ? '#101318' : '#f2f0e6';
                if ($t === 'lime') $zemin = '#0b0f0a';
                if ($t === 'navy') $zemin = '#0a0f1e';
                if ($t === 'liquid-glass') $zemin = 'linear-gradient(135deg,#12325e,#5c2440 60%,#0d4f46)';
                if ($t === 'liquid-glass-light') $zemin = 'linear-gradient(135deg,#a8cff5,#f7c4cf 60%,#bdedd9)';
                if ($t === 'glass') $zemin = 'linear-gradient(135deg,#3c1e78,#1e3a8a 60%,#701a4d)';
                if ($t === 'glass-light') $zemin = 'linear-gradient(135deg,#c9b8f5,#b3ccf7 60%,#f2b9d7)';
                if ($t === 'clay') $zemin = '#dce9f9';
                if ($t === 'maroon') $zemin = '#1a060b';
                if ($t === 'cream') $zemin = '#f8f2cb';
                if ($t === 'lime-light') $zemin = '#f2f6e8';
                if ($t === 'navy-light') $zemin = '#eef1f8';
                if ($t === 'maroon-light') $zemin = '#f9f0ec'; ?>
            <button class="kart tema-secim-kart" data-theme-sec="<?= $t ?>" style="padding:0;overflow:hidden;border:2px solid <?= $u['theme'] === $t ? $vurgu : 'var(--border)' ?>">
                <div style="height:64px;background:<?= $zemin ?>;position:relative">
                    <div style="position:absolute;bottom:9px;left:11px;width:30px;height:30px;border-radius:8px;background:<?= $vurgu ?>"></div>
                    <div style="position:absolute;bottom:14px;left:49px;right:11px;height:7px;border-radius:4px;background:<?= $vurgu ?>44"></div>
                    <div style="position:absolute;bottom:27px;left:49px;width:52px;height:5px;border-radius:3px;background:<?= $vurgu ?>22"></div>
                </div>
                <div class="satir-esnek arasi" style="padding:10px 13px"><span class="kalin" style="font-size:12.5px"><?= $tag ?></span><span class="etiket-nokta" style="background:<?= $vurgu ?>"></span></div>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[data-theme-sec]').forEach(card => {
    card.addEventListener('click', async () => {
        const theme = card.dataset.themeSec;
        document.documentElement.setAttribute('data-theme', theme);
        const j = await api('theme_change', { theme });
        if (j.ok) { toast('Tema güncellendi', 'basari'); setTimeout(() => location.reload(), 550); }
    });
});
</script>
<?php page_end(); ?>

<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_login();

$preferences = json_decode($u['notification_preferences'] ?? '', true) ?: [];
$tOpen = fn($k) => !isset($preferences[$k]) || $preferences[$k];

page_start('Profil', '');
?>
<div class="page-top"><div><div class="page-title">Profil & Tercihler</div><div class="page-bottom">Hesap bilgileriniz, tema ve bildirim ayarları</div></div></div>

<div class="grid grid-2">
    <div>
        <!-- Profile info -->
        <div class="card mb-2">
            <div class="row-flex mb-3" style="gap:14px">
                <?= avatar($u, 64) ?>
                <div>
                    <div class="bold" style="font-size:17px"><?= e($u['name']) ?></div>
                    <div class="cell-bottom"><?= ROLES[$u['role']] ?><?= $u['job_title'] ? ' · ' . e($u['job_title']) : '' ?></div>
                    <?php if ($u['avatar']): ?><button class="mini-btn mt-1" data-action="avatar_delete" data-confirm="Profil fotoğrafı kaldırılsın mı?" style="color:var(--danger)">Fotoğrafı kaldır</button><?php endif; ?>
                </div>
            </div>
            <form data-ajax="profile_save">
                <div class="form-group"><label class="form-label">Profil Fotoğrafı</label><input type="file" name="avatar" class="input" accept="image/*"><div class="form-hint">JPG, PNG veya WebP. Kare görseller en iyi sonucu verir.</div></div>
                <div class="form-group"><label class="form-label">Ad Soyad</label><input name="name" class="input" value="<?= e($u['name']) ?>" required></div>
                <div class="form-group"><label class="form-label">E-posta</label><input class="input" value="<?= e($u['email']) ?>" disabled><div class="form-hint">E-posta değişikliği için yöneticinize başvurun.</div></div>
                <div class="form-group"><label class="form-label">Ünvan</label><input name="job_title" class="input" value="<?= e($u['job_title']) ?>"></div>
                <div class="form-group"><label class="form-label">Yeni Şifre</label><input type="password" name="password" autocomplete="new-password" class="input" placeholder="Değiştirmek için doldurun"></div>
                <button type="submit" class="btn btn-brand mt-1">Kaydet</button>
            </form>
        </div>

        <!-- Notification preferences -->
        <div class="card">
            <div class="card-title mb-2">Bildirim Tercihleri</div>
            <div class="cell-bottom mb-3">Hangi olaylarda bildirim almak istediğinizi seçin.</div>
            <form data-ajax="preference_save" data-refresh="no">
                <div class="vertical" style="gap:10px">
                    <?php foreach (NOTIFICATION_CATEGORIES as $k => $label): ?>
                    <label class="row-flex between" style="padding:11px 14px;background:var(--surface-2);border-radius:11px;cursor:pointer">
                        <span class="small"><?= $label ?></span>
                        <span class="key"><input type="checkbox" name="t_<?= $k ?>" value="1" <?= $tOpen($k) ? 'checked' : '' ?>></span>
                    </label>
                    <?php endforeach; ?>
                    <label class="row-flex between" style="padding:11px 14px;background:var(--surface-2);border-radius:11px;cursor:pointer">
                        <span class="small">Adımlarım'da <b>yalnızca sorumlusu olduğum</b> adımlar görünsün</span>
                        <span class="key"><input type="checkbox" name="t_only_step" value="1" <?= only_own_steps() ? 'checked' : '' ?>></span>
                    </label>
                    <label class="row-flex between" style="padding:11px 14px;background:var(--bright);border-radius:11px;cursor:pointer;border:1px solid var(--border-2)">
                        <span class="small"><b>E-posta ile de gönder</b> — açık bildirimler e-postanıza da düşer</span>
                        <span class="key"><input type="checkbox" name="t_email" value="1" <?= $tOpen('email') ? 'checked' : '' ?>></span>
                    </label>
                </div>
                <button type="submit" class="btn btn-brand mt-3">Tercihleri Kaydet</button>
            </form>
        </div>
    </div>

    <!-- Theme selection -->
    <div class="card" style="align-self:start">
        <div class="card-title mb-2">Tema Seçimi</div>
        <div class="cell-bottom mb-3">Panelin renk temasını seçin. Değişiklik anında uygulanır.</div>
        <div class="grid grid-2" style="gap:12px">
            <?php foreach (THEMES as $t => $info):
                [$label, $accent, $dark] = $info;
                $surface = $dark ? '#101318' : '#f2f0e6';
                if ($t === 'lime') $surface = '#0b0f0a';
                if ($t === 'navy') $surface = '#0a0f1e';
                if ($t === 'liquid-glass') $surface = 'linear-gradient(135deg,#12325e,#5c2440 60%,#0d4f46)';
                if ($t === 'liquid-glass-light') $surface = 'linear-gradient(135deg,#a8cff5,#f7c4cf 60%,#bdedd9)';
                if ($t === 'glass') $surface = 'linear-gradient(135deg,#3c1e78,#1e3a8a 60%,#701a4d)';
                if ($t === 'glass-light') $surface = 'linear-gradient(135deg,#c9b8f5,#b3ccf7 60%,#f2b9d7)';
                if ($t === 'clay') $surface = '#dce9f9';
                if ($t === 'maroon') $surface = '#1a060b';
                if ($t === 'cream') $surface = '#f8f2cb';
                if ($t === 'lime-light') $surface = '#f2f6e8';
                if ($t === 'navy-light') $surface = '#eef1f8';
                if ($t === 'maroon-light') $surface = '#f9f0ec'; ?>
            <button class="card theme-select-card" data-theme-select="<?= $t ?>" style="padding:0;overflow:hidden;border:2px solid <?= $u['theme'] === $t ? $accent : 'var(--border)' ?>">
                <div style="height:64px;background:<?= $surface ?>;position:relative">
                    <div style="position:absolute;bottom:9px;left:11px;width:30px;height:30px;border-radius:8px;background:<?= $accent ?>"></div>
                    <div style="position:absolute;bottom:14px;left:49px;right:11px;height:7px;border-radius:4px;background:<?= $accent ?>44"></div>
                    <div style="position:absolute;bottom:27px;left:49px;width:52px;height:5px;border-radius:3px;background:<?= $accent ?>22"></div>
                </div>
                <div class="row-flex between" style="padding:10px 13px"><span class="bold" style="font-size:12.5px"><?= $label ?></span><span class="label-dot" style="background:<?= $accent ?>"></span></div>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[data-theme-select]').forEach(card => {
    card.addEventListener('click', async () => {
        const theme = card.dataset.themeSelect;
        document.documentElement.setAttribute('data-theme', theme);
        const j = await api('theme_change', { theme });
        if (j.ok) { toast('Tema güncellendi', 'success'); setTimeout(() => location.reload(), 550); }
    });
});
</script>
<?php page_end(); ?>

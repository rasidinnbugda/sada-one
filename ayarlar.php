<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

sayfa_basi('Ayarlar', 'ayarlar');
?>
<div class="sayfa-ust"><div><div class="sayfa-baslik">Sistem Ayarları</div><div class="sayfa-alt">Genel yapılandırma ve e-posta gönderimi</div></div></div>

<div class="izgara izgara-2">
    <div class="kart">
        <div class="kart-baslik mb-3">Genel</div>
        <form data-ajax="ayar_kaydet" data-yenile="evet">
            <div class="form-grup"><label class="form-etiket">Site / Ajans Adı</label><input name="site_adi" class="girdi" value="<?= e(ayar('site_adi')) ?>"></div>
            <div class="form-grup">
                <label class="form-etiket">Logo</label>
                <?php if (ayar('site_logo')): ?>
                <div class="satir-esnek mb-2" style="gap:12px;padding:10px;background:var(--surface-2);border-radius:10px">
                    <img src="uploads/<?= e(ayar('site_logo')) ?>" style="max-height:40px;max-width:160px;object-fit:contain">
                    <button type="button" class="mini-btn" style="color:var(--tehlike)" data-eylem="ayar_gorsel_sil" data-anahtar="site_logo" data-onay="Logo kaldırılsın mı? (SADA yazısına dönülür)">Kaldır</button>
                </div>
                <?php endif; ?>
                <input type="file" name="site_logo" class="girdi" accept="image/*">
                <div class="form-ipucu">PNG önerilir (şeffaf zemin). Kenar çubuğunda, giriş ekranında ve müşteri raporunda görünür.</div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Favicon (sekme simgesi)</label>
                <?php if (ayar('site_favicon')): ?>
                <div class="satir-esnek mb-2" style="gap:12px;padding:10px;background:var(--surface-2);border-radius:10px">
                    <img src="uploads/<?= e(ayar('site_favicon')) ?>" style="width:24px;height:24px;object-fit:contain">
                    <button type="button" class="mini-btn" style="color:var(--tehlike)" data-eylem="ayar_gorsel_sil" data-anahtar="site_favicon" data-onay="Favicon kaldırılsın mı?">Kaldır</button>
                </div>
                <?php endif; ?>
                <input type="file" name="site_favicon" class="girdi" accept=".png,.ico,image/*">
                <div class="form-ipucu">Kare PNG veya ICO (32×32 önerilir).</div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Varsayılan Tema</label>
                <select name="varsayilan_tema" class="secim">
                    <?php foreach (TEMALAR as $k => $bilgi): ?>
                    <option value="<?= $k ?>" <?= ayar('varsayilan_tema') === $k ? 'selected' : '' ?>><?= $bilgi[0] ?> (<?= $bilgi[1] ?>)</option>
                    <?php endforeach; ?>
                </select>
                <div class="form-ipucu">Yeni kullanıcılar ve giriş ekranı bu temayı kullanır.</div>
            </div>
            <button type="submit" class="btn btn-marka mt-2">Kaydet</button>
        </form>
    </div>

    <div class="kart">
        <div class="kart-baslik mb-3">E-posta Bildirimleri (SMTP)</div>
        <form data-ajax="ayar_kaydet" data-yenile="hayir" id="smtpForm">
            <div class="form-grup">
                <label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="smtp_aktif" value="1" <?= ayar('smtp_aktif') === '1' ? 'checked' : '' ?> onchange="this.form.querySelector('[name=smtp_aktif]').value=this.checked?'1':'0'"> <span class="kalin">SMTP ile e-posta gönderimini etkinleştir</span></label>
                <div class="form-ipucu">Kapalıysa sunucunun PHP mail() fonksiyonu denenir.</div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">SMTP Sunucu</label><input name="smtp_host" class="girdi" value="<?= e(ayar('smtp_host')) ?>" placeholder="smtp.gmail.com"></div>
                <div class="form-grup"><label class="form-etiket">Port</label><input name="smtp_port" class="girdi" value="<?= e(ayar('smtp_port')) ?>" placeholder="465"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Kullanıcı (E-posta)</label><input name="smtp_kullanici" class="girdi" value="<?= e(ayar('smtp_kullanici')) ?>" placeholder="panel@sizindomain.com"></div>
            <div class="form-grup"><label class="form-etiket">Şifre</label><input type="password" name="smtp_sifre" class="girdi" placeholder="<?= ayar('smtp_sifre') ? '••••••••' : 'E-posta şifresi' ?>"><div class="form-ipucu">Değiştirmek istemiyorsanız boş bırakın.</div></div>
            <div class="form-grup"><label class="form-etiket">Gönderen Adresi</label><input name="smtp_gonderen" class="girdi" value="<?= e(ayar('smtp_gonderen')) ?>" placeholder="panel@sizindomain.com"></div>
            <div class="form-grup"><label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="eposta_bildirim" value="1" <?= ayar('eposta_bildirim') === '1' ? 'checked' : '' ?>> Görev/onay bildirimlerini e-posta ile de gönder</label></div>
            <div class="satir-esnek mt-2" style="gap:10px">
                <button type="submit" class="btn btn-marka">Kaydet</button>
                <button type="button" class="btn" data-eylem="test_eposta" data-yenile="hayir">Test E-postası Gönder</button>
            </div>
        </form>
    </div>
</div>

<div class="kart mt-3">
    <div class="kart-baslik mb-2">Google Workspace (Gmail) SMTP Kurulumu</div>
    <div class="metin-2 kucuk" style="line-height:1.8">
        <b>1.</b> Google Hesabınız → <b>Güvenlik</b> → <b>2 Adımlı Doğrulama</b>'yı açın (uygulama şifresi için zorunlu).<br>
        <b>2.</b> Aynı sayfadan <b>Uygulama şifreleri</b> → yeni bir uygulama şifresi oluşturun (16 haneli kod).<br>
        <b>3.</b> Yukarıda: Sunucu <code>smtp.gmail.com</code> · Port <code>465</code> · Kullanıcı = tam Workspace adresiniz · Şifre = <b>uygulama şifresi</b> (normal şifreniz değil) · Gönderen = aynı adres.<br>
        <b>4.</b> Kaydedip <b>Test E-postası Gönder</b> ile doğrulayın. Workspace günlük ~2.000 e-posta limiti bildirimler için fazlasıyla yeterlidir; ek ücret yoktur.<br>
        <span class="metin-muted">Alternatif: Hostinger e-postası kullanacaksanız sunucu <code>smtp.hostinger.com</code>, port 465.</span>
    </div>
</div>
<?php sayfa_sonu(); ?>

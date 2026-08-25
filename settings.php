<?php
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
$u = require_admin();

page_start('Ayarlar', 'settings');
?>
<div class="sayfa-ust"><div><div class="sayfa-baslik">Sistem Ayarları</div><div class="sayfa-alt">Genel yapılandırma ve e-posta gönderimi</div></div></div>

<div class="izgara izgara-2">
    <div class="kart">
        <div class="kart-baslik mb-3">Genel</div>
        <form data-ajax="setting_save" data-refresh="evet">
            <div class="form-grup"><label class="form-etiket">Site / Ajans Adı</label><input name="site_name" class="girdi" value="<?= e(setting('site_adi')) ?>"></div>
            <div class="form-grup">
                <label class="form-etiket">Logo</label>
                <?php if (setting('site_logo')): ?>
                <div class="satir-esnek mb-2" style="gap:12px;padding:10px;background:var(--surface-2);border-radius:10px">
                    <img src="uploads/<?= e(setting('site_logo')) ?>" style="max-height:40px;max-width:160px;object-fit:contain">
                    <button type="button" class="mini-btn" style="color:var(--tehlike)" data-action="setting_image_delete" data-setting_key="site_logo" data-approval="Logo kaldırılsın mı? (SADA yazısına dönülür)">Kaldır</button>
                </div>
                <?php endif; ?>
                <input type="file" name="site_logo" class="girdi" accept="image/*">
                <div class="form-ipucu">PNG önerilir (şeffaf zemin). Kenar çubuğunda, giriş ekranında ve müşteri raporunda görünür.</div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Favicon (sekme simgesi)</label>
                <?php if (setting('site_favicon')): ?>
                <div class="satir-esnek mb-2" style="gap:12px;padding:10px;background:var(--surface-2);border-radius:10px">
                    <img src="uploads/<?= e(setting('site_favicon')) ?>" style="width:24px;height:24px;object-fit:contain">
                    <button type="button" class="mini-btn" style="color:var(--tehlike)" data-action="setting_image_delete" data-setting_key="site_favicon" data-approval="Favicon kaldırılsın mı?">Kaldır</button>
                </div>
                <?php endif; ?>
                <input type="file" name="site_favicon" class="girdi" accept=".png,.ico,image/*">
                <div class="form-ipucu">Kare PNG veya ICO (32×32 önerilir).</div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Koyu Tema Logosu <span class="metin-muted" style="font-weight:400">(opsiyonel)</span></label>
                <?php if (setting('site_logo_dark')): ?>
                <div class="satir-esnek mb-2" style="gap:12px;padding:10px;background:#14181f;border-radius:10px">
                    <img src="uploads/<?= e(setting('site_logo_dark')) ?>" style="max-height:40px;max-width:160px;object-fit:contain">
                    <button type="button" class="mini-btn" style="color:var(--tehlike)" data-action="setting_image_delete" data-setting_key="site_logo_dark" data-approval="Koyu tema logosu kaldırılsın mı?">Kaldır</button>
                </div>
                <?php endif; ?>
                <input type="file" name="site_logo_dark" class="girdi" accept="image/*">
                <div class="form-ipucu">Koyu temalarda bu logo gösterilir (örn. beyaz sürüm). Boşsa normal logo kullanılır.</div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Koyu Tema Favicon'u <span class="metin-muted" style="font-weight:400">(opsiyonel)</span></label>
                <?php if (setting('site_favicon_dark')): ?>
                <div class="satir-esnek mb-2" style="gap:12px;padding:10px;background:#14181f;border-radius:10px">
                    <img src="uploads/<?= e(setting('site_favicon_dark')) ?>" style="width:24px;height:24px;object-fit:contain">
                    <button type="button" class="mini-btn" style="color:var(--tehlike)" data-action="setting_image_delete" data-setting_key="site_favicon_dark" data-approval="Koyu tema favicon'u kaldırılsın mı?">Kaldır</button>
                </div>
                <?php endif; ?>
                <input type="file" name="site_favicon_dark" class="girdi" accept=".png,.ico,image/*">
                <div class="form-ipucu">Koyu temalarda kullanılır. Boşsa normal favicon geçerlidir.</div>
            </div>
            <div class="form-grup">
                <label class="form-etiket">Varsayılan Tema</label>
                <select name="default_theme" class="secim">
                    <?php foreach (THEMES as $k => $info): ?>
                    <option value="<?= $k ?>" <?= setting('varsayilan_tema') === $k ? 'selected' : '' ?>><?= $info[0] ?> (<?= $info[1] ?>)</option>
                    <?php endforeach; ?>
                </select>
                <div class="form-ipucu">Yeni kullanıcılar ve giriş ekranı bu temayı kullanır.</div>
            </div>
            <button type="submit" class="btn btn-marka mt-2">Kaydet</button>
        </form>
    </div>

    <div class="kart">
        <div class="kart-baslik mb-3">E-posta Bildirimleri (SMTP)</div>
        <form data-ajax="setting_save" data-refresh="hayir" id="smtpForm">
            <div class="form-grup">
                <label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="smtp_is_active" value="1" <?= setting('smtp_aktif') === '1' ? 'checked' : '' ?> onchange="this.form.querySelector('[name=smtp_is_active]').value=this.checked?'1':'0'"> <span class="kalin">SMTP ile e-posta gönderimini etkinleştir</span></label>
                <div class="form-ipucu">Kapalıysa sunucunun PHP mail() fonksiyonu denenir.</div>
            </div>
            <div class="form-satir">
                <div class="form-grup"><label class="form-etiket">SMTP Sunucu</label><input name="smtp_host" class="girdi" value="<?= e(setting('smtp_host')) ?>" placeholder="smtp.gmail.com"></div>
                <div class="form-grup"><label class="form-etiket">Port</label><input name="smtp_port" class="girdi" value="<?= e(setting('smtp_port')) ?>" placeholder="465"></div>
            </div>
            <div class="form-grup"><label class="form-etiket">Kullanıcı (E-posta)</label><input name="smtp_user" class="girdi" value="<?= e(setting('smtp_kullanici')) ?>" placeholder="panel@sizindomain.com"></div>
            <div class="form-grup"><label class="form-etiket">Şifre</label><input type="password" name="smtp_password" class="girdi" placeholder="<?= setting('smtp_sifre') ? '••••••••' : 'E-posta şifresi' ?>"><div class="form-ipucu">Değiştirmek istemiyorsanız boş bırakın.</div></div>
            <div class="form-grup"><label class="form-etiket">Gönderen Adresi</label><input name="smtp_sender" class="girdi" value="<?= e(setting('smtp_gonderen')) ?>" placeholder="panel@sizindomain.com"></div>
            <div class="form-grup"><label class="satir-esnek" style="gap:9px;cursor:pointer"><input type="checkbox" name="email_notification" value="1" <?= setting('eposta_bildirim') === '1' ? 'checked' : '' ?>> Görev/onay bildirimlerini e-posta ile de gönder</label></div>
            <div class="satir-esnek mt-2" style="gap:10px">
                <button type="submit" class="btn btn-marka">Kaydet</button>
                <button type="button" class="btn" data-action="test_email" data-refresh="hayir">Test E-postası Gönder</button>
            </div>
        </form>
    </div>
</div>

<div class="izgara izgara-2 mt-3">
    <!-- Google Drive integration -->
    <div class="kart">
        <div class="kart-baslik mb-2">📁 Google Drive Entegrasyonu</div>
        <div class="hucre-alt mb-3">Çekimlerin Drive'a aktarılıp aktarılmadığını panel otomatik denetler. Kurulum bir kez yapılır.</div>
        <?php $driveKurulu = is_file(ROOT . '/storage/google-service.json'); ?>
        <?php if ($driveKurulu): ?>
        <div class="satir-esnek mb-2" style="gap:10px;padding:10px 14px;background:var(--parlak);border-radius:10px">
            <span class="kucuk">✅ Servis hesabı anahtarı yüklü.</span>
        </div>
        <?php endif; ?>
        <form data-ajax="setting_save" data-refresh="evet">
            <div class="form-grup"><label class="form-etiket">Servis Hesabı Anahtarı (JSON)</label>
                <input type="file" name="google_service_key" class="girdi" accept=".json">
            </div>
            <div class="satir-esnek" style="gap:10px">
                <button type="submit" class="btn btn-marka">Kaydet</button>
                <button type="button" class="btn" data-action="drive_test" data-refresh="hayir">Bağlantıyı Test Et</button>
            </div>
        </form>
        <div class="metin-2 kucuk mt-3" style="line-height:1.8">
            <b>Kurulum (≈15 dk, ücretsiz):</b><br>
            <b>1.</b> <a href="https://console.cloud.google.com" target="_blank" style="color:var(--marka)">console.cloud.google.com</a> → yeni proje oluşturun (örn. "sada-one").<br>
            <b>2.</b> <b>API'ler ve Hizmetler → Kitaplık</b> → "Google Drive API"yi bulup <b>Etkinleştir</b>'e basın.<br>
            <b>3.</b> <b>API'ler ve Hizmetler → Kimlik Bilgileri → Kimlik bilgisi oluştur → Hizmet hesabı</b> → ad verin, oluşturun (rol seçmeye gerek yok).<br>
            <b>4.</b> Hizmet hesabına tıklayın → <b>Anahtarlar → Anahtar ekle → JSON</b> → inen dosyayı yukarıdan yükleyin.<br>
            <b>5.</b> Takip edilecek Drive klasörlerini, hizmet hesabının e-posta adresiyle (<code>...@...iam.gserviceaccount.com</code>) <b>Görüntüleyen</b> olarak paylaşın.<br>
            <b>6.</b> Dosya (müşteri) kartındaki <b>Drive Klasörü</b> alanına klasör linkini yapıştırın — hepsi bu.
        </div>
    </div>

    <!-- AI integration -->
    <div class="kart">
        <div class="kart-baslik mb-2">🪄 Yapay Zeka (Claude)</div>
        <div class="hucre-alt mb-3">Aylık rapor taslağı, içerik fikri üretimi ve görev özetleme için kullanılır. Kullanım başına ücretlendirilir; anahtar <a href="https://console.anthropic.com" target="_blank" style="color:var(--marka)">console.anthropic.com</a>'dan alınır.</div>
        <form data-ajax="setting_save" data-refresh="hayir">
            <div class="form-grup"><label class="form-etiket">Anthropic API Anahtarı</label>
                <input type="password" name="anthropic_api_key" class="girdi" placeholder="<?= setting('anthropic_api_key') ? '••••••••••••' : 'sk-ant-...' ?>">
                <div class="form-ipucu">Değiştirmek istemiyorsanız boş bırakın.</div>
            </div>
            <div class="form-grup"><label class="form-etiket">Model</label>
                <select name="ai_model" class="secim">
                    <?php foreach (['claude-opus-5' => 'Claude Opus 5 (önerilen — en yüksek kalite)', 'claude-sonnet-5' => 'Claude Sonnet 5 (hızlı ve ekonomik)', 'claude-haiku-4-5' => 'Claude Haiku 4.5 (en ekonomik)'] as $mk => $mv): ?>
                    <option value="<?= $mk ?>" <?= (setting('ai_model') ?: 'claude-opus-5') === $mk ? 'selected' : '' ?>><?= $mv ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="satir-esnek" style="gap:10px">
                <button type="submit" class="btn btn-marka">Kaydet</button>
                <button type="button" class="btn" data-action="ai_test" data-refresh="hayir">Bağlantıyı Test Et</button>
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
<?php page_end(); ?>

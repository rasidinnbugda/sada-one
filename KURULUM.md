# SADA One — Ajans Yönetim Sistemi
## Hostinger Kurulum Rehberi

Bu rehber, sistemi Hostinger hostinginize sıfırdan kurmanız için hazırlanmıştır. Teknik bilgi gerektirmez; adımları sırayla takip etmeniz yeterli.

---

## 📋 Gereksinimler
- Hostinger hosting paketi (herhangi bir Premium/Business paket yeterli)
- Bir alan adı (domain) veya alt alan adı
- PHP 7.4 veya üzeri (Hostinger'da varsayılan olarak PHP 8.x aktiftir)

---

## 1️⃣ Adım: Veritabanı Oluşturma

1. Hostinger **hPanel**'e giriş yapın.
2. Sol menüden **Veritabanları → MySQL Veritabanları** bölümüne girin.
3. **Yeni MySQL Veritabanı Oluştur** kısmında:
   - **Veritabanı adı:** örn. `sada` (otomatik başına `u123456789_` eklenir → `u123456789_sada`)
   - **Kullanıcı adı:** örn. `sada` (yine önekli olur → `u123456789_sada`)
   - **Şifre:** güçlü bir şifre belirleyin ve **bir yere not edin**.
4. **Oluştur** butonuna tıklayın.
5. Oluşan şu üç bilgiyi kaydedin — kurulum sihirbazında gireceksiniz:
   - Veritabanı adı (tam, önekli hali)
   - Kullanıcı adı (tam, önekli hali)
   - Şifre

> **Not:** Hostinger'da veritabanı **sunucu adresi** neredeyse her zaman `localhost`'tur.

---

## 2️⃣ Adım: Dosyaları Sunucuya Yükleme

### Yöntem A — Dosya Yöneticisi ile (önerilen, kolay)
1. hPanel → **Dosyalar → Dosya Yöneticisi**'ni açın.
2. Sitenizin kök klasörüne gidin: genellikle **`public_html`**.
   - Sistemi ana alan adında (`siteniz.com`) çalıştıracaksanız: dosyaları doğrudan `public_html` içine koyun.
   - Alt klasörde (`siteniz.com/panel`) çalıştıracaksanız: `public_html/panel` klasörü oluşturup içine koyun.
3. Bilgisayarınızdaki **`sada-panel`** klasörünün **içindeki tüm dosyaları** (klasörün kendisini değil, içindekileri) seçip **ZIP** olarak sıkıştırın.
4. Dosya Yöneticisi'nde **Yükle** ile ZIP dosyasını yükleyin.
5. Yüklenen ZIP'e sağ tıklayıp **Extract (Çıkart)** deyin.
6. Çıkarma sonrası klasör yapısı şöyle olmalı (kök dizinde):
   ```
   public_html/
   ├── index.php
   ├── login.php
   ├── ajax.php
   ├── ... (diğer .php dosyaları)
   ├── install/
   ├── includes/
   ├── assets/
   └── uploads/
   ```

### Yöntem B — FTP ile
1. hPanel → **Dosyalar → FTP Hesapları**'ndan FTP bilgilerinizi alın.
2. **FileZilla** gibi bir FTP programıyla bağlanın.
3. `sada-panel` içindeki tüm dosyaları `public_html` (veya alt klasöre) yükleyin.

---

## 3️⃣ Adım: Klasör İzinleri

Kurulum sihirbazı `config.php` dosyasını **kök dizine** yazacak ve `uploads/` klasörüne dosya kaydedecek. Bu iki konumun yazılabilir olması gerekir.

Hostinger'da genellikle varsayılan izinler (**755**) yeterlidir. Sorun yaşarsanız Dosya Yöneticisi'nde:
- `uploads` klasörüne sağ tık → **İzinler (Permissions)** → **755** olarak ayarlayın.

---

## 4️⃣ Adım: Kurulum Sihirbazını Çalıştırma

1. Tarayıcınızda şu adresi açın:
   - Ana alan adıysa: `https://siteniz.com/install/`
   - Alt klasördeyse: `https://siteniz.com/panel/install/`
2. Sihirbaz 4 adımda ilerler:
   - **Adım 1 — Gereksinimler:** Yeşil "Uygun" rozetlerini görün, **Devam Et**.
   - **Adım 2 — Veritabanı:** 1. adımda kaydettiğiniz bilgileri girin:
     - Sunucu: `localhost`
     - Veritabanı adı, Kullanıcı adı, Şifre
     - **Bağlantıyı Test Et**.
   - **Adım 3 — Site & Yönetici:** Ajans adı ve kendi yönetici hesabınızı (ad, e-posta, şifre) oluşturun.
   - **Adım 4 — Tamamlandı** 🎉
3. Kurulum; 25 veritabanı tablosunu oluşturur, yönetici hesabınızı açar ve hazır **akış şablonları** (Sosyal Medya, Video Prodüksiyon, Web Sitesi, Grafik) ile **talep formlarını** (Yeni İş, Revizyon, Çekim, Destek) yükler.

---

## 5️⃣ Adım: Güvenlik — `install` Klasörünü Silin

⚠️ **ÇOK ÖNEMLİ:** Kurulum bitince Dosya Yöneticisi'nden **`install`** klasörünü **silin**. Bu klasör durdukça sistem güvenlik uyarısı gösterir ve birileri kurulumu yeniden çalıştırabilir.

Artık `https://siteniz.com/` (veya `/panel/`) adresinden **giriş** yapabilirsiniz.

---

## 6️⃣ Adım: E-posta Bildirimleri (Opsiyonel ama Önerilir)

Görev atama, onay talebi gibi durumlarda otomatik e-posta göndermek için:

1. hPanel → **E-postalar → E-posta Hesapları**'ndan domain'iniz için bir hesap açın:
   - örn. `panel@siteniz.com`
2. Panelde **Yönetim → Ayarlar → E-posta Bildirimleri (SMTP)** bölümüne gidin:
   - **SMTP ile gönderimi etkinleştir:** işaretleyin
   - **SMTP Sunucu:** `smtp.hostinger.com`
   - **Port:** `465`
   - **Kullanıcı:** `panel@siteniz.com` (tam e-posta adresi)
   - **Şifre:** e-posta hesabının şifresi
   - **Gönderen Adresi:** `panel@siteniz.com`
3. **Kaydet** → **Test E-postası Gönder** ile doğrulayın (kendi adresinize gelir).

---

## 🎨 Temalar

Sistem 4 kurumsal renk temasıyla gelir. Her kullanıcı sol alttaki renk noktalarından veya **Profil & Tema** sayfasından temasını seçebilir:
- **Lime** `#b1fb01` (varsayılan)
- **Navy** `#182f5d`
- **Cream** `#f8f2cb`
- **Maroon** `#610714`

Varsayılan temayı **Ayarlar**'dan değiştirebilirsiniz.

---

## 👥 Roller ve İlk Kullanım

| Rol | Yetki |
|-----|-------|
| **Yönetici** | Her şey: kullanıcılar, ayarlar, akış/form şablonları, finans, raporlar |
| **Proje Yöneticisi (PM)** | Dosya/proje/görev yönetimi, finans, raporlar |
| **Ekip Üyesi** | Atanan görevler, içerik, onay gönderme, mesajlaşma |
| **Müşteri** | Sadece kendi dosyasının projeleri; onay verme, talep açma, mesajlaşma |

**Önerilen ilk adımlar:**
1. **Yönetim → Kullanıcılar**'dan ekip arkadaşlarınızı ekleyin.
2. **Dosyalar**'dan ilk markanızı/şirketinizi oluşturun.
3. O dosya altında bir **Proje** açın (Aylık / Dönemsel / Tek Seferlik).
4. Müşteri için **Kullanıcılar**'dan `Müşteri` rolünde hesap açıp ilgili dosyaya bağlayın.
5. **Akış Şablonları**'nı kendi süreçlerinize göre düzenleyin.

---

## 🔧 Sık Karşılaşılan Sorunlar

**"config.php yazılamadı" hatası:**
Kök dizinin yazma izni yok. Dosya Yöneticisi'nden kök klasörü 755 yapın veya Hostinger destek hattına yazın.

**Veritabanına bağlanılamıyor:**
Veritabanı adı/kullanıcı adının **önekli tam halini** (`u123456789_...`) girdiğinizden emin olun. Sunucu `localhost` olmalı.

**Türkçe karakterler bozuk görünüyor:**
Veritabanı `utf8mb4` ile oluşturulur; sistem bunu otomatik ayarlar. Sorun sürerse veritabanını silip yeniden oluşturun ve kurulumu tekrarlayın (önce `install` klasörünü geri yükleyin).

**Dosya yükleyemiyorum:**
`uploads/` klasörü izinlerini 755 yapın. Maksimum dosya boyutu 50 MB'dır (Hostinger PHP limitleri daha düşükse hPanel → **Gelişmiş → PHP Yapılandırması**'ndan `upload_max_filesize` artırılabilir).

**E-posta gitmiyor:**
Ayarlar'daki SMTP bilgilerini kontrol edin. Port 465 çalışmazsa 587 deneyin. E-posta hesabının hPanel'de gerçekten oluşturulduğundan emin olun.

---

## 🔄 Sürüm Güncelleme (mevcut kurulumlar için)

Sistemi daha önce kurduysanız ve yeni sürüm dosyalarını yüklediyseniz:
1. Yeni dosyaları eskilerin üzerine yükleyin (`config.php`'ye dokunmayın).
2. Tarayıcıdan `https://siteniz.com/guncelle.php` adresini açın (yönetici girişi gerekir) — veritabanına yeni kolonlar/tablolar güvenle eklenir.
3. Bittikten sonra `guncelle.php` dosyasını sunucudan silin.

## ⏰ Tekrarlayan Görevler (opsiyonel cron)

Tekrarlayan görevler, herhangi bir ekip üyesi paneli açtığında saatte bir otomatik kontrol edilir — **ek kurulum gerekmez**. Daha hassas zamanlama isterseniz:
- hPanel → **Gelişmiş → Cron İşleri** → saatlik yeni iş ekleyin:
  `curl -s "https://siteniz.com/cron.php?anahtar=SADA One"`
  (anahtar parametresi, Ayarlar'daki site adınızla aynı olmalıdır)

## 📁 Yedekleme

Düzenli yedek için iki şeyi saklayın:
1. **Veritabanı:** hPanel → **Veritabanları → phpMyAdmin** → veritabanını seçin → **Dışa Aktar (Export)**.
2. **uploads/ klasörü:** yüklenen tüm dosyalar burada; Dosya Yöneticisi'nden indirin.

---

Kurulumla ilgili takıldığınız yerde bu dosyadaki adımları tekrar gözden geçirin. İyi çalışmalar! 🚀

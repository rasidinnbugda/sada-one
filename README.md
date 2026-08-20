# SADA One

Dijital ajanslar için PHP + MySQL tabanlı iş yönetim paneli. Arayüz Türkçe, kod tabanı İngilizce.

> **v5.0 not:** Kod tabanı (dosya adları, veritabanı şeması, değişkenler, yorumlar) tamamen
> İngilizce'ye taşındı. Eski (Türkçe şemalı) kurulumlar ilk sayfa açılışında kendini onarır:
> şema otomatik olarak yeni adlara taşınır, veri kaybolmaz; eski sayfa adresleri 301 ile yönlenir.
> Ayrıca PHP oturum kilidi erken bırakılarak çok sekmeli kullanımdaki takılma sorunu giderildi.

## Özellikler

- **Dosyalar → Projeler → Görevler** hiyerarşisi (aylık düzenli, dönemsel ve tek seferlik proje tipleri)
- Kanban / tablo görünümlü görev takibi, iş akışı adımları, kontrol listeleri, bağımlılık kilidi, tekrarlayan görevler
- İçerik takvimi (görevlerle iki yönlü senkron), prodüksiyon takvimi, toplantılar, randevular, zaman çizelgesi
- Müşteri paneli: talep formları, onay/revizyon süreci, puanlama, randevu talebi, PDF rapor
- Ekip içi mesajlaşma (kanallar + DM), duyurular, bildirimler (e-posta seçenekli)
- Finans: teklif/fatura, gelir-gider, maaşlar, kâr/zarar, nakit projeksiyon, cari ekstre
- Stüdyo: ekipman envanteri, zimmet takibi, SD kart döngüsü
- Sosyal medya hesap ve metrik takibi
- 10 renk teması, rol + izin sistemi (yönetici, PM, ekip, finans, stajyer, müşteri)

## Gereksinimler

- PHP 8.1+ (PDO MySQL eklentisi ile)
- MySQL 8.0+ / MariaDB 10.5+ (v5.0 şema taşıması `RENAME COLUMN` kullanır)
- Paylaşımlı hosting yeterli (Hostinger üzerinde test edildi)

## Kurulum

1. Dosyaları sunucunuza yükleyin.
2. Tarayıcıda `install/index.php` adresini açın ve sihirbazı izleyin (veritabanı bilgileri + yönetici hesabı).
3. Kurulum bitince `install/` klasörünü sunucudan silin.

## Güncelleme

**Panel içinden (önerilen):** Yönetim → **Güncelleme** sayfasında iki seçenek vardır:
- **GitHub'dan Güncelle:** son yayınlanan sürüm denetlenir, tek tıkla indirilip kurulur.
- **ZIP Paketi Yükle:** `sada-one.zip` paketini seçin, sistem açar ve uygular.

Her iki yöntemde de kurulum öncesi `backups/` klasörüne otomatik kod yedeği alınır;
`config.php`, `uploads/` ve yedekler korunur; veritabanı şeması otomatik güncellenir.
Aynı ZIP sıfırdan kurulum için de kullanılır (`install/` içerir).

**Elle:** Dosyaları mevcut kurulumun üzerine yükleyip tarayıcıda `guncelle.php` çalıştırmak da mümkündür.

## E-posta (SMTP)

Ayarlar → E-posta Bildirimleri bölümünden yapılandırılır. Google Workspace için:
sunucu `smtp.gmail.com`, port `465`, şifre alanına 2 adımlı doğrulama sonrası
oluşturulan **uygulama şifresi** girilir. Ayrıntılı adımlar panelin Ayarlar sayfasındadır.

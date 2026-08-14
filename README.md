# SADA Panel

Dijital ajanslar için PHP + MySQL tabanlı iş yönetim paneli. Tamamı Türkçe.

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
- MySQL 5.7+ / MariaDB 10.4+
- Paylaşımlı hosting yeterli (Hostinger üzerinde test edildi)

## Kurulum

1. Dosyaları sunucunuza yükleyin.
2. Tarayıcıda `install/index.php` adresini açın ve sihirbazı izleyin (veritabanı bilgileri + yönetici hesabı).
3. Kurulum bitince `install/` klasörünü sunucudan silin.

## Güncelleme

1. Yeni sürüm dosyalarını mevcut kurulumun üzerine yükleyin (`config.php` depoda yoktur, ayarlarınız korunur).
2. Tarayıcıda `guncelle.php`'yi çalıştırın (şema güncellemeleri idempotenttir, mevcut veriye dokunmaz).
3. Bittiğinde `guncelle.php`'yi sunucudan silin.

## E-posta (SMTP)

Ayarlar → E-posta Bildirimleri bölümünden yapılandırılır. Google Workspace için:
sunucu `smtp.gmail.com`, port `465`, şifre alanına 2 adımlı doğrulama sonrası
oluşturulan **uygulama şifresi** girilir. Ayrıntılı adımlar panelin Ayarlar sayfasındadır.

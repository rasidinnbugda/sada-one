# SADA One — Yol haritası

Bu belge, 7.0 temizliğinden sonra SADA One'ı adım adım geliştirme planının ve alınan kararların tek kaynağıdır. Her aşama önce konuşulur, sonra kendi sürümü ve veri taşımasıyla çıkar. Mevcut veriler her zaman korunur.

## Model: Dosya → Ay / Proje → İş → Adım

| Katman | Koddaki adı | Anlamı |
|---|---|---|
| Dosya | `clients` | Müşterinin tamamı: ana sorumlu, dosya ekibi, iletişim kişileri, bilgi bankası |
| Ay | aylık projenin `periods` kaydı | Aylık hizmetin o ayı (3. aşamada plan → üretim → kapanış döngüsü kazanır) |
| Proje | `projects` | Kendi bütçesi ve takvimi olan ayrı iş (kampanya, web sitesi…) |
| İş | `tasks` | Tek bir çıktı. Müşteriye gidenler "Müşteri işi", gitmeyenler "İç iş" |
| Adım | `task_steps` | İş'in içindeki sıralı parçalar; her adımın bir sorumlusu ya da uzmanlık havuzu vardır |

Her İş bir projeye bağlıdır (aylık projede bir aya). Çekim takvimde etkinlik olarak kalır ve İş'lere bağlanır; bir çekim günü birden çok İş'e görüntü sağlayabilir.

## Aşamalar

0. **Temizlik** — 7.0 ✅ (7.0.1: dosya yüklemeleri onarıldı)
1. **İş'i tekleştirme** — 7.1 (bu belge aşağıda)
2. **Adım motoru:** iş türü tarifleri, adımlarda uzmanlık etiketi (Tasarım, Kurgu, Metin, Çekim, Koordinasyon), atanmayan adım uzmanlık havuzuna düşer, İş'in durumu adımlardan kendiliğinden hesaplanır.
3. **Ay ve Proje:** aylık projelerde "Ay" plan → üretim → kapanış; planlı/gündem ayrımı; ay sonu raporu aya bağlanır; kapsam sinyali; dosya bazlı onay kuralları.
4. **Eylemden durum:** dosya yüklenince adım ilerler; müşteri hesapsız linkten onaylar, revize üretime döner.
5. **Yardım eden ekran:** "Şimdi" kartı ve Bugün; işi izleyen Kule (Yönetici Takip'in yerine).
6. **Sadeleştirme:** menü ve takvimler toparlanır; İş'e katılan ayrı sayfalar ve eski `contents` tablosu kaldırılır.
7. **Görünüm:** açık tasarım sistemi, sayfa geçişleri, sunucuda yazı tipleri.

## 1. aşama — İş'i tekleştirme (7.1)

Bugün bir çıktı dört kayıtta yaşıyordu: görev, içerik, onay, talep. 1. aşamada tek kayıtta birleşiyor.

**İş'in yeni alanları:** `kind` (Müşteri işi / İç iş), `publish_date` + `publish_time` + `platforms` (içerikten gelir).

**Durumlar:** Yapılacak → Devam Ediyor → İç Onayda → Müşteride → Tamamlandı → Yayınlandı, bir de İptal. Kanban sütunları korunur; "Yayınlandı" sütunu eklenir, iptal edilenler panoda görünmez. Tamamlandı, Yayınlandı ve İptal "kapalı" sayılır. 2. aşamada ilk dört durum aktif adımın türünden hesaplanacak.

**Akış:**
- İş sayfasından "Müşteriye gönder" → onay kaydı açılır, İş "Müşteride" olur.
- Müşteri onaylarsa İş tamamlanır (bitmemiş adım varsa "Devam Ediyor"a döner). Revize ya da ret → "Devam Ediyor", not İş'in onay geçmişinde.
- "Yayınlandı" işaretlenince İş kapanır (tamamlanma kuralları geçerli).
- Talep kabul edilince İş olur, müşterinin cevapları İş'in açıklamasına yazılır.
- Müşteri İş'lerin durumunu değiştiremez; yalnızca onay yoluyla yanıt verir.

**Taşıma (bir kez, önce tam veritabanı yedeği):**
- Görevler İş olur, numaralar korunur.
- Göreve bağlı içerik o İş'e katılır (tarih, saat, platform; içerik metni açıklamaya eklenir). Bir içeriğe birden fazla görev bağlıysa içerik en eski göreve katılır, diğerlerine not düşülür.
- Göreve bağlı olmayan içerik yeni bir İş olur. Projesi yoksa dosyanın aylık projesine, yoksa son projesine bağlanır; hiç projesi olmayan dosya için "<dosya> — İçerikler" projesi açılır. Aylık projede yayın ayının dönemi atanır.
- Onaylar İş'in onay geçmişine bağlanır. Hiçbir şeye bağlı olmayan onay kendi başına bir İş olur.
- Durum birleştirme: yayınlandıysa Yayınlandı; tamamlandıysa ya da içerik onaylandıysa Tamamlandı; son onay bekliyorsa Müşteride; aksi hâlde en ileri durum.
- Eski `contents` tablosu ve `tasks.content_id` bu sürümde silinmez (yalnızca okunur arşiv); 6. aşamada kaldırılır.

**Ekranlar:** Görevler → İşler (pano, tablo). İçerik takvimi İş'lerin yayın tarihini gösterir ve oradan İş planlanır. Onaylar sayfası ve proje sekmesi onay geçmişini İş'e bağlı gösterir. Sayfa kaldırma 6. aşamada.

# SADA One — Yol haritası

Bu belge, 7.0 temizliğinden sonra SADA One'ı adım adım geliştirme planının ve alınan kararların tek kaynağıdır. Her aşama önce konuşulur, sonra kendi sürümü ve veri taşımasıyla çıkar. Mevcut veriler her zaman korunur.

## Model: Dosya → Ay / Proje → İş → Adım

| Katman | Koddaki adı | Anlamı |
|---|---|---|
| Dosya | `clients` | Müşterinin tamamı: ana sorumlu, dosya ekibi, iletişim kişileri, bilgi bankası |
| Ay | aylık projenin `periods` kaydı | Aylık hizmetin o ayı: planlama → üretim → kapanış → kapandı |
| Proje | `projects` | Kendi bütçesi ve takvimi olan ayrı iş (kampanya, web sitesi…) |
| İş | `tasks` | Tek bir çıktı. Müşteriye gidenler "Müşteri işi", gitmeyenler "İç iş" |
| Adım | `task_steps` | İş'in içindeki sıralı parçalar; her adımın bir sorumlusu ya da uzmanlık havuzu vardır |

Her İş bir projeye bağlıdır (aylık projede bir aya). Çekim takvimde etkinlik olarak kalır ve İş'lere bağlanır; bir çekim günü birden çok İş'e görüntü sağlayabilir.

## Aşamalar

0. **Temizlik** — 7.0 ✅ (7.0.1: dosya yüklemeleri onarıldı)
1. **İş'i tekleştirme** — 7.1 ✅
2. **Adım motoru** — 7.2 ✅: iş türü tarifleri, adımlarda uzmanlık etiketi (Tasarım, Kurgu, Metin, Çekim, Koordinasyon), atanmayan adım uzmanlık havuzuna düşer, İş'in durumu adımlardan kendiliğinden hesaplanır.
3. **Ay ve Proje** — 7.3 ✅: aylık projelerde "Ay" plan → üretim → kapanış; planlı/gündem ayrımı; ay sonu raporu aya bağlanır; kapsam sinyali; dosya bazlı onay kuralları.
4. **Eylemden durum:** dosya yüklenince adım ilerler; müşteri hesapsız linkten onaylar, revize üretime döner.
5. **Yardım eden ekran:** "Şimdi" kartı ve Bugün; işi izleyen Kule (Yönetici Takip'in yerine).
6. **Sadeleştirme:** menü ve takvimler toparlanır; İş'e katılan ayrı sayfalar ve eski `contents` tablosu kaldırılır.
7. **Görünüm:** açık tasarım sistemi, sayfa geçişleri, sunucuda yazı tipleri.

## 3. aşama — Ay ve Proje (7.3)

**Ay** (aylık projenin `periods` kaydı) dört evreden geçer: **Planlama** (planlı işler kurulur, istenirse plan müşteriye gider), **Üretim**, **Kapanış** (açık işler sonraki aya taşınır, aylık rapor yazılır), **Kapandı**. Evreyi proje yöneticisi bir adım ileri ya da geri alır; açık iş varken ay kapanmaz. Elle açılan ay ve yeni aylık projenin ilk ayı planlamada başlar; takvimden ya da tekrarlayan işten kendiliğinden oluşan ay başladıysa üretimde, ileride ise planlamada açılır.

**Planlı / Gündem:** müşteri işleri ayın planındaysa "Planlı", ay içinde çıktıysa "Gündem"dir. Plan onayı yalnızca planlı müşteri işlerinin listesini taşır. İç işlerin şeridi yoktur.

**Plan onayı:** onaylar tablosunda `period_id` ile tutulur; ayın `plan_status`'u son plan onayının cevabından gelir (eski bir onaya geç gelen cevap ayı oynatmaz). Onay, planlamadaki ayı üretime geçirir.

**Kapsam sinyali:** ayın (iptal edilmemiş) müşteri işi sayısı, önceki üç ayın ortalaması en az 3 iken bu ortalamanın 1,4 katını geçerse uyarı çıkar.

**Dosya:** strateji, marka kiti ve onay kuralları (`plan_approval`, `no_approval_types`) dosyada durur. Onayı atlanan türden yeni açılan işler müşteri onayı adımı olmadan kurulur; açık işler etkilenmez.

## 2. aşama — Adım motoru (7.2)

**İş türü** (eski adıyla akış şablonu) bir tariftir: adımlar, her adımın **uzmanlığı** (Koordinasyon, Tasarım, Kurgu, Çekim, Metin, Geliştirme — yönetici düzenler) ve **türü**: üretim, iç kontrol, müşteri onayı, yayın. İsteğe bağlı varsayılan kişi; boşsa Koordinasyon adımı proje yöneticisine, diğerleri havuza gider.

**Durum adımlardan gelir:** aktif üretim adımı → Devam Ediyor (hiç adım bitmediyse Yapılacak), iç kontrol → İç Onayda, müşteri onayı → Müşteride, yayın → Tamamlandı (yayın bekliyor); tüm adımlar bitince Tamamlandı, son adım yayınsa Yayınlandı. Adımlı işte elle yalnızca İptal / Yeniden aç. Adımsız işler elle yönetilir.

**Havuz:** sahibi olmayan aktif adım uzmanlığın havuzundadır; uzmanlığı olan herkese bildirim gider ("yalnızca bana atananlar" tercihini açanlar hariç), ilk "Ben alıyorum" diyen alır. İşin "atananı" aktif adımın sahibidir.

**Geri gönderme:** iç kontrol ya da müşteri revizesi işi en son biten üretim adımına döndürür; sebep işin tartışmasına yazılır. Müşteri onayı adımını müşterinin cevabı ilerletir; yönetici "Onay geldi / Revize geldi" ile müşteri adına kaydedebilir.

**Taşıma:** akış şablonları iş türü olur; adımların türü ve uzmanlığı adlarından çıkarılır, sabit "Revizyon" adımı kalkar; adımlı işlerin durumu adımlardan yeniden hesaplanır (kapanmış işler kapalı kalır); kişilerin uzmanlıkları unvanlarından önerilir.

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

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
4. **Eylemden durum** — 7.4 ✅: dosya yüklenince adım ilerler; müşteri hesapsız linkten onaylar, revize üretime döner.
5. **Yardım eden ekran** — 7.5 ✅: "Şimdi" kartı ve Bugün; işi izleyen Kule (Yönetici Takip'in yerine).
6. **Sadeleştirme** — 7.6 ✅: menü ve takvimler toparlanır; İş'e katılan ayrı sayfalar ve eski `contents` tablosu kaldırılır.
7. **Görünüm** — 7.7 ✅: açık tasarım sistemi, sayfa geçişleri, sunucuda yazı tipleri.

## 7. aşama — Görünüm (7.7)

- **SADA Açık** (`studio`) varsayılan tema: soğuk kâğıt zemin (`#eceff3`), beyaz sayfalar, SADA laciverti (`#182f5d`) mürekkep ve ana renk; SADA yeşili (`#b1fb01`) yalnızca "fosforlu kalem" olarak — aktif menü öğesi, Şimdi etiketi, bugünün tarihi, sıradaki adım, seçili mercek. Başlıklar Bricolage Grotesque, metin Instrument Sans, rakamlar JetBrains Mono.
- **Geçiş:** kullanıcı teması eski varsayılan `lime` olanlar ve varsayılan tema ayarı `studio`'ya taşınır; sütunun varsayılanı da `studio` olur. Diğer temalar ve bilerek seçilmiş temalar korunur.
- **Yazı tipleri sunucuda:** `assets/fonts/` (latin + latin-ext woff2, SIL OFL), `assets/css/fonts.css`; hiçbir sayfa Google Fonts'a bağlanmaz. Yazı tipi rolleri CSS değişkenleridir (`--font-display`, `--font-body`, `--font-mono`, `--font-logo`); eski temalar Space Grotesk / Inter ile kalır.
- **Sayfa geçişleri:** belgeler arası View Transitions (`@view-transition`); menü ve üst çubuk sabit kalır, içerik kayarak gelir; `prefers-reduced-motion` açıksa kapalı.

## 6. aşama — Sadeleştirme (7.6)

- **Tek takvim:** `calendar.php` ayın her şeyini gösterir — etkinlikler (çekim, toplantı, teslim, diğer) ve müşteri işlerinin yayın tarihleri — ve merceklerle (`data-kind`) süzülür; mercek tarayıcıda hatırlanır. Yayın planı (`content-calendar.php`, sürükle-bırak planlama), Toplantılar, Randevular ve Zaman çizelgesi aynı takvimin görünümleridir (`CALENDAR_VIEWS`): ekip menüsünde tek "Takvim" vardır, her görünümün üstünde görünüm sekmeleri durur. Eski adresler aynen çalışır.
- **Menü:** üstte Panel, Bugün, İşler, Takvim, Dosyalar, Projeler, Mesajlar; gruplar Stüdyo, Analiz (Kule dahil), Ekip & Fikir. Onaylar yalnızca müşteri menüsünde; ekip onayları işin, ayın ve Kule'nin içinden görür (`approvals.php` adresi durur).
- **Eski veri:** `contents` tablosu, `tasks.content_id`, `approvals.content_id` ve `periods.status` göç zincirinin en sonunda, veritabanı yedeği alındıktan sonra kaldırılır (`legacy:`). Kurulum şeması tarihsel komutlar temiz çalışsın diye bu yapıları hâlâ kurar; aynı adım kurulumun hemen ardından onları kaldırır.

## 5. aşama — Yardım eden ekran (7.5)

- **Şimdi** (Panel'in başı) ve **Bugün** (`today.php`) aynı kaynaktan beslenir (`includes/now.php`): kişinin aktif adımları (gecikmiş olan önce, sonra öncelik ve tarih), adımsız işleri, uzmanlıklarının havuzu, bugünün çekimleri ve yayınları, okunmamış gelişmeleri.
- **Bekleme süresi:** her adım aktif olduğu anı `task_steps.activated_at`'te taşır (kurulumda, bitirince, geri gönderince, yeniden açınca, tekrarlayan kopyada). Güncellemede aktif adımlar için son biten adımın tarihi, yoksa işin oluşturulma tarihi yazılır.
- **Kule** (`tower.php`, yalnızca yönetici): uzmanlık başına aktif adım / kişi ve havuz; 3 gündür aynı adımda duran (müşteri onayı hariç), gecikmiş, 3 gündür müşteride bekleyen (güncel) onaylar, Drive'a aktarılmamış çekimler; dosya sağlığı (kırmızı: 3+ geciken, 5+ gün bekleyen onay ya da son 60 günde cevapların yarısından fazlası revize; sarı: daha hafifi); bu ayın evreleri. "Yönetici notları" sekmesi eski Yönetici Takip tablosudur; `manager-tracking.php` oraya yönlenir. Kişi bazlı performans sıralaması yoktur.
- **Mikasa:** yalnızca ekip görür (yönetici genel olarak kapatabilir, herkes kendisi için kapatabilir — `notification_preferences.mikasa`). Cümleler sunucuda kişinin kendi işinden kurulur (`includes/mikasa.php`), en fazla 6; sayfa ilk açılışta birini, sonra görünür kaldıkça 25 dakikada bir diğerini söyler, günlük sayaç tarayıcıda tutulur. 23:00–07:00 arası uyur. Hareketler Web Animations API ile, azaltılmış hareket tercihinde durağan. Varsayılan görsel panelin kendi çizimidir; yönetici kullanım hakkı olan bir görsel yükleyebilir.

## 4. aşama — Eylemden durum (7.4)

Durum, yapılan işin kendisinden çıkar:

- **Teslim et:** sıradaki üretim adımını yapabilen kişi dosya ve/veya bağlantı yükleyerek adımı bitirir (`step_deliver`). Dosyalar işin eklerine, teslim notu tartışmaya düşer. Dosyasız "Bitir" de durur.
- **Hesapsız onay:** her onayın gizli bir `token`'ı vardır; `approve.php?t=…` hesap istemeden onay / revize / ret alır. Cevap, paneldeki cevapla aynı yoldan işler (`approval_apply_reply`): müşteri onayı adımı biter ya da iş son üretim adımına döner; aylık planda ay üretime geçer. Yalnızca işin (ya da ayın) **güncel** onayı cevaplanabilir; eski linkler durumu gösterir. Linkten gelen cevapta yazılan ad `reply_name`'de tutulur.
- **Çekim ↔ İş:** `event_tasks` bir çekim gününü birden çok işe bağlar. Çekim Drive'a aktarıldı sayılınca (elle, SD kart aktarımıyla ya da elle eklenen Drive linkiyle) bağlı işlerde sırada bekleyen çekim adımı (uzmanlığı Çekim ya da adında "çekim" geçen üretim adımı) biter (`shoot_transferred`).
- **Hatırlatma:** 3 gün cevapsız kalan güncel onay için müşteriye bir kez hatırlatma, gönderene bilgi gider (`reminded_at`).

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

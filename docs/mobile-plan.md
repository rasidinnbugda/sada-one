# SADA One — Mobil Uygulama Yol Haritası

## Kısa cevap

**"Bu sistem olduğu gibi, eksiksiz ve hatasız bir mobil uygulamaya çevrilebilir mi?"**
Tek adımda ve "olduğu gibi" — hayır; kademeli olarak — evet. Nedeni: panel ~35 ekranlık,
sunucu tarafında çalışan bir PHP uygulaması. Native bir uygulama (Flutter/React Native)
bu ekranların tamamının mobilde yeniden yazılması demektir; "eksiksiz ve hatasız" iddiası
ancak uzun bir test döngüsüyle kazanılır, baştan garanti edilemez. Doğru strateji üç kademedir:

## Kademe 1 — PWA (v6.0 ile geldi, ek maliyet yok) ✅

Panel artık **kurulabilir bir web uygulaması**: telefonda tarayıcıdan açıp
"Ana ekrana ekle" denince kendi simgesiyle, tam ekran, uygulama gibi çalışır.

- Tek kod tabanı: panele eklenen her özellik anında "uygulamada" da var.
- Çevrimdışı kabuk: bağlantı koptuğunda şık bir çevrimdışı ekranı.
- Eksiği: mağazada yer almaz, push bildirimi (şimdilik) yok.

## Kademe 2 — TWA ile Play Store (1-2 gün iş)

PWA'yı **Trusted Web Activity** paketiyle Android uygulamasına sarıp Google Play'e
koymak mümkündür (tek seferlik 25$ geliştirici hesabı). Kod değişikliği gerektirmez;
"mağazadan indirilen kurumsal uygulama" görüntüsü sağlar. iOS tarafında App Store
için Apple, saf web sarmalayıcılara mesafelidir; iPhone'da PWA (ana ekrana ekleme) kalır.

## Kademe 3 — Web Push bildirimleri (panel içi geliştirme, ~2-3 gün)

Görev atamaları, onaylar ve hatırlatmaların telefona **anlık bildirim** olarak düşmesi
PWA üzerinde Web Push (VAPID) ile yapılabilir — native uygulama gerektirmez.
Android'de tam, iOS 16.4+ üzerinde "ana ekrana eklenmiş" PWA'larda çalışır.

## Kademe 4 — Native (Flutter) — yalnızca gerekirse

Ne zaman mantıklı: kamera ile doğrudan medya yükleme, çevrimdışı veri girişi,
arka planda konum vb. cihaz özellikleri şart olursa.

- **Mimari hazır:** Panelin tüm işlemleri tek `ajax.php` JSON API'sinden geçer;
  Flutter uygulaması bu API'yi olduğu gibi kullanabilir (yeni backend gerekmez).
  API anahtarlı oturum için küçük bir token katmanı eklenir (~2 gün).
- **Kapsam:** ~35 ekran → gerçekçi süre, tek geliştiriciyle 6-10 hafta çekirdek
  (görevler, takvim, bildirim, onay, mesajlaşma) + 4-6 hafta kalan modüller.
- **Öneri:** Önce Kademe 1-3'ü kullanın; native'e ekip gerçekten cihaz özelliği
  istediğinde geçin. O gün geldiğinde API hazır olduğundan işin büyük yarısı bitmiş olur.

## Özet tablo

| Yol | Maliyet/Süre | Mağaza | Push | Kod tabanı |
|---|---|---|---|---|
| PWA (mevcut) | 0 — hazır | Hayır | Yok | Tek |
| TWA (Play) | 1-2 gün + 25$ | Android ✓ | Kademe 3 ile | Tek |
| Web Push | 2-3 gün | — | Android ✓ / iOS 16.4+ ✓ | Tek |
| Flutter native | 10-16 hafta | İkisi de ✓ | Tam ✓ | İkinci kod tabanı |

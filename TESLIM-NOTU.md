# GAÜN Kampüs Haritası — Teslim Paketi

Bu paket, projenin yayına hazır tam kaynak kodunu ve başlangıç verisini içerir.

## Paketin içeriği

- **Kaynak kod**: sürüm kontrolündeki temiz kopya (gizli dosyalar — `.env`,
  yedekler, geçici dosyalar — pakete dahil DEĞİLDİR).
- **`docker/init/09-baslangic-verisi.sql`**: 229 kampüs konumu ve site
  ayarları. İlk kurulumda **otomatik yüklenir** — ayrıca bir veri yükleme
  adımı GEREKMEZ. (Yalnızca içerik; kullanıcı hesabı/şifre İÇERMEZ.)

## Kurulum

README.md dosyasındaki **"Hızlı Başlangıç (Docker, ~5 dakika)"** bölümünü
izleyin: üç komutla site, veritabanı şeması VE konum verisi hazır gelir;
ardından ilk yönetici hesabı tek komutla oluşturulur.

"Bugün Kampüste" panelinin (duyuru/haber/etkinlik/yemek menüsü) dolması
için GAÜN resmi kaynak senkronizasyonunun bir kez çalıştırılması gerekir —
komut Hızlı Başlangıç'ın 4. adımındadır (internet gerektirir, ~1 dakika).

## Üretime çıkmadan önce

README'deki **"Üretim notları"** bölümünü mutlaka uygulayın: paketteki
`dev_*` parolaları yalnızca yerel geliştirme içindir; üretimde tüm parolalar
güçlü/benzersiz değerlerle değiştirilmeli, DB hesapları elle oluşturulmalı
ve site HTTPS arkasında çalıştırılmalıdır.

## Testler

Paket, 4 bağımsız test takımıyla gelir (119 PHP birim + 45 entegrasyon +
21 JS + 25 tarayıcı e2e — teslim anında tümü yeşil). Çalıştırma komutları
README "Testler" bölümündedir.

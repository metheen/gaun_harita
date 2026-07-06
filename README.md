# GAÜN Kampüs Haritası

Gaziantep Üniversitesi kampüsü için interaktif harita (Leaflet) ve kurumsal
yönetim paneli. Ziyaretçiler haritada bina/fakülte arar, canlı yürüme/araç
rotası alır (kendi barındırılan OSRM), TR/EN dil ve açık/koyu tema seçer.
Yöneticiler `/bordo/` panelinden konumları, site ayarlarını, kullanıcıları ve
güvenliği kod değişikliği yapmadan yönetir.

## Hızlı Başlangıç (Docker, ~5 dakika)

Gereksinim: Docker Desktop (Compose v2).

```bash
# 1) Ortam değişkenleri — dev varsayılanları hazır, düzenleme gerekmez.
cp .env.example .env

# 2) Veritabanı + web sunucusunu başlat (ilk açılışta şema otomatik kurulur).
docker compose up -d --build db web

# 3) İlk yönetici hesabını oluştur (KULLANICI ve PAROLA'yı değiştirin).
docker compose exec web php -r '
  require "/var/www/html/db.php";
  db("write")->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)")
      ->execute(["KULLANICI", password_hash("PAROLA", PASSWORD_DEFAULT), "super_admin"]);
  echo "Yönetici oluşturuldu.\n";'
```

```bash
# 4) (İsteğe bağlı, ~1 dk) "Bugün Kampüste" panelini doldur: GAÜN resmi
#    sitelerinden duyuru/haber/etkinlik/yemek menüsünü bir kez çek.
docker compose run --rm web php scripts/sync_gaun_sources.php --verbose
```

| Adres | Ne |
|---|---|
| http://localhost:8080 | Halka açık harita |
| http://localhost:8080/bordo/ | Yönetim paneli (3. adımdaki hesapla girin) |

229 kampüs konumu ilk açılışta otomatik yüklenir
(`docker/init/09-baslangic-verisi.sql`) — harita dolu gelir. Konumları
panelden düzenleyebilir, CSV ile toplu güncelleyebilirsiniz. Canlı rota için
OSRM servislerini de ayağa kaldırmak isterseniz aşağıdaki "Harita içi canlı
rota" bölümüne bakın (zorunlu değil; kuruluncaya kadar rota kuş uçuşu
çizgiye düşer).

## Yapı

```
.
├── public/                 # Web sunucusunun DocumentRoot'u (tek HTTP yüzeyi)
│   ├── index.php           # Haritayı servis eden giriş noktası (+ GAUN_CONFIG)
│   ├── get_locations.php   # Konum ağacı JSON API (yalnız aktif kayıtlar)
│   ├── analytics.php       # Kullanım olaylarını kaydeden public endpoint
│   ├── assets/, uploads/   # Derlenmiş CSS/JS ve panelden yüklenen görseller
│   └── bordo/              # Panel sayfalarının public wrapper'ları
├── index.html              # Harita arayüzü kaynağı (Leaflet + Tailwind)
├── bordo/                  # Yönetim paneli uygulaması
│   ├── dashboard.php       # Analitik + sistem sağlığı + uyarılar
│   ├── locations.php       # Konum CRUD, CSV, toplu işlem, filtre, sayfalama
│   ├── settings.php        # Site ayarları (dil, harita, kurumsal bilgiler)
│   ├── users.php           # Kullanıcı/rol yönetimi (super_admin)
│   ├── security.php        # Bakım modu, giriş olayları, kritik işlem audit'i
│   └── auth/bootstrap/csrf/helpers/layout_*.php  # Ortak altyapı
├── config.php, db.php      # Env'den yapılandırma + PDO fabrikası (read/write)
├── admin_ops.php           # Site durumu, giriş audit'i, IP maskeleme
├── settings_ops.php        # Site ayarları şeması/doğrulama/public config
├── auth_roles.php          # Rol katmanı (super_admin / editor / viewer)
├── audit_log.php           # Kritik işlem denetim kaydı
├── system_health.php       # DB/sync/OSRM sağlık kontrolleri
├── security_headers.php    # Ortak HTTP güvenlik başlıkları
├── gaun_enrich.php         # GAÜN resmi kaynak zenginleştirme (cache okuma)
├── scripts/                # CLI: sync_gaun_sources.php (resmi kaynak sync)
├── docker/                 # Dockerfile, OSRM kurulumu, init/ migration'ları
├── tests/                  # php (birim) / php-integration / js / e2e
└── private/                # Web kökü DIŞI hassas dosyalar (gitignore'lu)
```

## Yapılandırma

Web sunucusunun kök dizini proje kökü değil, **`public/`** olmalıdır. Docker
geliştirme ortamı bunu otomatik yapar (`/var/www/html/public`). Böylece
`config.php`, `db.php`, `private/` ve panelin dahili PHP dosyaları doğrudan HTTP
ile indirilemez. Panel public wrapper'lar üzerinden `/bordo/` adresinden çalışır.

Kimlik bilgileri **yalnızca ortam değişkenlerinden** okunur; koda gömülü
varsayılan yoktur. Tanımlı değilse uygulama güvenli şekilde durur.
`.env.example` dosyasını `.env` olarak kopyalayıp doldurun (proje kökündeki
`.env` otomatik yüklenir) veya sunucu ortamında tanımlayın:

```
DB_HOST, DB_NAME
DB_READ_USER,  DB_READ_PASS    # ön yüz için okuma yetkili hesap
DB_WRITE_USER, DB_WRITE_PASS   # panel için okuma-yazma yetkili hesap
APP_DEBUG=false                # üretimde hata ayrıntılarını gizler
```

Gerçek ortam değişkenleri `.env` dosyasına göre önceliklidir.

## Frontend CSS build

Ana harita arayüzü Tailwind sınıflarını `index.html` içinde tutar; production'da
CDN yerine derlenmiş statik CSS servis edilir:

```bash
npm install
npm run build:css
```

- Kaynak CSS: `assets/css/tailwind.css`
- Tailwind yapılandırması: `tailwind.config.js`
- Derlenen çıktı: `public/assets/css/tailwind.css`
- Geliştirme sırasında izleme için:

  ```bash
  npm run watch:css
  ```

Docker web container'ı `public/assets/css/tailwind.css` dosyasını doğrudan
servis eder; Tailwind sınıfları değiştiğinde `docker compose up` öncesinde veya
deploy adımında `npm run build:css` çalıştırın.

## Dil ve tema

Public harita arayüzünde üst arama satırında iki küçük kontrol bulunur:

- `TR/EN` düğmesi arayüz dilini Türkçe/İngilizce değiştirir.
- Ay/güneş ikonlu düğme açık/koyu tema arasında geçiş yapar.

Seçimler `localStorage` içinde `gaun_lang` ve `gaun_theme` anahtarlarıyla
hatırlanır. EN modda konumlar önce veritabanındaki `name_en` ve
`description_en` alanlarını kullanır; bu alanlar boşsa mevcut frontend sözlüğü
ve son olarak Türkçe metin fallback olarak kalır. Sabit UI metinleri, rota
mesajları, popup aksiyonları, bülten sekmeleri ve hızlı kısayollar da çevrilir.

## Admin görsel yükleme

Konum ekleme/düzenleme formunda `image_url` alanı korunur; istenirse aynı formdan
JPG, PNG veya WebP dosyası da yüklenebilir. Dosya yüklendiğinde kayıt
`uploads/locations/<rastgele-ad>.<uzantı>` yolu ile güncellenir, mevcut dış URL
kullanan kayıtlar değişmeden çalışır.

- Upload klasörü: `public/uploads/locations/`
- Kabul edilen türler: `jpg`, `jpeg`, `png`, `webp`
- Dosya boyutu sınırı: 4 MB
- Docker volume ile çalışırken klasör proje içinde yazılabilir durumdadır.
  Üretim sunucusunda web/PHP kullanıcısının bu klasöre yazma izni olmalıdır.

## PWA desteği

Ana harita arayüzü temel PWA dosyalarıyla gelir:

- Manifest: `public/manifest.json`
- Service worker: `public/sw.js`
- İkonlar: `public/assets/icons/icon-192.png`, `public/assets/icons/icon-512.png`

Service worker uygulama kabuğunu, derlenmiş CSS'i, logo/ikonları ve temel public
API yanıtlarını cache'ler. OpenStreetMap harita karoları cache-first stratejisiyle
sınırlı tutulur (`MAX_TILE_ENTRIES=120`); böylece zayıf ağda yakın zamanda görülen
alanlar daha hızlı açılırken depolama kontrolsüz büyümez. Admin paneli (`/bordo/`)
ve POST istekleri service worker tarafından cache'lenmez.

Chrome DevTools > Application sekmesinde `Manifest`, `Service Workers` ve
`Cache Storage` alanlarından doğrulayabilirsiniz. Offline testi için sayfayı bir
kez online açın, ardından DevTools'ta Offline moduna alıp `/` adresini yenileyin;
uygulama kabuğu açılmalıdır.

## Güvenlik notları

- Tüm veritabanı erişimleri parametreli sorgu (prepared statement) kullanır.
- Panel çıktıları `e()` ile HTML kaçışından geçer; haritada popup içeriği
  istemci tarafında kaçışlanır.
- Formlar CSRF jetonu ile korunur; durum değiştiren işlemler yalnızca POST.
- Panel girişi IP başına hız sınırlıdır (brute-force koruması): 15 dakikada
  5 başarısız denemeden sonra o IP 15 dakika kilitlenir. Denemeler web kökü
  dışındaki `private/login_attempts.json` dosyasında tutulur (bkz.
  `bordo/throttle.php`).
- `db.dump` yedeği web kökünün **dışında** `private/` klasöründe tutulur ve
  sürüm kontrolüne alınmaz (bkz. `.gitignore`). `.bash_history` gibi başıboş
  dosyalar web köküne **konulmamalıdır**.
- `public/` klasörü gerçek web köküdür. Proje kökünü doğrudan web root yapmak
  yalnızca `.htaccess` savunmasına güvenmek anlamına gelir; üretimde önerilmez.
- Apache altında `.htaccess` savunma katmanı olarak nokta dosyalarına,
  döküm/sql/log uzantılarına, PHP dahillerine ve `private/` klasörüne
  doğrudan HTTP erişimini engeller. **nginx** kullanıyorsanız eşdeğerini
  server bloğuna ekleyin:

  ```nginx
  location ~ /\.            { deny all; }
  location ~* \.(dump|sql|log|bak)$ { deny all; }
  location ^~ /private/     { deny all; }
  ```
- Ön yüz en az yetki ilkesiyle yalnızca okuma yetkili DB hesabı kullanır.
- Oturum çerezi sertleştirilmiştir: `HttpOnly` (JS erişemez), `SameSite=Lax`
  (CSRF azaltma) ve HTTPS altında `Secure` (bkz. `bordo/bootstrap.php`).
  Giriş sonrası oturum kimliği yenilenir (`session_regenerate_id`).

### Üretim notları

`.env.example` ve `docker/init/01-users.sql` içindeki `dev_*` parolaları
**yalnızca yerel geliştirme** içindir (DB dışarıya yalnızca
`localhost:3307`'den açıktır). Üretime çıkarken:

1. `MYSQL_ROOT_PASSWORD`, `DB_READ_PASS`, `DB_WRITE_PASS` için güçlü ve
   benzersiz parolalar üretin (`openssl rand -hex 24`), `.env`'e yazın.
2. DB hesaplarını `01-users.sql`'i KULLANMADAN elle oluşturun (aynı GRANT
   yapısıyla: okuma hesabına yalnız `SELECT`, yazma hesabına
   `SELECT, INSERT, UPDATE, DELETE`).
3. `ADMIN_AUDIT_SALT` için ayrı bir rastgele değer verin
   (`openssl rand -hex 32`).
4. `APP_DEBUG=false` olduğundan ve sitenin HTTPS arkasında çalıştığından
   emin olun.

## Admin güvenlik ve site durumu

Yönetim panelindeki `Güvenlik` sayfası, kod değişikliği yapmadan public haritayı
yayına alma/bakım moduna çekme ve admin giriş olaylarını izleme ekranıdır.

- `Harita public sitede yayında` anahtarı kapanırsa `/`, `get_locations.php` ve
  `get_campus_bulletin.php` bakım yanıtı verir; `/bordo/` admin paneli açık kalır.
- Bakım mesajı panelden düzenlenir ve ziyaretçiye gösterilir.
- Başarılı giriş, başarısız giriş, rate-limit kilidi ve çıkış olayları
  `admin_login_events` tablosuna yazılır.
- Panelde düz IP saklanmaz/gösterilmez; maskeli IP ve hash tutulur.
- Aktif brute-force kilitleri `private/login_attempts.json` üzerinden görüntülenir
  ve gerekirse panelden temizlenebilir.

Yeni Docker veritabanı kurulumlarında `docker/init/03-admin-ops.sql` otomatik
çalışır. Mevcut `dbdata` volume'ü olan yerel kurulumlarda tabloyu bir kez
eklemek için:

```bash
docker compose exec -T db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" < /docker-entrypoint-initdb.d/03-admin-ops.sql'
```

Uygulama tarafı tablo yoksa admin paneli düşürmez; güvenlik sayfasında uyarı
gösterir ve login audit yazımı sessizce atlanır.

## Kurumsal kontrol merkezi (Ayarlar, Kullanıcılar, Rol, Audit)

Panel, kod değişikliği gerektirmeden sitenin operasyonel parçalarını yönetir:

- **Ayarlar** (`bordo/settings.php`, yalnız `super_admin`): varsayılan dil
  (TR/EN), harita başlangıç merkezi/zoom, kurumsal başlık/açıklama, iletişim
  e-posta/telefon, PWA metinleri. Değerler `app_settings` tablosunda tutulur;
  public tarafa `window.GAUN_CONFIG.settings` ile enjekte edilir
  (`settings_ops.php`). İletişim e-postası doldurulursa haritadaki
  "Görüş ve Öneri" mailto linki o adresi kullanır (boşsa
  `Harita@gantep.edu.tr`).
- **Kullanıcılar** (`bordo/users.php`, yalnız `super_admin`): kullanıcı
  listeleme/oluşturma, rol atama (`super_admin` / `editor` / `viewer`),
  şifre sıfırlama. Kendi rolünüzü değiştiremezsiniz (kilitlenme koruması).
  Roller: `super_admin` her şeye, `editor` konum yönetimine erişir;
  `viewer` yalnızca görüntüler (mutasyon butonları gizlenir, sunucu tarafı
  `auth_require_role()` ayrıca engeller — bkz. `auth_roles.php`).
- **Kritik işlem audit'i** (`audit_log.php` → `audit_log` tablosu): konum
  ekleme/silme/toplu işlem, CSV import, ayar değişikliği, kullanıcı
  işlemleri, bakım modu aç/kapa olayları maskeli IP ile kaydedilir; Güvenlik
  sayfasındaki "Son Kritik İşlemler" kartında görüntülenir.
- **Dashboard sistem sağlığı**: DB bağlantısı, GAÜN sync cache durumu ve
  OSRM erişilebilirliği kartları (`system_health.php`); eksik-veri
  uyarıları (koordinatı/İngilizcesi/görseli/bağlantısı eksik veya pasif
  konumlar) `locations.php?data_filter=...` linkleriyle listelenir.
- **Konum yönetimi ek özellikleri**: aktif/pasif bayrağı (pasif konumlar
  public haritada ve `get_locations.php` çıktısında görünmez), eksik-veri
  filtreleri, toplu aktif/pasif ve toplu kategori taşıma, 50 kayıt/sayfa
  sayfalama (kaydetme sonrası kaydın bulunduğu sayfaya yönlendirilir).

Var olan tek `admin` hesabı migration ile otomatik `super_admin` olur —
kimse panelin dışında kalmaz. OSRM sağlık kontrolü Docker içi servis adlarını
kullanır (`osrm-car:5000` / `osrm-foot:5000`); farklı bir topolojide
`OSRM_DRIVE_HEALTH_URL` / `OSRM_WALK_HEALTH_URL` env değişkenleriyle
geçersiz kılınabilir.

## GAÜN resmi kaynak senkronizasyonu

Harita verileri, GAÜN resmi sitelerinden çekilen bilgilerle zenginleştirilebilir
(birim web siteleri, duyuru/haber başlıkları, yemek menüsü, öğrenci işleri
bağlantıları). Public sayfalar **hiçbir zaman** dış ağa istek atmaz; veri ayrı
bir CLI komutuyla çekilir ve cache'lenir:

```bash
docker compose run --rm web php scripts/sync_gaun_sources.php
# ayrıntılı çıktı için: ... sync_gaun_sources.php --verbose
```

- Cache: `private/cache/gaun_sources.json` (web kökü dışında, atomik yazılır).
- Kaynak erişilemezse ilgili bölümün **eski cache'i korunur**; uygulama bozulmaz.
- `get_locations.php` yalnızca cache'i okur ve konumları temkinli eşleştirir:
  birebir normalize-isim **veya** birebir website-host eşleşmesi. Koordinatlar
  asla değiştirilmez; emin olunmayan veri konuma bağlanmaz. Eşleşen konumlara
  `gaun: {website, source_url, fetched_at, matched_by}` alanı eklenir.
- Personel/hoca verileri de aynı cache'e `staff` bölümü olarak yazılır. Sync,
  resmî birim sitelerindeki personel/kadro/yönetim sayfalarını gezer; ad,
  unvan, birim/bölüm, kaynak URL ve doğrulama zamanı saklar. Public tarafta
  yalnızca haritadaki bir konumla güvenli şekilde eşleşen kayıtlar gösterilir;
  konum uydurulmaz.
- Ana sayfada görünmeyen ama doğrulanmış GAÜN kaynakları da manuel seed listesiyle
  cache'e dahil edilir: OBS, OİDB, AKBİS, UZEM, GAUNAY, fakülte/enstitü kanonik
  adresleri, bazı mühendislik bölüm alt siteleri, SKS, Personel, İdari/Mali
  İşler, Yapı İşleri, Erasmus/Farabi ve TÖMER. Çözülmeyen veya sertifika adı
  uyuşmayan adresler veri kaynağı yapılmaz; çalışan kanonik karşılığı varsa o
  kullanılır.
- Yönetim panelindeki konum listesinde eşleşen kayıtlar “✓ resmi” rozetiyle
  gösterilir (kaynak URL + son güncelleme zamanı rozet ipucundadır).
- Güvenlik: script yalnızca `*.gaziantep.edu.tr` / `*.gantep.edu.tr`
  host'larına istek atar (SSRF koruması, yönlendirmeler dahil), bağlantı/okuma
  zaman aşımı kullanır ve yalnızca CLI'dan çalışır.
- Docker ortamında `sync` servisi aynı PHP image'ı ve volume'ü kullanarak bu
  komutu her gün çalıştırır. Varsayılan saat `SYNC_RUN_AT=05:10` ve saat dilimi
  `TZ=Europe/Istanbul` değerleridir; `.env` üzerinden değiştirilebilir.
- Scheduler logları Docker stdout/stderr'e yazılır:

  ```bash
  docker compose logs -f sync
  ```

- Manuel tetikleme için scheduler servisinin ortamıyla aynı komutu çalıştırın:

  ```bash
  docker compose run --rm sync php scripts/sync_gaun_sources.php --verbose
  ```

### Yemekhane kartı (bugünkü menü)

Resmî yemekhane konumlarının (adı/türü “yemekhane” içeren; kafeler hariç)
harita popup'ında **Bugünkü Menü** kartı gösterilir: yemek adları, kalori
bilgisi, toplam kalori, “Kaynak: GAÜN” ve aylık liste bağlantısı. Veri
yukarıdaki sync komutuyla `https://www.gaziantep.edu.tr/yemek/` sayfasından
çekilip cache'e yazılır; **public sayfa hiçbir dış istek atmaz**. Cache bayat
(dünün menüsü) ya da boşsa kart “Menü bilgisi şu an yok.” der — uygulama
bozulmaz. Menünün güncel kalması için sync'i günlük çalıştırın.

### Bugün Kampüste (kampüs bülteni)

Haritanın sağ alt köşesindeki 📣 butonu, cache'lenmiş GAÜN duyuru/haber/
önemli-bağlantı listelerini sekmeli, kapatılabilir küçük bir panelde gösterir
(`get_campus_bulletin.php`). Veriler yukarıdaki **sync cache'inden** gelir;
**public istek sırasında hiçbir dış siteye erişilmez / scraping yapılmaz**.
Her liste en çok 5 kayıttır, yalnız güvenli alanlar (başlık, URL, tarih,
kaynak) döner ve URL'ler GAÜN host'larıyla sınırlanır. Duyurular konumlara
otomatik bağlanmaz (yanlış eşleşme riski); panel geneldir. Cache yoksa panel
"kayıt yok" der, buton erişilemezse hiç görünmez — harita etkilenmez.

## Hızlı ihtiyaç kısayolları

Harita üstündeki hızlı kısayollar öğrencinin yaygın ihtiyaçlarını tek dokunuşla
listelemeye yarar: yemekhane, kütüphane, öğrenci işleri, fakülteler, sağlık,
spor, kafe, ATM/banka ve durak aramaları. Kısayollar ayrı veri tutmaz; mevcut
`get_locations.php` çıktısından oluşturulan arama indeksini kullanır. Arama
Türkçe karakterlere toleranslıdır (`kutuphane`, `ogrenci isleri` gibi yazımlar
da eşleşir). Kısayol sonucu kullanıcıyı otomatik ilk kayda götürmez; ilgili
konumları listeler, seçim kullanıcıda kalır.

## Kampüs Asistanı

Ana arama alanının altında küçük bir soru-cevap alanı bulunur. Kullanıcı
“öğrenci belgesi alacağım”, “yemek yiyeceğim”, “hasta oldum” gibi amacını
yazar; asistan mevcut `get_locations.php` verisinden uygun konumu arar. Bilgi
yetersizse kısa bir soru sorar (ör. öğrenci/personel yemekhanesi); yeterli
eşleşme bulunca “Aradığınız yer burası” diyerek konumu haritada açar ve rota
kartını gösterir. Cache'te doğrulanmış personel varsa kişi adı veya unvan +
birim sorgularını da yakalar: ör. “Bülent Haznedar nerede?” dendiğinde ilgili
bölüm konumunu açar; “bilgisayar mühendisliği hocaları” gibi aramalar da aynı
indekse dahil edilir. Ayrıca GAÜN resmî web ekosistemindeki işlem sayfalarını
önerebilir: akademik takvim, OBS/e-kayıt, Bologna/ders içeriği, öğrenci işleri
formları, yemek listesi, kütüphane, Engelsiz Yaşam, Erasmus/Farabi,
uluslararası öğrenci ofisi, kariyer, öğrenci toplulukları, etkinlik/haber,
Bilgi İşlem, eduroam, öğrenci web-mail, e-rehber ve GİMER. Bu özellik dış yapay
zeka API'si kullanmaz; güvenli, yerel anahtar kelime ve konum skorlama mantığıyla
çalışır.

## Admin kullanım analitiği

Yönetim panelindeki `Dashboard` sayfası, haritanın gerçek kullanımından gelen
hafif metrikleri gösterir: bugünkü arama sayısı, konum görüntüleme sayısı, rota
isteği sayısı, son 30 günün popüler konumları/kategorileri ve en çok aranan
terimler. Public harita bu olayları `/analytics.php` endpoint'ine gönderir.

Dashboard sayaçları ham tabloyu doğrudan "gerçek" saymaz. Yeni kayıtlar
`is_verified=1` olarak işaretlenmeden panel özetlerine girmez: arama olayları
test/otomasyon işareti taşımamalı, konum/rota olayları ise `campus_locations`
tablosundaki aktif bir konum id'siyle eşleşmelidir. Playwright/PHPUnit gibi
test koşuları ve eski belirsiz legacy kayıtlar panel sayaçlarından dışlanır.
Bu nedenle veri yoksa sayaçlar 0 kalır; panel kendiliğinden/demo değer üretmez.

Analitik tasarımı gizlilik dostudur: IP adresi, kullanıcı ajanı, oturum kimliği,
öğrenci hesabı veya kesin kullanıcı konumu saklanmaz. Yalnızca olay türü, kısa
arama terimi, hedef konum adı/türü ve rota modu tutulur. Kayıtlar varsayılan
olarak 90 gün sonra temizlenir; "bugün" hesapları Türkiye saati (`+03:00`) ile
yapılır.

Yeni Docker veritabanı kurulumlarında `docker/init/01-analytics.sql` otomatik
çalışır. Mevcut `dbdata` volume'ü olan yerel kurulumlarda tabloyu bir kez
eklemek için:

```bash
docker compose exec -T db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" < /docker-entrypoint-initdb.d/01-analytics.sql'
```

Mevcut tabloda yalnızca doğrulama kolonu eksikse:

```bash
docker compose exec -T db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "ALTER TABLE analytics_events ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER route_mode; CREATE INDEX IF NOT EXISTS idx_verified_created ON analytics_events (is_verified, event_type, created_at);"'
```

## Harita içi canlı rota

Konum kartındaki rota butonu artık kullanıcıyı Google Maps'e göndermez. Seçilen
hedef için mevcut Leaflet haritasında rota çizilir; kullanıcı konumu
`watchPosition` ile canlı takip edilir ve hareket ettikçe rota güncellenir.
Yön kartındaki segmentli kontrolden **Yürüme** veya **Araç** modu seçilebilir.
Yürüme modu OSRM `foot`, araç modu OSRM `driving` profiliyle rota ister; servis
erişilemezse harita üzerinde düz çizgi ve ortalama süre tahmini gösterilir.
Süre tahmini rota mesafesinden hesaplanır: yürüyüşte **85 m/dk**, araçta
**800 m/dk** ortalama alınır.

OSRM endpoint'i ortam değişkenleriyle ayarlanabilir; varsayılan public demo
sunucusu korunur:

```env
OSRM_BASE_URL=https://router.project-osrm.org
OSRM_WALK_PROFILE=foot
OSRM_DRIVE_PROFILE=driving
OSRM_TIMEOUT_MS=4000
```

`OSRM_BASE_URL` değerini `/route/v1` olmadan verin.

> **Önemli:** `router.project-osrm.org` (public demo) yalnızca tek bir araç
> (driving) veri seti barındırır ve URL'deki profil segmentini (`foot`,
> `driving`, ne yazarsanız) yok sayar — yürüme modu isteği de araç rotası
> döner. Gerçek, birbirinden farklı araç ve yaya rotaları için aşağıdaki
> kendi barındırılan OSRM kurulumu kullanılmalıdır.

### Kendi barındırılan OSRM (gerçek araç + yaya rotaları)

`docker/osrm/` altında, resmi `osrm/osrm-backend` imajını temel alan bir
kurulum bulunur. Veri hazırlığı iki adımda, elle çalıştırılır (compose'da
`profiles: ["tools"]` ile işaretlidir, `docker compose up` sırasında otomatik
başlamaz):

```bash
# 1) Türkiye OSM verisini indirir (Geofabrik, ~1-2 GB), Gaziantep iline kırpar.
#    (osrm-backend imajı Debian 9/stretch tabanlı ve apt depoları arşivlendiği
#    için indirme burada ayrı, güncel bir Debian imajıyla yapılır.)
docker compose run --rm osrm-download

# 2) Kırpılmış veriyi car+foot profilleriyle işler (osrm-extract/partition/
#    customize). Resmi osrm-backend imajı doğrudan kullanılır.
docker compose run --rm osrm-process

# 3) Rota servislerini başlatın.
docker compose up -d osrm-car osrm-foot
```

Beklenen kaynak kullanımı: indirme adımı ağ hızına bağlı birkaç dakika
sürebilir (Türkiye geneli pbf ~1-2 GB); işleme adımı (extract/partition/
customize, iki profil için) birkaç dakika ile yarım saat arası sürebilir
(makine gücüne göre). Diskte kalıcı olarak `osrmdata` volume'unda ~2-4 GB yer
kaplar. Her iki adım da **idempotent**tir — tamamlanmış dosyalar varsa
yeniden çalıştırıldığında o adım atlanır.

`osrm-car` ve `osrm-foot`, veri hazır olmadan (`osrm-process` çalıştırılmadan)
başlatılırsa veri dosyasını bulamayıp çökecek ve `restart: unless-stopped`
sayesinde tekrar tekrar yeniden başlayacaktır — bu beklenen davranıştır, veri
hazır olunca kendiliğinden ayağa kalkar.

Servisler `localhost:5001` (araç) ve `localhost:5002` (yaya) portlarında
yayında olur; uygulamanın bunları kullanması için `.env`:

```env
OSRM_DRIVE_BASE_URL=http://localhost:5001
OSRM_WALK_BASE_URL=http://localhost:5002
```

Bu değişkenler boş bırakılırsa (varsayılan) her iki mod da genel
`OSRM_BASE_URL`'i kullanır — mevcut kurulumlar hiçbir değişiklik yapmadan
çalışmaya devam eder. Doldurulduğunda her mod kendi gerçek yol ağı (araç) veya
yaya/patika ağı (yürüme) verisiyle rota alır ve bu iki rota gözle görülür
şekilde farklılaşır.

Uygulama OSRM zaman aşımı, HTTP hatası veya geçersiz yanıt durumunda rota kartında
açık bir uyarı gösterir ve haritada kuş uçuşu çizgi + yerel süre tahminiyle
çalışmaya devam eder.

## Paylaşılabilir konum linki

Haritada bir konum seçildiğinde URL sayfa yenilenmeden `?loc=<id>` olarak
güncellenir (`history.pushState`); konum kartındaki **paylaş** butonu linki
panoya kopyalar ("Kopyalandı" geri bildirimiyle). Link açıldığında harita o
konuma odaklanır, popup ve rota kartı açılır:

```
https://ornek.site/?loc=123                          # id ile
https://ornek.site/?loc=merkez-ogrenci-yemekhanesi   # okunaklı slug ile
```

`loc` id veya isimden türetilen slug kabul eder; yalnızca mevcut bir konumla
birebir eşleşirse kullanılır, değer HTML'e basılmaz. Geçersiz `loc` sessizce
yok sayılır (normal harita açılır). Tarayıcı geri/ileri tuşları seçilen
konumlar arasında gezinir (`popstate`). Eski `#loc=` biçimi de okunur.

## Veritabanı

`campus_locations` tablosu kendi kendine referans veren bir ağaçtır
(`parent_id`, `ON DELETE CASCADE`). `users` tablosu panel girişini tutar
(şifreler `password_hash` ile saklanır).

Yeni kurulumlarda `docker/init/02-location-i18n.sql` otomatik çalışır ve
`campus_locations` tablosuna nullable `name_en` ile `description_en` alanlarını
ekler. Mevcut `dbdata` volume'ü olan yerel kurulumlarda aynı migration'ı bir
kez uygulamak için:

```bash
docker compose exec -T db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" < /docker-entrypoint-initdb.d/02-location-i18n.sql'
```

## Testler

Dört bağımsız test takımı var: PHP birim testleri (PHPUnit + SQLite), PHP
entegrasyon testleri (PHPUnit + gerçek MariaDB), JS yardımcı fonksiyonları
(Node `node:test`), ve tarayıcı/HTTP uçtan uca testleri (Playwright). Hiçbiri
birbirine bağımlı değil, istediğinizi ayrı ayrı çalıştırabilirsiniz.

### PHP birim testleri (PHPUnit + SQLite)

`web` container'ı içinde çalışır (Composer + PHPUnit `docker/Dockerfile`'a
dahildir). İlk kurulum:

```bash
docker compose exec web composer install
```

Çalıştırma:

```bash
docker compose exec web composer test
# veya doğrudan:
docker compose exec web vendor/bin/phpunit
```

Kapsam: `tests/php/`
- `GaunEnrichTest.php` — Türkçe isim normalizasyonu (`gaun_norm_name`,
  `gaun_fold_key`), public URL/host filtreleme, bugünkü yemek menüsü ve
  etkinlik listesinin "bayat veri asla sızmaz" davranışı, bülten/personel
  alan filtreleme, isim/host eşleştirme.
- `GetLocationsTreeTest.php` — `get_locations.php`'nin ağaç kurma mantığı:
  parent/child iç içeleme, koordinat/opsiyonel alan geçişi, GAÜN
  zenginleştirme ve yemekhane/personel eklerinin doğru konuma iliştirilmesi.
- `LocationsValidationTest.php` — bordo panelindeki konum formu doğrulama
  (koordinat/e-posta/URL/görsel referansı kuralları, ebeveyn döngüsü tespiti)
  ve CSV içe aktarım ayrıştırma/doğrulama (başlık normalizasyonu, boş satır,
  dosya boyutu/uzantı kısa devreleri).
- `SyncGaunSourcesParserTest.php` — `scripts/sync_gaun_sources.php`'nin HTML
  ayrıştırma (`parse_*`) fonksiyonlarını `tests/fixtures/*.html` sabit
  örnekleriyle test eder: **canlı GAÜN ağına hiç çıkmaz** (bkz. aşağıdaki
  "GAÜN senkronizasyonu" bölümü — bu, bilinçli olarak default `composer
  test`'e dahil edilen tek şey; gerçek ağa çıkan akış hiçbir composer
  script'inde yoktur). Kapsam: yemek menüsü + kcal ayrıştırma, etkinlik
  başlık/tarih (listing) ve saat/yer/açıklama/görsel (detay sayfası)
  ayrıştırma, duyuru başlık/tarih-öneki ayrıştırma + URL bazlı yineleme
  giderme, ve HER ÜÇÜ için de bozuk/tanınmayan HTML'de `null` dönüp eski
  cache'in korunacağı güvenli sonuç.

Not: `get_locations.php` ve `bordo/locations.php` test edilebilirlik için
saf mantığı `get_locations_lib.php` / `bordo/locations_lib.php` dosyalarına
taşıdı (oturum/CSRF/DB bağlantısı gibi yan etkisi olmayan fonksiyonlar);
asıl istek işleme dosyalarında değişmeden kaldı, davranış birebir korunur.

Not: `scripts/sync_gaun_sources.php`'de her `collect_X(url)` fonksiyonu
"ağa çık (`fetch_url`) + ayrıştır" ikilisini birleştiriyordu; test
edilebilirlik için ayrıştırma kısmı saf `parse_meal_menu_html()`,
`parse_events_listing_html()`, `parse_event_detail_html()`,
`parse_announcements_html()` fonksiyonlarına ayrıldı (HTML string + gerekli
metaveriyi alır, ağa hiç dokunmaz). `collect_*` fonksiyonları artık yalnızca
`fetch_url()` çağırıp sonucu bu saf fonksiyonlara devrediyor — davranış
birebir korunur, bu dosyanın CLI-only ve "yalnızca doğrudan çalıştırılırsa"
korumaları (bkz. dosyanın en altındaki `if (realpath(...) === __FILE__)`)
hiç değişmedi.

Bu takım SQLite in-memory kullanır — **gerçek MariaDB şemasına karşı çalışmaz**
(FK/CASCADE, charset/collation, gerçek dosya yükleme burada test edilmez).
Onlar için aşağıdaki entegrasyon takımına bakın.

### PHP entegrasyon testleri (PHPUnit + gerçek MariaDB)

Birim testlerden **bilinçli olarak ayrı** bir takım: gerçek `db` servisine
(docker compose) bağlanır, gerçek şemayı (FK'ler, `ON DELETE CASCADE`,
`utf8mb4` charset/collation) ve — CSV testi için — gerçek bir HTTP
login+CSRF+multipart isteğini kullanır. Bu yüzden ayrı bir composer script'i
ve ayrı bir phpunit yapılandırması var; birim testleri yavaşlatmaz/etkilemez.

**Ön koşul:** `db` (ve CSV testi için `web`) servisleri ayakta olmalı:

```bash
docker compose up -d db web
docker compose exec web composer install   # henüz yapılmadıysa
```

Çalıştırma:

```bash
docker compose exec web composer test:integration
# veya doğrudan:
docker compose exec web vendor/bin/phpunit -c phpunit.integration.xml.dist
```

Kapsam: `tests/php-integration/`
- `CampusLocationsCrudTest.php` — gerçek tabloda insert/update/delete;
  parent/child ilişkisi ve gerçek FK ile çok seviyeli `ON DELETE CASCADE`
  (büyükanne silinince anne ve çocuk da gider); Türkçe aksanlar **ve** bir
  emoji (gerçek `utf8mb4`'ün asıl kanıtı — Türkçe harfler tek başına
  `utf8mb3`'te de temsil edilebildiğinden yeterli kanıt değildir) round-trip
  ediyor; gerçek collation'ın (`utf8mb4_uca1400_ai_ci`) aksan+büyük/küçük
  harf duyarsız olduğu doğrudan doğrulanıyor.
- `ValidateLocationDuplicateIntegrationTest.php` — `validate_location()`'ın
  duplicate kontrolünü gerçek tabloya karşı doğrular; ayrıca gerçek collation
  yüzünden "Kütüphane" ile "KUTUPHANE"nin de duplicate sayıldığını gösterir
  (SQLite birim testi bunu yakalayamaz — bkz. aşağıdaki fark tablosu).
  `would_create_parent_cycle()`'ı gerçek çok seviyeli bir hiyerarşiye karşı
  test eder.
- `CsvImportHttpIntegrationTest.php` — CSV içe aktarmayı GERÇEK bir HTTP
  login + CSRF + `multipart/form-data` isteğiyle uçtan uca dener (saf
  PHP/cURL, tarayıcı gerekmez) ve satırın gerçek tabloya yazıldığını
  doğrudan DB'den doğrular. Bu, birim testindeki `is_uploaded_file()` engelini
  gerçek bir istekle aşan tek PHP testidir (bkz. aşağıdaki fark tablosu).
- `AnalyticsIntegrationTest.php` — `analytics.php`'yi gerçek şemaya karşı
  test eder: izinli üç event tipinin (`search`, `location_view`,
  `route_request`) gerçekten yazıldığı; geçersiz bir event tipinin hem
  uygulama katmanında (`analytics_log_event`) HEM DE gerçek
  `CHECK (event_type IN (...))` kısıtında (ikinci savunma hattı — SQLite'ta
  bu kısıt hiç yok) reddedildiği; kısa/boş arama teriminin kaydedilmediği;
  `route_request` için geçersiz `route_mode`'un olayı reddetmeden sessizce
  `null`'landığı (ve `route_mode`'un yalnızca `route_request`'te saklandığı);
  saklama (retention) temizliğinin gerçek `NOW() - INTERVAL` SQL'iyle eski
  kaydı silip yeniyi koruduğu (varsayılan 90 gün VE özel bir eşikle).

**Veri hijyeni:** her test benzersiz bir `_PHPINT_<rastgele>_` önek alır;
eklenen kayıtların id'leri izlenir ve `tearDown()`'da kesin olarak silinir,
ayrıca önek eşleşmesiyle bir güvenlik-ağı taraması yapılır. CSV/CSRF testi
kendi tek seferlik test kullanıcısını (`_phpint_csv_admin_<rastgele>`)
oluşturup siliyor — gerçek `admin` hesabına dokunmaz (bkz. Playwright admin
testlerindeki aynı ilke). Retention testi, `analytics_cleanup()`'ın
PDO-nesne-kimliğine göre "yalnızca bir kez çalış" korumasını (paylaşılan
bağlantıda başka bir test onu zaten tetiklemiş olabilir) atlatmak için
havuzlanmamış, taze bir bağlantı açar — bu sayede gerçek DELETE davranışı
her koşumda deterministik olarak doğrulanır.

**Birim vs. entegrasyon farkı:**

| | Birim (`tests/php/`) | Entegrasyon (`tests/php-integration/`) |
|---|---|---|
| Veritabanı | SQLite in-memory (yok — `SyncGaunSourcesParserTest` hiç DB kullanmaz) | Gerçek MariaDB (`db` servisi) |
| Ağ | Yok — `SyncGaunSourcesParserTest` bile yalnızca `tests/fixtures/*.html` okur, canlı GAÜN'e asla çıkmaz | `CsvImportHttpIntegrationTest` `web` servisinin kendi Apache'ine (localhost) HTTP isteği atar; başka dış ağ yok |
| Hız | ~15 ms / 110 test | ~150 ms / 27 test (ağ+gerçek DB round-trip) |
| FK/CASCADE | Yok (sahte tablo, FK tanımsız) | Gerçek `ON DELETE CASCADE` |
| Charset/collation | SQLite varsayılanı (aksan/büyük-küçük harf duyarlı) | `utf8mb4_uca1400_ai_ci` (aksan/büyük-küçük harf DUYARSIZ) |
| CHECK kısıtı | Yok | Gerçek `CHECK (event_type IN (...))` (analytics_events) |
| Dosya yükleme | `is_uploaded_file()` hep `false` döner, test edilemez | Gerçek HTTP multipart ile `true` döner |
| Ne zaman çalıştır | Her değişiklikte, hızlı geri bildirim için | Şema/DB davranışına dokunan değişiklikte, PR öncesi, CI'da |

### JavaScript yardımcı fonksiyonları (Node `node:test`)

Ekstra bağımlılık gerekmez (Node ≥ 18 yeterli).

```bash
npm run test:js
```

Kapsam: `tests/js/i18n.test.js` — `assets/js/i18n.js`'in Türkçe karakter
normalizasyonu (`foldTr`, `trLower`, NFC/NFD tutarlılığı — bkz. i18n
düzeltmesindeki JSON.parse NFD regresyonu) ve çeviri mantığı
(`locationNameEn`, `displayTaxonomy`, `displayLocationName`, `t()`).
`tests/js/helpers/load-i18n.js`, tarayıcı `<script>` dosyasını (module
olmayan) minimal bir DOM/localStorage sahtesiyle Node'da çalıştırır.

### Tarayıcı duman testi (Playwright)

```bash
npm install
npx playwright install chromium   # ilk seferde
npx playwright test               # veya: npm run test:e2e
```

**Ön koşul:** uygulama `http://localhost:8080` adresinde çalışıyor olmalı
(`docker compose up -d web`). Bu takım kendi sunucusunu başlatmaz.

Kapsam: `tests/e2e/smoke.spec.js`
- ana sayfa açılıyor, harita gerçekten tile render ediyor
- arama bir sorgu için sonuç listesi döndürüyor, sonuca tıklayınca konum
  kartı açılıyor
- TR→EN ve EN→TR dil geçişi (başlık, yer tutucu, etiket)
- EN modda `name_en` ile arama, yön kartı adı ve `description_en` popup
  açıklaması (mock `get_locations.php` fixture'ı ile)
- karanlık modda temel metinlerin okunabilir kontrastta render olduğu
  (`color: #fff` kuralının gerçekten uygulandığı)

Kapsam: `tests/e2e/admin.spec.js` — admin panel (`/bordo/`) uçtan uca, **hiçbir
bypass olmadan** (gerçek login formu, gerçek CSRF jetonu, gerçek POST
dispatcher, gerçek multipart dosya yükleme):
- doğru/yanlış kimlik bilgileriyle giriş; girişsiz sayfaya erişim engeli
- CSRF jetonu olmadan gönderilen POST'un reddedildiği (negatif kontrol —
  bkz. aşağıdaki bulgu)
- konum ekleme modalı: zorunlu alanlarla kayıt oluşturma + listede görünme;
  zorunlu alan eksikken sunucu tarafı reddi
- iki dilli alanlar (`name_en`, `description_en`): formdan kaydetme,
  düzenleme modalında geri okuma ve İngilizce ada göre admin araması
- **gerçek görsel yükleme** (`setInputFiles` ile bellekte üretilen 1x1 PNG):
  `image_url`'in gerçekten `uploads/locations/…` altına kaydedildiği ve
  dosyanın sunulabilir olduğu doğrulanıyor
- **gerçek CSV içe aktarma** (bellekte üretilen geçici CSV): yeni kaydın
  oluşup listede göründüğü doğrulanıyor

Test kullanıcısı ve veri hijyeni: `tests/e2e/global-setup.js`, yalnızca bu
koşum için rastgele şifreli, ayrı bir `_e2e_playwright_admin` hesabı oluşturur
(gerçek `admin` hesabına asla dokunulmaz — bkz. `private/ROTATE_CREDENTIALS.md`
notu, bu proje sızmış üretim kimlik bilgilerini rotasyon için saklıyor, testler
bunları KULLANMAZ) ve `global-teardown.js` işi bitince siler. Oluşturulan konum
kayıtları benzersiz isimle (`_E2E_<etiket>_<zaman damgası>_<rastgele>`)
açılır ve her test kendi `finally` bloğunda panelin **kendi** "Sil" akışıyla
temizlenir; yüklenen görsel dosyası da (uygulama bunu silme sırasında
kaldırmadığı için — bkz. bulgu) testin kendisi tarafından diskten silinir.

**Bulgu (düzeltildi):** `csrf_verify()` (`bordo/csrf.php`) `http_response_code(419)`
çağırıyordu, ama 419 IANA'da kayıtlı bir HTTP durum kodu değil. PHP CLI'da doğru
izleniyordu, ama bu proje Apache+mod_php altında çalıştığından
(`docker/Dockerfile`) durum satırı GERÇEKTE `500 Internal Server Error` olarak
gönderiliyordu (bu oturumda `curl` ile doğrulandı) — üretimdeki CSRF-red yanıtı
da muhtemelen 500'dü, 419 değil; izleme/alarm sistemlerinde "sunucu hatası" gibi
görünüp yanlış yorumlanabilirdi. Düzeltme: `http_response_code(419)` yerine
`header('HTTP/1.1 419 Page Expired')` ile durum satırı doğrudan yazılıyor —
Apache altında da gerçekten 419 gönderildiği doğrulandı. `admin.spec.js`'teki
CSRF testi artık `response.status()`'ın gerçekten `419` olduğunu doğruluyor.

### Canlı rota (OSRM) testleri

`tests/e2e/routing.spec.js`, kendi barındırılan OSRM servislerine (`osrm-car`,
`osrm-foot`) karşı gerçek uçtan uca rota akışını test eder — bu takım kendi
sunucusunu/servisini BAŞLATMAZ, hepsinin zaten ayakta olduğunu varsayar.

**Ön koşul:**

```bash
docker compose up -d db web osrm-car osrm-foot
```

`osrm-car`/`osrm-foot` veri hazır değilse (bkz. "Kendi barındırılan OSRM"
bölümü — `docker compose run --rm osrm-download && docker compose run --rm
osrm-process` bir kez çalıştırılmalı) crash-loop eder; bu durumda testler
`page.waitForResponse` zaman aşımıyla **açıkça başarısız olur**, sessiz
geçmez.

Çalıştırma:

```bash
npx playwright test tests/e2e/routing.spec.js
```

Kapsam:
- **Gerçek OSRM (`osrm-car`/`osrm-foot`)**: bilinen bir konum arama üzerinden
  seçilir, tarayıcı konumu (`context.setGeolocation`) kampüs içinde bilinen
  bir noktaya mocklanır, "Konumumu Kullan" tıklanır. Hem Yürüme (osrm-foot,
  `:5002`) hem Araç (osrm-car, `:5001`) modunda: gerçek OSRM isteğinin `200`
  döndüğü, rota kartındaki (`#dirStats`) ETA metninin boş olmadığı ve
  "rotası" içerdiği (fallback mesajını İÇERMEDİĞİ), haritada gerçek bir
  `L.geoJSON` rota çizgisinin (SVG `path.leaflet-interactive`) render
  olduğu doğrulanır. Üçüncü bir test, iki modun GERÇEKTEN farklı
  sunuculara/profillere gittiğini (public demonun aksine — bkz. "Kendi
  barındırılan OSRM" bölümündeki köken sorun) kanıtlar.
- **Düz çizgi (kuş uçuşu) fallback**: `page.route()` ile OSRM isteği
  BİLEREK engellenir (bağlantı reddi VE zaman aşımı senaryosu ayrı ayrı) —
  bu, gerçek servislerin o an çalışıp çalışmadığından bağımsız, deterministik
  bir regresyon testidir. Rota kartında "Rota servisine ulaşılamadı... kuş
  uçuşu" mesajının göründüğü ve çizilen çizginin kesikli (`stroke-dasharray`)
  olduğu doğrulanır.

**Public demo OSRM ile mi, yerel OSRM ile mi çalışıyor?** Bu test SADECE
projenin kendi barındırılan `osrm-car`/`osrm-foot` servisleriyle (`localhost:5001`/
`5002`) çalışacak şekilde yazıldı — `.env`'de `OSRM_DRIVE_BASE_URL`/
`OSRM_WALK_BASE_URL` boş bırakılıp genel `router.project-osrm.org` demosuna
düşülen bir kurulumda bu testler **anlamsız/yanıltıcı** olurdu, çünkü o demo
sunucusu profili yok sayar (yürüme isteği de araç rotası döner) ve SLA'sı
yoktur — `page.waitForResponse` filtreleri özellikle `:5001`/`:5002` port
numaralarını arar, bu yüzden genel demo kullanılan bir ortamda bu testler
OSRM isteğini hiç göremeyip zaman aşımıyla başarısız olur (bu da aslında
istenen bir sinyal: "yerel OSRM kurulu değil" demektir, sessizce yanlış
geçmez).

### GAÜN senkronizasyonunu manuel doğrulama

`scripts/sync_gaun_sources.php`'nin ayrıştırma mantığı `SyncGaunSourcesParserTest.php`
ile fixture'lara karşı test edilir (yukarıya bkz.), ama GERÇEK GAÜN
sayfalarına karşı çalışıp çalışmadığı — sayfa yapısı değiştiyse ayrıştırıcının
hâlâ doğru veri çıkardığı — hiçbir otomatik testte doğrulanmaz. Bu **bilinçli
bir tercihtir**: canlı bir dış siteye çıkan bir testi `composer test`'e (veya
herhangi bir CI komutuna) koymak, GAÜN'ün sayfa yapısını değiştirmesi, ağ
kesintisi veya rate-limiting gibi kendi kontrolümüz dışındaki nedenlerle test
takımını gürültülü/kırılgan hale getirir.

Manuel doğrulama (yalnızca elle, gerektiğinde çalıştırın):

```bash
docker compose run --rm web php scripts/sync_gaun_sources.php --verbose
```

Beklenen çıktı: her bölüm için `✓ <bölüm>: N kayıt` (başarılı), `! <bölüm>:
kaynak erişilemedi, eski cache korundu` (o bölüm çekilemedi ama uygulama
bozulmadı) veya `✗ <bölüm>: veri yok` (hiç veri yok — ne yeni ne eski). `--verbose`
bayrağı `vlog()` ile STDERR'e ayrıntılı hata nedenini yazar (ör. hangi XPath
sorgusu boş döndü). Çalıştırdıktan sonra `private/cache/gaun_sources.json`
dosyasını inceleyip her bölümün (units/announcements/news/meal_menu/
important_links/events/staff) gerçek, güncel veri içerdiğini gözle
doğrulayın — özellikle `events`/`meal_menu`, sayfa yapısı en sık değişen
bölümlerdir (bkz. bu dosyanın en üstündeki tasarım notu: "bir bölüm
çekilemezse eski cache'teki bölüm korunur").

### Test edilemeyen riskler (bilinçli kapsam dışı)

- ~~CSV/görsel import'un veritabanına gerçekten yazması (şema düzeyinde)~~ —
  **kapandı**: `tests/php-integration/` gerçek MariaDB'ye karşı FK/`ON DELETE
  CASCADE`, `utf8mb4` charset/collation ve gerçek HTTP multipart CSV
  yüklemesini doğruluyor (bkz. yukarıdaki "PHP entegrasyon testleri"
  bölümü); `admin.spec.js` aynısını tarayıcı üzerinden ayrıca doğruluyor.
- ~~OSRM canlı rota servisleri~~ — **kapandı**: `tests/e2e/routing.spec.js`
  geolocation mock'layıp gerçek osrm-car/osrm-foot'a karşı hem Yürüme hem
  Araç modunda gerçek rota çizimini, ayrıca OSRM erişilemezken düz-çizgi
  fallback'e düştüğünü doğruluyor (bkz. yukarıdaki "Canlı rota (OSRM)
  testleri" bölümü). Kapsam dışı kalan: OSRM'in kendi rota HESAPLAMA
  doğruluğu (ör. gerçekten en kısa/mantıklı yolu seçip seçmediği) — yalnızca
  "isteğe 200 dönüyor ve bir geometri geliyor" doğrulanıyor, coğrafi
  doğruluk değil.
- ~~Analitik (`analytics.php`) ve GAÜN senkronizasyon script'i
  (`scripts/sync_gaun_sources.php`)~~ — **kapandı**: `AnalyticsIntegrationTest.php`
  (gerçek MariaDB'ye karşı event doğrulama + retention) ve
  `SyncGaunSourcesParserTest.php` (fixture tabanlı HTML ayrıştırma) eklendi —
  bkz. yukarıdaki ilgili bölümler. Kapsam dışı kalan: `scripts/sync_gaun_sources.php`'nin
  GERÇEK ağa çıkan kısmı (`fetch_url`, `collect_units`, `collect_staff`, SSRF
  allowlist'in canlı bir yönlendirme zincirine karşı davranışı) ve tüm akışın
  gerçek GAÜN sayfalarına karşı hâlâ doğru ayrıştırdığı — bunlar kasıtlı
  olarak hiçbir otomatik testte YOKTUR (canlı ağa çıkan bir test default
  `composer test`'e asla konulmadı); bkz. aşağıdaki "GAÜN senkronizasyonunu
  manuel doğrulama" bölümü.
- **Karanlık mod kontrast testi tek örnek**: Playwright testi yalnızca kenar
  çubuğu başlığını kontrol eder; CSS'teki uzun `.dark :is(...)` seçici
  listesindeki HER öğe tek tek doğrulanmaz — yeni bir bileşen eklenip karanlık
  mod kuralı unutulursa bu test onu yakalamayabilir.
- **Konum düzenleme (var olan kaydı güncelleme) ve tekil silme dışındaki
  admin akışları**: `openEdit()` ile veri DOLU bir kaydı güncelleme, ebeveyn
  kategori seçimi/döngü tespiti, CSV'nin var olan bir `id` ile güncelleme
  (yalnızca yeni kayıt oluşturma) uçtan uca test edilmedi — yalnızca birim
  testinde (`validate_location`, `would_create_parent_cycle`) kapsandı.
- **Görsel/CSV upload'un dosya sistemi sızıntısı**: uygulama, bir konum
  silindiğinde ona ait yüklenmiş görsel dosyasını diskten KALDIRMIYOR (yalnızca
  DB satırını siliyor) — bu, gerçek admin kullanımında `public/uploads/locations/`
  altında yetim dosyalar birikmesine yol açar. `admin.spec.js` kendi ürettiği
  dosyayı elle temizliyor ama bu, uygulamanın kendisinde düzeltilmiş bir
  davranış değil; ayrı bir konu olarak ele alınmalı.

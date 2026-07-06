# GAUN Kampus Haritasi Deployment Rehberi

Bu proje PHP 8.3 + Apache + MariaDB 11 ile calisir. Onerilen production kurulum
sekli Docker Compose + Nginx reverse proxy'dir:

- Uygulama container icinde Apache ile 80 portunda calisir.
- Host tarafinda yalnizca `127.0.0.1:8080` yayina acilir.
- Nginx dis dunyadan gelen HTTP/HTTPS trafigini `127.0.0.1:8080` adresine proxy eder.
- MariaDB disari acilmaz; sadece Compose agindan erisilir.
- `sync` servisi GAUN kaynak cache'ini gunde bir kez yeniler.

## 1. Git'e yuklemeden once

Repo icin `.gitignore` ve `.dockerignore` hazirlandi. Git'e alinmamasi gerekenler:

- `.env`, `.env.*`
- `private/`
- `node_modules/`, `vendor/`
- DB dump'lari: `*.sql`, `*.dump`, `backups/`
- test/cache ciktisi: `.phpunit.cache/`, `test-results/`, `playwright-report/`
- yeni panel upload'lari: `public/uploads/locations/*`

Git'e alinmasi gereken lock dosyalari:

- `composer.lock`
- `package-lock.json`

Kontrol:

```bash
git status --short
git check-ignore -v .env private/db.dump node_modules/foo 2>/dev/null || true
```

## 2. Bagimlilikler

Production Docker imaji bagimlilikleri kendisi kurar. Yine de native kurulum icin
gerekenler:

- PHP 8.3
- PHP extension: `pdo_mysql`, `curl`, `mbstring`, `dom`, `SimpleXML`, `fileinfo`, `json`, `session`, `openssl`
- MariaDB 11
- Node.js 22 veya uyumlu LTS
- Composer 2

Yerelde bagimlilik listesini gormek icin:

```bash
composer show --direct
npm ls --depth=0
```

CSS build ve test:

```bash
npm ci
npm run build:css
composer install
npm test
```

Native production kurulumda:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build:css
```

## 3. Environment dosyasi

Sunucuda production env icin:

```bash
cp deploy/env.production.example .env
openssl rand -hex 24
openssl rand -hex 32
```

`.env` icindeki tum `CHANGE_ME...` degerlerini degistirin. Bu dosya Git'e
alinmaz.

Minimum zorunlu degiskenler:

```env
APP_PORT=8080
APP_DEBUG=false
DB_NAME=gau_kampus
DB_READ_USER=gau_read
DB_READ_PASS=...
DB_WRITE_USER=gau_write
DB_WRITE_PASS=...
MYSQL_ROOT_PASSWORD=...
ADMIN_AUDIT_SALT=...
```

Rota servisi icin varsayilan public OSRM:

```env
OSRM_BASE_URL=https://router.project-osrm.org
OSRM_DRIVE_BASE_URL=
OSRM_WALK_BASE_URL=
```

Self-hosted OSRM kurarsaniz tarayicinin erisecegi URL'leri doldurun.

## 4. Veritabani dump ve restore

Yerel Docker DB'den kayipsiz dump:

```bash
mkdir -p backups
docker compose exec -T db sh -lc \
  'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' \
  > "backups/gau_kampus_$(date +%Y%m%d-%H%M%S).sql"
```

Host MariaDB portundan dump almak isterseniz:

```bash
mariadb-dump -h 127.0.0.1 -P 3307 -uroot -p \
  --single-transaction --routines --triggers gau_kampus \
  > backups/gau_kampus.sql
```

Yeni sunucuda bos production DB'ye restore:

```bash
docker compose --env-file .env -f deploy/compose.prod.yaml up -d db
docker compose --env-file .env -f deploy/compose.prod.yaml exec -T db sh -lc \
  'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
  < backups/gau_kampus.sql
```

Restore sonrasi uygulama hesaplarini env'deki sifrelerle tekrar garantiye almak:

```bash
docker compose --env-file .env -f deploy/compose.prod.yaml exec -T db \
  sh /docker-entrypoint-initdb.d/04-app-users.sh
```

## 5. Docker production kurulum

Sunucuda Docker ve Compose plugin kurulu olmali. Projeyi `/opt/gaunharita`
altina yerlestirin:

```bash
sudo mkdir -p /opt/gaunharita
sudo chown "$USER":"$USER" /opt/gaunharita
git clone <REPO_URL> /opt/gaunharita
cd /opt/gaunharita
cp deploy/env.production.example .env
```

`.env` degerlerini duzenledikten sonra:

```bash
docker compose --env-file .env -f deploy/compose.prod.yaml build
docker compose --env-file .env -f deploy/compose.prod.yaml up -d
docker compose --env-file .env -f deploy/compose.prod.yaml ps
```

Loglar:

```bash
docker compose --env-file .env -f deploy/compose.prod.yaml logs -f web
docker compose --env-file .env -f deploy/compose.prod.yaml logs -f sync
docker compose --env-file .env -f deploy/compose.prod.yaml logs -f db
```

Uygulama host uzerinde sadece local porta acilir:

```bash
curl -I http://127.0.0.1:8080/
```

## 6. Systemd ile otomatik baslatma

Service dosyasini kopyalayin:

```bash
sudo install -m 0644 deploy/systemd/gaunharita.service /etc/systemd/system/gaunharita.service
sudo systemctl daemon-reload
sudo systemctl enable --now gaunharita.service
sudo systemctl status gaunharita.service
```

Guncelleme sonrasi:

```bash
cd /opt/gaunharita
git pull
docker compose --env-file .env -f deploy/compose.prod.yaml build
sudo systemctl restart gaunharita.service
```

## 7. Nginx reverse proxy

Nginx server block:

```bash
sudo install -m 0644 deploy/nginx/gaunharita.conf /etc/nginx/sites-available/gaunharita
sudo nano /etc/nginx/sites-available/gaunharita
```

`server_name harita.example.com;` satirini kendi domaininizle degistirin.

Aktif etme:

```bash
sudo ln -s /etc/nginx/sites-available/gaunharita /etc/nginx/sites-enabled/gaunharita
sudo nginx -t
sudo systemctl reload nginx
```

SSL icin Certbot kullanilacaksa:

```bash
sudo certbot --nginx -d harita.example.com
```

Konum izni mobil tarayicilarda HTTPS gerektirir; production'da SSL kullanin.

## 8. Admin kullanicisi olusturma

Ilk super admin kullanicisini container icinden olusturun:

```bash
docker compose --env-file .env -f deploy/compose.prod.yaml exec -T web php -r '
require "/var/www/html/db.php";
$pdo = db("write");
$stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
$stmt->execute(["admin", password_hash("COK_GUCLU_PAROLA", PASSWORD_DEFAULT), "super_admin"]);
echo "admin hazir\n";
'
```

Parolayi komutta birakmayin; olusturduktan sonra shell history temizligi yapin
veya komutu gecici bir root-only dosyadan calistirin.

## 9. Native Ubuntu kurulum notu

Docker kullanmadan kuracaksaniz web root `public/` olmalidir. Proje kokunu
dogrudan Nginx/Apache web root yapmayin. PHP process'ine `.env` degerlerini
vermeniz gerekir. Apache/PHP-FPM kendi servisleri systemd ile zaten yonetilir;
bu rehberdeki `gaunharita.service` Docker Compose stack'i icindir.

## 10. Hizli saglik kontrolu

```bash
curl -I http://127.0.0.1:8080/
docker compose --env-file .env -f deploy/compose.prod.yaml exec -T web php -r 'require "/var/www/html/system_health.php"; require "/var/www/html/db.php"; print_r(system_health_summary(db("write")));'
```

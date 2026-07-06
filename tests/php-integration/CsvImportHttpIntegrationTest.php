<?php
declare(strict_types=1);

namespace Tests\Integration;

use CURLFile;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../bordo/locations_lib.php';

/**
 * CSV içe aktarmanın GERÇEK bir HTTP çok parçalı (multipart) yükleme
 * üzerinden gerçek campus_locations tablosuna yazdığını doğrular.
 *
 * NEDEN HTTP ÜZERİNDEN (doğrudan import_locations_csv() çağrısı değil):
 * o fonksiyon içindeki is_uploaded_file() kontrolü yalnızca GERÇEK bir PHP
 * dosya yükleme isteğinde true döner; doğrudan fonksiyon çağrısıyla (unit
 * testlerdeki gibi, bkz. tests/php/LocationsValidationTest.php) asla
 * tetiklenemez. Bu yüzden bu test, uygulamanın kendi admin panel HTTP uç
 * noktasına (bordo/locations.php) gerçek bir login + CSRF + multipart POST
 * isteği gönderir — tests/e2e/admin.spec.js ile aynı fikir, ama tarayıcısız,
 * saf PHP/cURL ile (CI'da Playwright kurulu olmasa da çalışır).
 *
 * Bu test, çalıştığı PHP sürecinin AYNI Apache sunucusuna (localhost:80)
 * erişebildiğini varsayar — yani `docker compose exec web
 * vendor/bin/phpunit -c phpunit.integration.xml.dist` ile web container'ının
 * İÇİNDEN çalıştırılmalıdır (bkz. composer test:integration).
 */
final class CsvImportHttpIntegrationTest extends TestCase
{
    private const BASE_URL = 'http://localhost/bordo/';

    private PDO $pdo;
    private string $testUser;
    private string $testPassword;
    private string $cookieJar;

    /** @var int[] */
    private array $createdLocationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = db('write');
        $this->testUser = '_phpint_csv_admin_' . bin2hex(random_bytes(4));
        $this->testPassword = bin2hex(random_bytes(12));
        $this->pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)')
            ->execute([$this->testUser, password_hash($this->testPassword, PASSWORD_DEFAULT)]);

        $cookieJar = tempnam(sys_get_temp_dir(), 'phpint_cookies_');
        if ($cookieJar === false) {
            $this->fail('Çerez dosyası (cookie jar) oluşturulamadı.');
        }
        $this->cookieJar = $cookieJar;
    }

    protected function tearDown(): void
    {
        if ($this->createdLocationIds) {
            $placeholders = implode(',', array_fill(0, count($this->createdLocationIds), '?'));
            $this->pdo->prepare("DELETE FROM campus_locations WHERE id IN ({$placeholders})")
                ->execute($this->createdLocationIds);
        }
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$this->testUser]);
        @unlink($this->cookieJar);
        parent::tearDown();
    }

    /** @param array<int, mixed> $curlOptions */
    private function request(string $url, array $curlOptions = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->fail("cURL isteği başarısız: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body];
    }

    private function extractCsrfToken(string $html): string
    {
        if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $matches)) {
            $this->fail('Sayfada gizli CSRF alanı bulunamadı — sayfa yapısı değişmiş olabilir.');
        }
        return $matches[1];
    }

    public function testCsvImportOverRealHttpRequestWritesRowToRealTable(): void
    {
        // 1) Login sayfasından GERÇEK CSRF jetonunu oku (bypass yok).
        $loginPage = $this->request(self::BASE_URL . 'index.php');
        $this->assertSame(200, $loginPage['status']);
        $csrf = $this->extractCsrfToken($loginPage['body']);

        // 2) Gerçek formu, sayfadan okunan jetonla gönder.
        $loginResponse = $this->request(self::BASE_URL . 'index.php', [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $this->testUser,
                'password' => $this->testPassword,
                'csrf' => $csrf,
            ]),
        ]);
        $this->assertSame(302, $loginResponse['status'], 'giriş başarılı olmalıydı (dashboard.php\'ye yönlendirme)');

        // 3) locations.php'yi aç; csrf_token() oturum başına sabit olduğundan
        //    (session_regenerate_id veriyi korur) aynı jetonu tekrar okuyup
        //    kullanabiliriz.
        $locationsPage = $this->request(self::BASE_URL . 'locations.php');
        $this->assertSame(200, $locationsPage['status'], 'oturum geçerli olmalı, login\'e geri yönlendirilmemeli');
        $csrfForImport = $this->extractCsrfToken($locationsPage['body']);

        // 4) Geçici, yalnızca bu test için var olan CSV içeriğini GERÇEK bir
        //    multipart/form-data isteğiyle yükle.
        $name = '_PHPINT_CSV_' . bin2hex(random_bytes(4));
        $csvContent = "name,category,address\n{$name},Test Türü,Entegrasyon Test Adresi\n";
        $csvPath = tempnam(sys_get_temp_dir(), 'phpint_csv_');
        if ($csvPath === false) {
            $this->fail('Geçici CSV dosyası oluşturulamadı.');
        }
        file_put_contents($csvPath, $csvContent);

        try {
            $importResponse = $this->request(self::BASE_URL . 'locations.php', [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'action' => 'import_csv',
                    'csrf' => $csrfForImport,
                    'csv_file' => new CURLFile($csvPath, 'text/csv', 'import.csv'),
                ],
            ]);
        } finally {
            @unlink($csvPath);
        }
        $this->assertSame(302, $importResponse['status'], 'içe aktarma sonrası locations.php\'ye yönlendirme beklenir');

        // 5) Satırın GERÇEK tabloda oluştuğunu doğrudan DB'den doğrula —
        //    bu, is_uploaded_file() kontrolünün gerçekten geçtiğinin kanıtıdır
        //    (sahte bir dosya array'iyle çağrılsaydı fatal hata mesajıyla
        //    reddedilir, satır hiç oluşmazdı).
        $stmt = $this->pdo->prepare('SELECT * FROM campus_locations WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, 'CSV içe aktarma gerçek tabloya bir satır yazmalıydı');
        $this->createdLocationIds[] = (int) $row['id'];
        $this->assertSame('Test Türü', $row['type']);
        $this->assertSame('Entegrasyon Test Adresi', $row['address']);
    }
}

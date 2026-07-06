<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../analytics.php';

/**
 * analytics.php'yi GERÇEK MariaDB'ye karşı test eder: izinli event tipleri,
 * geçersiz event reddi, arama teriminin kısa/boş olma kuralı, route_mode
 * doğrulaması ve saklama (retention) temizliğinin gerçek DELETE davranışı.
 *
 * Neden entegrasyon (SQLite değil): analytics_ensure_schema() gerçek
 * CREATE TABLE ... CHECK (event_type IN (...)) kısıtını kurar ve
 * analytics_cleanup() gerçek `DATE_SUB(NOW(), INTERVAL ... DAY)` SQL'ini
 * çalıştırır — ikisi de MySQL/MariaDB'ye özgüdür, SQLite'ta anlamsızca
 * farklı davranır veya hiç desteklenmez.
 */
final class AnalyticsIntegrationTest extends TestCase
{
    private PDO $pdo;
    private string $prefix;
    private string $publicPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = db('write');
        analytics_ensure_schema($this->pdo);
        $this->prefix = '_PHPINT_ANALYTICS_' . bin2hex(random_bytes(4)) . '_';
        $this->publicPrefix = 'PUBLIC_ANALYTICS_' . bin2hex(random_bytes(4)) . '_';
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare(
            'DELETE FROM analytics_events
             WHERE search_term LIKE ? OR location_name LIKE ? OR search_term LIKE ? OR location_name LIKE ?'
        )->execute([$this->prefix . '%', $this->prefix . '%', $this->publicPrefix . '%', $this->publicPrefix . '%']);
        parent::tearDown();
    }

    /** db('write')'ın havuzlanmış (paylaşılan) PDO'sundan BAĞIMSIZ, taze bir bağlantı. */
    private function freshPdo(): PDO
    {
        $config = require __DIR__ . '/../../config.php';
        $dbConfig = $config['db'];
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbConfig['host'], $dbConfig['name'], $dbConfig['charset']);
        return new PDO($dsn, $dbConfig['write']['user'], $dbConfig['write']['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function fetchByLocationName(string $locationName): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM analytics_events WHERE location_name = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$locationName]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function countByTerm(string $searchTerm): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM analytics_events WHERE search_term = ?');
        $stmt->execute([$searchTerm]);
        return (int) $stmt->fetchColumn();
    }

    // ---- 1) İzinli event tipleri kaydediliyor --------------------------------

    public function testAllowedEventTypesAreLoggedToRealTable(): void
    {
        $searchTerm = $this->prefix . 'kutuphane';
        $locationName = $this->prefix . 'Konum';

        $this->assertTrue(analytics_log_event($this->pdo, [
            'event_type' => 'search',
            'search_term' => $searchTerm,
        ]));
        $this->assertTrue(analytics_log_event($this->pdo, [
            'event_type' => 'location_view',
            'location_id' => 42,
            'location_name' => $locationName,
            'location_type' => 'Bina',
        ]));
        $this->assertTrue(analytics_log_event($this->pdo, [
            'event_type' => 'route_request',
            'location_id' => 42,
            'location_name' => $locationName,
            'route_mode' => 'walk',
        ]));

        $this->assertSame(1, $this->countByTerm($searchTerm));

        $stmt = $this->pdo->prepare('SELECT event_type FROM analytics_events WHERE location_name = ? ORDER BY id ASC');
        $stmt->execute([$locationName]);
        $this->assertSame(['location_view', 'route_request'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testVerifiedSummaryOnlyCountsTrustedPublicEvents(): void
    {
        $stmt = $this->pdo->query(
            "SELECT id, name, type FROM campus_locations WHERE active = 1 ORDER BY id ASC LIMIT 1"
        );
        $location = $stmt->fetch();
        if ($location === false) {
            $this->markTestSkipped('Doğrulanmış analitik testi için aktif konum gerekli.');
        }

        $searchTerm = $this->publicPrefix . 'search';
        $locationName = $this->publicPrefix . 'Verified Location';
        $fakeLocationName = $this->publicPrefix . 'Özel Test Binası';
        $before = analytics_summary($this->pdo, true);

        analytics_log_event($this->pdo, [
            'event_type' => 'search',
            'search_term' => $searchTerm,
        ]);
        analytics_log_event($this->pdo, [
            'event_type' => 'location_view',
            'location_id' => (int) $location['id'],
            'location_name' => $locationName,
            'location_type' => (string) $location['type'],
        ]);
        analytics_log_event($this->pdo, [
            'event_type' => 'location_view',
            'location_id' => 910002,
            'location_name' => $fakeLocationName,
            'location_type' => 'Bina',
        ]);

        $verifiedRows = $this->pdo->prepare(
            'SELECT location_name, search_term, is_verified
             FROM analytics_events
             WHERE search_term = ? OR location_name IN (?, ?)
             ORDER BY id ASC'
        );
        $verifiedRows->execute([$searchTerm, $locationName, $fakeLocationName]);
        $rows = $verifiedRows->fetchAll();

        $this->assertSame(1, (int) $rows[0]['is_verified']);
        $this->assertSame(1, (int) $rows[1]['is_verified']);
        $this->assertSame(0, (int) $rows[2]['is_verified']);

        $summary = analytics_summary($this->pdo, true);
        $this->assertGreaterThanOrEqual($before['today']['searches'] + 1, $summary['today']['searches']);
        $this->assertGreaterThanOrEqual($before['today']['location_views'] + 1, $summary['today']['location_views']);
        $this->assertNotContains($fakeLocationName, array_column($summary['top_locations'], 'location_name'));
    }

    // ---- 2) Geçersiz event reddediliyor ---------------------------------------

    public function testInvalidEventTypeIsRejectedAndNothingIsWritten(): void
    {
        $searchTerm = $this->prefix . 'gecersiz_tip';

        $result = analytics_log_event($this->pdo, [
            'event_type' => 'hack_attempt',
            'search_term' => $searchTerm,
        ]);

        $this->assertFalse($result);
        $this->assertSame(0, $this->countByTerm($searchTerm));
    }

    public function testEmptyEventTypeIsRejected(): void
    {
        $this->assertFalse(analytics_log_event($this->pdo, ['event_type' => '', 'search_term' => 'x']));
    }

    // ---- 3) search için kısa/boş terim kaydedilmiyor -------------------------

    public function testSearchWithEmptyTermIsRejected(): void
    {
        $this->assertFalse(analytics_log_event($this->pdo, ['event_type' => 'search', 'search_term' => '']));
        $this->assertFalse(analytics_log_event($this->pdo, ['event_type' => 'search', 'search_term' => '   ']));
    }

    public function testSearchWithSingleCharacterTermIsRejected(): void
    {
        $this->assertFalse(analytics_log_event($this->pdo, ['event_type' => 'search', 'search_term' => 'a']));
    }

    public function testSearchWithTwoOrMoreCharactersIsAccepted(): void
    {
        $searchTerm = $this->prefix . 'ab'; // önek dahil >= 2 karakter, gerçek eşik zaten aşılıyor
        $this->assertTrue(analytics_log_event($this->pdo, ['event_type' => 'search', 'search_term' => $searchTerm]));
        $this->assertSame(1, $this->countByTerm($searchTerm));
    }

    public function testSearchEventIgnoresLocationAndRouteFields(): void
    {
        // search event'i location_id/name/type ve route_mode'u HER ZAMAN
        // null'lar — bunlar search'e ait değil (bkz. analytics_log_event).
        $searchTerm = $this->prefix . 'konum_alanlari_yoksayilmali';
        analytics_log_event($this->pdo, [
            'event_type' => 'search',
            'search_term' => $searchTerm,
            'location_id' => 999,
            'location_name' => 'Sızmamalı',
            'route_mode' => 'walk',
        ]);

        $stmt = $this->pdo->prepare('SELECT location_id, location_name, route_mode FROM analytics_events WHERE search_term = ?');
        $stmt->execute([$searchTerm]);
        $row = $stmt->fetch();
        $this->assertNull($row['location_id']);
        $this->assertNull($row['location_name']);
        $this->assertNull($row['route_mode']);
    }

    // ---- 4) route_request için route_mode doğrulanıyor -----------------------

    public function testRouteRequestStoresValidRouteMode(): void
    {
        $locationName = $this->prefix . 'GecerliMod';
        analytics_log_event($this->pdo, [
            'event_type' => 'route_request',
            'location_name' => $locationName,
            'route_mode' => 'drive',
        ]);

        $row = $this->fetchByLocationName($locationName);
        $this->assertNotNull($row);
        $this->assertSame('drive', $row['route_mode']);
    }

    public function testRouteRequestNullsOutInvalidRouteModeButStillLogsEvent(): void
    {
        $locationName = $this->prefix . 'GecersizMod';
        $logged = analytics_log_event($this->pdo, [
            'event_type' => 'route_request',
            'location_name' => $locationName,
            'route_mode' => 'ucarak', // walk/drive dışında -> null'lanmalı, ret DEĞİL
        ]);

        $this->assertTrue($logged, 'geçersiz route_mode olayı reddetmemeli, sadece alanı null\'lamalı');
        $row = $this->fetchByLocationName($locationName);
        $this->assertNotNull($row);
        $this->assertNull($row['route_mode']);
    }

    public function testRouteModeIsIgnoredForNonRouteRequestEvents(): void
    {
        // route_mode yalnızca route_request için saklanır; başka bir event
        // tipine sızdırılırsa (ör. yanlışlıkla payload'a eklenmişse) yok sayılır.
        $locationName = $this->prefix . 'YanlisEventTipi';
        analytics_log_event($this->pdo, [
            'event_type' => 'location_view',
            'location_name' => $locationName,
            'route_mode' => 'walk',
        ]);

        $row = $this->fetchByLocationName($locationName);
        $this->assertNotNull($row);
        $this->assertNull($row['route_mode']);
    }

    public function testRouteRequestWithoutLocationIdOrNameIsRejected(): void
    {
        $result = analytics_log_event($this->pdo, [
            'event_type' => 'route_request',
            'route_mode' => 'walk',
            // location_id VE location_name eksik -> reddedilmeli
        ]);
        $this->assertFalse($result);
    }

    // ---- 5) Retention cleanup beklenen eski kayıtları siliyor -----------------

    public function testCleanupDeletesRecordsOlderThanRetentionWindowButKeepsRecentOnes(): void
    {
        $oldTerm = $this->prefix . 'eski_kayit';
        $recentTerm = $this->prefix . 'yeni_kayit';

        // NOW() - INTERVAL burada SQL tarafında hesaplanır; PHP'nin kendi
        // saat dilimiyle MariaDB'nin NOW() referansı arasında olası bir
        // fark riskini tamamen ortadan kaldırır (ikisi de aynı ölçütü kullanır).
        $this->pdo->prepare(
            "INSERT INTO analytics_events (event_type, search_term, created_at) VALUES ('search', ?, NOW() - INTERVAL 100 DAY)"
        )->execute([$oldTerm]);
        $this->pdo->prepare(
            "INSERT INTO analytics_events (event_type, search_term, created_at) VALUES ('search', ?, NOW() - INTERVAL 10 DAY)"
        )->execute([$recentTerm]);

        // analytics_cleanup() PDO nesne kimliğine göre "yalnızca bir kez
        // çalış" koruması taşır (static $ran, spl_object_id anahtarlı).
        // Paylaşılan havuzlanmış db('write') bağlantısını kullanırsak, bu
        // süreçte BAŞKA bir test zaten cleanup'ı tetiklemiş olabilir ve
        // burada sessizce no-op olur. Taze/görülmemiş bir bağlantı bunu
        // garanti önler.
        analytics_cleanup($this->freshPdo(), 90);

        $stmt = $this->pdo->prepare('SELECT search_term FROM analytics_events WHERE search_term IN (?, ?)');
        $stmt->execute([$oldTerm, $recentTerm]);
        $remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->assertNotContains($oldTerm, $remaining, '90 günden eski kayıt silinmeliydi');
        $this->assertContains($recentTerm, $remaining, '90 günden yeni kayıt korunmalıydı');
    }

    public function testCleanupRespectsCustomRetentionWindow(): void
    {
        $keptTerm = $this->prefix . 'esik_ici';
        $deletedTerm = $this->prefix . 'esik_disi';

        // 7 gün (izin verilen minimum) özel eşik: 10 gün önce eklenen
        // silinmeli, 2 gün önce eklenen kalmalı.
        $this->pdo->prepare(
            "INSERT INTO analytics_events (event_type, search_term, created_at) VALUES ('search', ?, NOW() - INTERVAL 10 DAY)"
        )->execute([$deletedTerm]);
        $this->pdo->prepare(
            "INSERT INTO analytics_events (event_type, search_term, created_at) VALUES ('search', ?, NOW() - INTERVAL 2 DAY)"
        )->execute([$keptTerm]);

        analytics_cleanup($this->freshPdo(), 7);

        $stmt = $this->pdo->prepare('SELECT search_term FROM analytics_events WHERE search_term IN (?, ?)');
        $stmt->execute([$deletedTerm, $keptTerm]);
        $remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->assertNotContains($deletedTerm, $remaining);
        $this->assertContains($keptTerm, $remaining);
    }

    // ---- Ek: gerçek CHECK kısıtı (şema düzeyinde) -----------------------------

    public function testRealSchemaCheckConstraintRejectsRawInsertWithInvalidEventType(): void
    {
        // analytics_log_event() zaten uygulama düzeyinde reddediyor (yukarıda
        // test edildi); bu test GERÇEK CREATE TABLE ... CHECK (event_type IN
        // (...)) kısıtının da (uygulama katmanı atlanıp doğrudan INSERT
        // denense bile) ikinci bir savunma hattı olduğunu doğrular — SQLite
        // birim testinde bu kısıt hiç tanımlı değildir.
        $this->expectException(\PDOException::class);
        $this->pdo->prepare(
            "INSERT INTO analytics_events (event_type, search_term) VALUES ('gecersiz_tip_dogrudan', ?)"
        )->execute([$this->prefix . 'dogrudan_insert']);
    }
}

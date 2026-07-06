<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../settings_ops.php';

final class SettingsOpsIntegrationTest extends TestCase
{
    private PDO $pdo;
    private array $previousValues;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = db('write');
        $this->previousValues = settings_ops_get_all($this->pdo);
    }

    protected function tearDown(): void
    {
        settings_ops_update($this->pdo, $this->previousValues, 'phpunit');
        parent::tearDown();
    }

    public function testUpdateAndReadRoundTripsThroughRealAppSettingsTable(): void
    {
        $errors = settings_ops_update($this->pdo, [
            'site_title' => '_PHPINT_ Test Başlığı',
            'map_center_lat' => '37.1',
            'map_center_lng' => '37.2',
            'map_zoom' => '12',
        ], 'phpunit');

        $this->assertSame([], $errors);

        $values = settings_ops_get_all($this->pdo);
        $this->assertSame('_PHPINT_ Test Başlığı', $values['site_title']);
        $this->assertSame('37.1', $values['map_center_lat']);
        $this->assertSame('12', $values['map_zoom']);
    }

    public function testInvalidValuesAreRejectedWithoutBlockingValidFields(): void
    {
        $errors = settings_ops_update($this->pdo, [
            'map_zoom' => '999',
            'contact_email' => 'not-an-email',
            'site_title' => '_PHPINT_ Geçerli Başlık',
        ], 'phpunit');

        $this->assertArrayHasKey('map_zoom', $errors);
        $this->assertArrayHasKey('contact_email', $errors);

        $values = settings_ops_get_all($this->pdo);
        $this->assertSame('_PHPINT_ Geçerli Başlık', $values['site_title']);
        $this->assertNotSame('999', $values['map_zoom']);
    }

    public function testPublicConfigExposesSafeSubsetWithExpectedShape(): void
    {
        settings_ops_update($this->pdo, [
            'default_language' => 'en',
            'map_center_lat' => '37.5',
            'map_center_lng' => '37.6',
            'map_zoom' => '14',
        ], 'phpunit');

        $public = settings_ops_public_config();

        $this->assertSame('en', $public['defaultLanguage']);
        $this->assertSame([37.5, 37.6], $public['mapCenter']);
        $this->assertSame(14, $public['mapZoom']);
        $this->assertArrayNotHasKey('pwa_short_name', $public);
    }
}

<?php
declare(strict_types=1);

namespace Tests\Integration;

/**
 * campus_locations.active kolonunu ve toplu işlem (bulk_update_active,
 * bulk_update_parent) fonksiyonlarını gerçek MariaDB'ye karşı doğrular.
 * Ayrıca get_locations.php'nin (public API) pasif kayıtları GERÇEKTEN
 * dışladığını, aynı SQL sorgusunu burada çalıştırarak doğrular (HTTP
 * katmanını taklit etmeden, sorgunun kendisini test eder).
 */
final class LocationActiveAndBulkIntegrationTest extends IntegrationTestCase
{
    public function testNewLocationDefaultsToActiveWhenColumnNotProvided(): void
    {
        // insertLocation() 'active' anahtarını GÖNDERMEZ; save_location_record
        // SQL'i array_keys($data)'dan kurduğu için DB varsayılanı (1) uygulanır.
        $id = $this->insertLocation(['name' => $this->prefix . 'Varsayilan']);

        $row = find_location($this->pdo, $id);
        $this->assertSame(1, (int) $row['active']);
    }

    public function testBulkUpdateActiveTogglesSelectedRowsOnly(): void
    {
        $activeId = $this->insertLocation(['name' => $this->prefix . 'Aktif']);
        $untouchedId = $this->insertLocation(['name' => $this->prefix . 'Dokunulmamis']);

        $affected = bulk_update_active($this->pdo, [$activeId], false);

        $this->assertSame(1, $affected);
        $this->assertSame(0, (int) find_location($this->pdo, $activeId)['active']);
        $this->assertSame(1, (int) find_location($this->pdo, $untouchedId)['active']);
    }

    public function testBulkUpdateParentMovesSelectedRowsToNewParent(): void
    {
        $parentId = $this->insertLocation(['name' => $this->prefix . 'Ust']);
        $childId = $this->insertLocation(['name' => $this->prefix . 'Alt', 'parent_id' => null]);

        $affected = bulk_update_parent($this->pdo, [$childId], $parentId);

        $this->assertSame(1, $affected);
        $this->assertSame($parentId, (int) find_location($this->pdo, $childId)['parent_id']);
    }

    public function testBulkUpdateParentSkipsRowsThatWouldCreateCycle(): void
    {
        $parentId = $this->insertLocation(['name' => $this->prefix . 'DonguUst']);
        $childId = $this->insertLocation(['name' => $this->prefix . 'DonguAlt', 'parent_id' => $parentId]);

        $affected = bulk_update_parent($this->pdo, [$parentId], $childId);

        $this->assertSame(0, $affected);
        $this->assertNull(find_location($this->pdo, $parentId)['parent_id']);
        $this->assertSame($parentId, (int) find_location($this->pdo, $childId)['parent_id']);
    }

    public function testBulkUpdateActiveReportsZeroWhenValueUnchanged(): void
    {
        $id = $this->insertLocation(['name' => $this->prefix . 'AynenKalir']);

        $affected = bulk_update_active($this->pdo, [$id], true);

        $this->assertSame(0, $affected, 'Zaten aktif olan bir kaydı tekrar aktif yapmak satır etkilememeli');
    }

    public function testPublicLocationsQueryExcludesInactiveRows(): void
    {
        $activeId = $this->insertLocation(['name' => $this->prefix . 'Gorunur']);
        $inactiveId = $this->insertLocation(['name' => $this->prefix . 'Gizli']);
        bulk_update_active($this->pdo, [$inactiveId], false);

        // get_locations.php'deki gerçek sorgunun birebir aynısı.
        $rows = $this->pdo
            ->query('SELECT id FROM campus_locations WHERE active = 1 ORDER BY sirala ASC, id ASC')
            ->fetchAll();
        $ids = array_map('intval', array_column($rows, 'id'));

        $this->assertContains($activeId, $ids);
        $this->assertNotContains($inactiveId, $ids);
    }
}

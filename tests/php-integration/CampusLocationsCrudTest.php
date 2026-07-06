<?php
declare(strict_types=1);

namespace Tests\Integration;

/**
 * campus_locations üzerinde gerçek insert/update/delete, gerçek parent_id
 * FOREIGN KEY + ON DELETE CASCADE ve gerçek utf8mb4 charset/collation
 * davranışını doğrular. Bunların hiçbiri tests/php/'deki SQLite unit
 * testlerinde gerçek anlamda test edilemez (SQLite'ta FK varsayılan kapalı
 * olabilir, collation kuralları farklıdır, charset zorlaması yoktur).
 */
final class CampusLocationsCrudTest extends IntegrationTestCase
{
    public function testInsertWritesRowAndReadBackMatches(): void
    {
        $id = $this->insertLocation([
            'name' => $this->prefix . 'Ekleme Testi',
            'name_en' => 'Insert Test Location',
            'type' => 'Bina',
            'address' => 'Test Adresi 1',
            'description_en' => 'English integration description',
            'capacity' => 250,
        ]);

        $row = find_location($this->pdo, $id);
        $this->assertNotNull($row);
        $this->assertSame($this->prefix . 'Ekleme Testi', $row['name']);
        $this->assertSame('Insert Test Location', $row['name_en']);
        $this->assertSame('Bina', $row['type']);
        $this->assertSame('Test Adresi 1', $row['address']);
        $this->assertSame('English integration description', $row['description_en']);
        $this->assertSame(250, (int) $row['capacity']);
    }

    public function testUpdateChangesExistingRowInPlace(): void
    {
        $id = $this->insertLocation(['name' => $this->prefix . 'Once', 'type' => 'Bina']);

        $updated = collect_location([
            'name' => $this->prefix . 'Sonra',
            'type' => 'Kafe',
            'address' => 'Yeni Adres',
        ]);
        $returnedId = save_location_record($this->pdo, $updated, $id);

        $this->assertSame($id, $returnedId, 'update yolu aynı id\'yi döndürmeli (yeni satır oluşturmamalı)');
        $row = find_location($this->pdo, $id);
        $this->assertSame($this->prefix . 'Sonra', $row['name']);
        $this->assertSame('Kafe', $row['type']);
        $this->assertSame('Yeni Adres', $row['address']);

        // Toplam satır sayısı artmamalı — update, insert değil.
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM campus_locations WHERE name LIKE " .
            $this->pdo->quote($this->prefix . '%')
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testDeleteRemovesRowFromRealTable(): void
    {
        $id = $this->insertLocation();
        $this->pdo->prepare('DELETE FROM campus_locations WHERE id = ?')->execute([$id]);
        $this->assertNull(find_location($this->pdo, $id));
    }

    public function testParentChildRelationshipIsPersisted(): void
    {
        $parentId = $this->insertLocation(['name' => $this->prefix . 'Ebeveyn']);
        $childId = $this->insertLocation(['name' => $this->prefix . 'Cocuk', 'parent_id' => $parentId]);

        $child = find_location($this->pdo, $childId);
        $this->assertSame($parentId, (int) $child['parent_id']);
    }

    public function testDeletingParentCascadesToChildViaRealForeignKey(): void
    {
        $parentId = $this->insertLocation(['name' => $this->prefix . 'Ebeveyn2']);
        $childId = $this->insertLocation(['name' => $this->prefix . 'Cocuk2', 'parent_id' => $parentId]);
        $grandchildId = $this->insertLocation(['name' => $this->prefix . 'Torun', 'parent_id' => $childId]);

        // Gerçek FK: campus_locations_ibfk_1 ... ON DELETE CASCADE.
        // SQLite unit testleri bunu doğrulayamaz (would_create_parent_cycle
        // orada FK'siz sahte bir tabloya karşı test edilir).
        $this->pdo->prepare('DELETE FROM campus_locations WHERE id = ?')->execute([$parentId]);

        $this->assertNull(find_location($this->pdo, $parentId));
        $this->assertNull(find_location($this->pdo, $childId), 'ON DELETE CASCADE ile çocuk da silinmeliydi');
        $this->assertNull(find_location($this->pdo, $grandchildId), 'CASCADE çok seviyeli de yayılmalı (torun)');
    }

    public function testTurkishDiacriticsAndEmojiSurviveUtf8mb4RoundTrip(): void
    {
        // Türkçe aksanlar (BMP, utf8mb3'te de temsil edilebilir) VE bir emoji
        // (4 baytlık, yalnızca gerçek utf8mb4 destekler) birlikte test edilir
        // — utf8mb4'ün asıl amacı ikincisidir; yalnızca Türkçe harfleri test
        // etmek utf8mb4-özel bir garantiyi kanıtlamaz.
        $name = $this->prefix . 'Şğıöüç İĞÜ 🎓 Bölümü';
        $description = 'Açıklama: çşğıöü — 📍 test — 中文 テスト';

        $id = $this->insertLocation(['name' => $name, 'description' => $description]);
        $row = find_location($this->pdo, $id);

        $this->assertSame($name, $row['name']);
        $this->assertSame($description, $row['description']);
    }

    public function testRealSchemaCollationIsAccentAndCaseInsensitive(): void
    {
        // BULGU (bilgi amaçlı, "hata" değil): gerçek şemanın collation'ı
        // (utf8mb4_uca1400_ai_ci) aksan-duyarsız (ai) VE büyük/küçük harf
        // duyarsızdır (ci). Yani "Kütüphane" ile "KUTUPHANE" veritabanı
        // düzeyinde EŞİT sayılır. Bu, validate_location'ın duplicate
        // kontrolünü doğrudan etkiler (bkz. ValidateLocationDuplicateIntegrationTest).
        $exact = $this->prefix . 'Kütüphane';
        $this->insertLocation(['name' => $exact, 'type' => 'Bina']);

        $foldedUpper = $this->prefix . 'KUTUPHANE';
        $stmt = $this->pdo->prepare('SELECT id FROM campus_locations WHERE name = ?');
        $stmt->execute([$foldedUpper]);
        $this->assertNotFalse(
            $stmt->fetchColumn(),
            'gerçek collation aksan/büyük-küçük harf farkını yok saymalı (SQLite\'ta bu davranış farklıdır)'
        );
    }
}

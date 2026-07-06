<?php
declare(strict_types=1);

namespace Tests\Integration;

/**
 * validate_location()'ın "aynı ad ve türde kayıt zaten var" kontrolünü GERÇEK
 * MariaDB verisine karşı doğrular. tests/php/LocationsValidationTest.php
 * bunu SQLite in-memory ile test eder (varsayılan, aksan/büyük-küçük harf
 * DUYARLI karşılaştırma); burada gerçek şemanın collation'ı
 * (utf8mb4_uca1400_ai_ci — aksan-duyarsız + büyük/küçük harf duyarsız)
 * kullanıldığında sonucun FARKLI olabileceği doğrulanır.
 */
final class ValidateLocationDuplicateIntegrationTest extends IntegrationTestCase
{
    public function testRejectsExactDuplicateNameAndTypeAgainstRealTable(): void
    {
        $this->insertLocation(['name' => $this->prefix . 'Kütüphane', 'type' => 'Bina']);

        $data = collect_location(['name' => $this->prefix . 'Kütüphane', 'type' => 'Bina']);
        $errors = validate_location($this->pdo, $data, 0);

        $this->assertContains('Aynı ad ve türde bir kayıt zaten var.', $errors);
    }

    public function testAllowsUpdatingTheSameRecordWithoutFalseDuplicateError(): void
    {
        $id = $this->insertLocation(['name' => $this->prefix . 'Kütüphane', 'type' => 'Bina']);

        $data = collect_location(['name' => $this->prefix . 'Kütüphane', 'type' => 'Bina']);
        $errors = validate_location($this->pdo, $data, $id);

        $this->assertNotContains('Aynı ad ve türde bir kayıt zaten var.', $errors);
    }

    public function testDifferentTypeIsNotConsideredDuplicate(): void
    {
        $this->insertLocation(['name' => $this->prefix . 'Kütüphane', 'type' => 'Bina']);

        $data = collect_location(['name' => $this->prefix . 'Kütüphane', 'type' => 'Kafe']);
        $errors = validate_location($this->pdo, $data, 0);

        $this->assertNotContains('Aynı ad ve türde bir kayıt zaten var.', $errors);
    }

    public function testRealCollationTreatsAccentAndCaseVariantsAsDuplicates(): void
    {
        // BULGU: gerçek şemanın collation'ı aksan-duyarsız + büyük/küçük
        // harf duyarsızdır (bkz. CampusLocationsCrudTest::
        // testRealSchemaCollationIsAccentAndCaseInsensitive). Bu yüzden
        // "Kütüphane" zaten kayıtlıyken "KUTUPHANE" (aksansız, büyük harf)
        // eklemeye çalışmak da GERÇEK ortamda "zaten var" olarak reddedilir
        // — SQLite unit testi bunu yakalayamaz çünkü SQLite'ın varsayılan
        // metin karşılaştırması tam tersine (aksan/büyük-küçük harf DUYARLI).
        $this->insertLocation(['name' => $this->prefix . 'Kütüphane', 'type' => 'Bina']);

        $data = collect_location(['name' => $this->prefix . 'KUTUPHANE', 'type' => 'Bina']);
        $errors = validate_location($this->pdo, $data, 0);

        $this->assertContains(
            'Aynı ad ve türde bir kayıt zaten var.',
            $errors,
            'gerçek collation aksan+büyük/küçük harf farklı olan ismi de duplicate saymalı'
        );
    }

    public function testWouldCreateParentCycleAgainstRealMultiLevelHierarchy(): void
    {
        $grandparentId = $this->insertLocation(['name' => $this->prefix . 'Buyukanne']);
        $parentId = $this->insertLocation(['name' => $this->prefix . 'Anne', 'parent_id' => $grandparentId]);
        $childId = $this->insertLocation(['name' => $this->prefix . 'Cocuk', 'parent_id' => $parentId]);

        // Büyükanneyi kendi torununun altına taşımak döngü oluşturur.
        $this->assertTrue(would_create_parent_cycle($this->pdo, $grandparentId, $childId));
        // Ama tamamen ilgisiz yeni bir köke taşımak sorun değildir.
        $unrelatedId = $this->insertLocation(['name' => $this->prefix . 'Ilgisiz']);
        $this->assertFalse(would_create_parent_cycle($this->pdo, $grandparentId, $unrelatedId));
    }
}

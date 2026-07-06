<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../get_locations_lib.php';

/**
 * get_locations.php'nin ağaç kurma mantığını (normalize_type, plain_text,
 * build_tree) DB'ye dokunmadan doğrular. build_tree gaun_match/
 * gaun_is_dining_location'ı çağırır (gaun_enrich.php, get_locations_lib.php
 * tarafından require_once edilir) — burada sadece boş/etkisiz cache
 * değerleriyle (gaunIndex boş, diningMenu iskelet) çağırıyoruz.
 */
final class GetLocationsTreeTest extends TestCase
{
    private function emptyGaunIndex(): array
    {
        return ['by_name' => [], 'by_host' => []];
    }

    private function emptyDiningMenu(): array
    {
        return ['date' => '2026-01-01', 'items' => [], 'calories' => null];
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'parent_id' => null,
            'name' => 'Test Konumu',
            'name_en' => null,
            'type' => 'Bina',
            'latitude' => null,
            'longitude' => null,
            'description' => null,
            'description_en' => null,
            'link' => null,
            'route' => null,
            'address' => null,
            'phone' => null,
            'facilities' => null,
            'capacity' => null,
            'rooms' => null,
            'amenities' => null,
            'opening_hours' => null,
            'manager_name' => null,
            'manager_email' => null,
            'image_url' => null,
            'rating' => null,
            'color' => null,
        ], $overrides);
    }

    // ---- normalize_type -----------------------------------------------------

    public function testNormalizeTypeTitleCasesText(): void
    {
        $this->assertSame('Bina', normalize_type('bina'));
        $this->assertSame('Yemekhane', normalize_type('YEMEKHANE'));
        $this->assertSame('Diğer', normalize_type(''));
        $this->assertSame('Diğer', normalize_type(null));
    }

    public function testNormalizeTypeDoesNotRestoreTurkishDottedCapitalI(): void
    {
        // Bilinen incelik: mb_convert_case(..., MB_CASE_TITLE) Türkçe'ye özel
        // değildir; kelime "i" ile başlıyorsa noktalı büyük "İ" değil sıradan
        // ASCII "I" üretir (bkz. normalize_type kaynağındaki not). Bu test bunu
        // "doğru" olarak onaylamaz — sadece MEVCUT davranışı sabitler; gelecekte
        // kasıtlı bir düzeltme yapılırsa bu test bilinçli olarak güncellenmeli.
        $this->assertSame('Idari Bina', normalize_type('idari bina'));
    }

    // ---- plain_text -----------------------------------------------------------

    public function testPlainTextStripsHtmlAndBoilerplatePhrases(): void
    {
        $html = '<p>Kısa açıklama. <a href="#">Yol Tarifi Al</a> <b>Web Sitesi</b></p>';
        $this->assertSame('Kısa açıklama.', plain_text($html));
    }

    public function testPlainTextCollapsesWhitespace(): void
    {
        $this->assertSame('a b c', plain_text("a   b\n\nc"));
    }

    // ---- build_tree: temel alan geçişi ----------------------------------------

    public function testBuildTreeIncludesCoordsOnlyWhenBothLatAndLngPresent(): void
    {
        $rows = [$this->row(['latitude' => 37.05, 'longitude' => 37.38])];
        $tree = build_tree($rows, $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertSame([37.05, 37.38], $tree[0]['coords']);
    }

    public function testBuildTreeOmitsCoordsWhenLatLngMissing(): void
    {
        $tree = build_tree([$this->row()], $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertArrayNotHasKey('coords', $tree[0]);
    }

    public function testBuildTreeOmitsEmptyOptionalFields(): void
    {
        $tree = build_tree([$this->row(['address' => '', 'phone' => null])], $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertArrayNotHasKey('address', $tree[0]);
        $this->assertArrayNotHasKey('phone', $tree[0]);
    }

    public function testBuildTreeCastsNumericFieldsToFloat(): void
    {
        $tree = build_tree([$this->row(['capacity' => '250', 'rating' => '4'])], $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertSame(250.0, $tree[0]['capacity']);
        $this->assertSame(4.0, $tree[0]['rating']);
    }

    public function testBuildTreeAddsPlainTextSummaryForDescription(): void
    {
        $tree = build_tree([$this->row(['description' => '<p>Bir <b>açıklama</b>.</p>'])], $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertSame('Bir açıklama.', $tree[0]['description_text']);
    }

    public function testBuildTreeIncludesEnglishNameAndDescriptionFields(): void
    {
        $tree = build_tree([$this->row([
            'name_en' => 'Test Location',
            'description_en' => '<p>An <b>English</b> description.</p>',
        ])], $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);

        $this->assertSame('Test Location', $tree[0]['name_en']);
        $this->assertSame('<p>An <b>English</b> description.</p>', $tree[0]['description_en']);
        $this->assertSame('An English description.', $tree[0]['description_en_text']);
    }

    // ---- build_tree: parent/child hiyerarşisi --------------------------------

    public function testBuildTreeNestsChildrenUnderParent(): void
    {
        $rows = [
            $this->row(['id' => 1, 'parent_id' => null, 'name' => 'Mühendislik Fakültesi']),
            $this->row(['id' => 2, 'parent_id' => 1, 'name' => 'Bilgisayar Mühendisliği']),
            $this->row(['id' => 3, 'parent_id' => 1, 'name' => 'Elektrik Mühendisliği']),
        ];
        $tree = build_tree($rows, $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertCount(1, $tree);
        $this->assertCount(2, $tree[0]['children']);
        $this->assertSame('Bilgisayar Mühendisliği', $tree[0]['children'][0]['name']);
    }

    public function testBuildTreeSupportsMultipleNestingLevels(): void
    {
        $rows = [
            $this->row(['id' => 1, 'parent_id' => null, 'name' => 'Kategori']),
            $this->row(['id' => 2, 'parent_id' => 1, 'name' => 'Fakülte']),
            $this->row(['id' => 3, 'parent_id' => 2, 'name' => 'Bölüm']),
        ];
        $tree = build_tree($rows, $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertSame('Bölüm', $tree[0]['children'][0]['children'][0]['name']);
    }

    public function testBuildTreeOmitsChildrenKeyWhenNoChildren(): void
    {
        $tree = build_tree([$this->row()], $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertArrayNotHasKey('children', $tree[0]);
    }

    public function testBuildTreeIgnoresOrphanRowsWithMissingParent(): void
    {
        // parent_id bir grup anahtarı olarak kullanılır; var olmayan bir
        // parent_id'ye sahip satır 'root' altına düşmez, sonuç ağaçta
        // görünmez (build('root') yalnızca gerçek kök satırları döner).
        $rows = [$this->row(['id' => 5, 'parent_id' => 999, 'name' => 'Yetim Kayıt'])];
        $tree = build_tree($rows, $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertSame([], $tree);
    }

    // ---- build_tree: GAÜN zenginleştirme, yemekhane, personel ----------------

    public function testBuildTreeAttachesGaunMatchOnExactNameHit(): void
    {
        $gaunIndex = gaun_build_index(['units' => [
            ['name' => 'Test Konumu', 'website' => 'https://test.gaziantep.edu.tr'],
        ]]);
        $tree = build_tree([$this->row()], $gaunIndex, $this->emptyDiningMenu(), []);
        $this->assertSame('https://test.gaziantep.edu.tr', $tree[0]['gaun']['website']);
    }

    public function testBuildTreeDoesNotAttachGaunWhenNoMatch(): void
    {
        $tree = build_tree([$this->row()], $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertArrayNotHasKey('gaun', $tree[0]);
    }

    public function testBuildTreeAttachesDiningOnlyForDiningLocations(): void
    {
        $rows = [
            $this->row(['id' => 1, 'name' => 'Merkez Öğrenci Yemekhanesi', 'type' => 'Yemekhane']),
            $this->row(['id' => 2, 'name' => 'Kütüphane', 'type' => 'Bina']),
        ];
        $menu = ['date' => '2026-01-01', 'items' => [['name' => 'Çorba', 'kcal' => 100]]];
        $tree = build_tree($rows, $this->emptyGaunIndex(), $menu, []);
        $this->assertSame($menu, $tree[0]['dining']);
        $this->assertArrayNotHasKey('dining', $tree[1]);
    }

    public function testBuildTreeAttachesStaffAndCountWhenPresent(): void
    {
        $staffByLocation = [1 => [
            ['name' => 'Ayşe Yılmaz', 'title' => 'Prof. Dr.', 'source_url' => 'https://gaziantep.edu.tr/x'],
        ]];
        $tree = build_tree([$this->row(['id' => 1])], $this->emptyGaunIndex(), $this->emptyDiningMenu(), $staffByLocation);
        $this->assertSame(1, $tree[0]['staff_count']);
        $this->assertSame('Ayşe Yılmaz', $tree[0]['staff'][0]['name']);
    }

    public function testBuildTreeOmitsStaffKeyWhenNoneAssigned(): void
    {
        $tree = build_tree([$this->row(['id' => 42])], $this->emptyGaunIndex(), $this->emptyDiningMenu(), []);
        $this->assertArrayNotHasKey('staff', $tree[0]);
        $this->assertArrayNotHasKey('staff_count', $tree[0]);
    }
}

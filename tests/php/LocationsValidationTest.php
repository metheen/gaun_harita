<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../bordo/locations_lib.php';

/**
 * bordo/locations_lib.php: konum formu doğrulama, görsel referansı doğrulama
 * ve CSV içe aktarım ayrıştırma/doğrulama mantığını test eder.
 *
 * Not (bkz. README "Test edilemeyen riskler"): uploaded_location_image_path()
 * ve import_locations_csv()'nin BAŞARILI yükleme yolu is_uploaded_file()'a
 * dayanır; bu fonksiyon yalnızca gerçek bir HTTP multipart isteğinde true
 * döner, birim testinde asla true olmaz. Burada yalnızca is_uploaded_file'dan
 * ÖNCE kısa devre yapan dallar (hatalı uzantı, boyut sınırı, hata kodu)
 * test edilir; gerçek dosya kaydetme akışı manuel/entegrasyon testi ister.
 */
final class LocationsValidationTest extends TestCase
{
    private function sqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE campus_locations (
            id INTEGER PRIMARY KEY,
            parent_id INTEGER,
            name TEXT,
            type TEXT
        )');
        return $pdo;
    }

    // ---- is_valid_http_url --------------------------------------------------

    public function testValidHttpUrlAcceptsHttpAndHttps(): void
    {
        $this->assertTrue(is_valid_http_url('https://gaziantep.edu.tr'));
        $this->assertTrue(is_valid_http_url('http://example.com/path'));
    }

    public function testValidHttpUrlAcceptsNull(): void
    {
        // Alan boş bırakılabilir (zorunlu değil); null her zaman geçerlidir.
        $this->assertTrue(is_valid_http_url(null));
    }

    public function testValidHttpUrlRejectsOtherSchemesAndGarbage(): void
    {
        $this->assertFalse(is_valid_http_url('javascript:alert(1)'));
        $this->assertFalse(is_valid_http_url('ftp://example.com'));
        $this->assertFalse(is_valid_http_url('not a url'));
    }

    // ---- is_valid_image_reference -------------------------------------------

    public function testValidImageReferenceAcceptsHttpUrl(): void
    {
        $this->assertTrue(is_valid_image_reference('https://example.com/logo.png'));
    }

    public function testValidImageReferenceAcceptsUploadsPathWithAllowedExtension(): void
    {
        $this->assertTrue(is_valid_image_reference('uploads/locations/abc123.jpg'));
        $this->assertTrue(is_valid_image_reference('/uploads/locations/abc123.webp'));
        $this->assertTrue(is_valid_image_reference('uploads/Gaziantep-universitesi-logo.jpg'));
    }

    public function testValidImageReferenceRejectsPathTraversal(): void
    {
        $this->assertFalse(is_valid_image_reference('uploads/locations/../../../../etc/passwd'));
    }

    public function testValidImageReferenceRejectsWrongDirectory(): void
    {
        $this->assertFalse(is_valid_image_reference('uploads/other/abc123.jpg'));
    }

    public function testValidImageReferenceRejectsDisallowedExtension(): void
    {
        $this->assertFalse(is_valid_image_reference('uploads/locations/script.php'));
        $this->assertFalse(is_valid_image_reference('uploads/locations/script.svg'));
    }

    public function testValidImageReferenceAcceptsNull(): void
    {
        $this->assertTrue(is_valid_image_reference(null));
    }

    // ---- uploaded_location_image_path: kısa devre dallar (bkz. sınıf notu) -

    public function testUploadedImagePathReturnsNullPathWhenNoFileSent(): void
    {
        $result = uploaded_location_image_path(null);
        $this->assertNull($result['path']);
        $this->assertNull($result['error']);
    }

    public function testUploadedImagePathReturnsNullPathWhenUploadErrNoFile(): void
    {
        $result = uploaded_location_image_path(['error' => UPLOAD_ERR_NO_FILE]);
        $this->assertNull($result['path']);
        $this->assertNull($result['error']);
    }

    public function testUploadedImagePathRejectsPhpUploadError(): void
    {
        $result = uploaded_location_image_path(['error' => UPLOAD_ERR_INI_SIZE]);
        $this->assertNotNull($result['error']);
        $this->assertNull($result['path']);
    }

    public function testUploadedImagePathRejectsOversizedFile(): void
    {
        $result = uploaded_location_image_path([
            'error' => UPLOAD_ERR_OK,
            'size' => 10_000_000, // > 4 MB sınırı
            'tmp_name' => '/tmp/does-not-matter',
        ]);
        $this->assertStringContainsString('4 MB', (string) $result['error']);
        $this->assertNull($result['path']);
    }

    public function testUploadedImagePathRejectsZeroByteFile(): void
    {
        $result = uploaded_location_image_path([
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
            'tmp_name' => '/tmp/does-not-matter',
        ]);
        $this->assertNotNull($result['error']);
    }

    // ---- collect_location: POST -> normalize edilmiş alanlar ----------------

    public function testCollectLocationTrimsAndCoercesTypes(): void
    {
        $data = collect_location([
            'name' => '  Test Bina  ',
            'name_en' => '  Test Building  ',
            'type' => 'İdari Bina',
            'latitude' => '37.05',
            'longitude' => '',
            'description_en' => '  English description  ',
            'capacity' => '250',
            'rating' => '4.5',
        ]);
        $this->assertSame('Test Bina', $data['name']);
        $this->assertSame('Test Building', $data['name_en']);
        $this->assertSame('English description', $data['description_en']);
        $this->assertSame(37.05, $data['latitude']);
        $this->assertNull($data['longitude']);
        $this->assertSame(250, $data['capacity']);
        $this->assertSame(4.5, $data['rating']);
    }

    public function testCollectLocationDoesNotCoerceInvalidNumbersToZero(): void
    {
        $data = collect_location([
            'name' => 'X',
            'type' => 'Y',
            'parent_id' => 'abc',
            'latitude' => 'abc',
            'longitude' => 'def',
            'capacity' => 'çok',
            'rooms' => 'fazla',
            'rating' => 'iyi',
        ]);

        $this->assertNull($data['parent_id']);
        $this->assertNull($data['latitude']);
        $this->assertNull($data['longitude']);
        $this->assertNull($data['capacity']);
        $this->assertNull($data['rooms']);
        $this->assertNull($data['rating']);
    }

    public function testCollectLocationDefaultsColorToRedWhenBlank(): void
    {
        $data = collect_location(['name' => 'X', 'type' => 'Y', 'color' => '']);
        $this->assertSame('red', $data['color']);
    }

    public function testCollectLocationTreatsBlankStringsAsNull(): void
    {
        $data = collect_location(['name' => 'X', 'type' => 'Y', 'address' => '   ', 'name_en' => '   ', 'description_en' => '   ']);
        $this->assertNull($data['address']);
        $this->assertNull($data['name_en']);
        $this->assertNull($data['description_en']);
    }

    // ---- collect_location: 'active' checkbox anlambilimi ---------------------

    public function testCollectLocationActiveIsZeroWhenCheckboxAbsent(): void
    {
        // HTML checkbox işaretli değilse $_POST'ta hiç görünmez.
        $data = collect_location(['name' => 'X', 'type' => 'Y']);
        $this->assertSame(0, $data['active']);
    }

    public function testCollectLocationActiveIsOneWhenCheckboxPresent(): void
    {
        $data = collect_location(['name' => 'X', 'type' => 'Y', 'active' => '1']);
        $this->assertSame(1, $data['active']);
    }

    // ---- location_data_quality_conditions -------------------------------------

    public function testLocationDataQualityConditionsReturnsExpectedSqlFragments(): void
    {
        $this->assertSame("(type <> 'Kategori' AND (latitude IS NULL OR longitude IS NULL) AND (link IS NULL OR link = ''))", location_data_quality_conditions('missing_coords'));
        $this->assertSame("(name_en IS NULL OR name_en = '')", location_data_quality_conditions('missing_en'));
        $this->assertSame("(type <> 'Kategori' AND (image_url IS NULL OR image_url = ''))", location_data_quality_conditions('missing_image'));
        $this->assertSame("(type <> 'Kategori' AND (link IS NULL OR link = '') AND (latitude IS NULL OR longitude IS NULL))", location_data_quality_conditions('missing_link'));
        $this->assertSame('active = 0', location_data_quality_conditions('inactive'));
    }

    public function testLocationDataQualityConditionsReturnsNullForUnknownFilter(): void
    {
        $this->assertNull(location_data_quality_conditions('not_a_real_filter'));
    }

    // ---- validate_location (SQLite ile) --------------------------------------

    public function testValidateLocationRequiresNameAndType(): void
    {
        $pdo = $this->sqlitePdo();
        $data = collect_location(['name' => '', 'type' => '']);
        $errors = validate_location($pdo, $data, 0);
        $this->assertContains('Ad alanı zorunludur.', $errors);
        $this->assertContains('Tür alanı zorunludur.', $errors);
    }

    public function testValidateLocationRejectsLatitudeWithoutLongitude(): void
    {
        $pdo = $this->sqlitePdo();
        $data = collect_location(['name' => 'X', 'type' => 'Y', 'latitude' => '37.05']);
        $errors = validate_location($pdo, $data, 0);
        $this->assertContains('Latitude ve longitude birlikte girilmelidir.', $errors);
    }

    public function testValidateLocationRejectsOutOfRangeCoordinates(): void
    {
        $pdo = $this->sqlitePdo();
        $data = collect_location(['name' => 'X', 'type' => 'Y', 'latitude' => '200', 'longitude' => '37']);
        $errors = validate_location($pdo, $data, 0);
        $this->assertContains('Latitude -90 ile 90 arasında olmalıdır.', $errors);
    }

    public function testValidateLocationRejectsOutOfRangeRating(): void
    {
        $pdo = $this->sqlitePdo();
        $data = collect_location(['name' => 'X', 'type' => 'Y', 'rating' => '9']);
        $errors = validate_location($pdo, $data, 0);
        $this->assertContains('Puan 0 ile 5 arasında olmalıdır.', $errors);
    }

    public function testValidateLocationRejectsNegativeCapacityAndRooms(): void
    {
        $pdo = $this->sqlitePdo();
        $data = collect_location(['name' => 'X', 'type' => 'Y', 'capacity' => '-1', 'rooms' => '-2']);
        $errors = validate_location($pdo, $data, 0);
        $this->assertContains('Kapasite negatif olamaz.', $errors);
        $this->assertContains('Oda sayısı negatif olamaz.', $errors);
    }

    public function testValidateLocationRejectsMissingParentId(): void
    {
        $pdo = $this->sqlitePdo();
        $data = collect_location(['name' => 'X', 'type' => 'Y', 'parent_id' => '999']);
        $errors = validate_location($pdo, $data, 0);
        $this->assertContains('Üst kategori bulunamadı.', $errors);
    }

    public function testValidateLocationRejectsInvalidManagerEmail(): void
    {
        $pdo = $this->sqlitePdo();
        $data = collect_location(['name' => 'X', 'type' => 'Y', 'manager_email' => 'not-an-email']);
        $errors = validate_location($pdo, $data, 0);
        $this->assertContains('Yönetici e-posta adresi geçerli değil.', $errors);
    }

    public function testValidateLocationRejectsDuplicateNameAndType(): void
    {
        $pdo = $this->sqlitePdo();
        $pdo->exec("INSERT INTO campus_locations (id, parent_id, name, type) VALUES (1, NULL, 'Kütüphane', 'Bina')");
        $data = collect_location(['name' => 'Kütüphane', 'type' => 'Bina']);
        $errors = validate_location($pdo, $data, 0);
        $this->assertContains('Aynı ad ve türde bir kayıt zaten var.', $errors);
    }

    public function testValidateLocationAllowsUpdatingSameRecordWithoutDuplicateError(): void
    {
        $pdo = $this->sqlitePdo();
        $pdo->exec("INSERT INTO campus_locations (id, parent_id, name, type) VALUES (1, NULL, 'Kütüphane', 'Bina')");
        $data = collect_location(['name' => 'Kütüphane', 'type' => 'Bina']);
        $errors = validate_location($pdo, $data, 1); // kendi id'siyle güncelleme
        $this->assertNotContains('Aynı ad ve türde bir kayıt zaten var.', $errors);
    }

    public function testValidateLocationAcceptsWellFormedData(): void
    {
        $pdo = $this->sqlitePdo();
        $data = collect_location([
            'name' => 'Yeni Bina', 'type' => 'Bina',
            'latitude' => '37.05', 'longitude' => '37.38',
            'manager_email' => 'yonetici@gaziantep.edu.tr',
        ]);
        $this->assertSame([], validate_location($pdo, $data, 0));
    }

    // ---- would_create_parent_cycle -------------------------------------------

    public function testWouldCreateParentCycleFalseForNewRecordWithoutParent(): void
    {
        $pdo = $this->sqlitePdo();
        $this->assertFalse(would_create_parent_cycle($pdo, 0, null));
    }

    public function testWouldCreateParentCycleTrueWhenParentIsSelf(): void
    {
        $pdo = $this->sqlitePdo();
        $this->assertTrue(would_create_parent_cycle($pdo, 5, 5));
    }

    public function testWouldCreateParentCycleTrueForIndirectLoop(): void
    {
        $pdo = $this->sqlitePdo();
        // 1 -> parent 2 -> parent 3; 3'ü 1'in altına taşımak döngü oluşturur.
        $pdo->exec("INSERT INTO campus_locations (id, parent_id, name, type) VALUES
            (1, NULL, 'A', 'Bina'), (2, 1, 'B', 'Bina'), (3, 2, 'C', 'Bina')");
        $this->assertTrue(would_create_parent_cycle($pdo, 1, 3));
    }

    public function testWouldCreateParentCycleFalseForValidReparenting(): void
    {
        $pdo = $this->sqlitePdo();
        $pdo->exec("INSERT INTO campus_locations (id, parent_id, name, type) VALUES
            (1, NULL, 'A', 'Bina'), (2, NULL, 'B', 'Bina')");
        $this->assertFalse(would_create_parent_cycle($pdo, 1, 2));
    }

    // ---- CSV: başlık normalizasyonu, boş satır, satır eşleme -----------------

    public function testNormalizeCsvHeaderStripsBomLowercasesAndReplacesSeparators(): void
    {
        $this->assertSame('parent_id', normalize_csv_header("\xEF\xBB\xBFParent-ID"));
        $this->assertSame('image_url', normalize_csv_header('Image Url'));
    }

    public function testNormalizeCsvHeaderStripsUnexpectedSymbols(): void
    {
        $this->assertSame('name', normalize_csv_header('Na@me!'));
    }

    public function testCsvRowIsBlankTrueOnlyWhenAllCellsEmpty(): void
    {
        $this->assertTrue(csv_row_is_blank(['', '  ', '']));
        $this->assertFalse(csv_row_is_blank(['', 'x', '']));
    }

    public function testCsvAssocRowMapsHeadersToValuesAndSkipsBlankHeader(): void
    {
        $out = csv_assoc_row(['name', '', 'category'], ['Kütüphane', 'ignored', 'Bina']);
        $this->assertSame(['name' => 'Kütüphane', 'category' => 'Bina'], $out);
    }

    public function testCsvValueFallsBackToExistingWhenColumnAbsent(): void
    {
        $this->assertSame('eski-değer', csv_value([], 'name', ['name' => 'eski-değer']));
        $this->assertSame('yeni', csv_value(['name' => 'yeni'], 'name', ['name' => 'eski-değer']));
    }

    public function testCsvColumnsIncludeEnglishNameAndDescription(): void
    {
        $this->assertContains('name_en', CSV_LOCATION_COLUMNS);
        $this->assertContains('description_en', CSV_LOCATION_COLUMNS);
    }

    public function testCsvLocationPostPreservesEnglishFieldsWhenLegacyCsvOmitsThem(): void
    {
        $post = csv_location_post(
            ['name' => 'Yeni Ad', 'category' => 'Bina'],
            ['name_en' => 'Old English Name', 'description_en' => 'Old English Description']
        );

        $this->assertSame('Old English Name', $post['name_en']);
        $this->assertSame('Old English Description', $post['description_en']);
    }

    public function testCsvLocationPostAcceptsCategoryOrLegacyTypeColumn(): void
    {
        $viaCategory = csv_location_post(['name' => 'X', 'category' => 'Bina']);
        $this->assertSame('Bina', $viaCategory['type']);

        $viaType = csv_location_post(['name' => 'X', 'type' => 'Kafe']);
        $this->assertSame('Kafe', $viaType['type']);
    }

    // ---- import_locations_csv: erken-çıkış (fatal) dalları -------------------

    public function testImportCsvFailsFastOnUploadError(): void
    {
        $pdo = $this->sqlitePdo();
        $result = import_locations_csv($pdo, ['error' => UPLOAD_ERR_PARTIAL]);
        $this->assertSame('CSV dosyası yüklenemedi.', $result['fatal']);
    }

    public function testImportCsvFailsFastOnOversizedFile(): void
    {
        $pdo = $this->sqlitePdo();
        $result = import_locations_csv($pdo, ['error' => UPLOAD_ERR_OK, 'size' => 999_999_999, 'name' => 'x.csv']);
        $this->assertStringContainsString('2 MB', $result['fatal']);
    }

    public function testImportCsvFailsFastOnNonCsvExtension(): void
    {
        $pdo = $this->sqlitePdo();
        $result = import_locations_csv($pdo, ['error' => UPLOAD_ERR_OK, 'size' => 100, 'name' => 'konumlar.xlsx']);
        $this->assertSame('Yalnızca .csv uzantılı dosyalar kabul edilir.', $result['fatal']);
    }
}

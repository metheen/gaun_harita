<?php
declare(strict_types=1);

/**
 * Konum yönetimi (bordo panel) için saf mantık: veri toplama/doğrulama,
 * görsel/CSV içe-dışa aktarım yardımcıları. Bu dosyanın oturum/CSRF/
 * dispatcher gibi yan etkisi YOKTUR — bu yüzden testlerden doğrudan
 * require edilebilir (bkz. tests/php/). locations.php bunu require eder;
 * asıl istek işleme (auth, dispatch) orada kalır.
 */

const CSV_MAX_UPLOAD_BYTES = 2097152; // 2 MB
const CSV_LOCATION_COLUMNS = [
    'id',
    'parent_id',
    'name',
    'name_en',
    'category',
    'latitude',
    'longitude',
    'description',
    'description_en',
    'link',
    'route',
    'address',
    'phone',
    'facilities',
    'capacity',
    'rooms',
    'amenities',
    'opening_hours',
    'manager_name',
    'manager_email',
    'image_url',
    'rating',
    'color',
    'active',
    'sirala',
];

/** Konum yönetimi listesinde/dashboard'da kullanılan "eksik veri" filtreleri. */
const LOCATION_DATA_QUALITY_FILTERS = ['missing_coords', 'missing_en', 'missing_image', 'missing_link', 'inactive'];
const LOCATION_IMAGE_MAX_UPLOAD_BYTES = 4194304; // 4 MB
const LOCATION_IMAGE_MAX_PIXELS = 20000000; // 20 MP
const LOCATION_DEFAULT_IMAGE = 'uploads/Gaziantep-universitesi-logo.jpg';
const LOCATION_IMAGE_UPLOAD_DIR = __DIR__ . '/../public/uploads/locations';
const LOCATION_IMAGE_PUBLIC_PREFIX = 'uploads/locations/';
const LOCATION_IMAGE_MIME_EXTENSIONS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/** POST verisinden konum alanlarını normalize edilmiş biçimde toplar. */
function collect_location(array $post): array
{
    $int = static function ($v): ?int {
        $raw = trim((string) $v);
        return $raw === '' || !preg_match('/^-?\d+$/', $raw) ? null : (int) $raw;
    };
    $float = static function ($v): ?float {
        $raw = trim((string) $v);
        return $raw === '' || !is_numeric($raw) ? null : (float) $raw;
    };
    $str = static fn ($v) => (trim((string) $v) === '') ? null : trim((string) $v);

    return [
        'parent_id'     => $int($post['parent_id'] ?? null),
        'name'          => trim((string) ($post['name'] ?? '')),
        'name_en'       => $str($post['name_en'] ?? null),
        'type'          => trim((string) ($post['type'] ?? '')),
        'latitude'      => $float($post['latitude'] ?? null),
        'longitude'     => $float($post['longitude'] ?? null),
        'description'   => $str($post['description'] ?? null),
        'description_en' => $str($post['description_en'] ?? null),
        'link'          => $str($post['link'] ?? null),
        'route'         => $str($post['route'] ?? null),
        'address'       => $str($post['address'] ?? null),
        'phone'         => $str($post['phone'] ?? null),
        'facilities'    => $str($post['facilities'] ?? null),
        'capacity'      => $int($post['capacity'] ?? null),
        'rooms'         => $int($post['rooms'] ?? null),
        'amenities'     => $str($post['amenities'] ?? null),
        'opening_hours' => $str($post['opening_hours'] ?? null),
        'manager_name'  => $str($post['manager_name'] ?? null),
        'manager_email' => $str($post['manager_email'] ?? null),
        'image_url'     => $str($post['image_url'] ?? null),
        'rating'        => $float($post['rating'] ?? null),
        'color'         => $str($post['color'] ?? null) ?? 'red',
        // HTML checkbox kuralı: işaretliyse gönderilir ('1'), değilse
        // $post'ta hiç yer almaz. CSV import'tan gelen değerler (csv_location_post())
        // '0'/'1' string'i olarak zaten normalize edilmiş gelir.
        'active'        => (isset($post['active']) && $post['active'] !== '' && $post['active'] !== '0') ? 1 : 0,
    ];
}

function is_valid_http_url(?string $value): bool
{
    return $value === null
        || ((bool) filter_var($value, FILTER_VALIDATE_URL)
            && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true));
}

function is_valid_image_reference(?string $value): bool
{
    if ($value === null) {
        return true;
    }
    if (is_valid_http_url($value)) {
        return true;
    }

    $path = ltrim(trim($value), '/');
    if ($path === LOCATION_DEFAULT_IMAGE) {
        return true;
    }
    if ($path === '' || str_contains($path, '..') || !str_starts_with($path, LOCATION_IMAGE_PUBLIC_PREFIX)) {
        return false;
    }
    return (bool) preg_match('/\.(jpe?g|png|webp)$/i', $path);
}

function uploaded_location_image_path(?array $file): array
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => 'Görsel dosyası yüklenemedi.'];
    }
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > LOCATION_IMAGE_MAX_UPLOAD_BYTES) {
        return ['path' => null, 'error' => 'Görsel dosyası boş olmamalı ve 4 MB sınırını aşmamalıdır.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['path' => null, 'error' => 'Yüklenen görsel doğrulanamadı.'];
    }

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: null;
    }
    if ($mime === null && function_exists('mime_content_type')) {
        $mime = mime_content_type($tmp) ?: null;
    }
    $extension = is_string($mime) ? (LOCATION_IMAGE_MIME_EXTENSIONS[$mime] ?? null) : null;
    if ($extension === null) {
        return ['path' => null, 'error' => 'Yalnızca jpg, jpeg, png veya webp görseller yüklenebilir.'];
    }

    $imageInfo = @getimagesize($tmp);
    if ($imageInfo === false) {
        return ['path' => null, 'error' => 'Yüklenen dosya geçerli bir görsel değil.'];
    }
    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width <= 0 || $height <= 0 || ($width * $height) > LOCATION_IMAGE_MAX_PIXELS) {
        return ['path' => null, 'error' => 'Görsel boyutları çok büyük. En fazla 20 megapiksel yüklenebilir.'];
    }

    if (!is_dir(LOCATION_IMAGE_UPLOAD_DIR) && !mkdir(LOCATION_IMAGE_UPLOAD_DIR, 0775, true)) {
        return ['path' => null, 'error' => 'Görsel yükleme klasörü oluşturulamadı.'];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = LOCATION_IMAGE_UPLOAD_DIR . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        return ['path' => null, 'error' => 'Görsel dosyası kaydedilemedi.'];
    }
    @chmod($target, 0644);

    return ['path' => LOCATION_IMAGE_PUBLIC_PREFIX . $filename, 'error' => null];
}

function would_create_parent_cycle(PDO $pdo, int $id, ?int $parentId): bool
{
    if ($id <= 0 || $parentId === null) {
        return false;
    }
    if ($parentId === $id) {
        return true;
    }

    $seen = [];
    $current = $parentId;
    $stmt = $pdo->prepare('SELECT parent_id FROM campus_locations WHERE id = ?');

    while ($current !== null) {
        if ($current === $id || isset($seen[$current])) {
            return true;
        }
        $seen[$current] = true;
        $stmt->execute([$current]);
        $parent = $stmt->fetchColumn();
        $current = $parent === false || $parent === null ? null : (int) $parent;
    }

    return false;
}

function validate_location(PDO $pdo, array $data, int $id): array
{
    $errors = [];

    if ($data['name'] === '') {
        $errors[] = 'Ad alanı zorunludur.';
    }
    if ($data['type'] === '') {
        $errors[] = 'Tür alanı zorunludur.';
    }
    if (($data['latitude'] === null) !== ($data['longitude'] === null)) {
        $errors[] = 'Latitude ve longitude birlikte girilmelidir.';
    }
    if ($data['latitude'] !== null && ($data['latitude'] < -90 || $data['latitude'] > 90)) {
        $errors[] = 'Latitude -90 ile 90 arasında olmalıdır.';
    }
    if ($data['longitude'] !== null && ($data['longitude'] < -180 || $data['longitude'] > 180)) {
        $errors[] = 'Longitude -180 ile 180 arasında olmalıdır.';
    }
    if ($data['rating'] !== null && ($data['rating'] < 0 || $data['rating'] > 5)) {
        $errors[] = 'Puan 0 ile 5 arasında olmalıdır.';
    }
    if ($data['capacity'] !== null && $data['capacity'] < 0) {
        $errors[] = 'Kapasite negatif olamaz.';
    }
    if ($data['rooms'] !== null && $data['rooms'] < 0) {
        $errors[] = 'Oda sayısı negatif olamaz.';
    }
    if ($data['parent_id'] !== null) {
        if ($data['parent_id'] <= 0) {
            $errors[] = 'Üst kategori geçerli bir kayıt olmalıdır.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM campus_locations WHERE id = ? LIMIT 1');
            $stmt->execute([$data['parent_id']]);
            if ($stmt->fetchColumn() === false) {
                $errors[] = 'Üst kategori bulunamadı.';
            }
        }
    }
    foreach (['link' => 'Link', 'route' => 'Rota'] as $field => $label) {
        if (!is_valid_http_url($data[$field])) {
            $errors[] = "{$label} geçerli bir http/https adresi olmalıdır.";
        }
    }
    if (!is_valid_image_reference($data['image_url'])) {
        $errors[] = 'Görsel URL geçerli bir http/https adresi veya uploads/locations altında bir görsel yolu olmalıdır.';
    }
    if ($data['manager_email'] !== null && !filter_var($data['manager_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Yönetici e-posta adresi geçerli değil.';
    }
    if (would_create_parent_cycle($pdo, $id, $data['parent_id'])) {
        $errors[] = 'Üst kategori seçimi döngü oluşturuyor.';
    }

    if ($data['name'] !== '' && $data['type'] !== '') {
        $stmt = $pdo->prepare('SELECT id FROM campus_locations WHERE name = ? AND type = ? AND id <> ? LIMIT 1');
        $stmt->execute([$data['name'], $data['type'], $id]);
        if ($stmt->fetchColumn()) {
            $errors[] = 'Aynı ad ve türde bir kayıt zaten var.';
        }
    }

    return $errors;
}

function save_location_record(PDO $pdo, array $data, int $id): int
{
    if ($id > 0) {
        $cols = array_map(static fn ($c) => "$c = :$c", array_keys($data));
        $sql  = 'UPDATE campus_locations SET ' . implode(', ', $cols) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data + ['id' => $id]);
        return $id;
    }

    $keys = array_keys($data);
    $sql  = 'INSERT INTO campus_locations (' . implode(', ', $keys) . ') '
          . 'VALUES (:' . implode(', :', $keys) . ')';
    $pdo->prepare($sql)->execute($data);

    $newId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE campus_locations SET sirala = ? WHERE id = ?')->execute([$newId, $newId]);
    return $newId;
}

function find_location(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM campus_locations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * "Eksik veri" filtresi için SQL WHERE parçası. Konum listesi (locations.php)
 * ve dashboard sayaçları (location_data_quality_counts()) AYNI fonksiyonu
 * kullanır — koşul iki yerde çoğaltılmaz. Bilinmeyen filtre için null döner
 * (çağıran taraf filtreyi yok sayar, hata vermez).
 */
function location_data_quality_conditions(string $filter): ?string
{
    return match ($filter) {
        'missing_coords' => "(type <> 'Kategori' AND (latitude IS NULL OR longitude IS NULL) AND (link IS NULL OR link = ''))",
        'missing_en' => "(name_en IS NULL OR name_en = '')",
        'missing_image' => "(type <> 'Kategori' AND (image_url IS NULL OR image_url = ''))",
        'missing_link' => "(type <> 'Kategori' AND (link IS NULL OR link = '') AND (latitude IS NULL OR longitude IS NULL))",
        'inactive' => 'active = 0',
        default => null,
    };
}

/**
 * Bir kaydın, mevcut varsayılan sıralamada (sirala ASC, id ASC) hangi
 * sayfada göründüğünü hesaplar. Kayıt oluşturma/güncelleme sonrası admin'i
 * DOĞRU sayfaya yönlendirmek için kullanılır — aksi halde sayfalama (50/sayfa)
 * eklendiğinden beri yeni bir kayıt sirala değeri (varsayılan: kendi id'si)
 * eski/geniş aralıklı sirala verisi arasında herhangi bir sayfaya düşebilir
 * ve admin az önce eklediği kaydı hiç GÖRMEYEBİLİR.
 */
function location_page_for_id(PDO $pdo, int $id, int $perPage): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM campus_locations c
         JOIN (SELECT sirala, id FROM campus_locations WHERE id = ?) t
           ON (c.sirala < t.sirala) OR (c.sirala = t.sirala AND c.id <= t.id)'
    );
    $stmt->execute([$id]);
    $position = (int) $stmt->fetchColumn();
    return $position > 0 ? (int) ceil($position / $perPage) : 1;
}

/** Dashboard "Uyarılar" paneli için her eksik-veri filtresinin kaç kayda uyduğu. */
function location_data_quality_counts(PDO $pdo): array
{
    $counts = [];
    foreach (LOCATION_DATA_QUALITY_FILTERS as $filter) {
        $condition = location_data_quality_conditions($filter);
        if ($condition === null) {
            continue;
        }
        $stmt = $pdo->query("SELECT COUNT(*) FROM campus_locations WHERE {$condition}");
        $counts[$filter] = (int) $stmt->fetchColumn();
    }
    return $counts;
}

/** Seçili kayıtların üst kategorisini toplu değiştirir. Etkilenen satır sayısını döner. */
function bulk_update_parent(PDO $pdo, array $ids, ?int $parentId): int
{
    $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    if (!$ids) {
        return 0;
    }
    if ($parentId !== null) {
        $ids = array_values(array_filter(
            $ids,
            static fn (int $id): bool => !would_create_parent_cycle($pdo, $id, $parentId)
        ));
        if (!$ids) {
            return 0;
        }
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE campus_locations SET parent_id = ? WHERE id IN ({$placeholders})");
    $stmt->execute([$parentId, ...$ids]);
    return $stmt->rowCount();
}

/** Seçili kayıtları toplu aktif/pasif yapar. Etkilenen satır sayısını döner. */
function bulk_update_active(PDO $pdo, array $ids, bool $active): int
{
    $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    if (!$ids) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE campus_locations SET active = ? WHERE id IN ({$placeholders})");
    $stmt->execute([$active ? 1 : 0, ...$ids]);
    return $stmt->rowCount();
}

/**
 * Bir konumun görseli silinirken/değiştirilirken artık kullanılmayan
 * KENDİ yüklediğimiz dosyayı diskten kaldırır. Dış URL'lere (http/https)
 * ASLA dokunmaz — yalnızca uploads/locations/ altındaki, bizim
 * uploaded_location_image_path() ile ürettiğimiz dosyalar silinir.
 */
function location_cleanup_orphaned_image(?string $oldImageUrl, ?string $newImageUrl): void
{
    if ($oldImageUrl === null || $oldImageUrl === $newImageUrl) {
        return;
    }
    if (!str_starts_with($oldImageUrl, LOCATION_IMAGE_PUBLIC_PREFIX)) {
        return; // dış URL veya beklenmeyen biçim — dokunma
    }
    $filename = basename($oldImageUrl);
    if ($filename === '' || str_contains($filename, '..') || str_contains($filename, '/')) {
        return;
    }
    $path = LOCATION_IMAGE_UPLOAD_DIR . '/' . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

function export_locations_csv(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT id, parent_id, name, name_en, type AS category, latitude, longitude, description, description_en, link, route, address, phone, '
        . 'facilities, capacity, rooms, amenities, opening_hours, manager_name, manager_email, image_url, rating, color, active, sirala '
        . 'FROM campus_locations ORDER BY sirala ASC, id ASC'
    );

    $filename = 'gaun-konumlar-' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // Excel ve Türkçe karakterler için UTF-8 BOM.
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fputcsv($out, CSV_LOCATION_COLUMNS);
    while ($row = $stmt->fetch()) {
        $line = [];
        foreach (CSV_LOCATION_COLUMNS as $column) {
            $line[] = $row[$column] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

function normalize_csv_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = str_replace([' ', '-'], '_', $value);
    return preg_replace('/[^a-z0-9_]/', '', $value) ?? $value;
}

function csv_row_is_blank(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return false;
        }
    }
    return true;
}

function csv_assoc_row(array $headers, array $row): array
{
    $out = [];
    foreach ($headers as $i => $header) {
        if ($header === '') {
            continue;
        }
        $out[$header] = isset($row[$i]) ? trim((string) $row[$i]) : '';
    }
    return $out;
}

function csv_value(array $row, string $field, ?array $existing = null): mixed
{
    if (array_key_exists($field, $row)) {
        return $row[$field];
    }
    return $existing[$field] ?? '';
}

function csv_location_post(array $row, ?array $existing = null): array
{
    $category = array_key_exists('category', $row)
        ? $row['category']
        : (array_key_exists('type', $row) ? $row['type'] : ($existing['type'] ?? ''));

    return [
        'parent_id'     => csv_value($row, 'parent_id', $existing),
        'name'          => csv_value($row, 'name', $existing),
        'name_en'       => csv_value($row, 'name_en', $existing),
        'type'          => $category,
        'latitude'      => csv_value($row, 'latitude', $existing),
        'longitude'     => csv_value($row, 'longitude', $existing),
        'description'   => csv_value($row, 'description', $existing),
        'description_en' => csv_value($row, 'description_en', $existing),
        'link'          => csv_value($row, 'link', $existing),
        'route'         => csv_value($row, 'route', $existing),
        'address'       => csv_value($row, 'address', $existing),
        'phone'         => csv_value($row, 'phone', $existing),
        'facilities'    => csv_value($row, 'facilities', $existing),
        'capacity'      => csv_value($row, 'capacity', $existing),
        'rooms'         => csv_value($row, 'rooms', $existing),
        'amenities'     => csv_value($row, 'amenities', $existing),
        'opening_hours' => csv_value($row, 'opening_hours', $existing),
        'manager_name'  => csv_value($row, 'manager_name', $existing),
        'manager_email' => csv_value($row, 'manager_email', $existing),
        'image_url'     => csv_value($row, 'image_url', $existing),
        'rating'        => csv_value($row, 'rating', $existing),
        'color'         => csv_value($row, 'color', $existing),
        // csv_value()'nun genel '' varsayılanı burada YANLIŞ olurdu (yeni
        // satırı yanlışlıkla pasif yapardı): sütun yoksa var olan kaydın
        // durumu korunur, o da yoksa (yepyeni satır) '1' (aktif) varsayılır.
        'active'        => array_key_exists('active', $row) ? $row['active'] : ($existing['active'] ?? '1'),
    ];
}

function import_locations_csv(PDO $pdo, array $file): array
{
    $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'last_id' => null];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $result + ['fatal' => 'CSV dosyası yüklenemedi.'];
    }
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > CSV_MAX_UPLOAD_BYTES) {
        return $result + ['fatal' => 'CSV dosyası boş olmamalı ve 2 MB sınırını aşmamalıdır.'];
    }

    $name = (string) ($file['name'] ?? '');
    if (mb_strtolower(pathinfo($name, PATHINFO_EXTENSION), 'UTF-8') !== 'csv') {
        return $result + ['fatal' => 'Yalnızca .csv uzantılı dosyalar kabul edilir.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return $result + ['fatal' => 'Yüklenen dosya doğrulanamadı.'];
    }
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmp);
        $allowedMimes = ['text/csv', 'text/plain', 'text/x-csv', 'application/csv', 'application/vnd.ms-excel'];
        if (is_string($mime) && !in_array($mime, $allowedMimes, true)) {
            return $result + ['fatal' => 'Dosya tipi CSV olarak doğrulanamadı.'];
        }
    }

    $fh = fopen($tmp, 'rb');
    if ($fh === false) {
        return $result + ['fatal' => 'CSV dosyası okunamadı.'];
    }

    $sample = fgets($fh) ?: '';
    rewind($fh);
    $delimiter = substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
    $headers = fgetcsv($fh, 0, $delimiter);
    if ($headers === false) {
        fclose($fh);
        return $result + ['fatal' => 'CSV başlık satırı bulunamadı.'];
    }

    $headers = array_map(static fn ($h) => normalize_csv_header((string) $h), $headers);
    if (!in_array('name', $headers, true) || (!in_array('category', $headers, true) && !in_array('type', $headers, true))) {
        fclose($fh);
        return $result + ['fatal' => 'CSV en az name ve category sütunlarını içermelidir.'];
    }

    $lineNo = 1;
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        $lineNo++;
        if (csv_row_is_blank($row)) {
            $result['skipped']++;
            continue;
        }

        $csv = csv_assoc_row($headers, $row);
        $rowErrors = [];
        $idRaw = trim((string) ($csv['id'] ?? ''));
        $id = 0;
        $existing = null;

        if ($idRaw !== '') {
            if (!preg_match('/^\d+$/', $idRaw)) {
                $rowErrors[] = 'id pozitif tam sayı olmalıdır';
            } else {
                $id = (int) $idRaw;
                $existing = find_location($pdo, $id);
                if ($existing === null) {
                    $rowErrors[] = "id {$id} bulunamadı";
                }
            }
        }

        $sirala = null;
        if (array_key_exists('sirala', $csv) && trim((string) $csv['sirala']) !== '') {
            if (!preg_match('/^-?\d+$/', (string) $csv['sirala'])) {
                $rowErrors[] = 'sirala tam sayı olmalıdır';
            } else {
                $sirala = (int) $csv['sirala'];
            }
        }

        $data = collect_location(csv_location_post($csv, $existing));
        $rowErrors = array_merge($rowErrors, validate_location($pdo, $data, $id));
        if ($rowErrors) {
            $result['errors'][] = "Satır {$lineNo}: " . implode(', ', $rowErrors);
            continue;
        }

        try {
            $savedId = save_location_record($pdo, $data, $id);
            if ($sirala !== null) {
                $pdo->prepare('UPDATE campus_locations SET sirala = ? WHERE id = ?')->execute([$sirala, $savedId]);
            }
            $id > 0 ? $result['updated']++ : $result['created']++;
            $result['last_id'] = $savedId;
        } catch (Throwable $e) {
            $result['errors'][] = "Satır {$lineNo}: veritabanı işlemi başarısız oldu";
        }
    }
    fclose($fh);

    return $result;
}

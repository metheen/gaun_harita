<?php
declare(strict_types=1);

/**
 * get_locations.php için saf mantık (tip normalize, düz metin, ağaç kurma).
 * DB/HTTP yan etkisi YOKTUR — testlerden doğrudan require edilebilir
 * (bkz. tests/php/GetLocationsTreeTest.php).
 */

require_once __DIR__ . '/gaun_enrich.php';

/** Tür değerini başlık biçimine normalize eder. */
function normalize_type(?string $type): string
{
    $type = trim((string) $type);
    if ($type === '') {
        return 'Diğer';
    }
    return mb_convert_case(mb_strtolower($type, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

/** Eski HTML açıklamaları güvenli düz metin özetine indirger. */
function plain_text(?string $value): string
{
    $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    $text = preg_replace("/\b(Web Sayfası|Web Sitesi|Yol Tarifi Al|Google Haritalar'?da)\b/iu", ' ', $text) ?? '';
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return trim($text);
}

/** Kayıt listesinden iç içe ağaç üretir. */
function build_tree(array $rows, array $gaunIndex, array $diningMenu, array $staffByLocation): array
{
    // Sayısal olarak yorumlanacak alanlar.
    $numericFields = ['capacity', 'rooms', 'rating'];
    // Doluysa çıktıya eklenecek isteğe bağlı alanlar.
    $optionalFields = [
        'name_en', 'description', 'description_en', 'link', 'route', 'address', 'phone',
        'facilities', 'capacity', 'rooms', 'amenities',
        'opening_hours', 'manager_name', 'manager_email',
        'image_url', 'rating', 'color',
    ];

    // Kayıtları üst kimliğe göre grupla ('root' = köke bağlı olanlar).
    $byParent = [];
    foreach ($rows as $row) {
        $key = $row['parent_id'] === null ? 'root' : (string) $row['parent_id'];
        $byParent[$key][] = $row;
    }

    $build = function (string $parentKey) use (&$build, $byParent, $numericFields, $optionalFields, $gaunIndex, $diningMenu, $staffByLocation): array {
        $items = [];
        foreach ($byParent[$parentKey] ?? [] as $row) {
            $item = [
                'id'   => (int) $row['id'],
                'name' => $row['name'],
                'type' => normalize_type($row['type']),
            ];

            if ($row['latitude'] !== null && $row['longitude'] !== null) {
                $item['coords'] = [(float) $row['latitude'], (float) $row['longitude']];
            }

            foreach ($optionalFields as $field) {
                $value = $row[$field] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $item[$field] = in_array($field, $numericFields, true) && is_numeric($value)
                    ? (float) $value
                    : $value;

                if ($field === 'description') {
                    $descriptionText = plain_text((string) $value);
                    if ($descriptionText !== '') {
                        $item['description_text'] = $descriptionText;
                    }
                } elseif ($field === 'description_en') {
                    $descriptionText = plain_text((string) $value);
                    if ($descriptionText !== '') {
                        $item['description_en_text'] = $descriptionText;
                    }
                }
            }

            // GAÜN resmi kaynak zenginleştirmesi (cache'ten; dış istek yok).
            // Yalnızca birebir isim/host eşleşmesinde eklenir; koordinatlara dokunulmaz.
            $gaun = gaun_match($gaunIndex, (string) $row['name'], $row['link'] ?? null);
            if ($gaun !== null) {
                $item['gaun'] = $gaun;
            }

            // Bugünkü yemek menüsü: yalnızca resmî yemekhane konumlarına eklenir
            // (cache'ten gelir; menü yoksa boş items ile fallback görünümü).
            if (gaun_is_dining_location((string) $row['name'], (string) $row['type'])) {
                $item['dining'] = $diningMenu;
            }

            if (isset($staffByLocation[(int) $row['id']])) {
                $item['staff'] = $staffByLocation[(int) $row['id']];
                $item['staff_count'] = count($staffByLocation[(int) $row['id']]);
            }

            $children = $build((string) $row['id']);
            if ($children) {
                $item['children'] = $children;
            }

            $items[] = $item;
        }
        return $items;
    };

    return $build('root');
}

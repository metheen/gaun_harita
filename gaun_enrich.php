<?php
declare(strict_types=1);

/**
 * GAÜN kaynak cache'i okuma + konumlarla GÜVENLİ eşleştirme yardımcıları.
 *
 * Public istekler sırasında ASLA dış ağa çıkılmaz; yalnızca
 * private/cache/gaun_sources.json okunur (yoksa/bozuksa null → zenginleştirme
 * sessizce atlanır). Cache scripts/sync_gaun_sources.php ile üretilir.
 *
 * Eşleştirme temkinlidir:
 *   - yalnızca birebir normalize-isim VEYA birebir website host eşleşmesi
 *   - koordinatlara asla dokunulmaz
 *   - emin olunmayan hiçbir veri konuma bağlanmaz
 */

const GAUN_CACHE_FILE = __DIR__ . '/private/cache/gaun_sources.json';

/** Cache'i okur; yok/bozuksa null (uygulama bozulmaz). */
function gaun_sources_load(): ?array
{
    if (!is_readable(GAUN_CACHE_FILE)) {
        return null;
    }
    $data = json_decode((string) file_get_contents(GAUN_CACHE_FILE), true);
    return is_array($data) ? $data : null;
}

/** Türkçe-duyarlı isim normalizasyonu (karşılaştırma anahtarı). */
function gaun_norm_name(string $name): string
{
    $s = str_replace(['İ', 'I'], ['i', 'ı'], trim($name));
    $s = mb_strtolower($s, 'UTF-8');
    return preg_replace('/\s+/u', ' ', $s) ?? $s;
}

function gaun_fold_key(string $name): string
{
    $s = gaun_norm_name($name);
    $s = strtr($s, [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        'â' => 'a', 'î' => 'i', 'û' => 'u',
    ]);
    $s = preg_replace('/\b(fakultesi|fakulte|bolumu|bolum|dairesi|daire|baskanligi|baskanlik|mudurlugu|mudurluk|yuksekokulu|yuksekokul|meslek|enstitusu|enstitu|anabilim dali)\b/u', ' ', $s) ?? $s;
    $s = str_replace('makina', 'makine', $s);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** URL'den küçük-harf host çıkarır ('' = yok). */
function gaun_host(?string $url): string
{
    $host = parse_url((string) $url, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

function gaun_token_overlap_score(string $a, string $b): int
{
    $ak = gaun_fold_key($a);
    $bk = gaun_fold_key($b);
    if ($ak === '' || $bk === '') {
        return 0;
    }
    if ($ak === $bk) {
        return 70;
    }
    if (str_contains($ak, $bk) || str_contains($bk, $ak)) {
        return 55;
    }
    $at = array_values(array_filter(explode(' ', $ak), fn ($t) => mb_strlen($t, 'UTF-8') >= 3));
    $bt = array_values(array_filter(explode(' ', $bk), fn ($t) => mb_strlen($t, 'UTF-8') >= 3));
    if (!$at || !$bt) {
        return 0;
    }
    $intersect = array_intersect(array_unique($at), array_unique($bt));
    $ratio = count($intersect) / max(1, min(count(array_unique($at)), count(array_unique($bt))));
    return $ratio >= 0.75 ? 45 : 0;
}

/**
 * Birim listesinden arama indeksi kurar.
 * Çakışan anahtarlar (aynı isim/host'ta birden çok birim) güvenli tarafta
 * kalınarak indeks dışı bırakılır.
 */
function gaun_build_index(?array $cache): array
{
    $byName = [];
    $byHost = [];
    $nameDup = [];
    $hostDup = [];

    foreach (($cache['units'] ?? []) as $unit) {
        if (!isset($unit['name'], $unit['website'])) {
            continue;
        }
        $nk = gaun_norm_name($unit['name']);
        if ($nk !== '') {
            if (isset($byName[$nk])) {
                $nameDup[$nk] = true;
            } else {
                $byName[$nk] = $unit;
            }
        }
        $hk = gaun_host($unit['website']);
        if ($hk !== '' && !str_starts_with($hk, 'www.')) {
            if (isset($byHost[$hk])) {
                $hostDup[$hk] = true;
            } else {
                $byHost[$hk] = $unit;
            }
        }
    }

    return [
        'by_name' => array_diff_key($byName, $nameDup),
        'by_host' => array_diff_key($byHost, $hostDup),
    ];
}

/**
 * Cache'ten bugünün yemek menüsünü güvenli/temiz yapıda döndürür.
 *
 * - Menü verisi bugüne ait değilse items boşaltılır (dünün menüsü
 *   "bugünkü" diye gösterilmez); aylık liste linki korunur.
 * - Yalnızca beklenen alanlar, tip zorlamalı olarak geçirilir
 *   (kaynak HTML'inden gelen metin frontend'de escape edilir).
 * - Cache yoksa/bozuksa items boş bir kabukla döner (fallback görünümü).
 */
function gaun_dining(?array $cache): array
{
    $meal = $cache['meal_menu'] ?? null;
    $defaultSource = 'https://www.gaziantep.edu.tr/yemek/';

    // "Bugün" Türkiye saatine göre (sunucu UTC olsa bile gece yarısı
    // penceresinde dünün menüsü "bugünkü" sayılmaz).
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');

    // Public JSON sözleşmesi: date, items, calories, source_url, updated_at.
    $out = [
        'date'        => $today,
        'items'       => [],
        'calories'    => null,
        'monthly_url' => $defaultSource,
        'source_url'  => $defaultSource,
        'updated_at'  => null,
    ];

    if (!is_array($meal)) {
        return $out;
    }

    $out['monthly_url'] = is_string($meal['monthly_url'] ?? null) ? $meal['monthly_url'] : $defaultSource;
    $out['source_url']  = is_string($meal['source_url'] ?? null) ? $meal['source_url'] : $defaultSource;
    $out['updated_at']  = is_string($meal['fetched_at'] ?? null) ? $meal['fetched_at'] : null;

    // Bayat gün: eski menü ASLA gönderilmez; yalnız linkler/metaveri kalır,
    // items boş kalır → frontend sade fallback gösterir.
    if (($meal['date'] ?? null) !== $today) {
        return $out;
    }
    $out['date_label'] = is_string($meal['date_label'] ?? null) ? $meal['date_label'] : null;

    foreach (($meal['items'] ?? []) as $item) {
        // Yeni biçim: {name, kcal}; eski biçim (düz string) da tolere edilir.
        if (is_array($item) && isset($item['name'])) {
            $name = trim((string) $item['name']);
            $kcal = is_numeric($item['kcal'] ?? null) ? (int) $item['kcal'] : null;
        } elseif (is_string($item)) {
            $name = trim($item);
            $kcal = null;
        } else {
            continue;
        }
        if ($name !== '' && mb_strlen($name, 'UTF-8') <= 100) {
            $out['items'][] = ['name' => $name, 'kcal' => $kcal];
        }
        if (count($out['items']) >= 12) {
            break;
        }
    }
    $out['calories'] = is_numeric($meal['total_kcal'] ?? null) ? (int) $meal['total_kcal'] : null;

    return $out;
}

/**
 * Public tarafta gösterilebilir URL mi? Yalnız http(s) ve izinli GAÜN
 * host'ları (gaziantep.edu.tr / gantep.edu.tr ve alt alanları).
 */
function gaun_public_url(?string $url): ?string
{
    $url = trim((string) $url);
    $p = parse_url($url);
    if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
        return null;
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return null;
    }
    if (isset($p['port']) && !in_array((int) $p['port'], [80, 443], true)) {
        return null;
    }
    $host = strtolower($p['host'] ?? '');
    foreach (['gaziantep.edu.tr', 'gantep.edu.tr'] as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
            return $url;
        }
    }
    return null;
}

/**
 * Cache'ten "Bugün Kampüste" bülteni: duyuru/haber/(varsa etkinlik)/bağlantı.
 *
 * Konumlara BAĞLANMAZ; genel listedir. Her liste en fazla 5 kayıt; yalnız
 * güvenli alanlar (title|label, url, date?, source_url) geçirilir ve URL'ler
 * izinli GAÜN host'larıyla sınırlanır. Cache yok/bozuksa boş listeler döner.
 */
function gaun_bulletin(?array $cache): array
{
    $pick = function (array $rows, string $textKey) {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $text = trim((string) ($row[$textKey] ?? ''));
            $url  = gaun_public_url($row['url'] ?? null);
            if ($text === '' || mb_strlen($text, 'UTF-8') > 200 || $url === null) {
                continue;
            }
            $item = [$textKey => $text, 'url' => $url];
            if (is_string($row['date'] ?? null) && $row['date'] !== '') {
                $item['date'] = $row['date'];
            }
            $src = gaun_public_url($row['source_url'] ?? null);
            if ($src !== null) {
                $item['source_url'] = $src;
            }
            $out[] = $item;
            if (count($out) >= 5) {
                break;
            }
        }
        return $out;
    };

    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');

    return [
        'today'           => $today,
        'generated_at'    => is_string($cache['generated_at'] ?? null) ? $cache['generated_at'] : null,
        'announcements'   => $pick($cache['announcements'] ?? [], 'title'),
        'news'            => $pick($cache['news'] ?? [], 'title'),
        'important_links' => $pick($cache['important_links'] ?? [], 'label'),
        'events'          => gaun_events($cache),
    ];
}

/**
 * Cache'ten yaklaşan etkinlikleri güvenli/temiz yapıda döndürür.
 *
 * - Yalnızca bugün (Türkiye saati) ve sonrası etkinlikler döner; cache bir
 *   gün bayatlamışsa geçmiş etkinlik sızmaz.
 * - Tarihe göre artan sıralanır (en yakın önce), en fazla 5 kayıt.
 * - URL/image_url yalnızca izinli GAÜN host'larıysa geçirilir.
 * - Cache yok/bozuksa boş dizi döner (panelde sade "kayıt yok" görünümü).
 */
function gaun_events(?array $cache): array
{
    $rows = $cache['events'] ?? [];
    if (!is_array($rows)) {
        return [];
    }

    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $title = trim((string) ($row['title'] ?? ''));
        $date  = is_string($row['date'] ?? null) ? $row['date'] : '';
        $url   = gaun_public_url($row['url'] ?? null);
        if ($title === '' || mb_strlen($title, 'UTF-8') > 200 || $url === null) {
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $today) {
            continue; // tarihsiz veya geçmiş etkinlik gösterilmez
        }

        $item = ['title' => $title, 'date' => $date, 'url' => $url];
        if (is_string($row['date_label'] ?? null) && $row['date_label'] !== '') {
            $item['date_label'] = $row['date_label'];
        }
        if (is_string($row['time'] ?? null) && $row['time'] !== '') {
            $item['time'] = $row['time'];
        }
        if (is_string($row['location'] ?? null) && $row['location'] !== '') {
            $item['location'] = mb_strlen($row['location'], 'UTF-8') > 150
                ? mb_substr($row['location'], 0, 150, 'UTF-8') : $row['location'];
        }
        if (is_string($row['description'] ?? null) && $row['description'] !== '') {
            $item['description'] = mb_strlen($row['description'], 'UTF-8') > 500
                ? mb_substr($row['description'], 0, 500, 'UTF-8') . '…' : $row['description'];
        }
        $img = gaun_public_url($row['image_url'] ?? null);
        if ($img !== null) {
            $item['image_url'] = $img;
        }
        $src = gaun_public_url($row['source_url'] ?? null);
        if ($src !== null) {
            $item['source_url'] = $src;
        }

        $out[] = $item;
    }

    usort($out, fn ($a, $b) => $a['date'] <=> $b['date']);

    return array_slice($out, 0, 5);
}

/**
 * Konum resmî yemekhane mi? (kafe/kafeterya hariç, yalnız "yemekhane")
 */
function gaun_is_dining_location(string $name, string $type): bool
{
    if (gaun_norm_name($type) === 'kategori') {
        return false;
    }
    return str_contains(gaun_norm_name($name), 'yemekhane')
        || str_contains(gaun_norm_name($type), 'yemekhane');
}

function gaun_staff_title_rank(string $title): int
{
    $t = gaun_fold_key($title);
    return match (true) {
        str_contains($t, 'profesor') => 10,
        str_contains($t, 'docent') => 20,
        str_contains($t, 'doktor ogretim') => 30,
        str_contains($t, 'ogretim gorevlisi') => 40,
        str_contains($t, 'arastirma gorevlisi') => 50,
        str_contains($t, 'baskan') => 15,
        str_contains($t, 'mudur') => 25,
        default => 90,
    };
}

function gaun_public_staff(array $staff): ?array
{
    $name = trim((string) ($staff['name'] ?? ''));
    $title = trim((string) ($staff['title'] ?? ''));
    $source = gaun_public_url($staff['source_url'] ?? null);
    if ($name === '' || $title === '' || $source === null) {
        return null;
    }
    $out = [
        'name'        => $name,
        'title'       => $title,
        'unit'        => trim((string) ($staff['unit_name'] ?? '')),
        'source_url'  => $source,
        'verified_at' => is_string($staff['verified_at'] ?? null) ? $staff['verified_at'] : null,
    ];
    $department = trim((string) ($staff['department_name'] ?? ''));
    if ($department !== '') {
        $out['department'] = $department;
    }
    return $out;
}

function gaun_staff_location_score(array $staff, array $row): int
{
    if (($row['latitude'] ?? null) === null || ($row['longitude'] ?? null) === null) {
        return 0;
    }
    if (gaun_norm_name((string) ($row['type'] ?? '')) === 'kategori') {
        return 0;
    }

    $rowName = (string) ($row['name'] ?? '');
    $score = 0;

    $department = (string) ($staff['department_name'] ?? '');
    if ($department !== '') {
        $score = max($score, gaun_token_overlap_score($rowName, $department) + 20);
    }

    $unit = (string) ($staff['unit_name'] ?? '');
    if ($unit !== '') {
        $score = max($score, gaun_token_overlap_score($rowName, $unit) + 10);
    }

    $rowHost = gaun_host($row['link'] ?? null);
    $unitHost = gaun_host($staff['unit_website'] ?? null);
    $sourceHost = strtolower((string) ($staff['source_host'] ?? ''));
    if ($rowHost !== '' && ($rowHost === $unitHost || $rowHost === $sourceHost)) {
        $score = max($score, 85);
    }

    return $score >= 55 ? $score : 0;
}

/**
 * Cache'teki doğrulanmış personel kayıtlarını en olası kampüs konumuna bağlar.
 * Eşleşme yoksa kayıt public JSON'a dahil edilmez; koordinat uydurulmaz.
 */
function gaun_staff_by_location(?array $cache, array $rows): array
{
    $out = [];
    $seenPublic = [];
    foreach (($cache['staff'] ?? []) as $staff) {
        if (!is_array($staff)) {
            continue;
        }
        $public = gaun_public_staff($staff);
        if ($public === null) {
            continue;
        }
        $affiliationKey = gaun_fold_key((string) ($public['department'] ?? $public['unit'] ?? ''));
        $publicKey = gaun_fold_key((string) $public['name'] . '|' . (string) $public['title'] . '|' . $affiliationKey);
        if (isset($seenPublic[$publicKey])) {
            continue;
        }
        $seenPublic[$publicKey] = true;

        $bestId = null;
        $bestScore = 0;
        foreach ($rows as $row) {
            $score = gaun_staff_location_score($staff, $row);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = (int) $row['id'];
            }
        }
        if ($bestId === null) {
            continue;
        }
        $public['match_score'] = $bestScore;
        $out[$bestId][] = $public;
    }

    foreach ($out as &$staffRows) {
        usort($staffRows, static function ($a, $b) {
            return gaun_staff_title_rank((string) $a['title']) <=> gaun_staff_title_rank((string) $b['title'])
                ?: strcasecmp((string) $a['name'], (string) $b['name']);
        });
    }
    unset($staffRows);

    return $out;
}

/**
 * Bir konumu (name + link) resmi birimle eşleştirmeyi dener.
 * Dönüş: ['website','source_url','fetched_at','matched_by'] veya null.
 */
function gaun_match(array $index, string $name, ?string $link): ?array
{
    $unit = null;
    $by   = null;

    $nk = gaun_norm_name($name);
    if ($nk !== '' && isset($index['by_name'][$nk])) {
        $unit = $index['by_name'][$nk];
        $by   = 'name';
    }

    if ($unit === null) {
        $hk = gaun_host($link);
        if ($hk !== '' && isset($index['by_host'][$hk])) {
            $unit = $index['by_host'][$hk];
            $by   = 'website';
        }
    }

    if ($unit === null) {
        return null;
    }
    return [
        'website'    => $unit['website'],
        'source_url' => $unit['source_url'] ?? null,
        'fetched_at' => $unit['fetched_at'] ?? null,
        'matched_by' => $by,
    ];
}

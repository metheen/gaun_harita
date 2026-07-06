<?php
declare(strict_types=1);

/**
 * Site ayarları (dil, harita başlangıç görünümü, kurumsal bilgiler, PWA
 * metinleri). Depolamayı YENİDEN İCAT ETMEZ — admin_ops.php'nin zaten var
 * olan `app_settings` anahtar-değer tablosunu ve
 * admin_ops_setting()/admin_ops_set_setting() fonksiyonlarını kullanır.
 * Bu dosya yalnızca: (a) hangi anahtarların "site ayarı" sayıldığını,
 * varsayılan değerlerini ve doğrulama kurallarını tanımlar.
 */

require_once __DIR__ . '/admin_ops.php';

/** Anahtar => [varsayılan değer, doğrulayıcı callable veya null]. */
function settings_ops_schema(): array
{
    return [
        'default_language' => ['tr', static fn (string $v): bool => in_array($v, ['tr', 'en'], true)],
        'map_center_lat'    => ['37.0375', static fn (string $v): bool => is_numeric($v) && (float) $v >= -90 && (float) $v <= 90],
        'map_center_lng'    => ['37.3135', static fn (string $v): bool => is_numeric($v) && (float) $v >= -180 && (float) $v <= 180],
        'map_zoom'          => ['16', static fn (string $v): bool => ctype_digit($v) && (int) $v >= 1 && (int) $v <= 20],
        'site_title'        => ['GAÜN Kampüs Haritası', null],
        'site_description'  => ['Gaziantep Üniversitesi kampüs konumları interaktif haritası.', null],
        'contact_email'     => ['', static fn (string $v): bool => $v === '' || (bool) filter_var($v, FILTER_VALIDATE_EMAIL)],
        'contact_phone'     => ['', null],
        'pwa_short_name'    => ['GAÜN Harita', null],
        'pwa_description'   => ['GAÜN kampüs haritası ve yol bulma uygulaması.', null],
    ];
}

/**
 * Tüm site ayarlarını (varsayılanlar uygulanmış olarak) okur.
 * $ensure=true şema/seed INSERT'lerini tetikler (admin_ops_ensure_schema) —
 * bu, yalnızca yazma yetkisi olan bağlantıda güvenlidir. Public tarafta
 * 'read' bağlantısıyla çağrılırken $ensure=false verilmeli (bkz.
 * admin_ops_public_site_status()'un admin_ops_site_status($pdo, false)
 * kullanımıyla aynı ilke) — aksi halde salt-okunur DB kullanıcısı için
 * seed INSERT'i "access denied" ile patlar ve TÜM ayarlar sessizce
 * varsayılana düşer.
 */
function settings_ops_get_all(PDO $pdo, bool $ensure = true): array
{
    $schema = settings_ops_schema();
    $defaults = array_map(static fn (array $entry) => $entry[0], $schema);

    // Public ana sayfa her görüntülemede bu fonksiyonu çağırır — anahtar
    // başına ayrı SELECT (10 sorgu) yerine tümü TEK sorguyla okunur.
    try {
        if ($ensure) {
            admin_ops_ensure_schema($pdo);
        } elseif (!admin_ops_table_exists($pdo, ADMIN_SETTINGS_TABLE)) {
            return $defaults;
        }

        $keys = array_keys($schema);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare(
            "SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ({$placeholders})"
        );
        $stmt->execute($keys);

        $stored = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_string($row['setting_value'])) {
                $stored[$row['setting_key']] = $row['setting_value'];
            }
        }
        return array_merge($defaults, array_intersect_key($stored, $defaults));
    } catch (Throwable $e) {
        return $defaults;
    }
}

/**
 * Gönderilen değerleri doğrulayıp kaydeder. Dönüş: alan adı => hata mesajı
 * (boşsa hepsi geçerli demektir; geçersiz alanlar kaydedilmez, diğerleri
 * kaydedilir — bir alanın hatası diğerlerini engellemez).
 */
function settings_ops_update(PDO $pdo, array $values, string $updatedBy): array
{
    $errors = [];
    foreach (settings_ops_schema() as $key => [$default, $validator]) {
        if (!array_key_exists($key, $values)) {
            continue;
        }
        $value = trim((string) $values[$key]);
        if ($validator !== null && !$validator($value)) {
            $errors[$key] = "Geçersiz değer: {$key}";
            continue;
        }
        admin_ops_set_setting($pdo, $key, $value, $updatedBy);
    }
    return $errors;
}

/**
 * Public tarafa (window.GAUN_CONFIG) enjekte edilecek güvenli alt küme.
 * admin_ops_public_site_status() ile aynı "hata olursa varsayılana düş"
 * deseni — public sayfa ayar okuma hatasıyla asla çökmez.
 */
function settings_ops_public_config(): array
{
    $defaults = array_map(static fn (array $entry) => $entry[0], settings_ops_schema());
    try {
        if (!function_exists('db')) {
            require_once __DIR__ . '/db.php';
        }
        $values = settings_ops_get_all(db('read'), false);
        return [
            'defaultLanguage' => $values['default_language'],
            'mapCenter' => [(float) $values['map_center_lat'], (float) $values['map_center_lng']],
            'mapZoom' => (int) $values['map_zoom'],
            'siteTitle' => $values['site_title'],
            'siteDescription' => $values['site_description'],
            'contactEmail' => $values['contact_email'],
            'contactPhone' => $values['contact_phone'],
        ];
    } catch (Throwable $e) {
        return [
            'defaultLanguage' => $defaults['default_language'],
            'mapCenter' => [(float) $defaults['map_center_lat'], (float) $defaults['map_center_lng']],
            'mapZoom' => (int) $defaults['map_zoom'],
            'siteTitle' => $defaults['site_title'],
            'siteDescription' => $defaults['site_description'],
            'contactEmail' => $defaults['contact_email'],
            'contactPhone' => $defaults['contact_phone'],
        ];
    }
}

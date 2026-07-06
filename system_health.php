<?php
declare(strict_types=1);

/**
 * Dashboard için salt-okunur sistem sağlığı kontrolleri: DB bağlantısı,
 * GAÜN senkronizasyon cache durumu, OSRM (osrm-car/osrm-foot) erişilebilirliği.
 * Hiçbir kontrol admin işlemini TETİKLEMEZ (ör. sync başlatmaz) — yalnızca
 * mevcut durumu okur. Her fonksiyon kendi hatasını yutar; bir kontrolün
 * başarısız olması diğerlerini veya dashboard sayfasını etkilemez.
 */

require_once __DIR__ . '/gaun_enrich.php';

function system_health_db(PDO $pdo): array
{
    try {
        $pdo->query('SELECT 1');
        return ['ok' => true, 'message' => 'Bağlantı sağlıklı'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Veritabanına bağlanılamadı'];
    }
}

/** GAÜN senkronizasyon cache'inin son durumu (bkz. scripts/sync_gaun_sources.php). */
function system_health_sync(): array
{
    $cache = gaun_sources_load();
    if ($cache === null) {
        return [
            'ok' => false,
            'cache_exists' => false,
            'generated_at' => null,
            'sections' => [],
            'message' => 'Senkronizasyon cache\'i hiç oluşturulmamış',
        ];
    }

    $sections = is_array($cache['sections'] ?? null) ? $cache['sections'] : [];
    $staleCount = 0;
    $unavailableCount = 0;
    foreach ($sections as $section) {
        $status = (string) ($section['status'] ?? '');
        if (str_starts_with($status, 'stale')) {
            $staleCount++;
        } elseif ($status === 'unavailable') {
            $unavailableCount++;
        }
    }

    return [
        'ok' => $unavailableCount === 0,
        'cache_exists' => true,
        'generated_at' => $cache['generated_at'] ?? null,
        'sections' => $sections,
        'stale_count' => $staleCount,
        'unavailable_count' => $unavailableCount,
        'message' => $unavailableCount > 0
            ? "{$unavailableCount} bölüm hiç veri içermiyor"
            : ($staleCount > 0 ? "{$staleCount} bölüm eski cache'ten geliyor" : 'Tüm bölümler güncel'),
    ];
}

/** Bir OSRM base URL'sinin gerçekten yanıt verip vermediğini kısa bir timeout'la dener. */
function system_health_check_osrm_endpoint(?string $baseUrl, string $profile = 'driving'): array
{
    if ($baseUrl === null || $baseUrl === '') {
        return ['configured' => false, 'ok' => false, 'message' => 'Yapılandırılmamış (genel demo kullanılıyor olabilir)'];
    }

    $profile = preg_match('/^[A-Za-z0-9_-]{1,32}$/', $profile) ? $profile : 'driving';
    $ch = curl_init(rtrim($baseUrl, '/') . '/route/v1/' . rawurlencode($profile) . '/37.3135,37.0375;37.3140,37.0380?overview=false');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_NOBODY => false,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['configured' => true, 'ok' => false, 'message' => "Erişilemedi ({$error})"];
    }
    if ($status !== 200) {
        return ['configured' => true, 'ok' => false, 'message' => "HTTP {$status}"];
    }
    return ['configured' => true, 'ok' => true, 'message' => 'Yanıt veriyor'];
}

function system_health_osrm_base_url(): string
{
    $baseUrl = trim((string) (getenv('OSRM_BASE_URL') ?: 'https://router.project-osrm.org'));
    return $baseUrl !== '' ? $baseUrl : 'https://router.project-osrm.org';
}

function system_health_osrm_profile(string $key, string $default): string
{
    $profile = trim((string) (getenv($key) ?: $default));
    return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $profile) ? $profile : $default;
}

/**
 * Dashboard PHP container içinden çalışır; tarayıcı için yazılmış localhost
 * OSRM URL'leri container içinde aynı anlama gelmez. Yerel self-hosted URL
 * varsa Docker servis adını, URL boşsa haritanın kullandığı genel base URL'yi
 * kontrol eder. OSRM_*_HEALTH_URL verilirse her şeyin üstüne geçer.
 */
function system_health_osrm_endpoint(string $modeEnvKey, string $healthEnvKey, string $composeDefault): ?string
{
    $override = trim((string) (getenv($healthEnvKey) ?: ''));
    if ($override !== '') {
        return $override;
    }

    $modeBaseUrl = trim((string) (getenv($modeEnvKey) ?: ''));
    if ($modeBaseUrl === '') {
        return system_health_osrm_base_url();
    }

    $host = strtolower((string) (parse_url($modeBaseUrl, PHP_URL_HOST) ?: ''));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return $composeDefault;
    }

    return $modeBaseUrl;
}

/**
 * Sağlık kontrolü haritanın gerçek rota ayarını izler: moda özel URL boşsa
 * genel OSRM_BASE_URL'i, localhost moda özel URL varsa Docker Compose servis
 * adını, OSRM_*_HEALTH_URL verilirse doğrudan o override'ı kontrol eder.
 */
function system_health_osrm(): array
{
    $driveUrl = system_health_osrm_endpoint('OSRM_DRIVE_BASE_URL', 'OSRM_DRIVE_HEALTH_URL', 'http://osrm-car:5000');
    $walkUrl = system_health_osrm_endpoint('OSRM_WALK_BASE_URL', 'OSRM_WALK_HEALTH_URL', 'http://osrm-foot:5000');
    $drive = system_health_check_osrm_endpoint($driveUrl, system_health_osrm_profile('OSRM_DRIVE_PROFILE', 'driving'));
    $walk = system_health_check_osrm_endpoint($walkUrl, system_health_osrm_profile('OSRM_WALK_PROFILE', 'foot'));

    return [
        'drive' => $drive,
        'walk' => $walk,
        'mode' => ($driveUrl === system_health_osrm_base_url() && $walkUrl === system_health_osrm_base_url()) ? 'public' : 'custom',
        'ok' => (!$drive['configured'] || $drive['ok']) && (!$walk['configured'] || $walk['ok']),
    ];
}

function system_health_summary(PDO $pdo): array
{
    return [
        'db' => system_health_db($pdo),
        'sync' => system_health_sync(),
        'osrm' => system_health_osrm(),
    ];
}

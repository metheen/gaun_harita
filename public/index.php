<?php
declare(strict_types=1);

require_once __DIR__ . '/../security_headers.php';
security_headers_apply();

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../admin_ops.php';
require_once __DIR__ . '/../settings_ops.php';

$siteStatus = admin_ops_public_site_status();
if (!$siteStatus['enabled']) {
    http_response_code(503);
    header('Retry-After: 3600');
    echo admin_ops_maintenance_page($siteStatus);
    exit;
}

function gaun_load_env_file(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}

function gaun_env_string(string $key, string $default): string
{
    $value = trim((string) (getenv($key) ?: ''));
    return $value !== '' ? $value : $default;
}

/** Bir OSRM base URL'sini doğrular: http(s), host var, kimlik bilgisi yok. */
function gaun_valid_base_url(string $value): ?string
{
    $value = rtrim($value, '/');
    $parts = parse_url($value);
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
        return null;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    return preg_replace('#/route/v1$#', '', $value) ?: $value;
}

function gaun_osrm_base_url(): string
{
    $default = 'https://router.project-osrm.org';
    return gaun_valid_base_url(gaun_env_string('OSRM_BASE_URL', $default)) ?? $default;
}

/**
 * Moda özel OSRM base URL'si (kendi barındırılan osrm-car/osrm-foot gibi).
 * Tanımlı/geçerli değilse null döner; frontend bu durumda genel OSRM_BASE_URL'e
 * düşer (geriye dönük uyumluluk, mevcut public-demo davranışı bozulmaz).
 */
function gaun_osrm_mode_base_url(string $key): ?string
{
    $value = trim((string) (getenv($key) ?: ''));
    return $value === '' ? null : gaun_valid_base_url($value);
}

function gaun_osrm_profile(string $key, string $default): string
{
    $value = gaun_env_string($key, $default);
    return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $value) ? $value : $default;
}

function gaun_osrm_timeout(): int
{
    $value = (int) (getenv('OSRM_TIMEOUT_MS') ?: 4000);
    return max(500, min(20000, $value));
}

gaun_load_env_file(__DIR__ . '/../.env');

$config = [
    'routing' => [
        'osrmBaseUrl' => gaun_osrm_base_url(),
        'timeoutMs' => gaun_osrm_timeout(),
        'profiles' => [
            'walk' => gaun_osrm_profile('OSRM_WALK_PROFILE', 'foot'),
            'drive' => gaun_osrm_profile('OSRM_DRIVE_PROFILE', 'driving'),
        ],
        // Doluysa (kendi barındırılan osrm-car/osrm-foot) o mod gerçek
        // profiliyle rota alır; null ise genel osrmBaseUrl kullanılır.
        'baseUrls' => [
            'walk' => gaun_osrm_mode_base_url('OSRM_WALK_BASE_URL'),
            'drive' => gaun_osrm_mode_base_url('OSRM_DRIVE_BASE_URL'),
        ],
    ],
    'settings' => settings_ops_public_config(),
];

$html = (string) file_get_contents(__DIR__ . '/../index.html');
$script = '<script>window.GAUN_CONFIG='
    . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';</script>';

// <head> açılışının hemen ardından enjekte edilir (</head>'ten önce DEĞİL):
// index.html'in erken dil/tema bootstrap script'i GAUN_CONFIG.settings'e
// (varsayılan dil) ihtiyaç duyuyor — script'ler doküman sırasına göre
// çalıştığından, GAUN_CONFIG o script'ten ÖNCE tanımlanmış olmalı.
echo str_replace('<head>', "<head>\n{$script}", $html);

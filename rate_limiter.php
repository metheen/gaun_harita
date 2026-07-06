<?php
declare(strict_types=1);

const RATE_LIMIT_DIR = __DIR__ . '/private/rate_limits';

function rate_limit_client_key(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $ip !== '' ? $ip : 'unknown';
}

function rate_limit_scope_name(string $scope): string
{
    $scope = preg_replace('/[^A-Za-z0-9_-]/', '_', $scope) ?? 'default';
    return trim($scope, '_') !== '' ? $scope : 'default';
}

function rate_limit_file(string $scope): string
{
    $scope = rate_limit_scope_name($scope);
    $key = hash('sha256', $scope . '|' . rate_limit_client_key());
    return RATE_LIMIT_DIR . '/' . $scope . '-' . $key . '.json';
}

function rate_limit_gc(string $dir, int $now): void
{
    foreach ((array) @glob($dir . '/*.json') as $file) {
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || (int) ($data['reset_at'] ?? 0) < $now - 3600) {
            @unlink($file);
        }
    }
}

/**
 * Sabit pencereli, dosya tabanlı küçük rate limiter.
 * Depolama yazılamazsa public deneyimi bozmamak için fail-open davranır.
 *
 * @return array{allowed: bool, retry_after: int, remaining: int}
 */
function rate_limit_check(string $scope, int $maxRequests, int $windowSeconds): array
{
    $maxRequests = max(1, $maxRequests);
    $windowSeconds = max(1, $windowSeconds);

    if (!is_dir(RATE_LIMIT_DIR) && !@mkdir(RATE_LIMIT_DIR, 0770, true)) {
        return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxRequests];
    }

    $now = time();
    if (random_int(1, 100) === 1) {
        rate_limit_gc(RATE_LIMIT_DIR, $now);
    }

    $fp = @fopen(rate_limit_file($scope), 'c+');
    if ($fp === false) {
        return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxRequests];
    }

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw !== '' ? (json_decode($raw, true) ?: []) : [];

    $resetAt = (int) ($data['reset_at'] ?? 0);
    $count = (int) ($data['count'] ?? 0);
    if ($resetAt <= $now) {
        $resetAt = $now + $windowSeconds;
        $count = 0;
    }

    $count++;
    $allowed = $count <= $maxRequests;
    $remaining = max(0, $maxRequests - $count);
    $retryAfter = $allowed ? 0 : max(1, $resetAt - $now);

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['count' => $count, 'reset_at' => $resetAt], JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return ['allowed' => $allowed, 'retry_after' => $retryAfter, 'remaining' => $remaining];
}

function rate_limit_enforce(string $scope, int $maxRequests, int $windowSeconds): void
{
    $result = rate_limit_check($scope, $maxRequests, $windowSeconds);
    if ($result['allowed']) {
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        return;
    }

    http_response_code(429);
    header('Retry-After: ' . $result['retry_after']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'rate_limited'], JSON_UNESCAPED_UNICODE);
    exit;
}

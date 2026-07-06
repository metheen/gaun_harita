<?php
declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../analytics.php';
require __DIR__ . '/../security_headers.php';
require __DIR__ . '/../rate_limiter.php';

security_headers_apply();
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!analytics_request_is_automation([])) {
    rate_limit_enforce('analytics', 120, 60);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 4096) {
    http_response_code(400);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

try {
    if (!analytics_request_is_automation($payload)) {
        analytics_log_event(db('write'), $payload);
    }
} catch (Throwable $e) {
    // Analitik depolama harita deneyimini etkilememeli.
}

http_response_code(204);

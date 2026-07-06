<?php
declare(strict_types=1);

const ANALYTICS_TABLE = 'analytics_events';
const ANALYTICS_RETENTION_DAYS = 90;
const ANALYTICS_TZ_OFFSET = '+03:00';
const ANALYTICS_ALLOWED_EVENTS = ['search', 'location_view', 'route_request'];

function analytics_set_timezone(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $pdo->exec("SET time_zone = '" . ANALYTICS_TZ_OFFSET . "'");
    $done[$key] = true;
}

function analytics_table_exists(PDO $pdo): bool
{
    $stmt = $pdo->query("SHOW TABLES LIKE 'analytics_events'");
    return (bool) $stmt->fetchColumn();
}

function analytics_ensure_schema(PDO $pdo): void
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (isset($ready[$key])) {
        return;
    }

    analytics_set_timezone($pdo);
    if (!analytics_table_exists($pdo)) {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS analytics_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(32) NOT NULL,
  location_id INT NULL,
  location_name VARCHAR(255) NULL,
  location_type VARCHAR(80) NULL,
  search_term VARCHAR(120) NULL,
  route_mode VARCHAR(16) NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_event_created (event_type, created_at),
  KEY idx_verified_created (is_verified, event_type, created_at),
  KEY idx_created (created_at),
  KEY idx_location (location_id, event_type, created_at),
  KEY idx_type (location_type, event_type, created_at),
  CONSTRAINT chk_analytics_event_type CHECK (event_type IN ('search', 'location_view', 'route_request'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    try {
        if (!analytics_column_exists($pdo, ANALYTICS_TABLE, 'is_verified')) {
            $pdo->exec('ALTER TABLE analytics_events ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER route_mode');
        }
        if (analytics_column_exists($pdo, ANALYTICS_TABLE, 'is_verified')
            && !analytics_index_exists($pdo, ANALYTICS_TABLE, 'idx_verified_created')) {
            $pdo->exec('ALTER TABLE analytics_events ADD KEY idx_verified_created (is_verified, event_type, created_at)');
        }
    } catch (Throwable $e) {
        error_log('Analytics schema upgrade uygulanamadı: ' . $e->getMessage());
    }

    $ready[$key] = true;
}

function analytics_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function analytics_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function analytics_verified_column_available(PDO $pdo): bool
{
    try {
        return analytics_column_exists($pdo, ANALYTICS_TABLE, 'is_verified');
    } catch (Throwable $e) {
        return false;
    }
}

function analytics_text(mixed $value, int $max): ?string
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }
    return substr($text, 0, $max);
}

function analytics_int_or_null(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    $raw = trim((string) $value);
    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}

function analytics_is_automation_text(mixed $value): bool
{
    $text = trim((string) $value);
    if ($text === '') {
        return false;
    }

    $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    foreach (['_e2e_', '_phpint_', 'playwright', 'special test building', 'özel test binası', 'e2e test'] as $marker) {
        if (str_contains($lower, $marker)) {
            return true;
        }
    }
    return false;
}

function analytics_is_automation_payload(array $payload): bool
{
    foreach (['event_type', 'location_name', 'location_type', 'search_term', 'route_mode'] as $key) {
        if (analytics_is_automation_text($payload[$key] ?? null)) {
            return true;
        }
    }
    return false;
}

function analytics_request_is_automation(array $payload = []): bool
{
    $header = trim((string) ($_SERVER['HTTP_X_GAUN_TEST_AUTOMATION'] ?? ''));
    if ($header === '1' || strtolower($header) === 'true') {
        return true;
    }

    if (analytics_is_automation_text($_SERVER['HTTP_USER_AGENT'] ?? null)) {
        return true;
    }

    return analytics_is_automation_payload($payload);
}

function analytics_not_automation_condition(string $column): string
{
    if (!preg_match('/^[A-Za-z0-9_.]+$/', $column)) {
        throw new InvalidArgumentException('Invalid SQL column reference.');
    }

    return "({$column} IS NULL OR {$column} NOT REGEXP '(^_E2E_|^_PHPINT_|Playwright|Special Test Building|Özel Test Binası|E2E Test)')";
}

function analytics_event_is_verified(
    PDO $pdo,
    string $eventType,
    ?int $locationId,
    ?string $locationName,
    ?string $locationType,
    ?string $searchTerm
): int {
    if ($eventType === 'search') {
        return analytics_is_automation_text($searchTerm) ? 0 : 1;
    }

    if (analytics_is_automation_text($locationName) || analytics_is_automation_text($locationType)) {
        return 0;
    }
    if ($locationId === null) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM campus_locations WHERE id = ? AND active = 1');
    $stmt->execute([$locationId]);
    return (int) $stmt->fetchColumn() > 0 ? 1 : 0;
}

function analytics_cleanup(PDO $pdo, int $days = ANALYTICS_RETENTION_DAYS): void
{
    static $ran = [];
    $key = spl_object_id($pdo);
    if (isset($ran[$key])) {
        return;
    }

    $days = max(7, min(365, $days));
    $pdo->exec("DELETE FROM analytics_events WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
    $ran[$key] = true;
}

function analytics_log_event(PDO $pdo, array $payload): bool
{
    analytics_ensure_schema($pdo);

    $eventType = analytics_text($payload['event_type'] ?? '', 32);
    if ($eventType === null || !in_array($eventType, ANALYTICS_ALLOWED_EVENTS, true)) {
        return false;
    }

    $locationId = analytics_int_or_null($payload['location_id'] ?? null);
    $locationName = analytics_text($payload['location_name'] ?? null, 255);
    $locationType = analytics_text($payload['location_type'] ?? null, 80);
    $searchTerm = analytics_text($payload['search_term'] ?? null, 120);
    $routeMode = analytics_text($payload['route_mode'] ?? null, 16);

    if ($routeMode !== null && !in_array($routeMode, ['walk', 'drive'], true)) {
        $routeMode = null;
    }
    if ($eventType === 'search') {
        if ($searchTerm === null || strlen($searchTerm) < 2) {
            return false;
        }
        $locationId = null;
        $locationName = null;
        $locationType = null;
        $routeMode = null;
    } elseif ($locationId === null && $locationName === null) {
        return false;
    }
    if ($eventType !== 'route_request') {
        $routeMode = null;
    }

    analytics_cleanup($pdo);

    $isVerified = analytics_event_is_verified(
        $pdo,
        $eventType,
        $locationId,
        $locationName,
        $locationType,
        $searchTerm
    );

    $params = [
        ':event_type'    => $eventType,
        ':location_id'   => $locationId,
        ':location_name' => $locationName,
        ':location_type' => $locationType,
        ':search_term'   => $searchTerm,
        ':route_mode'    => $routeMode,
    ];

    if (analytics_verified_column_available($pdo)) {
        $stmt = $pdo->prepare(
            'INSERT INTO analytics_events
                (event_type, location_id, location_name, location_type, search_term, route_mode, is_verified)
             VALUES
                (:event_type, :location_id, :location_name, :location_type, :search_term, :route_mode, :is_verified)'
        );
        $stmt->execute([...$params, ':is_verified' => $isVerified]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO analytics_events
                (event_type, location_id, location_name, location_type, search_term, route_mode)
             VALUES
                (:event_type, :location_id, :location_name, :location_type, :search_term, :route_mode)'
        );
        $stmt->execute($params);
    }

    return true;
}

function analytics_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function analytics_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function analytics_summary(PDO $pdo, bool $verifiedOnly = false): array
{
    analytics_ensure_schema($pdo);

    if ($verifiedOnly) {
        return analytics_verified_summary($pdo);
    }

    $todaySql = "event_type = ? AND created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";

    return [
        'retention_days' => ANALYTICS_RETENTION_DAYS,
        'locations_total' => analytics_scalar($pdo, 'SELECT COUNT(*) FROM campus_locations'),
        'today' => [
            'searches' => analytics_scalar($pdo, "SELECT COUNT(*) FROM analytics_events WHERE {$todaySql}", ['search']),
            'location_views' => analytics_scalar($pdo, "SELECT COUNT(*) FROM analytics_events WHERE {$todaySql}", ['location_view']),
            'route_requests' => analytics_scalar($pdo, "SELECT COUNT(*) FROM analytics_events WHERE {$todaySql}", ['route_request']),
        ],
        'last_7_days' => analytics_rows($pdo, <<<SQL
SELECT DATE(created_at) AS day,
       SUM(event_type = 'search') AS searches,
       SUM(event_type = 'location_view') AS location_views,
       SUM(event_type = 'route_request') AS route_requests
FROM analytics_events
WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
GROUP BY DATE(created_at)
ORDER BY day DESC
SQL),
        'top_searches' => analytics_rows($pdo, <<<SQL
SELECT search_term, COUNT(*) AS total
FROM analytics_events
WHERE event_type = 'search'
  AND search_term IS NOT NULL
  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY search_term
ORDER BY total DESC, search_term ASC
LIMIT 8
SQL),
        'top_locations' => analytics_rows($pdo, <<<SQL
SELECT COALESCE(location_id, 0) AS location_id,
       COALESCE(NULLIF(location_name, ''), 'Bilinmeyen konum') AS location_name,
       COALESCE(MAX(NULLIF(location_type, '')), '') AS location_type,
       SUM(event_type = 'location_view') AS views,
       SUM(event_type = 'route_request') AS routes,
       COUNT(*) AS total
FROM analytics_events
WHERE event_type IN ('location_view', 'route_request')
  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY COALESCE(location_id, 0), COALESCE(NULLIF(location_name, ''), 'Bilinmeyen konum')
ORDER BY total DESC, routes DESC, views DESC, location_name ASC
LIMIT 10
SQL),
        'top_categories' => analytics_rows($pdo, <<<SQL
SELECT COALESCE(NULLIF(location_type, ''), 'Kategori yok') AS location_type,
       COUNT(*) AS total,
       SUM(event_type = 'route_request') AS routes
FROM analytics_events
WHERE event_type IN ('location_view', 'route_request')
  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY COALESCE(NULLIF(location_type, ''), 'Kategori yok')
ORDER BY total DESC, routes DESC, location_type ASC
LIMIT 8
SQL),
    ];
}

function analytics_verified_summary(PDO $pdo): array
{
    if (!analytics_verified_column_available($pdo)) {
        return [
            'retention_days' => ANALYTICS_RETENTION_DAYS,
            'verified_only' => true,
            'schema_ready' => false,
            'locations_total' => analytics_scalar($pdo, 'SELECT COUNT(*) FROM campus_locations'),
            'today' => ['searches' => 0, 'location_views' => 0, 'route_requests' => 0],
            'last_7_days' => [],
            'top_searches' => [],
            'top_locations' => [],
            'top_categories' => [],
        ];
    }

    $today = 'created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
    $todayAliased = 'e.created_at >= CURDATE() AND e.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
    $searchWhere = "event_type = 'search' AND is_verified = 1 AND search_term IS NOT NULL AND "
        . analytics_not_automation_condition('search_term');
    $locationWhere = "e.event_type IN ('location_view', 'route_request')
        AND e.is_verified = 1
        AND e.location_id IS NOT NULL
        AND l.active = 1";

    return [
        'retention_days' => ANALYTICS_RETENTION_DAYS,
        'verified_only' => true,
        'locations_total' => analytics_scalar($pdo, 'SELECT COUNT(*) FROM campus_locations'),
        'today' => [
            'searches' => analytics_scalar($pdo, "SELECT COUNT(*) FROM analytics_events WHERE {$searchWhere} AND {$today}"),
            'location_views' => analytics_scalar(
                $pdo,
                "SELECT COUNT(*)
                 FROM analytics_events e
                 JOIN campus_locations l ON l.id = e.location_id
                 WHERE {$locationWhere} AND e.event_type = 'location_view' AND {$todayAliased}"
            ),
            'route_requests' => analytics_scalar(
                $pdo,
                "SELECT COUNT(*)
                 FROM analytics_events e
                 JOIN campus_locations l ON l.id = e.location_id
                 WHERE {$locationWhere} AND e.event_type = 'route_request' AND {$todayAliased}"
            ),
        ],
        'last_7_days' => analytics_rows($pdo, <<<SQL
SELECT day,
       SUM(searches) AS searches,
       SUM(location_views) AS location_views,
       SUM(route_requests) AS route_requests
FROM (
    SELECT DATE(created_at) AS day,
           COUNT(*) AS searches,
           0 AS location_views,
           0 AS route_requests
    FROM analytics_events
    WHERE {$searchWhere}
      AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)

    UNION ALL

    SELECT DATE(e.created_at) AS day,
           0 AS searches,
           SUM(e.event_type = 'location_view') AS location_views,
           SUM(e.event_type = 'route_request') AS route_requests
    FROM analytics_events e
    JOIN campus_locations l ON l.id = e.location_id
    WHERE {$locationWhere}
      AND e.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(e.created_at)
) verified_events
GROUP BY day
ORDER BY day DESC
SQL),
        'top_searches' => analytics_rows($pdo, <<<SQL
SELECT search_term, COUNT(*) AS total
FROM analytics_events
WHERE {$searchWhere}
  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY search_term
ORDER BY total DESC, search_term ASC
LIMIT 8
SQL),
        'top_locations' => analytics_rows($pdo, <<<SQL
SELECT l.id AS location_id,
       l.name AS location_name,
       l.type AS location_type,
       SUM(e.event_type = 'location_view') AS views,
       SUM(e.event_type = 'route_request') AS routes,
       COUNT(*) AS total
FROM analytics_events e
JOIN campus_locations l ON l.id = e.location_id
WHERE {$locationWhere}
  AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY l.id, l.name, l.type
ORDER BY total DESC, routes DESC, views DESC, location_name ASC
LIMIT 10
SQL),
        'top_categories' => analytics_rows($pdo, <<<SQL
SELECT l.type AS location_type,
       COUNT(*) AS total,
       SUM(e.event_type = 'route_request') AS routes
FROM analytics_events e
JOIN campus_locations l ON l.id = e.location_id
WHERE {$locationWhere}
  AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY l.type
ORDER BY total DESC, routes DESC, location_type ASC
LIMIT 8
SQL),
    ];
}

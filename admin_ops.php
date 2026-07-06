<?php
declare(strict_types=1);

/**
 * Admin operasyon yardımcıları: site yayında/bakım modu ayarı ve admin giriş
 * denetim kayıtları. Public tarafta okuma hataları siteyi kapatmasın diye
 * okuma fonksiyonları güvenli varsayılanlara düşer.
 */

const ADMIN_SETTINGS_TABLE = 'app_settings';
const ADMIN_LOGIN_EVENTS_TABLE = 'admin_login_events';
const ADMIN_OPS_TZ_OFFSET = '+03:00';
const ADMIN_LOGIN_EVENT_STATUSES = ['success', 'failure', 'locked', 'logout'];

function admin_ops_set_timezone(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }

    $pdo->exec("SET time_zone = '" . ADMIN_OPS_TZ_OFFSET . "'");
    $done[$key] = true;
}

function admin_ops_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function admin_ops_ensure_schema(PDO $pdo): void
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (isset($ready[$key])) {
        return;
    }

    admin_ops_set_timezone($pdo);

    if (!admin_ops_table_exists($pdo, ADMIN_SETTINGS_TABLE)) {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(80) NOT NULL,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(80) NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    if (!admin_ops_table_exists($pdo, ADMIN_LOGIN_EVENTS_TABLE)) {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admin_login_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NULL,
  status VARCHAR(24) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  ip_display VARCHAR(64) NOT NULL,
  user_agent VARCHAR(180) NULL,
  reason VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_status_created (status, created_at),
  KEY idx_created (created_at),
  KEY idx_ip_created (ip_hash, created_at),
  CONSTRAINT chk_admin_login_status CHECK (status IN ('success', 'failure', 'locked', 'logout'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_key = setting_key'
    );
    $stmt->execute(['site_enabled', '1', 'system']);
    $stmt->execute(['maintenance_message', 'GAÜN Kampüs Haritası kısa süreli bakımda. Lütfen daha sonra tekrar deneyin.', 'system']);

    $ready[$key] = true;
}

function admin_ops_text(mixed $value, int $max): ?string
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

function admin_ops_client_ip(): string
{
    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?: 'unknown';
}

function admin_ops_mask_ip(?string $ip): string
{
    $ip = trim((string) $ip);
    if ($ip === '' || $ip === 'unknown') {
        return 'unknown';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        $parts[3] = 'x';
        return implode('.', $parts);
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $parts = explode(':', $ip);
        return implode(':', array_slice($parts, 0, 4)) . '::';
    }

    return 'unknown';
}

function admin_ops_ip_hash(?string $ip): string
{
    $ip = trim((string) $ip);
    $salt = (string) (getenv('ADMIN_AUDIT_SALT') ?: getenv('DB_WRITE_PASS') ?: 'gaun-admin-audit');
    return hash('sha256', $ip . '|' . $salt);
}

function admin_ops_user_agent(): ?string
{
    return admin_ops_text($_SERVER['HTTP_USER_AGENT'] ?? null, 180);
}

function admin_ops_not_automation_condition(string $column): string
{
    if (!preg_match('/^[A-Za-z0-9_.]+$/', $column)) {
        throw new InvalidArgumentException('Invalid SQL column reference.');
    }

    return "({$column} IS NULL OR {$column} NOT REGEXP '(^_e2e_|^_E2E_|^_PHPINT_|Playwright|PHPUnit|E2E Test)')";
}

function admin_ops_log_login_event(PDO $pdo, string $status, ?string $username = null, ?string $reason = null): void
{
    if (!in_array($status, ADMIN_LOGIN_EVENT_STATUSES, true)) {
        return;
    }

    try {
        admin_ops_ensure_schema($pdo);
        $ip = admin_ops_client_ip();
        $stmt = $pdo->prepare(
            'INSERT INTO admin_login_events
             (username, status, ip_hash, ip_display, user_agent, reason)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            admin_ops_text($username, 80),
            $status,
            admin_ops_ip_hash($ip),
            admin_ops_mask_ip($ip),
            admin_ops_user_agent(),
            admin_ops_text($reason, 120),
        ]);
    } catch (Throwable $e) {
        error_log('Admin login audit yazılamadı: ' . $e->getMessage());
    }
}

function admin_ops_setting(PDO $pdo, string $key, string $default, bool $ensure = false): string
{
    try {
        if ($ensure) {
            admin_ops_ensure_schema($pdo);
        } elseif (!admin_ops_table_exists($pdo, ADMIN_SETTINGS_TABLE)) {
            return $default;
        }

        $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function admin_ops_set_setting(PDO $pdo, string $key, string $value, string $updatedBy): void
{
    admin_ops_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$key, $value, admin_ops_text($updatedBy, 80)]);
}

function admin_ops_site_status(PDO $pdo, bool $ensure = false): array
{
    $enabled = admin_ops_setting($pdo, 'site_enabled', '1', $ensure) !== '0';
    $message = admin_ops_setting(
        $pdo,
        'maintenance_message',
        'GAÜN Kampüs Haritası kısa süreli bakımda. Lütfen daha sonra tekrar deneyin.',
        $ensure
    );

    $meta = ['updated_at' => null, 'updated_by' => null];
    try {
        if ($ensure || admin_ops_table_exists($pdo, ADMIN_SETTINGS_TABLE)) {
            $stmt = $pdo->query(
                "SELECT MAX(updated_at) AS updated_at,
                        SUBSTRING_INDEX(GROUP_CONCAT(updated_by ORDER BY updated_at DESC SEPARATOR ','), ',', 1) AS updated_by
                 FROM app_settings
                 WHERE setting_key IN ('site_enabled', 'maintenance_message')"
            );
            $row = $stmt->fetch();
            if (is_array($row)) {
                $meta['updated_at'] = $row['updated_at'] ?? null;
                $meta['updated_by'] = $row['updated_by'] ?? null;
            }
        }
    } catch (Throwable $e) {
        // Durum bilgisi yine de varsayılanlarla döner.
    }

    return [
        'enabled' => $enabled,
        'message' => $message,
        'updated_at' => $meta['updated_at'],
        'updated_by' => $meta['updated_by'],
    ];
}

function admin_ops_update_site_status(PDO $pdo, bool $enabled, string $message, string $updatedBy): void
{
    $message = admin_ops_text($message, 500)
        ?? 'GAÜN Kampüs Haritası kısa süreli bakımda. Lütfen daha sonra tekrar deneyin.';
    admin_ops_set_setting($pdo, 'site_enabled', $enabled ? '1' : '0', $updatedBy);
    admin_ops_set_setting($pdo, 'maintenance_message', $message, $updatedBy);
}

function admin_ops_public_site_status(): array
{
    try {
        if (!function_exists('db')) {
            require_once __DIR__ . '/db.php';
        }
        return admin_ops_site_status(db('read'), false);
    } catch (Throwable $e) {
        return [
            'enabled' => true,
            'message' => 'GAÜN Kampüs Haritası kısa süreli bakımda. Lütfen daha sonra tekrar deneyin.',
            'updated_at' => null,
            'updated_by' => null,
        ];
    }
}

function admin_ops_recent_login_events(PDO $pdo, int $limit = 30, bool $includeAutomation = true): array
{
    admin_ops_ensure_schema($pdo);
    $limit = max(5, min(100, $limit));
    $where = $includeAutomation
        ? ''
        : 'WHERE ' . admin_ops_not_automation_condition('username')
            . ' AND ' . admin_ops_not_automation_condition('reason')
            . ' AND ' . admin_ops_not_automation_condition('user_agent');
    $stmt = $pdo->query(
        "SELECT username, status, ip_display, user_agent, reason, created_at
         FROM admin_login_events
         {$where}
         ORDER BY id DESC
         LIMIT {$limit}"
    );
    return $stmt->fetchAll();
}

function admin_ops_login_summary(PDO $pdo, bool $includeAutomation = true): array
{
    admin_ops_ensure_schema($pdo);
    $where = $includeAutomation
        ? ''
        : 'WHERE ' . admin_ops_not_automation_condition('username')
            . ' AND ' . admin_ops_not_automation_condition('reason')
            . ' AND ' . admin_ops_not_automation_condition('user_agent');
    $stmt = $pdo->query(
        "SELECT
            SUM(status = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS success_24h,
            SUM(status = 'failure' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS failure_24h,
            SUM(status = 'locked' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS locked_24h,
            MAX(CASE WHEN status = 'success' THEN created_at ELSE NULL END) AS last_success
         FROM admin_login_events
         {$where}"
    );
    $row = $stmt->fetch() ?: [];
    return [
        'success_24h' => (int) ($row['success_24h'] ?? 0),
        'failure_24h' => (int) ($row['failure_24h'] ?? 0),
        'locked_24h' => (int) ($row['locked_24h'] ?? 0),
        'last_success' => $row['last_success'] ?? null,
    ];
}

function admin_ops_purge_login_events(PDO $pdo, int $days = 90): int
{
    admin_ops_ensure_schema($pdo);
    $days = max(7, min(365, $days));
    $affected = $pdo->exec("DELETE FROM admin_login_events WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
    return (int) $affected;
}

function admin_ops_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_ops_maintenance_page(array $status): string
{
    $message = admin_ops_escape((string) ($status['message'] ?? 'GAÜN Kampüs Haritası kısa süreli bakımda.'));

    return <<<HTML
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>GAÜN Kampüs Haritası Bakımda</title>
  <style>
    :root { color-scheme: light dark; }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #f4f7f2;
      color: #102114;
    }
    main {
      width: min(520px, calc(100% - 40px));
      padding: 32px;
      border: 1px solid rgba(16, 33, 20, 0.12);
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.86);
      box-shadow: 0 18px 48px rgba(16, 33, 20, 0.12);
    }
    h1 { margin: 0 0 12px; font-size: clamp(26px, 5vw, 38px); }
    p { margin: 0; font-size: 17px; line-height: 1.6; }
    small { display: block; margin-top: 24px; color: #5d6b60; }
    @media (prefers-color-scheme: dark) {
      body { background: #09130c; color: #f5f8f2; }
      main { background: rgba(20, 37, 24, 0.92); border-color: rgba(255,255,255,0.14); }
      small { color: #b8c6b4; }
    }
  </style>
</head>
<body>
  <main>
    <h1>Harita kısa süreli bakımda</h1>
    <p>{$message}</p>
    <small>Yönetim paneli açık kalır; bakım modu admin tarafından kapatılabilir.</small>
  </main>
</body>
</html>
HTML;
}

function admin_ops_json_maintenance_response(array $status): void
{
    http_response_code(503);
    header('Retry-After: 3600');
    echo json_encode([
        'error' => 'maintenance',
        'message' => (string) ($status['message'] ?? 'GAÜN Kampüs Haritası kısa süreli bakımda.'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

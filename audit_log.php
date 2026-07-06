<?php
declare(strict_types=1);

/**
 * Genel kritik-işlem denetim kaydı (konum ekleme/silme, CSV import, ayar
 * değişikliği, kullanıcı işlemleri, bakım modu aç/kapa). admin_login_events
 * (admin_ops.php) İLE KARIŞTIRILMAMALI — o yalnızca giriş/çıkış olaylarını
 * tutar ve CHECK kısıtıyla o durumlarla sınırlıdır. IP maskeleme/hash'leme
 * mantığını TEKRAR YAZMAZ; admin_ops.php'nin zaten var olan
 * admin_ops_client_ip()/admin_ops_mask_ip()/admin_ops_ip_hash()/
 * admin_ops_text() fonksiyonlarını kullanır.
 */

require_once __DIR__ . '/admin_ops.php';

const AUDIT_LOG_TABLE = 'audit_log';

function audit_ensure_schema(PDO $pdo): void
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (isset($ready[$key])) {
        return;
    }

    if (!admin_ops_table_exists($pdo, AUDIT_LOG_TABLE)) {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_username VARCHAR(80) NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id VARCHAR(64) NULL,
  summary VARCHAR(255) NULL,
  ip_hash CHAR(64) NOT NULL,
  ip_display VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_action_created (action, created_at),
  KEY idx_entity (entity_type, entity_id, created_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    $ready[$key] = true;
}

/**
 * Kritik bir işlemi kaydeder. Hata durumunda (ör. tablo yoksa ve
 * oluşturulamadıysa) sessizce yutar — audit yazımı asıl işlemi ASLA
 * engellememeli (bkz. admin_ops_log_login_event() ile aynı ilke).
 */
function audit_log_action(
    PDO $pdo,
    string $action,
    ?string $entityType = null,
    ?string $entityId = null,
    ?string $summary = null
): void {
    try {
        audit_ensure_schema($pdo);
        $ip = admin_ops_client_ip();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (actor_username, action, entity_type, entity_id, summary, ip_hash, ip_display)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            admin_ops_text($_SESSION['username'] ?? null, 80),
            $action,
            $entityType,
            $entityId,
            admin_ops_text($summary, 255),
            admin_ops_ip_hash($ip),
            admin_ops_mask_ip($ip),
        ]);
    } catch (Throwable $e) {
        error_log('Audit log yazılamadı: ' . $e->getMessage());
    }
}

function audit_recent(PDO $pdo, int $limit = 50, bool $includeAutomation = true): array
{
    audit_ensure_schema($pdo);
    $limit = max(5, min(200, $limit));
    $where = $includeAutomation
        ? ''
        : 'WHERE ' . admin_ops_not_automation_condition('actor_username')
            . ' AND ' . admin_ops_not_automation_condition('action')
            . ' AND ' . admin_ops_not_automation_condition('summary');
    $stmt = $pdo->query(
        "SELECT actor_username, action, entity_type, entity_id, summary, ip_display, created_at
         FROM audit_log
         {$where}
         ORDER BY id DESC
         LIMIT {$limit}"
    );
    return $stmt->fetchAll();
}

function audit_summary_by_action(PDO $pdo, int $days = 30, bool $includeAutomation = true): array
{
    audit_ensure_schema($pdo);
    $days = max(1, min(365, $days));
    $where = $includeAutomation
        ? ''
        : 'AND ' . admin_ops_not_automation_condition('actor_username')
            . ' AND ' . admin_ops_not_automation_condition('action')
            . ' AND ' . admin_ops_not_automation_condition('summary');
    $stmt = $pdo->prepare(
        "SELECT action, COUNT(*) AS total
         FROM audit_log
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
         {$where}
         GROUP BY action
         ORDER BY total DESC"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

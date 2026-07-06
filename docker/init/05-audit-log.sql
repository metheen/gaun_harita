-- Genel kritik-işlem denetim kaydı (login dışı). admin_login_events'ten
-- AYRI çünkü o tablonun CHECK kısıtı login-durumlarıyla sınırlı.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

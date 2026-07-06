-- Admin operasyon paneli: public site durumu ve giriş denetim kayıtları.
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(80) NOT NULL,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(80) NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, updated_by)
VALUES
  ('site_enabled', '1', 'system'),
  ('maintenance_message', 'GAÜN Kampüs Haritası kısa süreli bakımda. Lütfen daha sonra tekrar deneyin.', 'system')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

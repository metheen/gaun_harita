-- Harita kullanım analitiği: kişisel veri tutmadan olay sayımı.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

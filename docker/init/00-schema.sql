-- Taban şema: campus_locations (konum ağacı) ve users (panel girişi).
-- Yeni/boş bir Docker volume'ünde İLK çalışan init script'idir (00- öneki) —
-- sonraki migration'lar (02-location-i18n, 06-location-active, 07-user-roles)
-- idempotent ALTER ... IF NOT EXISTS oldukları için bu tam şema üzerinde
-- zararsız no-op olur. Var olan veritabanlarında IF NOT EXISTS sayesinde
-- hiçbir şeye dokunmaz.

CREATE TABLE IF NOT EXISTS campus_locations (
  id INT(11) NOT NULL AUTO_INCREMENT,
  parent_id INT(11) DEFAULT NULL,
  name VARCHAR(255) NOT NULL,
  name_en VARCHAR(255) DEFAULT NULL,
  latitude DECIMAL(10,7) DEFAULT NULL,
  longitude DECIMAL(10,7) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  description_en TEXT DEFAULT NULL,
  link VARCHAR(1501) DEFAULT NULL,
  type VARCHAR(50) NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  route VARCHAR(1501) DEFAULT NULL,
  address VARCHAR(1501) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  facilities TEXT DEFAULT NULL,
  capacity INT(11) DEFAULT NULL,
  rooms INT(11) DEFAULT NULL,
  amenities TEXT DEFAULT NULL,
  opening_hours VARCHAR(50) DEFAULT NULL,
  manager_name VARCHAR(100) DEFAULT NULL,
  manager_email VARCHAR(100) DEFAULT NULL,
  image_url VARCHAR(255) DEFAULT NULL,
  rating DECIMAL(3,1) DEFAULT NULL,
  color VARCHAR(20) DEFAULT 'red',
  sort INT(11) DEFAULT NULL,
  sirala INT(11) DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY parent_id (parent_id),
  CONSTRAINT campus_locations_ibfk_1 FOREIGN KEY (parent_id)
    REFERENCES campus_locations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Panel giriş hesapları. İLK admin kaydı bilerek seed edilmez (varsayılan
-- parola riski) — README "Hızlı Başlangıç" bölümündeki tek satırlık komutla
-- kendi kullanıcı adı/parolanızla oluşturulur (password_hash ile saklanır).
CREATE TABLE IF NOT EXISTS users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'super_admin',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Uygulama veritabanı hesapları (en az yetki ilkesi).
-- .env dosyasındaki DB_READ_* / DB_WRITE_* değerleriyle eşleşmelidir.
--
-- !!! YALNIZCA YEREL GELİŞTİRME !!!
-- Buradaki dev_read_pw/dev_write_pw parolaları .env.example ile eşleşen,
-- herkesin görebildiği geliştirme varsayılanlarıdır (DB yalnızca
-- localhost:3307'ye açıktır). ÜRETİMDE bu script'i KULLANMAYIN — hesapları
-- güçlü parolalarla elle oluşturun (bkz. README "Üretim notları").

CREATE USER IF NOT EXISTS 'gau_read'@'%'  IDENTIFIED BY 'dev_read_pw';
CREATE USER IF NOT EXISTS 'gau_write'@'%' IDENTIFIED BY 'dev_write_pw';

GRANT SELECT ON gau_kampus.* TO 'gau_read'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON gau_kampus.* TO 'gau_write'@'%';

FLUSH PRIVILEGES;

-- Konumlar icin aktif/pasif bayragi. Var olan tum satirlar otomatik olarak
-- aktif (1) sayilir; public haritada hicbir sey kaybolmaz. Pasif konumlar
-- yalnizca get_locations.php sorgusunda filtrelenir (bkz. o dosya).
ALTER TABLE campus_locations
    ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1;

-- Konum adlari/aciklamalari icin iki dilli alanlar.
-- MariaDB init sirasinda yeni kurulumlara uygulanir; mevcut DB'lerde de
-- manuel calistirilabilir ve var olan veriyi degistirmez.
ALTER TABLE campus_locations
    ADD COLUMN IF NOT EXISTS name_en VARCHAR(255) NULL AFTER name;

ALTER TABLE campus_locations
    ADD COLUMN IF NOT EXISTS description_en TEXT NULL AFTER description;

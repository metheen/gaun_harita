-- Admin kullanicilari icin rol tabanli yetkilendirme temeli.
-- Var olan tum kullanicilar (mevcut "admin" hesabi dahil) otomatik olarak
-- 'super_admin' olur -- hicbir manuel adim gerekmeden tam yetkili kalir.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'super_admin';

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

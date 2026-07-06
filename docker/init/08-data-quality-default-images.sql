-- Data-quality backfill for local/new database volumes.
-- Empty or placeholder images render with the local GAUN logo instead of
-- showing up as missing in the admin dashboard.

UPDATE campus_locations
SET image_url = 'uploads/Gaziantep-universitesi-logo.jpg'
WHERE type <> 'Kategori'
  AND (
    image_url IS NULL
    OR image_url = ''
    OR image_url LIKE 'https://example.com/%'
    OR image_url LIKE 'http://example.com/%'
  );

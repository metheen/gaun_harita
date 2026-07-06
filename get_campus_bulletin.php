<?php
declare(strict_types=1);

/**
 * "Bugün Kampüste" bülteni: cache'lenmiş GAÜN duyuru/haber/bağlantı listeleri.
 *
 * Yalnızca private/cache/gaun_sources.json okunur — bu istek sırasında
 * HİÇBİR dış siteye erişilmez (scraping sync CLI'sında yapılır).
 * Cache yok/bozuksa boş listeler döner; uygulama bozulmaz.
 */

require_once __DIR__ . '/security_headers.php';
security_headers_apply();

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/admin_ops.php';
require __DIR__ . '/gaun_enrich.php';

try {
    $siteStatus = admin_ops_public_site_status();
    if (!$siteStatus['enabled']) {
        admin_ops_json_maintenance_response($siteStatus);
    }

    echo json_encode(gaun_bulletin(gaun_sources_load()), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Bülten yüklenemedi.'], JSON_UNESCAPED_UNICODE);
}

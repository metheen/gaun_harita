<?php
declare(strict_types=1);

/**
 * Kampüs konumlarını hiyerarşik (parent/child) JSON ağacı olarak döndürür.
 * Tüm kayıtlar tek sorguyla çekilir, ağaç bellekte kurulur (N+1 yok).
 */

require_once __DIR__ . '/security_headers.php';
security_headers_apply();

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php';
require __DIR__ . '/admin_ops.php';
require __DIR__ . '/gaun_enrich.php';
require __DIR__ . '/get_locations_lib.php';

try {
    $siteStatus = admin_ops_public_site_status();
    if (!$siteStatus['enabled']) {
        admin_ops_json_maintenance_response($siteStatus);
    }

    $rows = db('read')
        ->query('SELECT * FROM campus_locations WHERE active = 1 ORDER BY sirala ASC, id ASC')
        ->fetchAll();

    // Cache yok/bozuksa boş indeks döner; zenginleştirme sessizce atlanır.
    $gaunCache  = gaun_sources_load();
    $gaunIndex  = gaun_build_index($gaunCache);
    $diningMenu = gaun_dining($gaunCache);
    $staffByLocation = gaun_staff_by_location($gaunCache, $rows);

    echo json_encode(build_tree($rows, $gaunIndex, $diningMenu, $staffByLocation), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Veriler yüklenemedi.'], JSON_UNESCAPED_UNICODE);
}

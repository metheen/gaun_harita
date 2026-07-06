<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/../analytics.php';
require __DIR__ . '/../admin_ops.php';
require __DIR__ . '/../system_health.php';
require __DIR__ . '/locations_lib.php';

$pdo = db('write');

$summary = [
    'retention_days' => ANALYTICS_RETENTION_DAYS,
    'locations_total' => 0,
    'today' => ['searches' => 0, 'location_views' => 0, 'route_requests' => 0],
    'last_7_days' => [],
    'top_searches' => [],
    'top_locations' => [],
    'top_categories' => [],
];
$analyticsError = null;

try {
    $summary = analytics_summary($pdo, true);
} catch (Throwable $e) {
    $analyticsError = 'Analitik tablosu hazırlanamadı. Yeni Docker kurulumlarında otomatik oluşur; mevcut veritabanında docker/init/01-analytics.sql dosyasını bir kez çalıştırın.';
}

$health = system_health_summary($pdo);
try {
    $siteEnabled = admin_ops_site_status($pdo, true)['enabled'];
} catch (Throwable $e) {
    $siteEnabled = true;
}
try {
    $recentLogins = admin_ops_recent_login_events($pdo, 5, false);
} catch (Throwable $e) {
    $recentLogins = [];
}
try {
    $qualityCounts = location_data_quality_counts($pdo);
} catch (Throwable $e) {
    $qualityCounts = [];
}

const DASHBOARD_QUALITY_LABELS = [
    'missing_coords' => 'Koordinatı eksik',
    'missing_en' => 'İngilizce adı eksik',
    'missing_image' => 'Görseli eksik',
    'missing_link' => 'Bağlantısı eksik',
    'inactive' => 'Pasif konum',
];

function dashboard_count(mixed $value): string
{
    return number_format((int) $value, 0, ',', '.');
}

function dashboard_day(string $value): string
{
    $time = strtotime($value);
    return $time ? date('d.m.Y', $time) : $value;
}

$pageTitle = 'Dashboard';
include __DIR__ . '/layout_header.php';
?>

<style>
    .dashboard-card .card-title {
        font-weight: 600;
    }
    .dashboard-muted {
        color: #6c757d;
        font-size: 0.875rem;
    }
    .dashboard-table td,
    .dashboard-table th {
        vertical-align: middle;
    }
    .dashboard-empty {
        color: #6c757d;
        padding: 1.5rem;
        text-align: center;
    }
</style>

<div class="content-header px-0 pt-0">
    <div class="container-fluid px-0">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-7">
                <h1 class="m-0">Kullanım Dashboard'u</h1>
                <p class="dashboard-muted mb-0">
                    Doğrulanmış harita olayları; test/otomasyon kayıtları ve eski belirsiz kayıtlar sayaçlara dahil edilmez.
                </p>
            </div>
            <div class="col-sm-5 text-sm-right mt-2 mt-sm-0">
                <a href="locations.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-map-marker-alt"></i> Konum Yönetimi
                </a>
            </div>
        </div>
    </div>
</div>

<?php if ($analyticsError !== null): ?>
    <div class="alert alert-warning"><?= e($analyticsError) ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box bg-info">
            <div class="inner">
                <h3><?= e(dashboard_count($summary['locations_total'])) ?></h3>
                <p>Toplam Konum</p>
            </div>
            <div class="icon"><i class="fas fa-map-marked-alt"></i></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box bg-success">
            <div class="inner">
                <h3><?= e(dashboard_count($summary['today']['searches'])) ?></h3>
                <p>Bugünkü Arama</p>
            </div>
            <div class="icon"><i class="fas fa-search"></i></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box bg-warning">
            <div class="inner">
                <h3><?= e(dashboard_count($summary['today']['location_views'])) ?></h3>
                <p>Bugünkü Konum Görüntüleme</p>
            </div>
            <div class="icon"><i class="fas fa-eye"></i></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box bg-danger">
            <div class="inner">
                <h3><?= e(dashboard_count($summary['today']['route_requests'])) ?></h3>
                <p>Bugünkü Rota İsteği</p>
            </div>
            <div class="icon"><i class="fas fa-route"></i></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box <?= $siteEnabled ? 'bg-success' : 'bg-warning' ?>">
            <div class="inner">
                <h3><?= $siteEnabled ? 'Açık' : 'Bakım' ?></h3>
                <p>Public Site Durumu</p>
            </div>
            <div class="icon"><i class="fas fa-power-off"></i></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box <?= $health['db']['ok'] ? 'bg-success' : 'bg-danger' ?>">
            <div class="inner">
                <h3><?= $health['db']['ok'] ? 'Sağlıklı' : 'Sorunlu' ?></h3>
                <p>Veritabanı</p>
            </div>
            <div class="icon"><i class="fas fa-database"></i></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box <?= $health['sync']['ok'] ? 'bg-success' : 'bg-warning' ?>">
            <div class="inner">
                <h3><?= $health['sync']['ok'] ? 'Güncel' : 'Kontrol Et' ?></h3>
                <p>GAÜN Senkronizasyonu</p>
            </div>
            <div class="icon"><i class="fas fa-sync"></i></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box <?= $health['osrm']['ok'] ? 'bg-success' : 'bg-danger' ?>">
            <div class="inner">
                <h3><?= $health['osrm']['ok'] ? 'Erişilebilir' : 'Erişilemiyor' ?></h3>
                <p>OSRM Rota Servisi</p>
            </div>
            <div class="icon"><i class="fas fa-route"></i></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card dashboard-card">
            <div class="card-header">
                <h3 class="card-title">Son 30 Günün Popüler Konumları</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 dashboard-table">
                        <thead>
                            <tr>
                                <th>Konum</th>
                                <th>Tür</th>
                                <th class="text-right">Görüntüleme</th>
                                <th class="text-right">Rota</th>
                                <th class="text-right">Toplam</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($summary['top_locations']): ?>
                            <?php foreach ($summary['top_locations'] as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= e((string) $row['location_name']) ?></strong>
                                        <?php if ((int) $row['location_id'] > 0): ?>
                                            <span class="text-muted">#<?= e((string) $row['location_id']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string) $row['location_type']) ?></td>
                                    <td class="text-right"><?= e(dashboard_count($row['views'])) ?></td>
                                    <td class="text-right"><?= e(dashboard_count($row['routes'])) ?></td>
                                    <td class="text-right font-weight-bold"><?= e(dashboard_count($row['total'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="dashboard-empty">Henüz konum olayı yok.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card dashboard-card">
            <div class="card-header">
                <h3 class="card-title">Son 7 Gün</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered mb-0 dashboard-table">
                        <thead>
                            <tr>
                                <th>Gün</th>
                                <th class="text-right">Arama</th>
                                <th class="text-right">Konum</th>
                                <th class="text-right">Rota</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($summary['last_7_days']): ?>
                            <?php foreach ($summary['last_7_days'] as $row): ?>
                                <tr>
                                    <td><?= e(dashboard_day((string) $row['day'])) ?></td>
                                    <td class="text-right"><?= e(dashboard_count($row['searches'])) ?></td>
                                    <td class="text-right"><?= e(dashboard_count($row['location_views'])) ?></td>
                                    <td class="text-right"><?= e(dashboard_count($row['route_requests'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="dashboard-empty">Son 7 gün için veri yok.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card dashboard-card">
            <div class="card-header">
                <h3 class="card-title">Uyarılar</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0 dashboard-table">
                    <tbody>
                    <?php $hasWarning = false; ?>
                    <?php foreach ($qualityCounts as $filter => $count): ?>
                        <?php if ($count > 0): $hasWarning = true; ?>
                            <tr>
                                <td>
                                    <a href="locations.php?data_filter=<?= e($filter) ?>">
                                        <?= e(DASHBOARD_QUALITY_LABELS[$filter] ?? $filter) ?>
                                    </a>
                                </td>
                                <td class="text-right font-weight-bold"><?= e(dashboard_count($count)) ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$hasWarning): ?>
                        <tr><td class="dashboard-empty">Uyarı yok, tüm konumlar tamam.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card dashboard-card">
            <div class="card-header">
                <h3 class="card-title">Son Girişler</h3>
                <div class="card-tools">
                    <a href="security.php" class="btn btn-tool" title="Güvenlik sayfasına git">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0 dashboard-table">
                    <tbody>
                    <?php if ($recentLogins): ?>
                        <?php foreach ($recentLogins as $event): ?>
                            <tr>
                                <td><?= e((string) ($event['username'] ?: '-')) ?></td>
                                <td>
                                    <span class="badge badge-<?= $event['status'] === 'success' ? 'success' : (($event['status'] === 'failure') ? 'danger' : 'warning') ?>">
                                        <?= e((string) $event['status']) ?>
                                    </span>
                                </td>
                                <td class="text-right"><?= e(dashboard_day((string) $event['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td class="dashboard-empty">Henüz giriş olayı yok.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card dashboard-card">
            <div class="card-header">
                <h3 class="card-title">En Çok Arananlar</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0 dashboard-table">
                    <tbody>
                    <?php if ($summary['top_searches']): ?>
                        <?php foreach ($summary['top_searches'] as $row): ?>
                            <tr>
                                <td><?= e((string) $row['search_term']) ?></td>
                                <td class="text-right font-weight-bold"><?= e(dashboard_count($row['total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td class="dashboard-empty">Henüz arama kaydı yok.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card dashboard-card">
            <div class="card-header">
                <h3 class="card-title">Popüler Kategoriler</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0 dashboard-table">
                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th class="text-right">Toplam</th>
                            <th class="text-right">Rota</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($summary['top_categories']): ?>
                        <?php foreach ($summary['top_categories'] as $row): ?>
                            <tr>
                                <td><?= e((string) $row['location_type']) ?></td>
                                <td class="text-right font-weight-bold"><?= e(dashboard_count($row['total'])) ?></td>
                                <td class="text-right"><?= e(dashboard_count($row['routes'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="dashboard-empty">Henüz kategori verisi yok.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="callout callout-info">
            <h5>Sayım ilkesi</h5>
            <p class="mb-0">
                Konum ve rota sayaçları yalnızca veritabanındaki aktif konumla eşleşen
                doğrulanmış olaylardan hesaplanır. Arama sayacı otomasyon işareti taşımayan
                gerçek public istekleri sayar. IP, kullanıcı ajanı, öğrenci hesabı veya
                oturum kimliği analitik tablosunda saklanmaz.
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/layout_footer.php'; ?>

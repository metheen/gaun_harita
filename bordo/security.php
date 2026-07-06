<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/throttle.php';
require __DIR__ . '/../admin_ops.php';
require __DIR__ . '/../auth_roles.php';
require __DIR__ . '/../audit_log.php';

// Site ayarları/güvenlik yalnızca super_admin'e açık (bkz. auth_roles.php).
// Var olan tek hesap migration ile otomatik super_admin olduğu için bu,
// hiç kimseyi dışarıda bırakmayan bilinçli bir sıkılaştırmadır.
auth_require_role('super_admin');

$pageTitle = 'Güvenlik ve Site Durumu';
$schemaError = null;
$pdo = db('write');

try {
    admin_ops_ensure_schema($pdo);
} catch (Throwable $e) {
    $schemaError = 'Güvenlik tabloları hazırlanamadı. Veritabanı yetkilerini ve migration durumunu kontrol edin.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'site_status') {
            $enabled = isset($_POST['site_enabled']);
            admin_ops_update_site_status(
                $pdo,
                $enabled,
                (string) ($_POST['maintenance_message'] ?? ''),
                (string) ($_SESSION['username'] ?? 'admin')
            );
            audit_log_action($pdo, 'site_status.update', 'app_settings', null, $enabled ? 'enabled' : 'disabled');
            flash('Site durumu güncellendi.');
        } elseif ($action === 'clear_throttle') {
            throttle_clear_all();
            audit_log_action($pdo, 'throttle.clear_all');
            flash('Aktif giriş kilitleri temizlendi.', 'warning');
        } elseif ($action === 'purge_login_events') {
            $deleted = admin_ops_purge_login_events($pdo, 90);
            audit_log_action($pdo, 'login_events.purge', 'admin_login_events', null, "deleted={$deleted}");
            flash($deleted > 0 ? "{$deleted} eski giriş kaydı temizlendi." : 'Temizlenecek eski giriş kaydı yok.', 'info');
        }
    } catch (Throwable $e) {
        flash('İşlem tamamlanamadı: ' . $e->getMessage(), 'danger');
    }

    redirect('security.php');
}

$siteStatus = $schemaError === null
    ? admin_ops_site_status($pdo, true)
    : ['enabled' => true, 'message' => 'GAÜN Kampüs Haritası kısa süreli bakımda. Lütfen daha sonra tekrar deneyin.', 'updated_at' => null, 'updated_by' => null];
$loginSummary = $schemaError === null
    ? admin_ops_login_summary($pdo, false)
    : ['success_24h' => 0, 'failure_24h' => 0, 'locked_24h' => 0, 'last_success' => null];
$recentEvents = $schemaError === null ? admin_ops_recent_login_events($pdo, 40, false) : [];
$throttleRows = throttle_snapshot();
$recentAudit = $schemaError === null ? audit_recent($pdo, 20, false) : [];

function admin_badge_class(string $status): string
{
    return match ($status) {
        'success' => 'success',
        'failure' => 'danger',
        'locked' => 'warning',
        'logout' => 'secondary',
        default => 'light',
    };
}

function admin_status_label(string $status): string
{
    return match ($status) {
        'success' => 'Başarılı',
        'failure' => 'Başarısız',
        'locked' => 'Kilitlendi',
        'logout' => 'Çıkış',
        default => $status,
    };
}

function admin_time(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date('d.m.Y H:i', $time) : $value;
}

function admin_seconds(int $seconds): string
{
    if ($seconds <= 0) {
        return '-';
    }
    $minutes = (int) ceil($seconds / 60);
    return $minutes . ' dk';
}

include __DIR__ . '/layout_header.php';
?>

<style>
    .security-muted {
        color: #6c757d;
        font-size: 0.875rem;
    }
    .security-table td,
    .security-table th {
        vertical-align: middle;
    }
    .security-empty {
        color: #6c757d;
        padding: 1.5rem;
        text-align: center;
    }
    .security-message {
        min-height: 110px;
        resize: vertical;
    }
</style>

<div class="content-header px-0 pt-0">
    <div class="container-fluid px-0">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-7">
                <h1 class="m-0">Güvenlik ve Site Durumu</h1>
                <p class="security-muted mb-0">
                    Haritanın yayında olup olmadığını ve gerçek admin giriş olaylarını buradan izleyin.
                    Test/otomasyon kayıtları bu görünümde sayılmaz.
                </p>
            </div>
            <div class="col-sm-5 text-sm-right mt-2 mt-sm-0">
                <a href="/" target="_blank" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-external-link-alt"></i> Public Siteyi Aç
                </a>
            </div>
        </div>
    </div>
</div>

<?php if ($schemaError !== null): ?>
    <div class="alert alert-warning"><?= e($schemaError) ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box <?= $siteStatus['enabled'] ? 'bg-success' : 'bg-warning' ?>">
            <div class="inner">
                <h3><?= $siteStatus['enabled'] ? 'Açık' : 'Bakım' ?></h3>
                <p>Public Site Durumu</p>
            </div>
            <div class="icon"><i class="fas fa-power-off"></i></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box bg-info">
            <div class="inner">
                <h3><?= e((string) $loginSummary['success_24h']) ?></h3>
                <p>24 Saat Başarılı Giriş</p>
            </div>
            <div class="icon"><i class="fas fa-user-check"></i></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box bg-danger">
            <div class="inner">
                <h3><?= e((string) $loginSummary['failure_24h']) ?></h3>
                <p>24 Saat Hatalı Giriş</p>
            </div>
            <div class="icon"><i class="fas fa-user-times"></i></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="small-box bg-secondary">
            <div class="inner">
                <h3><?= e((string) count($throttleRows)) ?></h3>
                <p>Aktif Deneme/Kilit</p>
            </div>
            <div class="icon"><i class="fas fa-shield-alt"></i></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Site Yayın Durumu</h3>
            </div>
            <form method="POST">
                <div class="card-body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="site_status">

                    <div class="custom-control custom-switch mb-3">
                        <input type="checkbox" class="custom-control-input" id="siteEnabled" name="site_enabled" value="1" <?= $siteStatus['enabled'] ? 'checked' : '' ?>>
                        <label class="custom-control-label" for="siteEnabled">
                            Harita public sitede yayında
                        </label>
                    </div>

                    <div class="form-group mb-0">
                        <label for="maintenanceMessage">Bakım mesajı</label>
                        <textarea id="maintenanceMessage" name="maintenance_message" class="form-control security-message" maxlength="500"><?= e((string) $siteStatus['message']) ?></textarea>
                        <small class="form-text text-muted">
                            Site kapalıyken ziyaretçilere bu mesaj gösterilir. Admin panel etkilenmez.
                        </small>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="security-muted">
                        Son güncelleme: <?= e(admin_time($siteStatus['updated_at'])) ?>
                    </span>
                    <button class="btn btn-primary">
                        <i class="fas fa-save"></i> Kaydet
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Aktif Giriş Kilitleri</h3>
                <div class="card-tools">
                    <form method="POST" onsubmit="return confirm('Tüm aktif giriş kilitleri temizlensin mi?')" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_throttle">
                        <button class="btn btn-tool" title="Kilitleri temizle">
                            <i class="fas fa-unlock"></i>
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0 security-table">
                        <thead>
                            <tr>
                                <th>IP</th>
                                <th class="text-right">Deneme</th>
                                <th class="text-right">Kilit</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($throttleRows): ?>
                            <?php foreach ($throttleRows as $row): ?>
                                <tr>
                                    <td><?= e(admin_ops_mask_ip((string) $row['ip'])) ?></td>
                                    <td class="text-right"><?= e((string) $row['count']) ?></td>
                                    <td class="text-right">
                                        <?php if ($row['is_locked']): ?>
                                            <span class="badge badge-warning"><?= e(admin_seconds((int) $row['locked_for'])) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-light">İzleniyor</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="security-empty">Aktif kilit veya deneme yok.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Son Admin Giriş Olayları</h3>
                <div class="card-tools">
                    <form method="POST" onsubmit="return confirm('90 günden eski giriş kayıtları temizlensin mi?')" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="purge_login_events">
                        <button class="btn btn-tool" title="Eski kayıtları temizle">
                            <i class="fas fa-broom"></i>
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 security-table">
                        <thead>
                            <tr>
                                <th>Zaman</th>
                                <th>Kullanıcı</th>
                                <th>Durum</th>
                                <th>IP</th>
                                <th>Detay</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($recentEvents): ?>
                            <?php foreach ($recentEvents as $event): ?>
                                <tr>
                                    <td><?= e(admin_time((string) $event['created_at'])) ?></td>
                                    <td><?= e((string) ($event['username'] ?: '-')) ?></td>
                                    <td>
                                        <span class="badge badge-<?= e(admin_badge_class((string) $event['status'])) ?>">
                                            <?= e(admin_status_label((string) $event['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= e((string) $event['ip_display']) ?></td>
                                    <td>
                                        <span title="<?= e((string) ($event['user_agent'] ?? '')) ?>">
                                            <?= e((string) ($event['reason'] ?: '-')) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="security-empty">Henüz giriş olayı yok.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Son Kritik İşlemler</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 security-table">
                        <thead>
                            <tr>
                                <th>Zaman</th>
                                <th>Kullanıcı</th>
                                <th>İşlem</th>
                                <th>Hedef</th>
                                <th>Detay</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($recentAudit): ?>
                            <?php foreach ($recentAudit as $entry): ?>
                                <tr>
                                    <td><?= e(admin_time((string) $entry['created_at'])) ?></td>
                                    <td><?= e((string) ($entry['actor_username'] ?: '-')) ?></td>
                                    <td><span class="badge badge-light"><?= e((string) $entry['action']) ?></span></td>
                                    <td>
                                        <?php if ($entry['entity_type']): ?>
                                            <?= e((string) $entry['entity_type']) ?><?= $entry['entity_id'] ? '#' . e((string) $entry['entity_id']) : '' ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string) ($entry['summary'] ?: '-')) ?></td>
                                    <td><?= e((string) $entry['ip_display']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="security-empty">Henüz kritik işlem kaydı yok.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="callout callout-info">
            <h5>Aktif güvenlik önlemleri</h5>
            <p class="mb-0">
                CSRF koruması, oturum çerezi sertleştirme, başarısız giriş hız sınırlama,
                maskeli IP audit kaydı ve public bakım modu aktif. IP hash'i korelasyon
                için tutulur; panelde düz IP gösterilmez.
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/layout_footer.php'; ?>

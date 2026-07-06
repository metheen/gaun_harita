<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/../auth_roles.php';
require __DIR__ . '/../audit_log.php';
require __DIR__ . '/../settings_ops.php';

auth_require_role('super_admin');

$pageTitle = 'Site Ayarları';
$pdo = db('write');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $values = [];
    foreach (array_keys(settings_ops_schema()) as $key) {
        $values[$key] = (string) ($_POST[$key] ?? '');
    }

    $errors = settings_ops_update($pdo, $values, (string) ($_SESSION['username'] ?? 'admin'));
    if ($errors) {
        flash('Bazı alanlar kaydedilemedi, lütfen değerleri kontrol edin.', 'danger');
    } else {
        audit_log_action($pdo, 'settings.update', 'app_settings', null, implode(',', array_keys($values)));
        flash('Site ayarları güncellendi.');
        redirect('settings.php');
    }
}

$settings = settings_ops_get_all($pdo);

include __DIR__ . '/layout_header.php';
?>

<div class="content-header px-0 pt-0">
    <div class="container-fluid px-0">
        <h1 class="m-0">Site Ayarları</h1>
        <p class="text-muted mb-0">
            Kod değişikliği gerektirmeden site genel ayarlarını buradan yönetin.
        </p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $field => $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST">
    <?= csrf_field() ?>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Genel</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="site_title">Kurumsal Başlık</label>
                        <input type="text" id="site_title" name="site_title" class="form-control" maxlength="255" value="<?= e($settings['site_title']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="site_description">Kurumsal Açıklama</label>
                        <textarea id="site_description" name="site_description" class="form-control" rows="3" maxlength="500"><?= e($settings['site_description']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="default_language">Varsayılan Dil</label>
                        <select id="default_language" name="default_language" class="form-control">
                            <option value="tr" <?= $settings['default_language'] === 'tr' ? 'selected' : '' ?>>Türkçe</option>
                            <option value="en" <?= $settings['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
                        </select>
                        <small class="form-text text-muted">
                            Yalnızca ziyaretçinin daha önce kaydedilmiş bir dil tercihi yoksa uygulanır.
                        </small>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="contact_email">İletişim E-posta</label>
                            <input type="email" id="contact_email" name="contact_email" class="form-control" maxlength="255" value="<?= e($settings['contact_email']) ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="contact_phone">İletişim Telefon</label>
                            <input type="text" id="contact_phone" name="contact_phone" class="form-control" maxlength="50" value="<?= e($settings['contact_phone']) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Harita Başlangıç Görünümü</h3>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="map_center_lat">Enlem</label>
                            <input type="text" id="map_center_lat" name="map_center_lat" class="form-control" value="<?= e($settings['map_center_lat']) ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="map_center_lng">Boylam</label>
                            <input type="text" id="map_center_lng" name="map_center_lng" class="form-control" value="<?= e($settings['map_center_lng']) ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="map_zoom">Zoom (1-20)</label>
                            <input type="number" id="map_zoom" name="map_zoom" class="form-control" min="1" max="20" value="<?= e($settings['map_zoom']) ?>">
                        </div>
                    </div>
                    <small class="form-text text-muted">
                        Haritanın ilk açıldığında hangi noktada ve yakınlık düzeyinde
                        görüneceğini belirler.
                    </small>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">PWA Metinleri</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="pwa_short_name">Kısa Uygulama Adı</label>
                        <input type="text" id="pwa_short_name" name="pwa_short_name" class="form-control" maxlength="50" value="<?= e($settings['pwa_short_name']) ?>">
                    </div>
                    <div class="form-group mb-0">
                        <label for="pwa_description">Uygulama Açıklaması</label>
                        <textarea id="pwa_description" name="pwa_description" class="form-control" rows="2" maxlength="255"><?= e($settings['pwa_description']) ?></textarea>
                    </div>
                    <small class="form-text text-muted">
                        Şu an için yalnızca kaydedilir; manifest.json'a otomatik yansıtma
                        sonraki fazda planlanıyor.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <button class="btn btn-primary">
        <i class="fas fa-save"></i> Kaydet
    </button>
</form>

<?php include __DIR__ . '/layout_footer.php'; ?>

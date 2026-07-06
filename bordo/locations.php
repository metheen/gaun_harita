<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/../gaun_enrich.php';
require __DIR__ . '/../auth_roles.php';
require __DIR__ . '/../audit_log.php';

require __DIR__ . '/locations_lib.php';

const LOCATIONS_PER_PAGE = 50;

$pdo = db('write');

if (($_GET['action'] ?? '') === 'export_csv') {
    export_locations_csv($pdo);
}

// ---- İşlem yönlendirmesi (POST/PRG deseni) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // Listeleme/görüntüleme (GET) her role açık; mutasyon yalnızca
    // editor/super_admin. Viewer buraya gelirse dashboard'a yönlendirilir.
    auth_require_role('editor', 'super_admin');
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = find_location($pdo, $id);
        $pdo->prepare('DELETE FROM campus_locations WHERE id = ?')->execute([$id]);
        if ($existing !== null) {
            location_cleanup_orphaned_image((string) ($existing['image_url'] ?? ''), null);
            audit_log_action($pdo, 'location.delete', 'campus_locations', (string) $id, (string) $existing['name']);
        }
        flash('Kayıt silindi.');
        redirect('locations.php');
    }

    if ($action === 'save') {
        $data = collect_location($_POST);
        $id   = (int) ($_POST['id'] ?? 0);
        $existing = $id > 0 ? find_location($pdo, $id) : null;
        $upload = uploaded_location_image_path($_FILES['image_file'] ?? null);
        if ($upload['error'] !== null) {
            flash($upload['error'], 'danger');
            redirect('locations.php');
        }
        if ($upload['path'] !== null) {
            $data['image_url'] = $upload['path'];
        }

        $errors = validate_location($pdo, $data, $id);
        if ($errors) {
            flash(implode(' ', $errors), 'danger');
            redirect('locations.php');
        }

        try {
            $savedId = save_location_record($pdo, $data, $id);
            if ($existing !== null) {
                location_cleanup_orphaned_image((string) ($existing['image_url'] ?? ''), $data['image_url']);
            }
            $sirala = trim((string) ($_POST['sirala'] ?? ''));
            if ($sirala !== '' && preg_match('/^-?\d+$/', $sirala)) {
                $pdo->prepare('UPDATE campus_locations SET sirala = ? WHERE id = ?')->execute([(int) $sirala, $savedId]);
            }
            audit_log_action(
                $pdo,
                $id > 0 ? 'location.update' : 'location.create',
                'campus_locations',
                (string) $savedId,
                $data['name']
            );
            flash($id > 0 ? 'Kayıt güncellendi.' : 'Kayıt eklendi.');
            // Kaydedilen satırın az önce eklenmiş/düzenlenmiş olsa da varsayılan
            // sayfalamada GÖRÜNMEYEBİLECEĞİ (bkz. location_page_for_id() belgesi)
            // için doğrudan onu içeren sayfaya yönlendirilir.
            redirect('locations.php?page=' . location_page_for_id($pdo, $savedId, LOCATIONS_PER_PAGE));
        } catch (Throwable $e) {
            flash('İşlem başarısız oldu.', 'danger');
        }

        redirect('locations.php');
    }

    if ($action === 'import_csv') {
        $result = import_locations_csv($pdo, $_FILES['csv_file'] ?? []);
        if (isset($result['fatal'])) {
            flash($result['fatal'], 'danger');
        } else {
            audit_log_action(
                $pdo,
                'location.csv_import',
                'campus_locations',
                null,
                "created={$result['created']}, updated={$result['updated']}, skipped={$result['skipped']}"
            );
            $message = "CSV içe aktarıldı: {$result['created']} yeni, {$result['updated']} güncellendi, {$result['skipped']} boş satır atlandı.";
            if ($result['errors']) {
                $shown = array_slice($result['errors'], 0, 6);
                $message .= ' Hatalar: ' . implode(' | ', $shown);
                $remaining = count($result['errors']) - count($shown);
                if ($remaining > 0) {
                    $message .= " (+{$remaining} hata daha)";
                }
                flash($message, 'warning');
            } else {
                flash($message);
            }
            if ($result['last_id'] !== null) {
                // En azından SON işlenen satırın göründüğü sayfaya yönlendir
                // (bkz. location_page_for_id() belgesi) — çoklu satır içeren
                // içe aktarımlarda diğer yeni satırlar farklı sayfalara
                // düşebilir, bu bilinen bir sınırlamadır.
                redirect('locations.php?page=' . location_page_for_id($pdo, (int) $result['last_id'], LOCATIONS_PER_PAGE));
            }
        }
        redirect('locations.php');
    }

    if ($action === 'bulk_activate' || $action === 'bulk_deactivate') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $active = $action === 'bulk_activate';
        $affected = bulk_update_active($pdo, $ids, $active);
        if ($affected > 0) {
            audit_log_action($pdo, 'location.bulk_active', 'campus_locations', null, ($active ? 'active=1' : 'active=0') . ", count={$affected}");
        }
        flash($affected > 0 ? "{$affected} kayıt " . ($active ? 'aktif' : 'pasif') . ' yapıldı.' : 'Seçili kayıt bulunamadı.');
        redirect('locations.php');
    }

    if ($action === 'bulk_move_category') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $parentRaw = trim((string) ($_POST['bulk_parent_id'] ?? ''));
        $parentId = $parentRaw === '' ? null : (int) $parentRaw;
        $affected = bulk_update_parent($pdo, $ids, $parentId);
        if ($affected > 0) {
            audit_log_action($pdo, 'location.bulk_move', 'campus_locations', null, "parent_id={$parentRaw}, count={$affected}");
        }
        flash($affected > 0 ? "{$affected} kayıt taşındı." : 'Seçili kayıt bulunamadı veya üst kategori seçimi döngü oluşturacağı için taşınmadı.');
        redirect('locations.php');
    }

    redirect('locations.php');
}

// ---- Listeleme + filtreleme ----
$search      = trim((string) ($_GET['search'] ?? ''));
$filterType  = trim((string) ($_GET['type'] ?? ''));
$dataFilter  = trim((string) ($_GET['data_filter'] ?? ''));
$page        = max(1, (int) ($_GET['page'] ?? 1));

$conditions = [];
$params     = [];
if ($search !== '') {
    $searchFields = ['name', 'name_en', 'description', 'description_en', 'route', 'link'];
    $searchParts = [];
    foreach ($searchFields as $i => $field) {
        $key = ':s' . $i;
        $searchParts[] = "{$field} LIKE {$key}";
        $params[$key] = '%' . $search . '%';
    }
    $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
}
if ($filterType !== '') {
    $conditions[] = 'type = :t';
    $params[':t'] = $filterType;
}
$dataFilterCondition = $dataFilter !== '' ? location_data_quality_conditions($dataFilter) : null;
if ($dataFilterCondition !== null) {
    $conditions[] = $dataFilterCondition;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM campus_locations $where");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / LOCATIONS_PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * LOCATIONS_PER_PAGE;

$stmt = $pdo->prepare("SELECT * FROM campus_locations $where ORDER BY sirala ASC, id ASC LIMIT " . LOCATIONS_PER_PAGE . " OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Üst kategori seçimi ve görüntüleme için tüm kayıtlar.
$allLocations = $pdo->query('SELECT id, name, type FROM campus_locations ORDER BY name ASC')->fetchAll();
$nameById = [];
foreach ($allLocations as $loc) {
    $nameById[(int) $loc['id']] = $loc['name'];
}

// Tür süzgeci / datalist için benzersiz türler.
$types = $pdo->query("SELECT DISTINCT type FROM campus_locations WHERE type <> '' ORDER BY type ASC")
             ->fetchAll(PDO::FETCH_COLUMN);

// GAÜN resmi kaynak eşleştirme indeksi (cache'ten; yoksa boş).
$gaunCache = gaun_sources_load();
$gaunIndex = gaun_build_index($gaunCache);
$dataQualityCounts = location_data_quality_counts($pdo);
$canManageLocations = auth_can('manage_locations');

$pageTitle = 'Konum Yönetimi';
include __DIR__ . '/layout_header.php';
?>

<style>
    .coordinate-picker-map {
        height: 280px;
        min-height: 240px;
        overflow: hidden;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        background: #f8f9fa;
    }

    @media (max-width: 575.98px) {
        .coordinate-picker-map {
            height: 220px;
            min-height: 220px;
        }
    }
</style>

<div class="card shadow">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap">
        <h4 class="mb-0">Kampüs Konum Yönetimi</h4>
        <form method="GET" class="form-inline mt-2 mt-md-0">
            <input type="text" name="search" class="form-control mr-2" placeholder="Ara..." value="<?= e($search) ?>">
            <select name="type" class="form-control mr-2">
                <option value="">-- Tüm Türler --</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="data_filter" class="form-control mr-2">
                <option value="">-- Eksik veri filtresi yok --</option>
                <option value="missing_coords" <?= $dataFilter === 'missing_coords' ? 'selected' : '' ?>>Koordinatı olmayanlar (<?= e((string) ($dataQualityCounts['missing_coords'] ?? 0)) ?>)</option>
                <option value="missing_en" <?= $dataFilter === 'missing_en' ? 'selected' : '' ?>>İngilizce adı olmayanlar (<?= e((string) ($dataQualityCounts['missing_en'] ?? 0)) ?>)</option>
                <option value="missing_image" <?= $dataFilter === 'missing_image' ? 'selected' : '' ?>>Görseli olmayanlar (<?= e((string) ($dataQualityCounts['missing_image'] ?? 0)) ?>)</option>
                <option value="missing_link" <?= $dataFilter === 'missing_link' ? 'selected' : '' ?>>Link/website olmayanlar (<?= e((string) ($dataQualityCounts['missing_link'] ?? 0)) ?>)</option>
                <option value="inactive" <?= $dataFilter === 'inactive' ? 'selected' : '' ?>>Pasif konumlar (<?= e((string) ($dataQualityCounts['inactive'] ?? 0)) ?>)</option>
            </select>
            <button type="submit" class="btn btn-light">Ara</button>
            <?php if ($search !== '' || $filterType !== '' || $dataFilter !== ''): ?>
                <a href="locations.php" class="btn btn-secondary ml-2">Temizle</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card-body">
        <?php if ($canManageLocations): ?>
        <div class="d-flex flex-wrap align-items-center mb-3">
            <button type="button" class="btn btn-success mr-2 mb-2" onclick="openCreate()">
                <i class="fas fa-plus"></i> Yeni Konum Ekle
            </button>
            <a href="locations.php?action=export_csv" class="btn btn-outline-primary mr-2 mb-2">
                <i class="fas fa-file-export"></i> CSV Dışa Aktar
            </a>
            <form method="POST" enctype="multipart/form-data" class="form-inline mb-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_csv">
                <div class="custom-file mr-2" style="width: 260px; max-width: 100%;">
                    <input type="file" name="csv_file" id="csv_file" class="custom-file-input" accept=".csv,text/csv" required>
                    <label class="custom-file-label" for="csv_file" data-default="CSV dosyası seç">CSV dosyası seç</label>
                </div>
                <button type="submit" class="btn btn-outline-success">
                    <i class="fas fa-file-import"></i> CSV İçe Aktar
                </button>
            </form>
        </div>

        <form method="POST" id="bulkForm" class="d-flex flex-wrap align-items-center mb-3 border rounded p-2 bg-light">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="bulkAction" value="">
            <span class="mr-2 mb-2 font-weight-bold" id="bulkSelectedCount">0 kayıt seçili</span>
            <select name="bulk_parent_id" id="bulkParentSelect" class="form-control form-control-sm mr-2 mb-2" style="width:auto;">
                <option value="">-- Üst kategori yok --</option>
                <?php foreach ($allLocations as $loc): ?>
                    <option value="<?= e((string) $loc['id']) ?>"><?= e($loc['name']) ?> (<?= e($loc['type']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-secondary btn-sm mr-2 mb-2" onclick="submitBulk('bulk_move_category')">
                <i class="fas fa-arrows-alt"></i> Kategoriye Taşı
            </button>
            <button type="button" class="btn btn-outline-success btn-sm mr-2 mb-2" onclick="submitBulk('bulk_activate')">
                <i class="fas fa-check"></i> Aktif Yap
            </button>
            <button type="button" class="btn btn-outline-warning btn-sm mr-2 mb-2" onclick="submitBulk('bulk_deactivate')">
                <i class="fas fa-ban"></i> Pasif Yap
            </button>
        </form>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead class="thead-dark">
                    <tr>
                        <?php if ($canManageLocations): ?>
                        <th style="width:32px;"><input type="checkbox" id="selectAllRows" title="Tümünü seç"></th>
                        <?php endif; ?>
                        <th>ID</th>
                        <th>Adı / EN</th>
                        <th>Tür</th>
                        <th>Üst Kategori</th>
                        <th>Koordinat</th>
                        <th>Renk</th>
                        <th>Durum</th>
                        <th title="GAÜN resmi kaynak eşleşmesi">GAÜN</th>
                        <?php if ($canManageLocations): ?>
                        <th style="width:140px;">İşlemler</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $parentName = $row['parent_id'] !== null
                                ? ($nameById[(int) $row['parent_id']] ?? '—')
                                : '—';
                            $coords = ($row['latitude'] !== null && $row['longitude'] !== null)
                                ? $row['latitude'] . ', ' . $row['longitude']
                                : '—';
                            $gaun = gaun_match($gaunIndex, (string) $row['name'], $row['link'] ?? null);
                            $isActive = (int) ($row['active'] ?? 1) === 1;
                        ?>
                        <tr class="<?= $isActive ? '' : 'text-muted' ?>">
                            <?php if ($canManageLocations): ?>
                            <td><input type="checkbox" class="row-select" value="<?= e((string) $row['id']) ?>"></td>
                            <?php endif; ?>
                            <td><?= e((string) $row['id']) ?></td>
                            <td>
                                <?= e($row['name']) ?>
                                <?php if (!empty($row['name_en'])): ?>
                                    <br><small class="text-muted"><?= e($row['name_en']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= e($row['type']) ?></td>
                            <td><?= e($parentName) ?></td>
                            <td><small><?= e($coords) ?></small></td>
                            <td>
                                <span style="display:inline-block;width:16px;height:16px;border:1px solid #ccc;vertical-align:middle;background:<?= e($row['color']) ?>;"></span>
                                <small><?= e($row['color']) ?></small>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge badge-success">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Pasif</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($gaun !== null): ?>
                                    <a href="<?= e($gaun['website']) ?>" target="_blank" rel="noopener"
                                       class="badge badge-success"
                                       title="Resmi kaynak eşleşti (<?= e($gaun['matched_by'] === 'name' ? 'isim' : 'web sitesi') ?>)
Kaynak: <?= e((string) $gaun['source_url']) ?>
Güncelleme: <?= e((string) $gaun['fetched_at']) ?>">✓ resmi</a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canManageLocations): ?>
                            <td>
                                <button type="button" class="btn btn-warning btn-sm"
                                        onclick='openEdit(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)'>
                                    Düzenle
                                </button>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Bu kaydı silmek istediğinize emin misiniz?\nAlt kayıtlar da silinecektir.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Sil</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center text-danger">Kayıt bulunamadı.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav aria-label="Sayfalama">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php
                    $pageQuery = static function (int $p) use ($search, $filterType, $dataFilter): string {
                        return '?' . http_build_query(['search' => $search, 'type' => $filterType, 'data_filter' => $dataFilter, 'page' => $p]);
                    };
                ?>
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= e($pageQuery($p)) ?>"><?= e((string) $p) ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
            <p class="text-center text-muted small mt-2 mb-0">Toplam <?= e((string) $totalCount) ?> kayıt, sayfa <?= e((string) $page) ?>/<?= e((string) $totalPages) ?></p>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Ekle / Düzenle Modalı -->
<div class="modal fade" id="locationModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="f_id">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle">Yeni Konum</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>

                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Türkçe Ad *</label>
                            <input type="text" name="name" id="f_name" class="form-control" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>İngilizce Ad</label>
                            <input type="text" name="name_en" id="f_name_en" class="form-control" autocomplete="off">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Tür *</label>
                            <input type="text" name="type" id="f_type" class="form-control" list="typeList" required>
                            <datalist id="typeList">
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= e($t) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="form-group col-md-8">
                            <label>Üst Kategori</label>
                            <select name="parent_id" id="f_parent_id" class="form-control">
                                <option value="">-- Yok --</option>
                                <?php foreach ($allLocations as $loc): ?>
                                    <option value="<?= e((string) $loc['id']) ?>">
                                        <?= e($loc['name']) ?> (<?= e($loc['type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>Latitude</label>
                            <input type="number" step="0.0000001" min="-90" max="90" inputmode="decimal" name="latitude" id="f_latitude" class="form-control">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Longitude</label>
                            <input type="number" step="0.0000001" min="-180" max="180" inputmode="decimal" name="longitude" id="f_longitude" class="form-control">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Renk</label>
                            <input type="text" name="color" id="f_color" class="form-control" placeholder="red veya #ff0000">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Puan (0-5)</label>
                            <input type="number" step="0.1" max="5" name="rating" id="f_rating" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>Sıralama</label>
                            <input type="number" step="1" name="sirala" id="f_sirala" class="form-control" placeholder="otomatik">
                            <small class="form-text text-muted">Boş bırakılırsa değişmez.</small>
                        </div>
                        <div class="form-group col-md-9 d-flex align-items-end">
                            <div class="custom-control custom-switch mb-2">
                                <input type="checkbox" class="custom-control-input" id="f_active" name="active" value="1" checked>
                                <label class="custom-control-label" for="f_active">Aktif (public haritada görünür)</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Haritadan Koordinat Seç</label>
                        <div id="coordinateMap" class="coordinate-picker-map"></div>
                        <small class="form-text text-muted">Haritaya tıklayınca latitude/longitude alanları otomatik dolar.</small>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Link</label>
                            <input type="text" name="link" id="f_link" class="form-control">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Rota (Route)</label>
                            <input type="text" name="route" id="f_route" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-8">
                            <label>Adres</label>
                            <input type="text" name="address" id="f_address" class="form-control">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Telefon</label>
                            <input type="text" name="phone" id="f_phone" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>Kapasite</label>
                            <input type="number" name="capacity" id="f_capacity" class="form-control">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Oda Sayısı</label>
                            <input type="number" name="rooms" id="f_rooms" class="form-control">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Çalışma Saatleri</label>
                            <input type="text" name="opening_hours" id="f_opening_hours" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Yönetici Adı</label>
                            <input type="text" name="manager_name" id="f_manager_name" class="form-control">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Yönetici E-posta</label>
                            <input type="email" name="manager_email" id="f_manager_email" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Görsel URL</label>
                        <input type="text" name="image_url" id="f_image_url" class="form-control">
                        <small class="form-text text-muted">Dış URL kullanabilir veya aşağıdan dosya yükleyebilirsiniz.</small>
                    </div>

                    <div class="form-group">
                        <label>Görsel Yükle</label>
                        <input type="file" name="image_file" id="f_image_file" class="form-control-file" accept="image/jpeg,image/png,image/webp">
                        <small class="form-text text-muted">JPG, PNG veya WebP; en fazla 4 MB. Yüklenen dosya Görsel URL alanının yerine geçer.</small>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Türkçe Açıklama</label>
                            <textarea name="description" id="f_description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-group col-md-6">
                            <label>İngilizce Açıklama</label>
                            <textarea name="description_en" id="f_description_en" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Olanaklar (Facilities)</label>
                            <textarea name="facilities" id="f_facilities" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Donanımlar (Amenities)</label>
                            <textarea name="amenities" id="f_amenities" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Kaydet</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Kapat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script>
    const LOCATION_FIELDS = [
        'name', 'name_en', 'type', 'parent_id', 'latitude', 'longitude', 'color', 'rating',
        'link', 'route', 'address', 'phone', 'capacity', 'rooms', 'opening_hours',
        'manager_name', 'manager_email', 'image_url', 'description', 'description_en', 'facilities', 'amenities',
        'sirala'
    ];
    const CAMPUS_CENTER = [37.0375, 37.3135];
    let coordinateMap = null;
    let coordinateMarker = null;

    function readCoordinateInputs() {
        const lat = parseFloat(document.getElementById('f_latitude').value);
        const lng = parseFloat(document.getElementById('f_longitude').value);
        return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
    }

    function setCoordinates(lat, lng, pan = true) {
        document.getElementById('f_latitude').value = Number(lat).toFixed(7);
        document.getElementById('f_longitude').value = Number(lng).toFixed(7);
        if (!coordinateMap) return;
        const point = [lat, lng];
        if (!coordinateMarker) {
            coordinateMarker = L.marker(point, { draggable: true }).addTo(coordinateMap);
            coordinateMarker.on('dragend', () => {
                const pos = coordinateMarker.getLatLng();
                setCoordinates(pos.lat, pos.lng, false);
            });
        } else {
            coordinateMarker.setLatLng(point);
        }
        if (pan) coordinateMap.setView(point, Math.max(coordinateMap.getZoom(), 17));
    }

    function clearCoordinateMarker(resetView = false) {
        if (coordinateMap && coordinateMarker) {
            coordinateMap.removeLayer(coordinateMarker);
            coordinateMarker = null;
        }
        if (resetView && coordinateMap) {
            coordinateMap.setView(CAMPUS_CENTER, 16);
        }
    }

    function syncMarkerFromInputs() {
        const coords = readCoordinateInputs();
        if (coords) {
            setCoordinates(coords[0], coords[1]);
            return;
        }

        const latValue = document.getElementById('f_latitude').value.trim();
        const lngValue = document.getElementById('f_longitude').value.trim();
        if (latValue === '' && lngValue === '') {
            clearCoordinateMarker(true);
        }
    }

    function ensureCoordinateMap() {
        if (!coordinateMap) {
            coordinateMap = L.map('coordinateMap').setView(CAMPUS_CENTER, 16);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(coordinateMap);
            coordinateMap.on('click', e => setCoordinates(e.latlng.lat, e.latlng.lng));
        }

        setTimeout(() => {
            coordinateMap.invalidateSize();
            const coords = readCoordinateInputs();
            if (coords) {
                setCoordinates(coords[0], coords[1]);
            } else {
                clearCoordinateMarker(true);
            }
        }, 250);
    }

    function resetForm() {
        document.getElementById('f_id').value = '';
        LOCATION_FIELDS.forEach(f => {
            const el = document.getElementById('f_' + f);
            if (el) el.value = '';
        });
        const imageFile = document.getElementById('f_image_file');
        if (imageFile) imageFile.value = '';
        document.getElementById('f_active').checked = true;
    }

    function openCreate() {
        resetForm();
        document.getElementById('modalTitle').textContent = 'Yeni Konum';
        document.getElementById('f_color').value = 'red';
        $('#locationModal').modal('show');
        ensureCoordinateMap();
    }

    function openEdit(data) {
        resetForm();
        document.getElementById('modalTitle').textContent = 'Kayıt Düzenle';
        document.getElementById('f_id').value = data.id ?? '';
        LOCATION_FIELDS.forEach(f => {
            const el = document.getElementById('f_' + f);
            if (el && data[f] !== null && data[f] !== undefined) el.value = data[f];
        });
        document.getElementById('f_active').checked = Number(data.active ?? 1) === 1;
        $('#locationModal').modal('show');
        ensureCoordinateMap();
    }

    // ---- Toplu işlem (bulk) çubuğu ----
    function updateBulkSelectedCount() {
        const count = document.querySelectorAll('.row-select:checked').length;
        const label = document.getElementById('bulkSelectedCount');
        if (label) label.textContent = count + ' kayıt seçili';
    }

    function submitBulk(action) {
        const ids = Array.from(document.querySelectorAll('.row-select:checked')).map(el => el.value);
        if (ids.length === 0) {
            alert('Önce en az bir kayıt seçin.');
            return;
        }
        if (action === 'bulk_deactivate' && !confirm(ids.length + ' kayıt pasif yapılacak. Onaylıyor musunuz?')) {
            return;
        }
        const form = document.getElementById('bulkForm');
        document.getElementById('bulkAction').value = action;
        form.querySelectorAll('input[name="ids[]"]').forEach(el => el.remove());
        ids.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = id;
            form.appendChild(input);
        });
        form.submit();
    }

    document.addEventListener('DOMContentLoaded', () => {
        ['f_latitude', 'f_longitude'].forEach(id => {
            const input = document.getElementById(id);
            if (!input) return;
            input.addEventListener('input', syncMarkerFromInputs);
            input.addEventListener('change', syncMarkerFromInputs);
        });

        const csvInput = document.getElementById('csv_file');
        if (csvInput) {
            csvInput.addEventListener('change', () => {
                const label = document.querySelector('label[for="csv_file"]');
                if (!label) return;
                label.textContent = csvInput.files && csvInput.files[0]
                    ? csvInput.files[0].name
                    : (label.dataset.default || 'CSV dosyası seç');
            });
        }

        const selectAll = document.getElementById('selectAllRows');
        if (selectAll) {
            selectAll.addEventListener('change', () => {
                document.querySelectorAll('.row-select').forEach(el => { el.checked = selectAll.checked; });
                updateBulkSelectedCount();
            });
        }
        document.querySelectorAll('.row-select').forEach(el => {
            el.addEventListener('change', updateBulkSelectedCount);
        });
    });
</script>

<?php include __DIR__ . '/layout_footer.php'; ?>

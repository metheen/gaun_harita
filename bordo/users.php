<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/../auth_roles.php';
require __DIR__ . '/../audit_log.php';

auth_require_role('super_admin');

const USERS_USERNAME_MAX_LENGTH = 50;
const USERS_PASSWORD_MIN_BYTES = 8;

$pageTitle = 'Kullanıcılar';
$pdo = db('write');
auth_roles_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create_user') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role     = (string) ($_POST['role'] ?? 'viewer');
            $usernameLength = function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username);

            if ($username === '' || $usernameLength > USERS_USERNAME_MAX_LENGTH || strlen($password) < USERS_PASSWORD_MIN_BYTES || !in_array($role, USER_ROLES, true)) {
                flash('Kullanıcı adı en fazla 50 karakter olmalı; en az 8 karakterli şifre ve geçerli bir rol gerekli.', 'danger');
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
                audit_log_action($pdo, 'user.create', 'users', (string) $pdo->lastInsertId(), "username={$username};role={$role}");
                flash('Kullanıcı oluşturuldu.');
            }
        } elseif ($action === 'reset_password') {
            $userId   = (int) ($_POST['user_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            $lookup = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $lookup->execute([$userId]);
            $targetUsername = $lookup->fetchColumn() ?: null;

            if ($userId <= 0 || $targetUsername === null || strlen($password) < USERS_PASSWORD_MIN_BYTES) {
                flash('Geçerli bir kullanıcı ve en az 8 karakterli şifre gerekli.', 'danger');
            } else {
                $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
                audit_log_action($pdo, 'user.reset_password', 'users', (string) $userId);
                flash('Şifre güncellendi.');
            }
        } elseif ($action === 'update_role') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $role   = (string) ($_POST['role'] ?? '');

            $targetUsername = null;
            if ($userId > 0) {
                $lookup = $pdo->prepare('SELECT username FROM users WHERE id = ?');
                $lookup->execute([$userId]);
                $targetUsername = $lookup->fetchColumn() ?: null;
            }

            if ($userId <= 0 || $targetUsername === null || !in_array($role, USER_ROLES, true)) {
                flash('Geçerli bir kullanıcı ve rol gerekli.', 'danger');
            } elseif ($targetUsername !== null && $targetUsername === ($_SESSION['username'] ?? null)) {
                flash('Kendi rolünüzü buradan değiştiremezsiniz.', 'danger');
            } else {
                $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
                $stmt->execute([$role, $userId]);
                audit_log_action($pdo, 'user.update_role', 'users', (string) $userId, "role={$role}");
                flash('Rol güncellendi.');
            }
        }
    } catch (Throwable $e) {
        flash('İşlem tamamlanamadı: kullanıcı adı zaten kayıtlı olabilir.', 'danger');
    }

    redirect('users.php');
}

$users = $pdo->query('SELECT id, username, role, created_at FROM users ORDER BY id ASC')->fetchAll();

function users_time(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date('d.m.Y H:i', $time) : $value;
}

include __DIR__ . '/layout_header.php';
?>

<div class="content-header px-0 pt-0">
    <div class="container-fluid px-0">
        <h1 class="m-0">Kullanıcılar</h1>
        <p class="text-muted mb-0">
            Yönetim paneline giriş yapabilecek hesapları ve rollerini yönetin.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Kullanıcı Listesi</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Kullanıcı Adı</th>
                                <th>Rol</th>
                                <th>Oluşturulma</th>
                                <th class="text-right">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= e((string) $user['username']) ?></td>
                                <td>
                                    <form method="POST" class="form-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_role">
                                        <input type="hidden" name="user_id" value="<?= e((string) $user['id']) ?>">
                                        <select name="role" class="form-control form-control-sm mr-1" onchange="this.form.submit()" <?= $user['username'] === ($_SESSION['username'] ?? null) ? 'disabled' : '' ?>>
                                            <?php foreach (USER_ROLES as $roleOption): ?>
                                                <option value="<?= e($roleOption) ?>" <?= $user['role'] === $roleOption ? 'selected' : '' ?>><?= e($roleOption) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td><?= e(users_time((string) $user['created_at'])) ?></td>
                                <td class="text-right">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="modal" data-target="#resetPasswordModal" onclick="document.getElementById('resetUserId').value='<?= e((string) $user['id']) ?>'; document.getElementById('resetUserLabel').textContent='<?= e((string) $user['username']) ?>';">
                                        <i class="fas fa-key"></i> Şifre Sıfırla
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Yeni Kullanıcı</h3>
            </div>
            <form method="POST">
                <div class="card-body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_user">
                    <div class="form-group">
                        <label for="new_username">Kullanıcı Adı</label>
                        <input type="text" id="new_username" name="username" class="form-control" maxlength="<?= USERS_USERNAME_MAX_LENGTH ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="new_password">Şifre</label>
                        <input type="password" id="new_password" name="password" class="form-control" minlength="<?= USERS_PASSWORD_MIN_BYTES ?>" required>
                    </div>
                    <div class="form-group mb-0">
                        <label for="new_role">Rol</label>
                        <select id="new_role" name="role" class="form-control">
                            <?php foreach (USER_ROLES as $roleOption): ?>
                                <option value="<?= e($roleOption) ?>"><?= e($roleOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Oluştur
                    </button>
                </div>
            </form>
        </div>

        <div class="callout callout-info">
            <h5>Roller</h5>
            <p class="mb-0">
                <strong>super_admin</strong>: tüm işlemler. <strong>editor</strong>: konum
                ekleme/düzenleme/silme. <strong>viewer</strong>: yalnızca görüntüleme.
            </p>
        </div>
    </div>
</div>

<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" id="resetUserId" name="user_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Şifre Sıfırla: <span id="resetUserLabel"></span></h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-0">
                        <label for="reset_password_input">Yeni Şifre</label>
                        <input type="password" id="reset_password_input" name="password" class="form-control" minlength="<?= USERS_PASSWORD_MIN_BYTES ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Vazgeç</button>
                    <button class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/layout_footer.php'; ?>

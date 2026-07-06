<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/throttle.php';
require __DIR__ . '/../admin_ops.php';
require __DIR__ . '/../auth_roles.php';

const LOGIN_USERNAME_MAX_LENGTH = 50;
const LOGIN_PASSWORD_MAX_BYTES = 1024;
const LOGIN_DUMMY_PASSWORD_HASH = '$2y$10$tHkohPObKeNrUeMzhtjmn.CpHzsetR5kpdHWZ8xE8gqNhNmNJIrbW';

function login_is_test_automation(): bool
{
    $flag = strtolower(trim((string) ($_SERVER['HTTP_X_GAUN_TEST_AUTOMATION'] ?? '')));
    return getenv('APP_DEBUG') === 'true' && ($flag === '1' || $flag === 'true');
}

// Zaten giriş yapılmışsa panele yönlendir.
if (!empty($_SESSION['login'])) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Brute-force koruması: bu IP kilitliyse denemeyi hiç işleme.
    $skipThrottle = login_is_test_automation();
    $lockedFor = $skipThrottle ? 0 : throttle_locked_for();
    if ($lockedFor > 0) {
        admin_ops_log_login_event(db('write'), 'locked', $username, 'rate_limited');
        $minutes = (int) ceil($lockedFor / 60);
        $error = "Çok fazla başarısız deneme. Lütfen {$minutes} dakika sonra tekrar deneyin.";
    } else {
        $pdo = db('write');
        auth_roles_ensure_schema($pdo);

        $usernameLength = function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username);
        $inputShapeOk = $username !== ''
            && $usernameLength <= LOGIN_USERNAME_MAX_LENGTH
            && strlen($password) <= LOGIN_PASSWORD_MAX_BYTES;

        $user = false;
        $passwordOk = false;
        if ($inputShapeOk) {
            $stmt = $pdo->prepare('SELECT id, username, password, role FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            $hash = is_array($user) ? (string) $user['password'] : LOGIN_DUMMY_PASSWORD_HASH;
            $passwordOk = password_verify($password, $hash);
        } else {
            password_verify($password, LOGIN_DUMMY_PASSWORD_HASH);
        }

        if (is_array($user) && $passwordOk) {
            if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
            }
            if (!$skipThrottle) {
                throttle_clear();
            }
            admin_ops_log_login_event($pdo, 'success', $user['username'], null);
            session_regenerate_id(true);
            $_SESSION['login']    = true;
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = in_array($user['role'] ?? null, USER_ROLES, true) ? $user['role'] : 'super_admin';
            $_SESSION['last_activity'] = time();
            redirect('dashboard.php');
        }

        // Başarısız denemeyi kaydet (eşiğe ulaşınca IP kilitlenir).
        if (!$skipThrottle) {
            throttle_register_failure();
        }
        admin_ops_log_login_event(db('write'), 'failure', $username, 'invalid_credentials');
        usleep(random_int(150000, 300000));

        // Kullanıcı adı ve şifre hatalarını ayırt etmeyerek kullanıcı sızıntısını önle.
        $error = 'Kullanıcı adı veya şifre hatalı.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yönetim Paneli Girişi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow">
                <div class="card-header bg-primary text-white text-center">
                    <h4 class="mb-0">Yönetim Paneli Girişi</h4>
                </div>
                <div class="card-body">
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label>Kullanıcı Adı</label>
                            <input type="text" name="username" class="form-control" maxlength="<?= LOGIN_USERNAME_MAX_LENGTH ?>" autocomplete="username" required autofocus>
                        </div>
                        <div class="form-group">
                            <label>Şifre</label>
                            <input type="password" name="password" class="form-control" maxlength="<?= LOGIN_PASSWORD_MAX_BYTES ?>" autocomplete="current-password" required>
                        </div>
                        <button class="btn btn-primary btn-block">Giriş Yap</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

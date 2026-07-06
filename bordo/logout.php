<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../admin_ops.php';

$username = isset($_SESSION['username']) ? (string) $_SESSION['username'] : null;
if ($username !== null && $username !== '') {
    admin_ops_log_login_event(db('write'), 'logout', $username, null);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $p['path'],
        'domain' => $p['domain'],
        'secure' => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}

session_destroy();
redirect('index.php');

<?php
declare(strict_types=1);

/** Korumalı sayfalar için oturum kontrolü. Giriş yoksa login'e yönlendirir. */

require_once __DIR__ . '/bootstrap.php';

if (empty($_SESSION['login'])) {
    redirect('index.php');
}

const ADMIN_SESSION_IDLE_TIMEOUT = 7200; // 2 saat

$now = time();
$lastActivity = (int) ($_SESSION['last_activity'] ?? $now);
if ($now - $lastActivity > ADMIN_SESSION_IDLE_TIMEOUT) {
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
}

$_SESSION['last_activity'] = $now;

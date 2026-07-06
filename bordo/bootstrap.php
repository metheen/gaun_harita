<?php
declare(strict_types=1);

/**
 * Yönetim paneli önyükleyicisi.
 * Her giriş noktası (login, logout, korumalı sayfalar) bunu dahil eder.
 */

require __DIR__ . '/../security_headers.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/csrf.php';

$config = require __DIR__ . '/../config.php';

if (!$config['app']['debug']) {
    ini_set('display_errors', '0');
    error_reporting(0);
}

security_headers_apply(true);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    // HTTPS altında mı? (doğrudan veya ters proxy başlığıyla)
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    // Oturum çerezini sertleştir: JS erişemez (httponly), CSRF için SameSite,
    // ve HTTPS'te yalnızca güvenli bağlantıda gönderilir (secure).
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);

    session_start();
}

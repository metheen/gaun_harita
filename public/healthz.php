<?php
declare(strict_types=1);

require_once __DIR__ . '/../security_headers.php';
security_headers_apply();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

echo 'ok';

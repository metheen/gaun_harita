<?php
declare(strict_types=1);

/**
 * Ortak HTTP güvenlik başlıkları.
 *
 * CSP burada özellikle dar tutuldu: mevcut arayüz CDN ve inline script/event
 * kullanıyor. Bu yüzden script-src gibi kırıcı direktifler yerine, yüksek
 * getirili ve düşük riskli tarayıcı korumaları uygulanır.
 */
function security_headers_apply(bool $admin = false): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), payment=(), usb=(), geolocation=(self)');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");

    if ($admin) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

<?php
declare(strict_types=1);

/** Oturuma bağlı CSRF jetonunu döndürür (yoksa üretir). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Formlara gömülecek gizli CSRF alanını üretir. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Gönderilen CSRF jetonunu doğrular; geçersizse isteği reddeder. */
function csrf_verify(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        // http_response_code(419) KULLANILMAZ: 419 IANA'da kayıtlı bir HTTP
        // durum kodu olmadığından, Apache+mod_php altında (bu projenin
        // çalıştığı ortam) durum satırını sessizce 500 Internal Server
        // Error'a düşürüyor — CSRF reddini gerçek bir sunucu hatasıymış gibi
        // gösteriyor. Durum satırını doğrudan yazmak bunu düzeltir (bkz.
        // tests/e2e/admin.spec.js "CSRF jetonu olmadan..." testi).
        header('HTTP/1.1 419 Page Expired');
        exit('Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
    }
}

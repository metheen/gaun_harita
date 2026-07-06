<?php
declare(strict_types=1);

/**
 * Admin panel rol tabanlı yetkilendirme (super_admin / editor / viewer).
 *
 * Var olan bordo/auth.php'yi DEĞİŞTİRMEZ (o hâlâ sadece "giriş yapılmış mı"
 * kontrolü yapar). Bu dosya, ihtiyaç duyan sayfaların/aksiyonların EK olarak
 * çağırdığı ikinci bir katmandır — böylece rol kontrolü henüz eklenmemiş
 * mevcut sayfalar bozulmaz, yeni/duyarlı sayfalar tek satırla korunur.
 *
 * Var olan tek admin hesabı migration ile 'super_admin' varsayılanı aldığı
 * için (bkz. docker/init/07-user-roles.sql) bu dosya devreye girdiğinde
 * kimse dışarıda kalmaz.
 */

const USER_ROLES = ['super_admin', 'editor', 'viewer'];

/** roles tablosu/kolonunun var olduğundan emin olur (idempotent). */
function auth_roles_ensure_schema(PDO $pdo): void
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (isset($ready[$key])) {
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
    );
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'super_admin'");
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'created_at'"
    );
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    $ready[$key] = true;
}

/** Geçerli oturumun rolü. Oturumda yoksa (eski oturum) en kısıtlı varsayılana düşer. */
function auth_current_role(): string
{
    $role = (string) ($_SESSION['role'] ?? '');
    return in_array($role, USER_ROLES, true) ? $role : 'viewer';
}

/**
 * Geçerli rol izin verilenler arasında değilse dashboard'a flash mesajıyla
 * yönlendirir (sayfayı fatal etmez). Sayfaların/aksiyonların en üstünde,
 * bordo/auth.php'den SONRA çağrılır.
 */
function auth_require_role(string ...$allowed): void
{
    if (in_array(auth_current_role(), $allowed, true)) {
        return;
    }
    flash('Bu işlem için yetkiniz yok.', 'danger');
    redirect('dashboard.php');
}

/**
 * View katmanında buton/bağlantı gizlemek için basit bir yetenek eşlemesi.
 * Sunucu tarafı gerçek koruma HER ZAMAN auth_require_role() ile yapılır;
 * bu yalnızca arayüzü rolle tutarlı göstermek içindir.
 */
function auth_can(string $capability): bool
{
    $role = auth_current_role();
    return match ($capability) {
        'manage_locations' => in_array($role, ['super_admin', 'editor'], true),
        'manage_settings', 'manage_users', 'manage_security' => $role === 'super_admin',
        default => false,
    };
}

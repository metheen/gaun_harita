<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_roles.php';

final class UserRolesIntegrationTest extends TestCase
{
    private PDO $pdo;
    private string $prefix;

    /** @var int[] */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = db('write');
        auth_roles_ensure_schema($this->pdo);
        $this->prefix = '_PHPINT_ROLES_' . bin2hex(random_bytes(4)) . '_';
    }

    protected function tearDown(): void
    {
        if ($this->createdIds) {
            $placeholders = implode(',', array_fill(0, count($this->createdIds), '?'));
            $this->pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})")
                ->execute($this->createdIds);
        }
        $this->pdo->prepare('DELETE FROM users WHERE username LIKE ?')->execute([$this->prefix . '%']);
        unset($_SESSION['role']);
        parent::tearDown();
    }

    public function testExistingAdminAccountBecameSuperAdminAfterMigration(): void
    {
        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE username = 'admin'");
        $stmt->execute();
        $role = $stmt->fetchColumn();

        if ($role === false) {
            $this->markTestSkipped('Bu ortamda "admin" kullanıcısı yok.');
        }

        $this->assertSame('super_admin', $role);
    }

    public function testNewUserCanBeCreatedWithRoleAndHashedPassword(): void
    {
        $username = $this->prefix . 'editor_user';
        $stmt = $this->pdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
        $stmt->execute([$username, password_hash('a-strong-password', PASSWORD_DEFAULT), 'editor']);
        $this->createdIds[] = (int) $this->pdo->lastInsertId();

        $check = $this->pdo->prepare('SELECT password, role, created_at FROM users WHERE username = ?');
        $check->execute([$username]);
        $row = $check->fetch();

        $this->assertNotFalse($row);
        $this->assertTrue(password_verify('a-strong-password', $row['password']));
        $this->assertSame('editor', $row['role']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function testAuthCurrentRoleAndCanReflectSessionRole(): void
    {
        $_SESSION['role'] = 'editor';
        $this->assertSame('editor', auth_current_role());
        $this->assertTrue(auth_can('manage_locations'));
        $this->assertFalse(auth_can('manage_users'));

        $_SESSION['role'] = 'viewer';
        $this->assertFalse(auth_can('manage_locations'));

        unset($_SESSION['role']);
        $this->assertSame('viewer', auth_current_role());
    }
}

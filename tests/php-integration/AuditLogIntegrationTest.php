<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../audit_log.php';

final class AuditLogIntegrationTest extends TestCase
{
    private PDO $pdo;
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = db('write');
        audit_ensure_schema($this->pdo);
        $this->prefix = '_PHPINT_AUDIT_' . bin2hex(random_bytes(4)) . '_';
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM audit_log WHERE action LIKE ? OR summary LIKE ?')
            ->execute([$this->prefix . '%', $this->prefix . '%']);
        parent::tearDown();
    }

    public function testAuditLogActionPersistsMaskedIpAndActorFromSession(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
        $_SESSION['username'] = $this->prefix . 'admin';

        audit_log_action(
            $this->pdo,
            $this->prefix . 'settings.update',
            'app_settings',
            null,
            $this->prefix . 'summary'
        );

        $stmt = $this->pdo->prepare('SELECT actor_username, action, entity_type, summary, ip_display FROM audit_log WHERE action = ?');
        $stmt->execute([$this->prefix . 'settings.update']);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame($this->prefix . 'admin', $row['actor_username']);
        $this->assertSame('app_settings', $row['entity_type']);
        $this->assertSame($this->prefix . 'summary', $row['summary']);
        $this->assertSame('10.20.30.x', $row['ip_display']);
    }

    public function testAuditRecentReturnsNewestEntriesFirst(): void
    {
        audit_log_action($this->pdo, $this->prefix . 'action_one');
        audit_log_action($this->pdo, $this->prefix . 'action_two');

        $recent = audit_recent($this->pdo, 5);
        $actions = array_column($recent, 'action');
        $posOne = array_search($this->prefix . 'action_one', $actions, true);
        $posTwo = array_search($this->prefix . 'action_two', $actions, true);

        $this->assertNotFalse($posOne);
        $this->assertNotFalse($posTwo);
        $this->assertLessThan($posOne, $posTwo);
    }

    public function testAuditRecentCanExcludeAutomationEntriesForPanelViews(): void
    {
        $_SESSION['username'] = $this->prefix . 'admin';

        audit_log_action($this->pdo, $this->prefix . 'panel_hidden', 'app_settings', null, $this->prefix . 'summary');

        $rawRecent = audit_recent($this->pdo, 5, true);
        $panelRecent = audit_recent($this->pdo, 5, false);

        $this->assertContains($this->prefix . 'panel_hidden', array_column($rawRecent, 'action'));
        $this->assertNotContains($this->prefix . 'panel_hidden', array_column($panelRecent, 'action'));
    }
}

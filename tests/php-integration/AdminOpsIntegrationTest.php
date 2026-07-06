<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../admin_ops.php';

final class AdminOpsIntegrationTest extends TestCase
{
    private PDO $pdo;
    private string $prefix;
    private array $previousStatus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = db('write');
        admin_ops_ensure_schema($this->pdo);
        $this->previousStatus = admin_ops_site_status($this->pdo, true);
        $this->prefix = '_PHPINT_ADMIN_OPS_' . bin2hex(random_bytes(4)) . '_';
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM admin_login_events WHERE username LIKE ? OR reason LIKE ?')
            ->execute([$this->prefix . '%', $this->prefix . '%']);
        admin_ops_update_site_status(
            $this->pdo,
            (bool) ($this->previousStatus['enabled'] ?? true),
            (string) ($this->previousStatus['message'] ?? 'GAÜN Kampüs Haritası kısa süreli bakımda. Lütfen daha sonra tekrar deneyin.'),
            (string) ($this->previousStatus['updated_by'] ?? 'phpunit')
        );
        parent::tearDown();
    }

    public function testSiteStatusCanBeUpdatedAndReadFromRealDatabase(): void
    {
        $message = $this->prefix . 'Bakım mesajı';

        admin_ops_update_site_status($this->pdo, false, $message, $this->prefix . 'admin');
        $status = admin_ops_site_status($this->pdo, true);

        $this->assertFalse($status['enabled']);
        $this->assertSame($message, $status['message']);
        $this->assertSame($this->prefix . 'admin', $status['updated_by']);
        $this->assertNotEmpty($status['updated_at']);
    }

    public function testLoginEventsAreStoredWithMaskedIpAndSummaryCounts(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
        $_SERVER['HTTP_USER_AGENT'] = $this->prefix . 'Browser';

        admin_ops_log_login_event($this->pdo, 'success', $this->prefix . 'admin', null);
        admin_ops_log_login_event($this->pdo, 'failure', $this->prefix . 'admin', $this->prefix . 'invalid_credentials');

        $stmt = $this->pdo->prepare('SELECT status, ip_display FROM admin_login_events WHERE username = ? ORDER BY id DESC');
        $stmt->execute([$this->prefix . 'admin']);
        $events = $stmt->fetchAll();

        $this->assertCount(2, $events);
        $this->assertContains('10.20.30.x', array_column($events, 'ip_display'));
        $this->assertContains('success', array_column($events, 'status'));
        $this->assertContains('failure', array_column($events, 'status'));

        $summary = admin_ops_login_summary($this->pdo);
        $this->assertGreaterThanOrEqual(1, $summary['success_24h']);
        $this->assertGreaterThanOrEqual(1, $summary['failure_24h']);
    }

    public function testPanelLoginSummaryCanExcludeAutomationEvents(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        admin_ops_log_login_event($this->pdo, 'success', $this->prefix . 'admin', null);

        $rawSummary = admin_ops_login_summary($this->pdo, true);
        $panelSummary = admin_ops_login_summary($this->pdo, false);
        $panelEvents = admin_ops_recent_login_events($this->pdo, 5, false);

        $this->assertGreaterThanOrEqual(1, $rawSummary['success_24h']);
        $this->assertGreaterThan($panelSummary['success_24h'], $rawSummary['success_24h']);
        $this->assertNotContains($this->prefix . 'admin', array_column($panelEvents, 'username'));
    }
}

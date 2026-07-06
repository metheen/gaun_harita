<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../admin_ops.php';

final class AdminOpsTest extends TestCase
{
    public function testMaskIpMasksIpv4LastOctet(): void
    {
        $this->assertSame('192.168.1.x', admin_ops_mask_ip('192.168.1.44'));
    }

    public function testMaskIpMasksIpv6Prefix(): void
    {
        $this->assertSame('2001:db8:85a3:0::', admin_ops_mask_ip('2001:db8:85a3:0:0:8a2e:370:7334'));
    }

    public function testMaskIpRejectsInvalidValue(): void
    {
        $this->assertSame('unknown', admin_ops_mask_ip('not an ip'));
        $this->assertSame('unknown', admin_ops_mask_ip(''));
    }

    public function testTextNormalizesWhitespaceAndLimitsLength(): void
    {
        $this->assertSame('Merhaba dünya', admin_ops_text("  Merhaba\n  dünya  ", 100));
        $this->assertSame('abc', admin_ops_text('abcdef', 3));
        $this->assertNull(admin_ops_text('   ', 10));
    }
}

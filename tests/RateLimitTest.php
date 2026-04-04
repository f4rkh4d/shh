<?php
declare(strict_types=1);

namespace Shh\Tests;

use PHPUnit\Framework\TestCase;
use Shh\RateLimit;

final class RateLimitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shh_rl_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testAllowsUpToMax(): void
    {
        $rl = new RateLimit($this->dir, 3, 60);
        $this->assertTrue($rl->allow('1.2.3.4'));
        $this->assertTrue($rl->allow('1.2.3.4'));
        $this->assertTrue($rl->allow('1.2.3.4'));
    }

    public function testBlocksOverMax(): void
    {
        $rl = new RateLimit($this->dir, 2, 60);
        $rl->allow('5.6.7.8');
        $rl->allow('5.6.7.8');
        $this->assertFalse($rl->allow('5.6.7.8'));
    }

    public function testSeparateIpsHaveSeparateBudgets(): void
    {
        $rl = new RateLimit($this->dir, 1, 60);
        $this->assertTrue($rl->allow('10.0.0.1'));
        $this->assertTrue($rl->allow('10.0.0.2'));
        $this->assertFalse($rl->allow('10.0.0.1'));
    }

    public function testResetClearsBudget(): void
    {
        $rl = new RateLimit($this->dir, 1, 60);
        $rl->allow('9.9.9.9');
        $this->assertFalse($rl->allow('9.9.9.9'));
        $rl->reset('9.9.9.9');
        $this->assertTrue($rl->allow('9.9.9.9'));
    }

    public function testWindowRollsOver(): void
    {
        $rl = new RateLimit($this->dir, 2, 60);
        $now = time();
        $rl->allow('7.7.7.7', $now - 120); // older than window
        $rl->allow('7.7.7.7', $now - 120);
        // both should be pruned; two fresh allows work
        $this->assertTrue($rl->allow('7.7.7.7', $now));
        $this->assertTrue($rl->allow('7.7.7.7', $now));
        $this->assertFalse($rl->allow('7.7.7.7', $now));
    }
}

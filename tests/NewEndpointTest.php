<?php
declare(strict_types=1);

namespace Shh\Tests;

use PHPUnit\Framework\TestCase;
use Shh\Db;

/**
 * direct unit tests against Db that back the /api/new behavior.
 * full http coverage lives in BurnEndpointTest.
 */
final class NewEndpointTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'shhnew_');
        unlink($this->tmp);
        Db::setPath($this->tmp);
    }

    protected function tearDown(): void
    {
        Db::setPath(null);
        if (is_file($this->tmp)) unlink($this->tmp);
        foreach (glob($this->tmp . '*') ?: [] as $f) @unlink($f);
    }

    public function testInsertStoresBlobBytesExactly(): void
    {
        $raw = random_bytes(64);
        $iv = random_bytes(12);
        $t = Db::newToken();
        Db::insertSecret($t, $raw, $iv, 3600);
        $r = Db::burn($t);
        $this->assertSame($raw, $r['ciphertext']);
        $this->assertSame($iv, $r['iv']);
    }

    public function testCountTracksRows(): void
    {
        $this->assertSame(0, Db::count());
        Db::insertSecret(Db::newToken(), 'a', 'b', 60);
        Db::insertSecret(Db::newToken(), 'a', 'b', 60);
        $this->assertSame(2, Db::count());
    }
}

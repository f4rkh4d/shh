<?php
declare(strict_types=1);

namespace Shh\Tests;

use PHPUnit\Framework\TestCase;
use Shh\Db;

final class DbTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'shhdb_');
        // tempnam makes a file; delete so sqlite opens fresh
        unlink($this->tmp);
        Db::setPath($this->tmp);
    }

    protected function tearDown(): void
    {
        Db::setPath(null);
        if (is_file($this->tmp)) {
            unlink($this->tmp);
        }
        foreach (glob($this->tmp . '*') ?: [] as $f) {
            @unlink($f);
        }
    }

    public function testInsertAndBurnRoundtrip(): void
    {
        $token = Db::newToken();
        Db::insertSecret($token, 'ciphertext-bytes', 'iv-12-bytes!', 3600);

        $row = Db::burn($token);
        $this->assertNotNull($row);
        $this->assertSame('ciphertext-bytes', $row['ciphertext']);
        $this->assertSame('iv-12-bytes!', $row['iv']);
    }

    public function testSecondBurnReturnsNull(): void
    {
        $token = Db::newToken();
        Db::insertSecret($token, 'x', 'y', 3600);
        Db::burn($token);
        $this->assertNull(Db::burn($token));
    }

    public function testMissingTokenReturnsNull(): void
    {
        $this->assertNull(Db::burn('nonexistentoken12345'));
    }

    public function testSweepExpiredRemovesOldRows(): void
    {
        $a = Db::newToken();
        $b = Db::newToken();
        Db::insertSecret($a, 'old', 'iv1', 60);
        Db::insertSecret($b, 'fresh', 'iv2', 7200);

        // sweep as if it's 10 minutes later — kills the 60s one, leaves the 2h one
        $n = Db::sweepExpired(time() + 600);
        $this->assertSame(1, $n);

        // fresh one still burnable
        $this->assertNotNull(Db::burn($b));
    }

    public function testExpiredSecretReturnsNullOnBurn(): void
    {
        $token = Db::newToken();
        // insert with negative ttl effectively already expired
        Db::insertSecret($token, 'ct', 'iv', 1);
        // manually expire by updating the row
        $pdo = Db::pdo();
        $pdo->prepare('UPDATE secrets SET expires_at = ? WHERE token = ?')
            ->execute([time() - 1000, $token]);

        $this->assertNull(Db::burn($token));
        // but the row should still be deleted (one read, expired or not)
        $this->assertSame(0, Db::count());
    }

    public function testTokensAreUnique(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $t = Db::newToken();
            $this->assertMatchesRegularExpression('/^[a-z2-9]+$/', $t);
            $this->assertArrayNotHasKey($t, $seen);
            $seen[$t] = true;
        }
    }

    public function testBase32encodeIsDeterministic(): void
    {
        $a = Db::base32encode("\x00\x01\x02\x03");
        $b = Db::base32encode("\x00\x01\x02\x03");
        $this->assertSame($a, $b);
    }
}

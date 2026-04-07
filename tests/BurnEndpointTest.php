<?php
declare(strict_types=1);

namespace Shh\Tests;

use PHPUnit\Framework\TestCase;

/**
 * roundtrip test using the php built-in server. boots on a random port,
 * hits /api/new then /api/burn twice, asserts 404 on the second.
 */
final class BurnEndpointTest extends TestCase
{
    private static $proc;
    private static int $port;
    private static string $cwd;
    private static string $tmpDb;
    private static string $tmpRate;

    public static function setUpBeforeClass(): void
    {
        self::$cwd = dirname(__DIR__);
        self::$tmpDb = sys_get_temp_dir() . '/shh_e2e_' . bin2hex(random_bytes(4)) . '.sqlite';
        self::$tmpRate = sys_get_temp_dir() . '/shh_e2e_rate_' . bin2hex(random_bytes(4));
        mkdir(self::$tmpRate, 0775, true);

        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
        $name = stream_socket_get_name($sock, false);
        self::$port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($sock);

        $env = [
            'PATH' => getenv('PATH'),
            'SHH_DB_PATH' => self::$tmpDb,
            'SHH_RATE_DIR' => self::$tmpRate,
            'SHH_ADMIN_TOKEN' => 'test-admin-token',
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = [
            PHP_BINARY,
            '-S', '127.0.0.1:' . self::$port,
            '-t', self::$cwd . '/public',
            self::$cwd . '/router.php',
        ];
        self::$proc = proc_open($cmd, $descriptors, $pipes, self::$cwd, $env);

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $f = @fsockopen('127.0.0.1', self::$port, $errno, $err, 0.2);
            if ($f) { fclose($f); return; }
            usleep(50000);
        }
        self::fail('server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc, 15);
            proc_close(self::$proc);
        }
        if (is_file(self::$tmpDb)) {
            @unlink(self::$tmpDb);
        }
        foreach (glob(self::$tmpDb . '*') ?: [] as $f) @unlink($f);
        foreach (glob(self::$tmpRate . '/*') ?: [] as $f) @unlink($f);
        @rmdir(self::$tmpRate);
    }

    private function http(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $url = 'http://127.0.0.1:' . self::$port . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $hdrs = [];
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
            $hdrs[] = 'Content-Type: application/json';
        }
        foreach ($headers as $k => $v) {
            $hdrs[] = "$k: $v";
        }
        if ($hdrs) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['code' => $code, 'body' => $body, 'json' => json_decode((string)$body, true)];
    }

    public function testNewBurnBurn(): void
    {
        $ct = base64_encode('hello world ciphertext bytes');
        $iv = base64_encode(str_repeat('a', 12));
        $r = $this->http('POST', '/api/new', ['ciphertext' => $ct, 'iv' => $iv, 'ttl_seconds' => 3600]);
        $this->assertSame(201, $r['code'], 'new failed: ' . $r['body']);
        $this->assertArrayHasKey('token', $r['json']);

        $token = $r['json']['token'];

        $b1 = $this->http('POST', '/api/burn/' . $token);
        $this->assertSame(200, $b1['code']);
        $this->assertSame($ct, $b1['json']['ciphertext']);
        $this->assertSame($iv, $b1['json']['iv']);

        $b2 = $this->http('POST', '/api/burn/' . $token);
        $this->assertSame(404, $b2['code']);
    }

    public function testNewRejectsBadIv(): void
    {
        $r = $this->http('POST', '/api/new', [
            'ciphertext' => base64_encode('x'),
            'iv' => base64_encode('too-short'),
            'ttl_seconds' => 3600,
        ]);
        $this->assertSame(400, $r['code']);
    }

    public function testNewRejectsHugeCiphertext(): void
    {
        $r = $this->http('POST', '/api/new', [
            'ciphertext' => base64_encode(str_repeat('x', 10000)),
            'iv' => base64_encode(str_repeat('a', 12)),
            'ttl_seconds' => 3600,
        ]);
        $this->assertSame(400, $r['code']);
    }

    public function testBurnRejectsBadTokenFormat(): void
    {
        $r = $this->http('POST', '/api/burn/SHORT');
        $this->assertSame(404, $r['code']);
    }

    public function testGcRequiresAdminToken(): void
    {
        $r = $this->http('POST', '/api/gc');
        $this->assertSame(403, $r['code']);

        $ok = $this->http('POST', '/api/gc', null, ['X-Admin-Token' => 'test-admin-token']);
        $this->assertSame(200, $ok['code']);
        $this->assertArrayHasKey('swept', $ok['json']);
    }

    public function testIndexServes(): void
    {
        $r = $this->http('GET', '/');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('shh', (string)$r['body']);
    }
}

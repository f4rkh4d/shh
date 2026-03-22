<?php
declare(strict_types=1);

namespace Shh;

use PDO;
use RuntimeException;

final class Db
{
    private static ?PDO $pdo = null;
    private static ?string $pathOverride = null;

    public static function setPath(?string $path): void
    {
        self::$pathOverride = $path;
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = self::$pathOverride ?? shh_env('SHH_DB_PATH', shh_data_path('shh.sqlite'));
        if ($path === null || $path === '') {
            throw new RuntimeException('no db path');
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        $schema = file_get_contents(__DIR__ . '/../schema.sql');
        if ($schema === false) {
            throw new RuntimeException('missing schema.sql');
        }
        $pdo->exec($schema);

        self::$pdo = $pdo;
        return $pdo;
    }

    public static function insertSecret(string $token, string $ciphertext, string $iv, int $ttlSeconds): void
    {
        $now = time();
        $stmt = self::pdo()->prepare(
            'INSERT INTO secrets (token, ciphertext, iv, created_at, expires_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $token);
        $stmt->bindValue(2, $ciphertext, PDO::PARAM_LOB);
        $stmt->bindValue(3, $iv, PDO::PARAM_LOB);
        $stmt->bindValue(4, $now, PDO::PARAM_INT);
        $stmt->bindValue(5, $now + $ttlSeconds, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function burn(string $token): ?array
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT ciphertext, iv, expires_at FROM secrets WHERE token = ?');
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->commit();
                return null;
            }
            $del = $pdo->prepare('DELETE FROM secrets WHERE token = ?');
            $del->execute([$token]);
            $pdo->commit();

            if ((int)$row['expires_at'] < time()) {
                return null;
            }
            return [
                'ciphertext' => (string)$row['ciphertext'],
                'iv' => (string)$row['iv'],
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function sweepExpired(?int $now = null): int
    {
        $now ??= time();
        $stmt = self::pdo()->prepare('DELETE FROM secrets WHERE expires_at < ?');
        $stmt->execute([$now]);
        return $stmt->rowCount();
    }

    public static function count(): int
    {
        $stmt = self::pdo()->query('SELECT COUNT(*) FROM secrets');
        return (int)$stmt->fetchColumn();
    }

    public static function newToken(): string
    {
        $bytes = random_bytes(16);
        return self::base32encode($bytes);
    }

    public static function base32encode(string $data): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0');
            }
            $out .= $alphabet[bindec($chunk)];
        }
        return $out;
    }
}

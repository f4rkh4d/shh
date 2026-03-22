<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    // fallback psr-4 for running without composer (tests can still run with phpunit phar if needed)
    spl_autoload_register(function (string $class): void {
        if (str_starts_with($class, 'Shh\\Tests\\')) {
            $rel = substr($class, strlen('Shh\\Tests\\'));
            $path = __DIR__ . '/tests/' . str_replace('\\', '/', $rel) . '.php';
            if (is_file($path)) {
                require $path;
            }
            return;
        }
        if (str_starts_with($class, 'Shh\\')) {
            $rel = substr($class, strlen('Shh\\'));
            $path = __DIR__ . '/src/' . str_replace('\\', '/', $rel) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    });
}

// load .env if present (tiny parser, no deps)
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

if (!function_exists('shh_env')) {
    function shh_env(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $default;
        }
        return $v;
    }
}

if (!function_exists('shh_data_path')) {
    function shh_data_path(string $sub = ''): string
    {
        $root = dirname(__DIR__) . '/shh';
        $base = __DIR__ . '/data';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    }
}

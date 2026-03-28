<?php
declare(strict_types=1);

// router for php -S. also used as front controller behind nginx if you want.

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// static file passthrough for the built-in server
if (PHP_SAPI === 'cli-server') {
    $pub = __DIR__ . '/public' . $uri;
    if ($uri !== '/' && is_file($pub)) {
        return false;
    }
}

// /api/new
if ($uri === '/api/new') {
    require __DIR__ . '/api/new.php';
    return;
}

// /api/burn/{token}
if (preg_match('#^/api/burn/([a-z2-9]{20,32})$#', $uri, $m)) {
    $_GET['token'] = $m[1];
    require __DIR__ . '/api/burn.php';
    return;
}

// /api/gc
if ($uri === '/api/gc') {
    require __DIR__ . '/api/gc.php';
    return;
}

// /x/{token} → reveal page
if (preg_match('#^/x/([a-z2-9]{20,32})$#', $uri)) {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    readfile(__DIR__ . '/public/reveal.html');
    return;
}

// index
if ($uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    readfile(__DIR__ . '/public/index.html');
    return;
}

http_response_code(404);
header('Content-Type: text/plain');
echo "404";

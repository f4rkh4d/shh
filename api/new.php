<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Shh\Db;
use Shh\RateLimit;
use Shh\Response;

Response::securityHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::error('method not allowed', 405);
    return;
}

$rl = new RateLimit();
if (!$rl->allow(Response::clientIp())) {
    Response::error('slow down', 429);
    return;
}

$data = Response::readJson();
$ctB64 = $data['ciphertext'] ?? null;
$ivB64 = $data['iv'] ?? null;
$ttl = (int)($data['ttl_seconds'] ?? 86400);

if (!is_string($ctB64) || !is_string($ivB64)) {
    Response::error('missing ciphertext or iv', 400);
    return;
}

$ct = base64_decode($ctB64, true);
$iv = base64_decode($ivB64, true);
if ($ct === false || $iv === false) {
    Response::error('bad base64', 400);
    return;
}
if (strlen($ct) === 0 || strlen($ct) > 8192) {
    Response::error('ciphertext size out of range', 400);
    return;
}
if (strlen($iv) !== 12) {
    Response::error('iv must be 12 bytes', 400);
    return;
}
if ($ttl < 60 || $ttl > 7 * 86400) {
    Response::error('ttl out of range', 400);
    return;
}

$token = Db::newToken();
Db::insertSecret($token, $ct, $iv, $ttl);

Response::json(['token' => $token, 'expires_in' => $ttl], 201);

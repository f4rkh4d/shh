<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Shh\Db;
use Shh\Response;

Response::securityHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::error('method not allowed', 405);
    return;
}

$token = $_GET['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-z2-9]{20,32}$/', $token)) {
    Response::error('bad token', 400);
    return;
}

$row = Db::burn($token);
if ($row === null) {
    Response::error('gone', 404);
    return;
}

Response::json([
    'ciphertext' => base64_encode($row['ciphertext']),
    'iv' => base64_encode($row['iv']),
]);

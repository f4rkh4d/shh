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

$admin = shh_env('SHH_ADMIN_TOKEN');
$given = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
if ($admin === null || $admin === '' || !is_string($given) || !hash_equals($admin, $given)) {
    Response::error('forbidden', 403);
    return;
}

$count = Db::sweepExpired();
Response::json(['swept' => $count]);

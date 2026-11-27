<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.mysql.database');
if (config('database.default') !== 'mysql' || ! is_string($database) || ! str_starts_with($database, 'vicartech_test_e2e_') || $app->environment('testing')) {
    http_response_code(503);
    exit('An isolated database with normal HTTP security is required.');
}
header('X-Vicartech-Isolated-Database: '.$database);
$app->handleRequest(Request::capture());

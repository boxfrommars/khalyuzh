<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

$assets = [
    '/assets/app.css',
    '/assets/common.js',
    '/assets/food.js',
    '/assets/weight.js',
];

if (in_array($path, $assets, true)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$frontController = dirname(__DIR__) . '/public/index.php';
$_SERVER['SCRIPT_FILENAME'] = $frontController;
$_SERVER['PHP_SELF'] = '/index.php';

require $frontController;

return true;

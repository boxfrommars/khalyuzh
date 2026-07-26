<?php

declare(strict_types=1);

use Khalyuzh\AppFactory;

/** @var AppFactory $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$versions = $app->migrate();

if ($versions === []) {
    echo "Database schema is up to date.\n";
    exit(0);
}

echo 'Applied migrations: ' . implode(', ', $versions) . "\n";

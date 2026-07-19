<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

requireAdminAccess();
$isAdmin = true;

require dirname(__DIR__) . '/index.php';

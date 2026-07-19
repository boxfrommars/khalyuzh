<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/api.php';

requireAdminAccess();
runApi(true);

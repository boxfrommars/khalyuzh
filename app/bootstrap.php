<?php

declare(strict_types=1);

use Khalyuzh\AppFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

return new AppFactory(require __DIR__ . '/config.php');

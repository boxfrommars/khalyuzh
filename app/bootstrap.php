<?php

declare(strict_types=1);

use Khalyuzh\AppFactory;
use Khalyuzh\ConfigLoader;

require_once dirname(__DIR__) . '/vendor/autoload.php';

return new AppFactory(ConfigLoader::load(dirname(__DIR__)));

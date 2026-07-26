<?php

declare(strict_types=1);

use Khalyuzh\AppFactory;
use Symfony\Component\HttpFoundation\Request;

/** @var AppFactory $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$request = Request::createFromGlobals();
$app->application()->handle($request)->send();

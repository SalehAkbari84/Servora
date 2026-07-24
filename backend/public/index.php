<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// PHP 8.5 compatibility: Laravel 11's config/database.php references the
// PDO::MYSQL_ATTR_SSL_CA constant, deprecated as of PHP 8.5. That notice fires
// while the config files are loaded -- before Laravel's error handler is
// registered -- so it would otherwise be echoed into the response body and
// corrupt every JSON API payload. Silence deprecation notices at the entry point.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());

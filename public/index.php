<?php

declare(strict_types=1);

/*
 * Front controller: the only public PHP entry point.
 * Local run:  php -S localhost:8000 -t public
 */

use App\App;
use App\Core\Response;
use App\Core\Session;
use Bramus\Router\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

// Show errors only while developing; never print them to visitors.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Security headers for every response.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

Session::start();

$config = require dirname(__DIR__) . '/config/config.php';
$app = new App($config);

$router = new Router();
$router->setBasePath($config['base_path']);
(require dirname(__DIR__) . '/config/routes.php')($router, $app);

try {
    $router->run();
} catch (Throwable $e) {
    // Log the error type and location only: no request data, passwords or tokens.
    error_log(sprintf('[forum] %s in %s:%d', get_class($e), $e->getFile(), $e->getLine()));
    if (!headers_sent()) {
        Response::error($app->view(), 500, 'Something went wrong on our side. Please try again.');
    }
}

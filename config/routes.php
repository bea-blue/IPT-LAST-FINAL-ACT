<?php

declare(strict_types=1);

use App\App;
use App\Core\Response;
use Bramus\Router\Router;

/**
 * Route table. Each route only dispatches to a controller method.
 *
 * Discussion IDs are captured with ([^/]+) on purpose, so that malformed IDs
 * such as /discussions/abc reach the controller and get a documented
 * 400 Bad Request instead of a generic router 404.
 */
return static function (Router $router, App $app): void {
    // Public reading
    $router->get('/', fn () => $app->discussions()->index());
    $router->get('/discussions/([^/]+)', fn (string $id) => $app->discussions()->show($id));

    // Authenticated writes
    $router->post('/discussions/([^/]+)/comments', fn (string $id) => $app->comments()->store($id));

    // Accounts
    $router->get('/register', fn () => $app->auth()->showRegister());
    $router->post('/register', fn () => $app->auth()->register());
    $router->get('/login', fn () => $app->auth()->showLogin());
    $router->post('/login', fn () => $app->auth()->login());
    $router->post('/logout', fn () => $app->auth()->logout());

    // Everything else
    $router->set404(fn () => Response::error($app->view(), 404, 'That page does not exist.'));
};

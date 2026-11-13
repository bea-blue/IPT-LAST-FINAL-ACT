<?php

declare(strict_types=1);

namespace App\Core;

use Mustache_Engine;
use Mustache_Loader_FilesystemLoader;

/**
 * Renders Mustache templates from app/Views.
 *
 * Every {{variable}} is HTML-escaped with htmlspecialchars(ENT_QUOTES, UTF-8).
 * No template uses triple braces or {{& }}, so nothing is output unescaped.
 * Pages include {{> header}} and {{> footer}} partials instead of a layout.
 */
final class View
{
    private Mustache_Engine $engine;

    public function __construct(string $viewsPath)
    {
        $this->engine = new Mustache_Engine([
            'loader'          => new Mustache_Loader_FilesystemLoader($viewsPath),
            'partials_loader' => new Mustache_Loader_FilesystemLoader($viewsPath . '/partials'),
            'charset'         => 'UTF-8',
            'entity_flags'    => ENT_QUOTES | ENT_SUBSTITUTE,
            'strict_callables' => true,
        ]);
    }

    /** Render a template with the shared page data (user, CSRF token, flash). */
    public function render(string $template, array $data = []): string
    {
        $userId = Session::userId();

        $shared = [
            'csrfToken' => Session::csrfToken(),
            'loggedIn'  => $userId !== null,
            'username'  => Session::username(),
            'flash'     => Session::takeFlash(),
            'pageTitle' => 'Nested Forum',
        ];

        return $this->engine->render($template, array_merge($shared, $data));
    }
}

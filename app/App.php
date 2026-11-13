<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AuthController;
use App\Controllers\CommentController;
use App\Controllers\DiscussionController;
use App\Core\Database;
use App\Core\View;
use App\Models\Comment;
use App\Models\Discussion;
use App\Models\User;
use PDO;

/**
 * Tiny service container: builds the PDO connection, the view and the
 * controllers once, and only when a route actually needs them.
 */
final class App
{
    private ?PDO $pdo = null;
    private ?View $view = null;

    public function __construct(private array $config)
    {
    }

    public function config(string $key): mixed
    {
        return $this->config[$key];
    }

    public function db(): PDO
    {
        return $this->pdo ??= Database::connect($this->config['database']);
    }

    public function view(): View
    {
        return $this->view ??= new View(__DIR__ . '/Views');
    }

    public function discussions(): DiscussionController
    {
        return new DiscussionController(
            new Discussion($this->db()),
            new Comment($this->db()),
            $this->view(),
            $this->config['forum'],
        );
    }

    public function comments(): CommentController
    {
        return new CommentController(
            new Discussion($this->db()),
            new Comment($this->db()),
            $this->view(),
            $this->config['forum'],
        );
    }

    public function auth(): AuthController
    {
        return new AuthController(new User($this->db()), $this->view());
    }
}

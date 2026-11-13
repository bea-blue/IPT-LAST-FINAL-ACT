<?php

declare(strict_types=1);

/*
 * Application configuration.
 *
 * Database credentials are read from environment variables, or from
 * config/database.local.php if that file exists (it is git-ignored).
 * Copy config/database.local.example.php to config/database.local.php
 * and fill in your own values. Never commit real credentials.
 */

$database = [
    'dsn'      => getenv('FORUM_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=nested_forum;charset=utf8mb4',
    'username' => getenv('FORUM_DB_USER') ?: 'root',
    'password' => getenv('FORUM_DB_PASS') ?: '',
];

$local = __DIR__ . '/database.local.php';
if (is_file($local)) {
    $database = array_merge($database, require $local);
}

return [
    'database' => $database,

    // Router base path. "/" when served with: php -S localhost:8000 -t public
    'base_path' => getenv('FORUM_BASE_PATH') ?: '/',

    'forum' => [
        // Visual indentation stops growing after this displayed depth.
        'indent_cap'        => 8,
        // Deepest displayed depth a new reply may have (server-side write limit).
        'max_reply_depth'   => 20,
        // Rendering guards: nodes per page and recursion depth.
        'max_render_nodes'  => 2000,
        'max_render_depth'  => 50,
        // Comment length in characters.
        'max_comment_chars' => 2000,
    ],
];

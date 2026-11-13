<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session wrapper: hardened cookie settings, login state, CSRF tokens and
 * one-time flash messages. Session IDs and tokens are never logged.
 */
final class Session
{
    private const CSRF_KEY = '_csrf_token';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        session_name('forum_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,   // Secure cookie whenever the site runs on HTTPS
            'httponly' => true,     // not readable from JavaScript
            'samesite' => 'Lax',    // not sent on cross-site POSTs
        ]);

        session_start();
    }

    // ----- authentication -------------------------------------------------

    public static function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return is_int($id) ? $id : null;
    }

    public static function isLoggedIn(): bool
    {
        return self::userId() !== null;
    }

    public static function username(): ?string
    {
        $name = $_SESSION['username'] ?? null;
        return is_string($name) ? $name : null;
    }

    public static function login(int $userId, string $username): void
    {
        // New session ID after login prevents session fixation.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        self::rotateCsrfToken();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::rotateCsrfToken();
    }

    // ----- CSRF -----------------------------------------------------------

    public static function csrfToken(): string
    {
        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            self::rotateCsrfToken();
        }
        return $_SESSION[self::CSRF_KEY];
    }

    public static function verifyCsrf(mixed $submitted): bool
    {
        $expected = $_SESSION[self::CSRF_KEY] ?? null;
        return is_string($expected)
            && is_string($submitted)
            && $submitted !== ''
            && hash_equals($expected, $submitted);
    }

    private static function rotateCsrfToken(): void
    {
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
    }

    // ----- flash messages and redirects ------------------------------------

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function takeFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $messages;
    }

    public static function setIntended(string $path): void
    {
        $_SESSION['_intended'] = $path;
    }

    public static function takeIntended(): string
    {
        $path = $_SESSION['_intended'] ?? '/';
        unset($_SESSION['_intended']);
        // Only local absolute paths; blocks "//evil.example" open redirects.
        return (is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//'))
            ? $path
            : '/';
    }
}

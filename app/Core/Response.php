<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small helpers for sending HTML, errors and redirects.
 */
final class Response
{
    public static function html(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        echo $body;
    }

    /** 303 See Other: the browser follows with GET (Post/Redirect/Get). */
    public static function redirect(string $path, int $status = 303): void
    {
        http_response_code($status);
        header('Location: ' . $path);
    }

    public static function error(View $view, int $status, string $message, array $details = [], ?string $backUrl = null): void
    {
        $titles = [
            400 => 'Bad request',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            422 => 'Could not save your reply',
            500 => 'Server error',
        ];

        self::html($view->render('errors/error', [
            'pageTitle' => $titles[$status] ?? 'Error',
            'status'    => $status,
            'title'     => $titles[$status] ?? 'Error',
            'message'   => $message,
            'details'   => array_map(fn (string $d) => ['text' => $d], $details),
            'hasDetails' => $details !== [],
            'backUrl'   => $backUrl ?? '/',
        ]), $status);
    }
}

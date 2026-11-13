<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\User;
use PDOException;

/**
 * Registration, login and logout. Every POST checks a session-backed CSRF
 * token. Passwords are hashed with password_hash() and checked with
 * password_verify(); they are never logged or echoed back.
 */
final class AuthController
{
    private const USERNAME_PATTERN = '/^[A-Za-z0-9_]{3,30}$/';
    private const MIN_PASSWORD = 8;
    private const MAX_PASSWORD_BYTES = 72; // bcrypt ignores bytes after 72
    // Hash of a throwaway string; only used to equalise login timing.
    private const DUMMY_HASH = '$2y$10$QzUki8CZpmRf5CK72Vv1tec21.Cv8CfWL9AKQcQb1AGRJekcNmCRy';

    public function __construct(private User $users, private View $view)
    {
    }

    // ----- registration ---------------------------------------------------

    /** GET /register */
    public function showRegister(): void
    {
        if (Session::isLoggedIn()) {
            Response::redirect('/');
            return;
        }
        Response::html($this->view->render('auth/register', ['pageTitle' => 'Create an account']));
    }

    /** POST /register */
    public function register(): void
    {
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            Response::error($this->view, 403, 'Your form expired or the security token is invalid. Reload the page and try again.', [], '/register');
            return;
        }

        $username = self::stringInput('username');
        $email    = self::stringInput('email');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirmation'] ?? '';

        $errors = [];

        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            $errors[] = 'Username must be 3–30 characters: letters, numbers or underscores.';
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }
        if (!is_string($password) || !is_string($confirm)) {
            $errors[] = 'Enter a password.';
        } else {
            if (mb_strlen($password) < self::MIN_PASSWORD) {
                $errors[] = 'Password must be at least ' . self::MIN_PASSWORD . ' characters.';
            } elseif (strlen($password) > self::MAX_PASSWORD_BYTES) {
                $errors[] = 'Password must be at most ' . self::MAX_PASSWORD_BYTES . ' bytes.';
            }
            if (!hash_equals($password, $confirm)) {
                $errors[] = 'Passwords do not match.';
            }
        }

        if ($errors === []) {
            $taken = $this->users->usernameOrEmailTaken($username, $email);
            if ($taken['username']) {
                $errors[] = 'That username is already taken.';
            }
            if ($taken['email']) {
                $errors[] = 'An account with that email already exists.';
            }
        }

        if ($errors === []) {
            try {
                $this->users->create($username, $email, password_hash($password, PASSWORD_DEFAULT));
            } catch (PDOException $e) {
                // 23000 = unique key violation (two sign-ups at the same moment).
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $errors[] = 'That username or email is already registered.';
            }
        }

        if ($errors !== []) {
            Response::html($this->view->render('auth/register', [
                'pageTitle' => 'Create an account',
                'errors'    => array_map(fn ($e) => ['text' => $e], $errors),
                'hasErrors' => true,
                'old'       => ['username' => $username, 'email' => $email],
            ]), 422);
            return;
        }

        Session::flash('success', 'Account created. Please log in.');
        Response::redirect('/login');
    }

    // ----- login / logout ---------------------------------------------------

    /** GET /login */
    public function showLogin(): void
    {
        if (Session::isLoggedIn()) {
            Response::redirect('/');
            return;
        }
        Response::html($this->view->render('auth/login', ['pageTitle' => 'Log in']));
    }

    /** POST /login */
    public function login(): void
    {
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            Response::error($this->view, 403, 'Your form expired or the security token is invalid. Reload the page and try again.', [], '/login');
            return;
        }

        $username = self::stringInput('username');
        $password = $_POST['password'] ?? '';

        $user = $username !== '' ? $this->users->findByUsername($username) : null;
        $hash = $user['password_hash'] ?? null;

        // A missing user or a NULL hash (fictional seed users) never authenticates.
        $valid = false;
        if (is_string($password)) {
            if (is_string($hash) && $hash !== '') {
                $valid = password_verify($password, $hash);
            } else {
                // Spend the same time as a real check so response timing
                // does not reveal which usernames exist.
                password_verify($password, self::DUMMY_HASH);
            }
        }

        if (!$valid) {
            Response::html($this->view->render('auth/login', [
                'pageTitle' => 'Log in',
                'errors'    => [['text' => 'Incorrect username or password.']],
                'hasErrors' => true,
                'old'       => ['username' => $username],
            ]), 401);
            return;
        }

        Session::login((int) $user['id'], $user['username']); // regenerates the session ID
        Session::flash('success', 'Welcome back, ' . $user['username'] . '.');
        Response::redirect(Session::takeIntended());
    }

    /** POST /logout */
    public function logout(): void
    {
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            Response::error($this->view, 403, 'Your form expired or the security token is invalid. Reload the page and try again.');
            return;
        }

        Session::logout();
        Session::flash('success', 'You have been logged out.');
        Response::redirect('/');
    }

    private static function stringInput(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}

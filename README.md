# Nested Forum Lab — PHP, MySQL & PDO

A small forum that shows replies as a tree. Anyone can read a discussion;
only a logged-in user can post. Built MVC-style with a router, controllers,
data-access models and Mustache templates.

**Author:** Bea Louise Uson · BSIT 3A · Angeles University Foundation

## Features

- Public discussion list (`/`) and discussion page (`/discussions/{id}`) with the
  nested reply tree: author name, default avatar, UTC timestamp, displayed depth
  label and indentation (indentation capped at depth 8, labels stay exact).
- Registration, login and POST logout. Passwords are stored with `password_hash()`
  and checked with `password_verify()`; a NULL hash never logs in.
- Authenticated top-level comments and replies, checked on the server.
- CSRF tokens on every form that changes state (register, login, logout, replies).
- Optional challenge: accessible **Hide replies / Show replies** buttons for
  branches (`public/assets/replies.js`).

## Libraries and why

| Need | Choice | Notes |
|---|---|---|
| Routing | [bramus/router](https://github.com/bramus/router) `^1.6` | Lecture library. One front controller, regex routes. |
| Data access | **PDO** (built into PHP) | Substitution for Eloquent: plain parameterized PDO queries in model classes. SQL appears only in `app/Models`. |
| Templates | [mustache/mustache](https://github.com/bobthecow/mustache.php) `^2.14` | Lecture library. Logic-less templates, `{{ }}` escapes HTML. |

Eloquent was not used because the lab only needs a few queries; plain PDO keeps
the parameter binding visible, which is what the lab asks us to explain.

## Requirements

- PHP 8.2+ with `pdo_mysql` and `mbstring`
- MySQL 8 (or MariaDB 10.5+)
- Composer

## Setup

```bash
git clone https://github.com/bea-blue/forum-refactor.git
cd forum-refactor
composer install                          # installs bramus/router and mustache/mustache

mysql -u root -p < database/schema.sql    # creates the nested_forum database + seed data

cp config/database.local.example.php config/database.local.php
# edit config/database.local.php with your MySQL user and password (this file is git-ignored)

php -S localhost:8000 -t public           # or: composer serve
```

Open <http://localhost:8000/discussions/1>.

On Windows (XAMPP), use XAMPP's `php.exe` and `mysql.exe`, and run the commands
in PowerShell or Git Bash. Instead of `cp`, use `copy` in Command Prompt.

You can also set the connection with environment variables instead of the local
file: `FORUM_DB_DSN`, `FORUM_DB_USER`, `FORUM_DB_PASS`.

The seed users (Alice, Ben, Cara, Dee, Eli) are fictional and have no password,
so they cannot log in. **Register your own account** to post.

## Routes

| Method | Path | Controller | Auth |
|---|---|---|---|
| GET  | `/` | `DiscussionController::index` | public |
| GET  | `/discussions/{id}` | `DiscussionController::show` | public |
| POST | `/discussions/{id}/comments` | `CommentController::store` | login + CSRF |
| GET  | `/register` | `AuthController::showRegister` | guest |
| POST | `/register` | `AuthController::register` | CSRF |
| GET  | `/login` | `AuthController::showLogin` | guest |
| POST | `/login` | `AuthController::login` | CSRF |
| POST | `/logout` | `AuthController::logout` | CSRF |

Routes are in `config/routes.php`.

### Response codes

- Malformed discussion ID (`abc`, `0`, `01`, `-1`, too large) → **400 Bad Request**.
  The route captures `([^/]+)` on purpose so the controller can tell malformed from
  missing. No database query runs for a malformed ID.
- Valid ID that does not exist → **404 Not Found** (no comment query runs).
- Guest POST to a write route → **303 redirect to /login**, nothing saved.
- Bad or missing CSRF token → **403 Forbidden**, nothing saved.
- Invalid comment (empty, longer than 2000 characters, parent in another discussion,
  too deep) → **422**, nothing saved. Wrong login → **401**.
- Successful post → **303 redirect** to the new comment (Post/Redirect/Get).

### Production rewrites (explanation only)

The local test uses PHP's built-in server, which sends unknown paths to
`public/index.php` automatically. On Apache you would point the document root at
`public/` and add a `public/.htaccess` with:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

On nginx: `try_files $uri /index.php?$query_string;`.

## Project layout

```
forum-refactor/
  public/index.php            front controller (only public PHP file)
  public/assets/              style.css, replies.js (collapse/expand), default-avatar.svg
  app/App.php                 tiny service container (PDO, view, controllers)
  app/Core/                   Database (PDO), Session (cookies, login, CSRF, flash), View (Mustache), Response
  app/Controllers/            DiscussionController, CommentController, AuthController
  app/Models/                 User, Discussion, Comment (all SQL lives here)
  app/Services/CommentTree.php   groups rows by parent and builds the nested view data
  app/Views/                  Mustache templates; partials/comment.mustache renders itself recursively
  config/                     config.php, routes.php, database.local.example.php
  database/schema.sql         schema + fictional seed data
  tests/run.php               unit tests (tree building, depth, cap, cycles, ID validation)
  tests/http_checks.sh        end-to-end HTTP checks against a running server
  composer.json
```

## Data model

```
users (id, username, email, password_hash, avatar_filename, use_gravatar, created_at)
discussions (id, user_id → users, title, body, created_at)
comments (id, discussion_id → discussions, parent_id → comments, user_id → users, body, created_at)
```

- `discussion_id` says which tree a comment belongs to (the discussion is the root).
- `parent_id` says which comment it answers. `NULL` = top-level comment.
- A composite foreign key `(discussion_id, parent_id) → comments(discussion_id, id)`
  makes sure a parent is always in the same discussion.
- Depth is **calculated** when rendering, not stored.
- Cycles: the composite key cannot stop them, so the app does. Parent links are
  immutable (there is no edit route) and a new comment can only point to an
  existing comment, so a new row can never close a loop. Writes are limited to
  displayed depth 20. The renderer also keeps a visited set, a 2000-node budget
  and a recursion limit, so bad data can never loop forever.

## Request flow

```
Browser → public/index.php → Bramus Router (config/routes.php)
        → DiscussionController::show($id)
            1. validate ID            (400 if malformed)
            2. Discussion::find()     (404 if missing)   ← prepared PDO query
            3. Comment::forDiscussion()                   ← prepared PDO query, JOIN users
            4. CommentTree::build()   group by parent_id, compute depth
        → View → discussions/show.mustache + partials/comment.mustache (recursive)
        → HTML response
```

Posting a reply: `CommentController::store` checks **login → CSRF token →
discussion → content and parent** in that order, then saves with the user ID from
the session (never from the form) and redirects.

## Security notes

- **Parameter binding:** every query uses `prepare()` with named placeholders;
  `PDO::ATTR_EMULATE_PREPARES` is off, so values go to MySQL separately from the SQL.
- **Escaping:** templates only use `{{ }}`, which runs `htmlspecialchars` with
  `ENT_QUOTES`. No `{{{ }}}` or `{{& }}` anywhere, so `<script>` in a comment shows
  as text.
- **Sessions:** `HttpOnly`, `SameSite=Lax`, `Secure` when served over HTTPS,
  strict mode, cookies only. The session ID is regenerated on login and logout.
- **CSRF:** a random 32-byte token per session, compared with `hash_equals`, rotated
  on login and logout.
- **Passwords:** `password_hash(PASSWORD_DEFAULT)`; at least 8 characters, at most
  72 bytes (bcrypt's limit). Wrong-user and wrong-password take the same time.
- Errors are logged with class and line only — never passwords, tokens or form data.
- A Content-Security-Policy header allows only same-origin scripts and styles.

## Tests

Unit tests (no database needed):

```bash
composer test        # or: php tests/run.php
```

End-to-end checks (start the server on a freshly imported database first):

```bash
bash tests/http_checks.sh                 # BASE=http://localhost:8000 by default
```

They cover: reading as a guest; missing (404) and malformed (400) IDs; registration
validation and duplicates; wrong password; seed users with no password; CSRF
rejection on register, login, logout and replies; a depth-5 reply and a second
top-level comment with the right author; HTML/script escaping; cross-discussion
and nonexistent parents; empty and too-long comments; logout; and guest POSTs
(with and without cookies) creating no rows.

### Manual checks for the collapse / expand challenge

1. Open `/discussions/1`. Press **Hide replies** on Alice: Ben, Cara and Dee
   disappear, Eli stays. The button says **Show replies** and has
   `aria-expanded="false"`. Press it again: everything returns with the same depth labels.
2. Collapse Ben: Cara is hidden, Dee is still visible.
3. Use only the keyboard (Tab to the button, Enter or Space).
4. Disable JavaScript: all comments show and no toggle buttons appear (the buttons
   are created by the script).

## Limitations

- All comments of one discussion are loaded at once and rendered recursively —
  fine for a lab, not for a large forum (would need pagination or lazy loading).
- No rate limiting, account recovery, moderation or avatar upload (optional extensions).
- Creating new discussions is not part of this lab; discussions come from the seed data.

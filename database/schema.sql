-- Nested Forum Lab: schema and fictional seed data
-- Target: MySQL 8.0+ (or MariaDB 10.5+), InnoDB, utf8mb4.
-- Run:  mysql -u root -p < database/schema.sql

DROP DATABASE IF EXISTS nested_forum;
CREATE DATABASE nested_forum CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nested_forum;

-- All timestamps are stored and displayed as UTC.
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- users: authors of discussions and comments
-- password_hash is NULL for fictional seed users; a NULL hash can never log in.
-- avatar_filename / use_gravatar exist for the optional avatar extension only.
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username         VARCHAR(30)  NOT NULL,
    email            VARCHAR(254) NOT NULL,
    password_hash    VARCHAR(255) NULL,
    avatar_filename  VARCHAR(64)  NULL,
    use_gravatar     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- discussions: the root of each reply tree
-- ---------------------------------------------------------------------------
CREATE TABLE discussions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(200) NOT NULL,
    body        TEXT         NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_discussions_user (user_id),
    CONSTRAINT fk_discussions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- comments: parent_id NULL  -> top-level comment (edge from the discussion)
--           parent_id = n   -> reply to comment n
-- The composite key (discussion_id, parent_id) -> comments (discussion_id, id)
-- guarantees that a parent belongs to the SAME discussion. Rows whose
-- parent_id is NULL are not checked by that key (MATCH SIMPLE), which is
-- exactly what top-level comments need.
-- Cycles are prevented by the application: parent links are immutable
-- (there is no edit route) and a new row can only point to an existing row.
-- ---------------------------------------------------------------------------
CREATE TABLE comments (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    discussion_id  INT UNSIGNED NOT NULL,
    parent_id      INT UNSIGNED NULL,
    user_id        INT UNSIGNED NOT NULL,
    body           TEXT         NOT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_comments_discussion_id (discussion_id, id),
    KEY idx_comments_parent (discussion_id, parent_id),
    KEY idx_comments_user (user_id),
    CONSTRAINT fk_comments_discussion
        FOREIGN KEY (discussion_id) REFERENCES discussions (id),
    CONSTRAINT fk_comments_user
        FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_comments_parent_same_discussion
        FOREIGN KEY (discussion_id, parent_id) REFERENCES comments (discussion_id, id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Fictional seed data. Seed users have NULL password hashes, so they cannot
-- log in; register your own account to post. Emails use the reserved
-- example.test domain.
-- ---------------------------------------------------------------------------
INSERT INTO users (id, username, email, password_hash, created_at) VALUES
    (1, 'Alice', 'alice@example.test', NULL, '2026-11-01 08:00:00'),
    (2, 'Ben',   'ben@example.test',   NULL, '2026-11-01 08:05:00'),
    (3, 'Cara',  'cara@example.test',  NULL, '2026-11-01 08:10:00'),
    (4, 'Dee',   'dee@example.test',   NULL, '2026-11-01 08:15:00'),
    (5, 'Eli',   'eli@example.test',   NULL, '2026-11-01 08:20:00');

INSERT INTO discussions (id, user_id, title, body, created_at) VALUES
    (1, 1, 'How do I learn PHP?', 'Share your learning tips.', '2026-11-02 09:00:00'),
    (2, 5, 'Favorite SQL tools?', 'Which clients do you use for MySQL?', '2026-11-02 10:00:00');

-- Discussion 1 reproduces the lab's sample tree (7 comments, a branch that
-- reaches displayed depth 4: 1 -> 2 -> 3 -> 6 -> 7).
INSERT INTO comments (id, discussion_id, parent_id, user_id, body, created_at) VALUES
    (1, 1, NULL, 1, 'Start with the PHP manual.',     '2026-11-02 09:10:00'),
    (2, 1, 1,    2, 'Try the examples as you read.',  '2026-11-02 09:20:00'),
    (3, 1, 2,    3, 'Build a small project too.',     '2026-11-02 09:30:00'),
    (4, 1, 1,    4, 'Which manual section first?',    '2026-11-02 09:40:00'),
    (5, 1, NULL, 5, 'Practice SQL as well.',          '2026-11-02 09:50:00'),
    (6, 1, 3,    2, 'Start with a forum.',            '2026-11-02 10:00:00'),
    (7, 1, 6,    5, 'Add replies next.',              '2026-11-02 10:10:00'),
    -- Discussion 2 exists so a cross-discussion parent can be tested.
    (8, 2, NULL, 3, 'I like the mysql command line.', '2026-11-02 10:30:00');

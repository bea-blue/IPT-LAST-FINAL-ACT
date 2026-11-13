<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Data access for the users table. All values are bound parameters.
 */
final class User
{
    public function __construct(private PDO $db)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash FROM users WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, username, email FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Case-insensitive duplicate check (MySQL's default collation already
     * compares case-insensitively; LOWER() keeps the check portable).
     */
    public function usernameOrEmailTaken(string $username, string $email): array
    {
        $stmt = $this->db->prepare(
            'SELECT LOWER(username) AS username, LOWER(email) AS email FROM users
             WHERE LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email)'
        );
        $stmt->execute(['username' => $username, 'email' => $email]);

        $taken = ['username' => false, 'email' => false];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['username'] === mb_strtolower($username)) {
                $taken['username'] = true;
            }
            if ($row['email'] === mb_strtolower($email)) {
                $taken['email'] = true;
            }
        }
        return $taken;
    }

    public function create(string $username, string $email, string $passwordHash): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :hash)'
        );
        $stmt->execute(['username' => $username, 'email' => $email, 'hash' => $passwordHash]);
        return (int) $this->db->lastInsertId();
    }
}

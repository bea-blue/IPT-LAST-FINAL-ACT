<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Data access for the discussions table, including the author relationship.
 */
final class Discussion
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array> discussions with author name and comment count */
    public function all(): array
    {
        return $this->db->query(
            'SELECT d.id, d.title, d.created_at, u.username AS author,
                    (SELECT COUNT(*) FROM comments c WHERE c.discussion_id = d.id) AS comment_count
             FROM discussions d
             JOIN users u ON u.id = d.user_id
             ORDER BY d.created_at DESC, d.id DESC'
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT d.id, d.title, d.body, d.created_at, u.username AS author
             FROM discussions d
             JOIN users u ON u.id = d.user_id
             WHERE d.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM discussions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetchColumn() !== false;
    }
}

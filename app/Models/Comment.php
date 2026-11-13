<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Data access for the comments table, including the author relationship.
 */
final class Comment
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * All comments of one discussion as a flat list, oldest first.
     * The tree is built from parent_id by App\Services\CommentTree.
     *
     * @return list<array{id:int,parent_id:?int,author:string,body:string,created_at:string}>
     */
    public function forDiscussion(int $discussionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.parent_id, c.body, c.created_at, u.username AS author
             FROM comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.discussion_id = :discussion_id
             ORDER BY c.created_at ASC, c.id ASC'
        );
        $stmt->execute(['discussion_id' => $discussionId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['id'] = (int) $row['id'];
            $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $rows[] = $row;
        }
        return $rows;
    }

    /** Parent link of a comment, but only if it belongs to the given discussion. */
    public function findInDiscussion(int $commentId, int $discussionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, parent_id FROM comments WHERE id = :id AND discussion_id = :discussion_id'
        );
        $stmt->execute(['id' => $commentId, 'discussion_id' => $discussionId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'id'        => (int) $row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
        ];
    }

    /**
     * Displayed depth of an existing comment (top-level = 0), found by walking
     * parent links upward. Returns null if the chain is broken, loops back on
     * itself, or is longer than $limit — the caller then rejects the reply.
     */
    public function displayedDepth(int $commentId, int $discussionId, int $limit): ?int
    {
        $depth = 0;
        $seen = [];
        $current = $this->findInDiscussion($commentId, $discussionId);

        while ($current !== null) {
            if (isset($seen[$current['id']]) || $depth > $limit) {
                return null; // cycle or too deep
            }
            $seen[$current['id']] = true;

            if ($current['parent_id'] === null) {
                return $depth;
            }
            $depth++;
            $current = $this->findInDiscussion($current['parent_id'], $discussionId);
        }
        return null; // broken chain
    }

    public function create(int $discussionId, ?int $parentId, int $userId, string $body): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO comments (discussion_id, parent_id, user_id, body)
             VALUES (:discussion_id, :parent_id, :user_id, :body)'
        );
        $stmt->bindValue('discussion_id', $discussionId, PDO::PARAM_INT);
        $stmt->bindValue('parent_id', $parentId, $parentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('body', $body, PDO::PARAM_STR);
        $stmt->execute();
        return (int) $this->db->lastInsertId();
    }
}

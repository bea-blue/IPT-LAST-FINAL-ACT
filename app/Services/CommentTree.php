<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns the flat comment rows of one discussion into nested view data.
 *
 * - Comments are grouped by parent_id; a null parent means top-level.
 * - Displayed depth is calculated (top-level = 0), never stored.
 * - Visual indentation stops growing at $indentCap, but depth labels stay exact.
 * - A visited set, a node budget and a depth limit guard the traversal, so bad
 *   data (a cycle or a huge thread) cannot cause endless recursion. Rows that
 *   cannot be reached from the discussion root are simply not rendered.
 */
final class CommentTree
{
    private int $rendered = 0;
    /** @var array<int,true> */
    private array $visited = [];
    private bool $truncated = false;

    public function __construct(
        private int $indentCap = 8,
        private int $maxReplyDepth = 20,
        private int $maxNodes = 2000,
        private int $maxDepth = 50,
    ) {
    }

    /**
     * Group rows by parent. Key 0 holds the top-level comments
     * (comment IDs start at 1, so 0 is free to mean "the discussion").
     *
     * @param list<array{id:int,parent_id:?int}> $rows
     * @return array<int, list<array>>
     */
    public static function groupByParent(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[$row['parent_id'] ?? 0][] = $row;
        }
        return $byParent;
    }

    /**
     * @param list<array> $rows rows from Comment::forDiscussion()
     * @return array{comments:list<array>,total:int,rendered:int,hidden:int,truncated:bool}
     */
    public function build(array $rows): array
    {
        $this->rendered = 0;
        $this->visited = [];
        $this->truncated = false;

        $byParent = self::groupByParent($rows);
        $comments = $this->buildLevel($byParent, 0, 0);

        return [
            'comments'  => $comments,
            'total'     => count($rows),
            'rendered'  => $this->rendered,
            'hidden'    => count($rows) - $this->rendered,
            'truncated' => $this->truncated,
        ];
    }

    /** @return list<array> */
    private function buildLevel(array $byParent, int $parentKey, int $depth): array
    {
        $nodes = [];

        foreach ($byParent[$parentKey] ?? [] as $row) {
            $id = $row['id'];

            if (isset($this->visited[$id])) {
                continue; // path guard: never visit a comment twice
            }
            if ($this->rendered >= $this->maxNodes || $depth > $this->maxDepth) {
                $this->truncated = true;
                break;
            }

            $this->visited[$id] = true;
            $this->rendered++;

            $children = $this->buildLevel($byParent, $id, $depth + 1);
            $nodes[] = $this->toViewNode($row, $depth, $children);
        }

        return $nodes;
    }

    private function toViewNode(array $row, int $depth, array $children): array
    {
        $created = new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC'));

        return [
            'id'          => $row['id'],
            'author'      => $row['author'],
            'body'        => $row['body'],
            'createdIso'  => $created->format('Y-m-d\TH:i:s\Z'),
            'createdUtc'  => $created->format('M j, Y H:i') . ' UTC',
            'depth'       => $depth,
            // Children of a comment at the cap are not indented further.
            'indentChildren' => $depth < $this->indentCap,
            'canReplyHere'   => $depth + 1 <= $this->maxReplyDepth,
            // Always present, so Mustache never looks these up in a parent context.
            'hasChildren' => $children !== [],
            'replyCount'  => count($children),
            'children'    => $children,
        ];
    }
}

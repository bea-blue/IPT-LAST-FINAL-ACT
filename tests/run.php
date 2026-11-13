<?php

declare(strict_types=1);

/*
 * Unit tests for the parts that need no database or web server:
 * reply-tree building (depth, grouping, cap, cycle and size guards)
 * and discussion-ID validation.
 *
 * Run:  composer test      (or: php tests/run.php)
 */

use App\Controllers\DiscussionController;
use App\Services\CommentTree;

require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;

function check(string $name, mixed $expected, mixed $actual): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "PASS  {$name}\n";
    } else {
        $failed++;
        echo "FAIL  {$name}\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
    }
}

function row(int $id, ?int $parent, string $author = 'Test'): array
{
    return [
        'id' => $id, 'parent_id' => $parent, 'author' => $author,
        'body' => "Comment {$id}", 'created_at' => '2026-11-02 09:00:00',
    ];
}

/** Flatten a built tree into "id:depth" strings in display order. */
function walk(array $nodes): array
{
    $out = [];
    foreach ($nodes as $n) {
        $out[] = "{$n['id']}:{$n['depth']}";
        $out = array_merge($out, walk($n['children']));
    }
    return $out;
}

function findNode(array $nodes, int $id): ?array
{
    foreach ($nodes as $n) {
        if ($n['id'] === $id) {
            return $n;
        }
        if (($hit = findNode($n['children'], $id)) !== null) {
            return $hit;
        }
    }
    return null;
}

// --- The lab's sample discussion -------------------------------------------
$sample = [
    row(1, null, 'Alice'), row(2, 1, 'Ben'), row(3, 2, 'Cara'), row(4, 1, 'Dee'),
    row(5, null, 'Eli'), row(6, 3, 'Ben'), row(7, 6, 'Eli'),
];
$tree = (new CommentTree())->build($sample);

check('sample: display order and depths', ['1:0', '2:1', '3:2', '6:3', '7:4', '4:1', '5:0'], walk($tree['comments']));
check('sample: two top-level comments', 2, count($tree['comments']));
check('sample: all 7 rendered, none hidden', [7, 0], [$tree['rendered'], $tree['hidden']]);
check('sample: Ben (2) and Dee (4) are siblings under Alice', [2, 4], array_column(findNode($tree['comments'], 1)['children'], 'id'));
check('sample: leaves 4, 5, 7 have no children', [false, false, false], [
    findNode($tree['comments'], 4)['hasChildren'],
    findNode($tree['comments'], 5)['hasChildren'],
    findNode($tree['comments'], 7)['hasChildren'],
]);
check('sample: author kept', 'Cara', findNode($tree['comments'], 3)['author']);
check('sample: UTC timestamp', '2026-11-02T09:00:00Z', findNode($tree['comments'], 3)['createdIso']);

$grouped = CommentTree::groupByParent($sample);
check('groupByParent: top level under key 0', [1, 5], array_column($grouped[0], 'id'));
check('groupByParent: replies to 1', [2, 4], array_column($grouped[1], 'id'));

// --- Adding a reply under comment 7 gives displayed depth 5 ------------------
$withReply = (new CommentTree())->build([...$sample, row(8, 7)]);
check('reply under 7 has displayed depth 5', 5, findNode($withReply['comments'], 8)['depth']);

// --- Indentation cap at depth 8, labels stay exact ---------------------------
$chain = [row(1, null)];
for ($i = 2; $i <= 12; $i++) {
    $chain[] = row($i, $i - 1);
}
$deep = (new CommentTree(indentCap: 8))->build($chain);
check('depth label at 11 is exact', 11, findNode($deep['comments'], 12)['depth']);
check('depth 7 still indents its children', true, findNode($deep['comments'], 8)['indentChildren']);
check('depth 8 stops indenting its children', false, findNode($deep['comments'], 9)['indentChildren']);

// --- Reply depth limit on the form -----------------------------------------
$limited = (new CommentTree(maxReplyDepth: 5))->build($chain);
check('depth 4 may be replied to (reply depth 5)', true, findNode($limited['comments'], 5)['canReplyHere']);
check('depth 5 may not be replied to', false, findNode($limited['comments'], 6)['canReplyHere']);

// --- Cycle: 2 -> 3 -> 2 is unreachable from the root and is not rendered ------
$cyclic = [row(1, null), row(2, 3), row(3, 2)];
$c = (new CommentTree())->build($cyclic);
check('cycle: only the reachable comment renders', ['1:0'], walk($c['comments']));
check('cycle: two rows reported hidden', 2, $c['hidden']);

// --- Self-parent is also unreachable ------------------------------------------
$self = (new CommentTree())->build([row(1, null), row(2, 2)]);
check('self-parent is not rendered', ['1:0'], walk($self['comments']));

// --- Traversal limits ---------------------------------------------------------
$many = [];
for ($i = 1; $i <= 30; $i++) {
    $many[] = row($i, null);
}
$capped = (new CommentTree(maxNodes: 10))->build($many);
check('node budget stops at 10', [10, true], [$capped['rendered'], $capped['truncated']]);

$tooDeep = (new CommentTree(maxDepth: 5))->build($chain);
check('depth guard stops recursion', [6, true], [$tooDeep['rendered'], $tooDeep['truncated']]);

// --- Discussion ID validation --------------------------------------------------
$cases = ['1' => 1, '42' => 42, '4294967295' => 4294967295, '0' => null, '01' => null,
          '-1' => null, '1.5' => null, 'abc' => null, '' => null, ' 1' => null,
          '4294967296' => null, '99999999999' => null, '1e3' => null];
foreach ($cases as $raw => $expected) {
    check("parseId('{$raw}')", $expected, DiscussionController::parseId((string) $raw));
}

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);

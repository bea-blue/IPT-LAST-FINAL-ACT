<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Comment;
use App\Models\Discussion;
use App\Services\CommentTree;

/**
 * Public, read-only pages: the discussion list and one discussion's reply tree.
 * No SQL and no HTML here: data comes from the models, HTML from the templates.
 */
final class DiscussionController
{
    public function __construct(
        private Discussion $discussions,
        private Comment $comments,
        private View $view,
        private array $forumConfig,
    ) {
    }

    /** GET / */
    public function index(): void
    {
        $list = array_map(fn (array $d) => [
            'id'           => (int) $d['id'],
            'title'        => $d['title'],
            'author'       => $d['author'],
            'commentCount' => (int) $d['comment_count'],
            'createdUtc'   => gmdate('M j, Y', strtotime($d['created_at'] . ' UTC')),
        ], $this->discussions->all());

        Response::html($this->view->render('discussions/index', [
            'discussions'    => $list,
            'hasDiscussions' => $list !== [],
        ]));
    }

    /** GET /discussions/{id} */
    public function show(string $rawId): void
    {
        // 1. Validate the ID. Malformed IDs get 400 and no query runs.
        $id = self::parseId($rawId);
        if ($id === null) {
            Response::error($this->view, 400, 'Discussion IDs are positive whole numbers, for example /discussions/1.');
            return;
        }

        // 2. Load the discussion. A valid but unknown ID gets 404.
        $discussion = $this->discussions->find($id);
        if ($discussion === null) {
            Response::error($this->view, 404, "Discussion {$id} does not exist.");
            return;
        }

        // 3. Load comments with authors, group by parent and build the tree.
        $tree = (new CommentTree(
            $this->forumConfig['indent_cap'],
            $this->forumConfig['max_reply_depth'],
            $this->forumConfig['max_render_nodes'],
            $this->forumConfig['max_render_depth'],
        ))->build($this->comments->forDiscussion($id));

        // 4. Hand plain data to the template.
        Response::html($this->view->render('discussions/show', [
            'pageTitle'  => $discussion['title'],
            'discussion' => [
                'id'         => (int) $discussion['id'],
                'title'      => $discussion['title'],
                'body'       => $discussion['body'],
                'author'     => $discussion['author'],
                'createdIso' => gmdate('Y-m-d\TH:i:s\Z', strtotime($discussion['created_at'] . ' UTC')),
                'createdUtc' => gmdate('M j, Y H:i', strtotime($discussion['created_at'] . ' UTC')) . ' UTC',
            ],
            'comments'      => $tree['comments'],
            'hasComments'   => $tree['comments'] !== [],
            'commentTotal'  => $tree['total'],
            'treeTruncated' => $tree['truncated'] || $tree['hidden'] > 0,
            'hiddenCount'   => $tree['hidden'],
            'canReply'      => Session::isLoggedIn(),
            'maxChars'      => $this->forumConfig['max_comment_chars'],
            'loginUrl'      => '/login',
        ]));
    }

    /**
     * Accepts "1" to "4294967295" (INT UNSIGNED). Rejects "0", "01", "-1",
     * "1.5", "abc", "" and anything longer.
     */
    public static function parseId(string $raw): ?int
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $raw)) {
            return null;
        }
        $id = (int) $raw;
        return $id <= 4294967295 ? $id : null;
    }
}

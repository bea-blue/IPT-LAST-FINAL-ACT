<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Comment;
use App\Models\Discussion;

/**
 * POST /discussions/{id}/comments — top-level comments and replies.
 *
 * Order of checks (matches the "post a reply" activity diagram):
 *   1. logged in?            no  -> redirect to /login, nothing saved
 *   2. CSRF token valid?     no  -> 403, nothing saved
 *   3. discussion ID valid / exists?   -> 400 / 404
 *   4. content and parent valid?       no  -> 422, nothing saved
 *   5. save with the SESSION user ID, then 303 redirect to the new comment
 */
final class CommentController
{
    public function __construct(
        private Discussion $discussions,
        private Comment $comments,
        private View $view,
        private array $forumConfig,
    ) {
    }

    public function store(string $rawDiscussionId): void
    {
        $discussionId = DiscussionController::parseId($rawDiscussionId);
        $backUrl = $discussionId !== null ? "/discussions/{$discussionId}" : '/';

        // 1. Authentication is checked on the server for every write.
        $userId = Session::userId();
        if ($userId === null) {
            Session::setIntended($backUrl);
            Session::flash('error', 'Please log in to post a reply.');
            Response::redirect('/login');
            return;
        }

        // 2. CSRF token bound to this session.
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            Response::error($this->view, 403, 'Your form expired or the security token is invalid. Reload the page and try again.', [], $backUrl);
            return;
        }

        // 3. Discussion must be a valid, existing ID.
        if ($discussionId === null) {
            Response::error($this->view, 400, 'Discussion IDs are positive whole numbers.');
            return;
        }
        if (!$this->discussions->exists($discussionId)) {
            Response::error($this->view, 404, "Discussion {$discussionId} does not exist.");
            return;
        }

        // 4a. Validate content.
        $errors = [];
        $body = $this->cleanBody($_POST['body'] ?? null, $errors);

        // 4b. Validate the parent: empty = top-level, otherwise it must be a
        //     comment in THIS discussion and the new reply must fit the depth limit.
        $parentId = null;
        $rawParent = $_POST['parent_id'] ?? '';
        if (!is_string($rawParent)) {
            $errors[] = 'Invalid parent comment.';
        } elseif ($rawParent !== '') {
            $parentId = DiscussionController::parseId($rawParent);
            if ($parentId === null) {
                $errors[] = 'Invalid parent comment.';
            } else {
                $maxDepth = $this->forumConfig['max_reply_depth'];

                if ($this->comments->findInDiscussion($parentId, $discussionId) === null) {
                    $errors[] = 'That parent comment does not belong to this discussion.';
                } else {
                    $parentDepth = $this->comments->displayedDepth($parentId, $discussionId, $maxDepth);
                    if ($parentDepth === null || $parentDepth + 1 > $maxDepth) {
                        $errors[] = "Replies cannot be nested deeper than depth {$maxDepth}.";
                    }
                }
            }
        }

        if ($errors !== []) {
            Response::error($this->view, 422, 'Nothing was saved.', $errors, $backUrl);
            return;
        }

        // 5. Save. user_id comes from the session, never from the form.
        $newId = $this->comments->create($discussionId, $parentId, $userId, $body);

        Session::flash('success', $parentId === null ? 'Comment posted.' : 'Reply posted.');
        Response::redirect("/discussions/{$discussionId}#comment-{$newId}");
    }

    private function cleanBody(mixed $raw, array &$errors): string
    {
        if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
            $errors[] = 'Comment text is invalid.';
            return '';
        }

        $body = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        $length = mb_strlen($body);
        $max = $this->forumConfig['max_comment_chars'];

        if ($length === 0) {
            $errors[] = 'Comment text is required.';
        } elseif ($length > $max) {
            $errors[] = "Comments can be at most {$max} characters (yours has {$length}).";
        }
        return $body;
    }
}

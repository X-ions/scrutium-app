<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Exceptions\Social\UnsupportedCapabilityException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialHub\ReplyToCommentRequest;
use App\Jobs\Engagement\SyncAccountCommentsJob;
use App\Models\Comment;
use App\Services\Engagement\CommentFilters;
use App\Services\Engagement\CommentQueryService;
use App\Services\Engagement\ReplyService;
use App\Services\Social\SocialProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

final class CommentsController extends Controller
{
    public function __construct(
        private readonly CommentQueryService $inbox,
        private readonly ReplyService $replies,
        private readonly SocialProviderRegistry $registry,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Comment::class);

        $filters = CommentFilters::make([
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'platform' => (array) $request->input('platform', []),
            'account_id' => (array) $request->input('account', []),
            'post_variant_id' => (array) $request->input('post', []),
            'search' => $request->input('search'),
            'handled' => $request->input('replies') === 'mine' ? true : ($request->input('replies') === 'unhandled' ? false : null),
            'sort' => $request->input('sort', 'newest'),
        ]);

        $breakdown = $this->inbox->platformBreakdown();

        return view('pages.socialhub.comments.index', [
            'title' => 'Comments',
            'comments' => $this->inbox->paginate($filters),
            'filters' => $filters->describe(),
            'breakdown' => $breakdown,
            'counters' => $this->inbox->counters(),
            'platforms' => $this->inbox->inboxPlatforms(),
        ]);
    }

    public function show(Request $request, Comment $comment): View
    {
        $this->authorize('view', $comment);

        $comment->load(['socialAccount', 'postVariant.post', 'thread', 'replies']);

        return view('pages.socialhub.comments.show', [
            'title' => 'Comment',
            'comment' => $comment,
            'canReply' => $this->canReply($comment),
            'replyUnavailableReason' => $this->replyUnavailableReason($comment),
        ]);
    }

    public function reply(ReplyToCommentRequest $request, Comment $comment): RedirectResponse
    {
        $this->authorize('reply', $comment);

        try {
            $this->replies->reply($comment, $request->user(), $request->string('body')->toString());
        } catch (UnsupportedCapabilityException $exception) {
            $facing = $exception->getUserFacingError();

            return back()->with('error', $facing?->userMessage
                ?? 'Replying is not available on this network.');
        } catch (Throwable $exception) {
            Log::warning('socialhub.comments.reply_failed', [
                'comment_id' => $comment->id,
                'exception' => $exception::class,
            ]);

            return back()->with('error', 'The reply could not be queued. Try again in a moment.');
        }

        return back()->with('status', 'Reply queued. It will appear here once the network accepts it.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $this->authorize('sync', Comment::class);

        SyncAccountCommentsJob::dispatchForAll();

        return back()->with('status', 'A comment sync has been queued for every connected account.');
    }

    private function canReply(Comment $comment): bool
    {
        $account = $comment->socialAccount;

        if ($account === null) {
            return false;
        }

        try {
            return $this->registry->get((string) $account->provider->value)->getSupportedFeatures()->commentReplies;
        } catch (Throwable) {
            return false;
        }
    }

    private function replyUnavailableReason(Comment $comment): ?string
    {
        if ($this->canReply($comment)) {
            return null;
        }

        $label = $comment->provider?->label() ?? 'This network';

        return sprintf('%s does not offer a reply API, so replies must be sent from the original post.', $label);
    }
}

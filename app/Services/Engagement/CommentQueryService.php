<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Enums\CommentSyncStatus;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\Comment;
use App\Models\SocialAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The unified inbox read model.
 *
 * One query serves the whole inbox regardless of platform. A platform whose
 * account is degraded is reported as degraded rather than dropped, so a
 * Facebook outage never makes the Instagram inbox look empty.
 */
final class CommentQueryService
{
    public function paginate(CommentFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->scoped($filters)
            ->with(['socialAccount', 'postVariant.post', 'replies'])
            ->paginate($perPage)
            ->withQueryString();
    }

    public function search(CommentFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        if ($filters->search === null) {
            return $this->paginate($filters, $perPage);
        }

        return $this->paginate($filters, $perPage);
    }

    /**
     * The single place every read is built from, so a filter can never be
     * accepted by a controller and then quietly ignored.
     */
    public function scoped(CommentFilters $filters): Builder
    {
        $query = Comment::query();

        // Asking for a specific status is an explicit opt-in: filtering to
        // "deleted" or "hidden" would otherwise be defeated by the default
        // visibility rule applied below.
        if ($filters->statuses === []) {
            if (! $filters->includeDeleted) {
                $query->where('is_deleted', false);
            }

            if (! $filters->includeHidden) {
                $query->where('is_hidden', false);
            }
        }

        if ($filters->platforms !== []) {
            $query->whereIn('provider', array_map(
                static fn (SocialPlatform $platform): string => $platform->value,
                $filters->platforms,
            ));
        }

        if ($filters->accountIds !== []) {
            $query->whereIn('social_account_id', $filters->accountIds);
        }

        if ($filters->postVariantIds !== []) {
            $query->whereIn('post_variant_id', $filters->postVariantIds);
        }

        if ($filters->statuses !== []) {
            $this->applyStatusFilter($query, $filters->statuses);
        }

        if ($filters->from !== null) {
            $query->where('provider_created_at', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('provider_created_at', '<=', $filters->to);
        }

        if ($filters->search !== null) {
            $like = '%'.mb_strtolower($filters->search).'%';

            $query->where(function (Builder $query) use ($like): void {
                $query->whereRaw('LOWER(content) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(author_username) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(author_display_name) LIKE ?', [$like]);
            });
        }

        // A comment is only "handled" once a reply of ours was actually
        // delivered. A pending or failed reply leaves the workspace owing an
        // answer, so the comment stays in the inbox.
        if ($filters->handledOnly === true || $filters->unreadOnly === true) {
            $query->whereHas('replies', fn (Builder $q) => $q->where('status', CommentSyncStatus::Sent->value));
        }

        if ($filters->handledOnly === false) {
            $query->whereDoesntHave(
                'replies',
                fn (Builder $q) => $q->where('status', CommentSyncStatus::Sent->value),
            );
        }

        return $this->applySort($query, $filters->sort);
    }

    /**
     * @param  list<CommentSyncStatus>  $statuses
     */
    private function applyStatusFilter(Builder $query, array $statuses): void
    {
        $query->where(function (Builder $query) use ($statuses): void {
            foreach ($statuses as $status) {
                match ($status) {
                    CommentSyncStatus::Deleted => $query->orWhere('is_deleted', true),
                    CommentSyncStatus::Failed => $query->orWhere('is_hidden', true),
                    CommentSyncStatus::Synced, CommentSyncStatus::Sent => $query->orWhere(function (Builder $q): void {
                        $q->where('is_deleted', false)->where('is_hidden', false)->whereNotNull('synced_at');
                    }),
                    CommentSyncStatus::Pending => $query->orWhereNull('synced_at'),
                };
            }
        });
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'oldest' => $query->orderBy('provider_created_at')->orderBy('id'),
            'most_liked' => $query->orderByDesc('like_count')->orderByDesc('provider_created_at'),
            // An unrecognised sort falls back rather than throwing: a bookmarked
            // URL from an older release should still render the inbox.
            default => $query->orderByDesc('provider_created_at')->orderByDesc('id'),
        };
    }

    /**
     * Per-platform view used by the filter chips. A degraded platform is kept
     * in the result with `degraded => true` so a failure is visible instead of
     * making the other platforms look like the whole story.
     *
     * @return array<string, array<string, mixed>>
     */
    public function platformBreakdown(): array
    {
        $comments = Comment::query()
            ->select('provider')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider');

        $accounts = SocialAccount::query()
            ->select('provider')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider');

        $connected = SocialAccount::query()
            ->connected()
            ->select('provider')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider');

        $errors = SocialAccount::query()
            ->whereNotNull('last_error')
            ->select('provider')
            ->selectRaw('MAX(last_error) as last_error')
            ->groupBy('provider')
            ->pluck('last_error', 'provider');

        $keys = array_values(array_unique(array_merge(
            $comments->keys()->all(),
            $accounts->keys()->all(),
            $connected->keys()->all(),
        )));

        sort($keys);

        $breakdown = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            $error = $errors[$key] ?? null;

            $breakdown[$key] = [
                'platform' => $key,
                'comments' => (int) ($comments[$key] ?? 0),
                'accounts' => (int) ($accounts[$key] ?? 0),
                'connected_accounts' => (int) ($connected[$key] ?? 0),
                'available' => $error === null,
                'degraded' => $error !== null,
                'last_error' => $error,
            ];
        }

        return $breakdown;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function groupedByPlatform(CommentFilters $filters, int $perPage = 25): array
    {
        $groups = [];

        foreach ($this->scoped($filters)->paginate($perPage) as $comment) {
            /** @var Comment $comment */
            $key = $comment->provider?->value ?? 'unknown';

            $groups[$key] ??= ['platform' => $key, 'total' => 0, 'comments' => []];

            $groups[$key]['comments'][] = $this->present($comment);
            $groups[$key]['total']++;
        }

        // Group order follows the platform key, not the page order, so the
        // sections do not reshuffle between two identical queries.
        ksort($groups);

        return $groups;
    }

    /**
     * @return array{total: int, unreplied: int, platforms: int}
     */
    public function counters(): array
    {
        $total = Comment::query()->count();

        $unreplied = Comment::query()
            ->whereDoesntHave('replies', fn (Builder $q) => $q->where('status', CommentSyncStatus::Sent->value))
            ->count();

        $platforms = Comment::query()->distinct()->count('provider');

        return [
            'total' => $total,
            'unreplied' => $unreplied,
            'platforms' => $platforms,
        ];
    }

    /**
     * Credential-free view of a comment for the UI and the API.
     *
     * @return array<string, mixed>
     */
    public function present(Comment $comment): array
    {
        $comment->loadMissing(['socialAccount', 'postVariant.post']);

        return [
            'id' => $comment->id,
            'content' => (string) $comment->content,
            'platform' => $comment->provider?->value,
            'platform_label' => $comment->provider?->label(),
            'like_count' => (int) $comment->like_count,
            'reply_count' => (int) $comment->reply_count,
            'created_at' => $comment->provider_created_at?->toIso8601String(),
            'sync_status' => $comment->syncStatus()->value,
            'author' => [
                'provider_id' => $comment->author_provider_id,
                'username' => $comment->author_username,
                'display_name' => $comment->authorDisplayLabel(),
                'avatar_url' => $comment->author_avatar_url,
            ],
            'post' => [
                'post_variant_id' => $comment->post_variant_id,
                'post_id' => $comment->postVariant?->post_id,
                'title' => $comment->postVariant?->post?->title,
                'url' => $comment->postVariant?->provider_post_url,
            ],
        ];
    }

    /**
     * @return array{count: int, sent: int, failed: int, pending: int}
     */
    public function replyState(Comment $comment): array
    {
        $replies = $comment->replies()->get(['status']);

        return [
            'count' => $replies->count(),
            'sent' => $replies->where('status', CommentSyncStatus::Sent->value)->count(),
            'failed' => $replies->where('status', CommentSyncStatus::Failed->value)->count(),
            'pending' => $replies->where('status', CommentSyncStatus::Pending->value)->count(),
        ];
    }

    /**
     * Platforms with at least one account that could contribute comments.
     *
     * @return list<SocialPlatform>
     */
    public function inboxPlatforms(): array
    {
        return SocialAccount::query()
            ->whereIn('status', [SocialAccountStatus::Connected->value, SocialAccountStatus::Error->value])
            ->distinct()
            ->pluck('provider')
            ->map(fn ($provider) => $provider instanceof SocialPlatform ? $provider : SocialPlatform::tryFrom((string) $provider))
            ->filter()
            ->values()
            ->all();
    }
}

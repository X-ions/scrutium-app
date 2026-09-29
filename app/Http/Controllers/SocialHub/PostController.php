<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialHub\SavePostRequest;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Media\MediaLibraryService;
use App\Services\Publishing\PublishingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class PostController extends Controller
{
    public function __construct(private readonly PublishingService $publishing) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Post::class);

        $posts = Post::query()
            ->with(['author', 'variants.socialAccount'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($query) => $query->search((string) $request->string('search')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('pages.socialhub.posts.index', [
            'title' => 'Posts',
            'posts' => $posts,
            'statuses' => PostStatus::options(),
        ]);
    }

    public function create(Request $request, MediaLibraryService $media): View
    {
        $this->authorize('create', Post::class);

        return view('pages.socialhub.posts.composer', $this->composerPayload(new Post([
            'tenant_id' => $request->user()->tenant_id,
            'user_id' => $request->user()->id,
        ]), $media));
    }

    public function store(SavePostRequest $request, MediaLibraryService $media): RedirectResponse
    {
        $this->authorize('create', Post::class);

        $post = $this->persist($request, new Post, PostStatus::Draft);

        if ($request->boolean('publish_now')) {
            $this->publishing->publishNow($post->load('variants'), $request->user());

            return redirect()
                ->route('socialhub.posts.show', $post)
                ->with('status', 'Publishing has started. Each network is tracked separately below.');
        }

        if ($request->filled('scheduled_at')) {
            $this->publishing->schedule(
                $post->load('variants'),
                now()->parse($request->string('scheduled_at')->toString()),
                (string) config('app.timezone', 'UTC'),
                $request->user(),
            );

            return redirect()
                ->route('socialhub.calendar')
                ->with('status', 'Post scheduled.');
        }

        return redirect()
            ->route('socialhub.posts.show', $post)
            ->with('status', 'Draft saved.');
    }

    public function show(Request $request, Post $post): View
    {
        $this->authorize('view', $post);

        $post->load(['variants.socialAccount', 'variants.media', 'variants.publishingAttempts', 'author']);

        return view('pages.socialhub.posts.show', [
            'title' => $post->title ?: 'Post',
            'post' => $post,
        ]);
    }

    public function edit(Request $request, Post $post, MediaLibraryService $media): View
    {
        $this->authorize('update', $post);

        $post->load('variants.media');

        return view('pages.socialhub.posts.composer', $this->composerPayload($post, $media));
    }

    public function update(SavePostRequest $request, Post $post, MediaLibraryService $media): RedirectResponse
    {
        $this->authorize('update', $post);

        $post = $this->persist($request, $post, $post->statusEnum());

        return redirect()
            ->route('socialhub.posts.show', $post)
            ->with('status', 'Post updated.');
    }

    public function publish(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('publish', $post);

        $this->publishing->publishNow($post->load('variants'), $request->user());

        return back()->with('status', 'Publishing has started. Each network is tracked separately.');
    }

    public function schedule(SavePostRequest $request, Post $post): RedirectResponse
    {
        $this->authorize('schedule', $post);

        $this->publishing->schedule(
            $post->load('variants'),
            now()->parse($request->string('scheduled_at')->toString()),
            (string) config('app.timezone', 'UTC'),
            $request->user(),
        );

        return redirect()
            ->route('socialhub.calendar')
            ->with('status', 'Post scheduled.');
    }

    public function cancel(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('schedule', $post);

        $cancelled = 0;

        foreach ($post->variants as $variant) {
            if (in_array($variant->statusEnum(), [PostVariantStatus::Pending, PostVariantStatus::Scheduled], true)) {
                $variant->scheduledPost?->update(['status' => 'cancelled', 'processed_at' => now()]);
                $variant->update(['status' => PostVariantStatus::Cancelled, 'scheduled_at' => null]);
                $cancelled++;
            }
        }

        return back()->with('status', $cancelled > 0
            ? "Cancelled {$cancelled} scheduled network(s)."
            : 'There was nothing scheduled to cancel.');
    }

    public function duplicate(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('duplicate', $post);

        $copy = DB::transaction(function () use ($post, $request): Post {
            $clone = $post->replicate(['id', 'published_at']);
            $clone->status = PostStatus::Draft;
            $clone->published_at = null;
            $clone->title = $post->title ? \Illuminate\Support\Str::limit($post->title.' (copy)', 255, '') : null;
            $clone->user_id = $request->user()->id;
            $clone->save();

            foreach ($post->variants as $variant) {
                $variantClone = $variant->replicate(['post_id']);
                $variantClone->post_id = $clone->id;
                $variantClone->status = PostVariantStatus::Pending;
                $variantClone->provider_post_id = null;
                $variantClone->provider_post_url = null;
                $variantClone->error_message = null;
                $variantClone->retry_count = 0;
                $variantClone->scheduled_at = null;
                $variantClone->published_at = null;
                $variantClone->save();

                $variant->media()->get()->each(function ($asset) use ($variantClone): void {
                    $variantClone->media()->attach($asset->id, ['sort_order' => 0]);
                });
            }

            return $clone;
        });

        return redirect()
            ->route('socialhub.posts.edit', $copy)
            ->with('status', 'Post duplicated as a draft.');
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()
            ->route('socialhub.posts.index')
            ->with('status', 'Post deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function composerPayload(Post $post, MediaLibraryService $media): array
    {
        $post->loadMissing('variants.media');

        $accounts = SocialAccount::query()
            ->connected()
            ->orderBy('provider')
            ->get()
            ->map(fn (SocialAccount $account, int $index) => [
                'index' => $index,
                'id' => $account->id,
                'label' => $account->provider_display_name ?: $account->provider_username ?: 'Account',
                'provider' => $account->provider?->value,
                'platform' => $account->provider?->label(),
                'avatar' => $account->provider_avatar_url,
                'max_caption' => $account->provider?->maxCaptionLength() ?? 5000,
                'max_media' => $account->provider?->maxMediaAttachments() ?? 1,
                'supports_hashtags' => (bool) $account->provider?->supportsNativeHashtags(),
            ])
            ->values();

        return [
            'title' => $post->title ? 'Edit post' : 'New post',
            'post' => $post,
            'accounts' => $accounts,
            'selectedIds' => $post->exists
                ? $post->variants->pluck('social_account_id')->map('intval')->values()
                : collect(),
            'media' => $media->paginate([], 24),
            'platforms' => SocialPlatform::cases(),
        ];
    }

    /**
     * Write the master post plus one variant per selected network.
     */
    private function persist(SavePostRequest $request, Post $post, PostStatus $status): Post
    {
        return DB::transaction(function () use ($request, $post, $status): Post {
            $post->fill([
                'tenant_id' => $request->user()->tenant_id,
                'user_id' => $post->exists ? $post->user_id : $request->user()->id,
                'title' => $request->input('title'),
                'notes' => $request->input('notes'),
                'campaign_id' => $request->input('campaign_id'),
                'tags' => $request->input('tags', []),
                'status' => $post->exists ? $post->statusEnum() : $status,
            ]);
            $post->save();

            $keepVariantIds = [];

            foreach ((array) $request->input('variants', []) as $payload) {
                $account = SocialAccount::query()->findOrFail((int) $payload['social_account_id']);
                $mediaIds = array_map('intval', (array) ($payload['media_ids'] ?? []));

                $variant = $post->variants()->firstOrNew(['social_account_id' => $account->id]);

                $variant->fill([
                    'provider' => $account->provider,
                    'caption' => $payload['caption'] ?? null,
                    'hashtags' => $payload['hashtags'] ?? [],
                    'media_ids' => $mediaIds,
                    'scheduled_at' => $payload['scheduled_at'] ?? null,
                ]);

                if (! $variant->status instanceof PostVariantStatus) {
                    $variant->status = PostVariantStatus::Pending;
                }

                $variant->save();

                $keepVariantIds[] = $variant->id;

                $variant->media()->sync(array_map(
                    static fn (int $mediaId): array => ['media_id' => $mediaId, 'sort_order' => 0],
                    $mediaIds,
                ));
            }

            // Variants removed in the editor are dropped only while the post has
            // not reached a network yet; a published variant is kept so the
            // history of what went live stays intact.
            $post->variants()
                ->whereNotIn('id', $keepVariantIds ?: [0])
                ->where('status', PostVariantStatus::Pending->value)
                ->get()
                ->each->delete();

            return $post->fresh(['variants', 'variants.media']);
        });
    }
}

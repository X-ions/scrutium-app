<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Enums\PostVariantStatus;
use App\Enums\ScheduledPostStatus;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\Engagement\CommentQueryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly AnalyticsQueryService $analytics,
        private readonly CommentQueryService $inbox,
    ) {}

    public function index(Request $request): View
    {
        $filters = AnalyticsFilters::make([
            'from' => $request->date('from')?->toDateString() ?? now()->subDays(29)->toDateString(),
            'to' => $request->date('to')?->toDateString() ?? now()->toDateString(),
            'platforms' => (array) $request->input('platform', []),
            'account_ids' => (array) $request->input('account', []),
        ]);

        $headline = $this->analytics->headlineTotals($filters);

        return view('pages.socialhub.dashboard', [
            'title' => 'Dashboard',
            'headline' => $headline,
            'viewsSeries' => $this->analytics->metricSeries($filters, 'views'),
            'engagementSeries' => $this->analytics->metricSeries($filters, 'engagement'),
            'followerGrowth' => $this->analytics->followerGrowth($filters),
            'platformComparison' => $this->analytics->platformComparison($filters),
            'topPosts' => $this->analytics->topPosts($filters, 'engagement', 6),
            'availability' => $this->analytics->availability($filters),
            'accounts' => SocialAccount::query()->connected()->orderBy('provider')->get()
                ->map(fn (SocialAccount $account) => [
                    'id' => $account->id,
                    'label' => $account->provider_display_name ?: $account->provider_username ?: $account->provider?->label(),
                    'provider' => $account->provider?->value,
                ]),
            'platforms' => SocialPlatform::cases(),
            'filters' => [
                'from' => $filters->from->format('Y-m-d'),
                'to' => $filters->to->format('Y-m-d'),
                'platform' => $filters->platforms,
                'account' => $filters->accountIds,
            ],
            'upcoming' => $this->upcoming(),
            'recent' => $this->recent(),
            'needsAttention' => $this->needsAttention(),
            'unhandledComments' => $this->inbox->counters()['unreplied'],
            'mediaCount' => \App\Models\MediaAsset::query()->count(),
            'counters' => $this->counters(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcoming(): array
    {
        return PostVariant::query()
            ->with(['post', 'socialAccount'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now())
            ->inStatus(PostVariantStatus::Pending, PostVariantStatus::Scheduled)
            ->orderBy('scheduled_at')
            ->limit(8)
            ->get()
            ->map(fn (PostVariant $variant) => [
                'id' => $variant->id,
                'post_id' => $variant->post_id,
                'title' => $variant->post?->title ?: 'Untitled post',
                'provider' => $variant->provider?->value,
                'platform' => $variant->provider?->label(),
                'scheduled_at' => $variant->scheduled_at?->toIso8601String(),
                'scheduled_human' => $variant->scheduled_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recent(): array
    {
        return Post::query()
            ->with('variants.socialAccount')
            ->published()
            ->latest('published_at')
            ->limit(6)
            ->get()
            ->map(fn (Post $post) => [
                'id' => $post->id,
                'title' => $post->title ?: 'Untitled post',
                'published_at' => $post->published_at?->toIso8601String(),
                'published_human' => $post->published_at?->diffForHumans(),
                'networks' => $post->variants->map(fn (PostVariant $variant) => [
                    'provider' => $variant->provider?->value,
                    'status' => $variant->status?->value,
                    'url' => $variant->provider_post_url,
                ])->all(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function needsAttention(): array
    {
        return SocialAccount::query()
            ->needingAttention()
            ->get()
            ->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'label' => $account->provider_display_name ?: $account->provider_username,
                'platform' => $account->provider?->label(),
                'status' => $account->statusEnum()->value,
                'status_label' => $account->statusEnum()->label(),
                'error' => $account->last_error,
            ])
            ->all();
    }

    /**
     * Publishing backlog counters used by the dashboard tiles.
     *
     * @return array<string, int>
     */
    public function counters(): array
    {
        return [
            'drafts' => Post::query()->drafts()->count(),
            'scheduled' => PostVariant::query()
                ->whereNotNull('scheduled_at')
                ->inStatus(PostVariantStatus::Pending, PostVariantStatus::Scheduled)
                ->count(),
            'failed' => PostVariant::query()->failed()->count(),
            'queued' => \App\Models\ScheduledPost::query()
                ->whereIn('status', [
                    ScheduledPostStatus::Pending->value,
                    ScheduledPostStatus::Queued->value,
                    ScheduledPostStatus::Processing->value,
                ])
                ->count(),
        ];
    }
}

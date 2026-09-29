<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Enums\MetricType;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsQueryService $analytics) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('pages.socialhub.analytics.index', [
            'title' => 'Analytics',
            'filters' => $this->filterPayload($filters),
            'headline' => $this->analytics->headlineTotals($filters),
            'viewsSeries' => $this->analytics->metricSeries($filters, MetricType::Views),
            'engagementSeries' => $this->analytics->metricSeries($filters, MetricType::Engagement),
            'followerGrowth' => $this->analytics->followerGrowth($filters),
            'platformComparison' => $this->analytics->platformComparison($filters),
            'topPosts' => $this->analytics->topPosts($filters, MetricType::Engagement),
            'contentTypes' => $this->analytics->contentTypePerformance($filters),
            'postingFrequency' => $this->analytics->postingFrequency($filters),
            'availability' => $this->analytics->availability($filters),
            'accounts' => SocialAccount::query()->connected()->orderBy('provider')->get()
                ->map(fn (SocialAccount $account) => [
                    'id' => $account->id,
                    'label' => $account->provider_display_name ?: $account->provider_username ?: $account->provider?->label(),
                    'provider' => $account->provider?->value,
                ]),
            'platforms' => SocialPlatform::cases(),
        ]);
    }

    public function content(Request $request): View
    {
        $filters = $this->filters($request);

        return view('pages.socialhub.analytics.content', [
            'title' => 'Content performance',
            'filters' => $this->filterPayload($filters),
            'topPosts' => $this->analytics->topPosts($filters, MetricType::Engagement, 25),
            'viewsSeries' => $this->analytics->metricSeries($filters, MetricType::Views),
            'contentTypes' => $this->analytics->contentTypePerformance($filters),
            'postingFrequency' => $this->analytics->postingFrequency($filters),
            'platforms' => SocialPlatform::cases(),
            'accounts' => SocialAccount::query()->connected()->get()
                ->map(fn (SocialAccount $a) => ['id' => $a->id, 'label' => $a->provider_display_name ?: $a->provider?->label()]),
        ]);
    }

    public function accounts(Request $request): View
    {
        $filters = $this->filters($request);

        return view('pages.socialhub.analytics.accounts', [
            'title' => 'Account performance',
            'filters' => $this->filterPayload($filters),
            'platformComparison' => $this->analytics->platformComparison($filters),
            'followerGrowth' => $this->analytics->followerGrowth($filters),
            'availability' => $this->analytics->availability($filters),
            'accounts' => SocialAccount::query()
                ->with('metrics')
                ->connected()
                ->get()
                ->map(fn (SocialAccount $account) => [
                    'id' => $account->id,
                    'label' => $account->provider_display_name ?: $account->provider_username ?: $account->provider?->label(),
                    'provider' => $account->provider?->value,
                    'status' => $account->statusEnum()->label(),
                    'last_synced_at' => $account->last_synced_at?->diffForHumans(),
                ]),
        ]);
    }

    public function post(Request $request, Post $post): View
    {
        $this->authorize('view', $post);

        $filters = $this->filters($request);

        return view('pages.socialhub.analytics.post', [
            'title' => $post->title ?: 'Post performance',
            'post' => $post->load(['variants.socialAccount', 'variants.metrics']),
            'filters' => $this->filterPayload($filters),
            'series' => $this->analytics->metricSeries($filters, MetricType::Engagement),
        ]);
    }

    private function filters(Request $request): AnalyticsFilters
    {
        return AnalyticsFilters::make([
            'from' => $request->date('from')?->toDateString() ?? now()->subDays(29)->toDateString(),
            'to' => $request->date('to')?->toDateString() ?? now()->toDateString(),
            'platforms' => (array) $request->input('platform', []),
            'account_ids' => (array) $request->input('account', []),
            'content_types' => (array) $request->input('content_type', []),
            'campaign_ids' => (array) $request->input('campaign', []),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filterPayload(AnalyticsFilters $filters): array
    {
        return [
            'from' => $filters->from->format('Y-m-d'),
            'to' => $filters->to->format('Y-m-d'),
            'platform' => array_map(static fn (SocialPlatform $p) => $p->value, $filters->platforms),
            'account' => $filters->accountIds,
            'content_type' => $filters->contentTypes,
            'campaign' => $filters->campaignIds,
        ];
    }
}

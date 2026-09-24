<?php

namespace App\Http\Controllers;

use App\Enums\DeliverableStatus;
use App\Models\Campaign;
use App\Models\ContentPost;
use App\Models\Deliverable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PerformanceController extends Controller
{
    public function index(Request $request): View
    {
        $campaignId = $request->integer('campaign_id') ?: null;
        $campaigns = Campaign::query()->orderBy('name')->get(['id', 'name']);
        $approved = Deliverable::query()
            ->where('status', DeliverableStatus::Approved->value)
            ->when($campaignId, fn ($query) => $query->where('campaign_id', $campaignId));
        $content = ContentPost::query()
            ->when($campaignId, fn ($query) => $query->where('campaign_id', $campaignId));
        $spend = (float) $approved->sum('fee');
        $engagements = (int) $content->sum('likes') + (int) $content->sum('comments') + (int) $content->sum('shares') + (int) $content->sum('saves');
        $reach = (int) $content->sum('reach');
        $impressions = (int) $content->sum('impressions');

        $byCampaign = Campaign::query()
            ->when($campaignId, fn ($query) => $query->whereKey($campaignId))
            ->withSum(['deliverables as approved_spend' => fn ($query) => $query->where('status', DeliverableStatus::Approved->value)], 'fee')
            ->withSum(['contentPosts as total_reach'], 'reach')
            ->orderBy('name')
            ->get()
            ->map(function (Campaign $campaign) use ($spend, $reach): array {
                $campaignSpend = (float) $campaign->approved_spend;
                $campaignReach = (int) $campaign->total_reach;

                return [
                    'campaign' => $campaign,
                    'spend' => $campaignSpend,
                    'reach' => $campaignReach,
                    'cpe' => $campaignSpend > 0 && $campaignReach > 0 ? round($campaignSpend / $campaignReach, 4) : null,
                    'share' => $reach > 0 ? round(($campaignReach / $reach) * 100, 1) : 0,
                ];
            });

        return view('pages.scrutium.performance.index', [
            'title' => 'Performance',
            'campaigns' => $campaigns,
            'selectedCampaign' => $campaignId,
            'metrics' => [
                'spend' => $spend,
                'engagements' => $engagements,
                'reach' => $reach,
                'impressions' => $impressions,
                'cpe' => $engagements > 0 ? round($spend / $engagements, 4) : null,
                'engagement_rate' => $reach > 0 ? round(($engagements / $reach) * 100, 2) : 0,
            ],
            'byCampaign' => $byCampaign,
        ]);
    }
}
<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStage;
use App\Enums\DeliverableStatus;
use App\Models\Alert;
use App\Models\Campaign;
use App\Models\ContentPost;
use App\Models\Deliverable;
use App\Models\Influencer;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $campaigns = Campaign::query()
            ->with('owner')
            ->withCount(['deliverables', 'influencers'])
            ->latest('updated_at')
            ->limit(6)
            ->get();

        $pipeline = collect(CampaignStage::pipeline())->map(function (CampaignStage $stage): array {
            return [
                'stage' => $stage,
                'count' => Campaign::where('stage', $stage->value)->count(),
            ];
        });

        $deliverableTotal = Deliverable::count();
        $posted = Deliverable::whereIn('status', [
            DeliverableStatus::Submitted->value,
            DeliverableStatus::Approved->value,
        ])->count();
        $approved = Deliverable::where('status', DeliverableStatus::Approved->value)->count();
        $postCount = ContentPost::count();
        $flaggedCount = ContentPost::flagged()->count();
        $integrations = Integration::query()->get();
        $connectedIntegrations = $integrations->filter(fn (Integration $integration): bool => $integration->isHealthy())->count();

        return view('pages.dashboard.overview', [
            'title' => 'Overview',
            'user' => $request->user(),
            'metrics' => [
                ['label' => 'Active campaigns', 'value' => Campaign::open()->count(), 'detail' => 'Open workspace campaigns', 'tone' => 'brand'],
                ['label' => 'Creators in roster', 'value' => Influencer::count(), 'detail' => 'Across your creator intelligence library', 'tone' => 'blue'],
                ['label' => 'Deliverables outstanding', 'value' => Deliverable::outstanding()->count(), 'detail' => Deliverable::overdue()->count().' overdue', 'tone' => 'warning'],
                ['label' => 'Integrity score', 'value' => $postCount > 0 ? round((1 - ($flaggedCount / $postCount)) * 100).'%' : '100%', 'detail' => $flaggedCount.' content flags to review', 'tone' => 'success'],
            ],
            'pipeline' => $pipeline,
            'attainment' => [
                'posted' => $deliverableTotal > 0 ? round(($posted / $deliverableTotal) * 100) : 0,
                'approved' => $deliverableTotal > 0 ? round(($approved / $deliverableTotal) * 100) : 0,
            ],
            'campaigns' => $campaigns,
            'topCreators' => Influencer::query()->orderByDesc('pulse_score')->limit(5)->get(),
            'openAlerts' => Alert::query()->open()->mostSevereFirst()->limit(5)->get(),
            'integrations' => $integrations,
            'connectedIntegrations' => $connectedIntegrations,
        ]);
    }
}

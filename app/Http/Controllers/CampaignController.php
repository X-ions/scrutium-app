<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStage;
use App\Enums\CampaignStatus;
use App\Enums\DeliverableStatus;
use App\Enums\DeliverableType;
use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\Influencer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function index(Request $request): View
    {
        $campaigns = Campaign::query()
            ->with('owner')
            ->withCount(['deliverables', 'influencers'])
            ->search($request->string('search')->toString())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('stage'), fn ($query) => $query->where('stage', $request->string('stage')))
            ->latest('updated_at')
            ->paginate(12)
            ->withQueryString();

        return view('pages.scrutium.campaigns.index', compact('campaigns') + [
            'title' => 'Campaigns',
            'stages' => CampaignStage::options(),
            'statuses' => CampaignStatus::options(),
        ]);
    }

    public function tools(): View
    {
        $activeCampaigns = Campaign::query()
            ->where('status', CampaignStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name', 'budget_total']);
        $creators = Influencer::bookable()
            ->orderByDesc('pulse_score')
            ->limit(100)
            ->get(['id', 'handle', 'full_name', 'platform', 'country', 'followers', 'engagement_rate', 'pulse_score', 'tier'])
            ->map(fn (Influencer $creator): array => [
                'id' => $creator->id,
                'name' => $creator->displayName(),
                'platform' => $creator->platform->value,
                'platformName' => $creator->platform->label(),
                'country' => $creator->country,
                'followers' => $creator->followers,
                'engagement' => (float) $creator->engagement_rate,
                'pulse' => (float) ($creator->pulse_score ?? 0),
                'tier' => $creator->tier->value,
            ])->values();

        return view('pages.scrutium.discover.campaign-tools', [
            'title' => 'Campaign tools',
            'activeCampaigns' => $activeCampaigns,
            'creators' => $creators,
            'platforms' => Platform::options(),
            'countries' => $creators->pluck('country')->filter()->unique()->sort()->values(),
        ]);
    }

    public function applyTools(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'campaign_id' => [
                'required',
                'integer',
                Rule::exists('campaigns', 'id')
                    ->where('tenant_id', $request->user()->tenant_id)
                    ->where('status', CampaignStatus::Active->value),
            ],
            'objective' => ['nullable', 'string', 'max:180'],
            'brief' => ['required', 'string', 'max:10000'],
            'budget_total' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $campaign = Campaign::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('status', CampaignStatus::Active->value)
            ->findOrFail($data['campaign_id']);

        $updates = [
            'objective' => $data['objective'] ?? $campaign->objective,
            'brief' => $data['brief'],
        ];

        if (array_key_exists('budget_total', $data) && $data['budget_total'] !== null) {
            $updates['budget_total'] = $data['budget_total'];
        }

        $campaign->update($updates);

        return redirect()->route('campaigns.show', $campaign)
            ->with('success', 'Campaign tools draft applied to '.$campaign->name.'.');
    }

    public function create(Request $request): View
    {
        $name = $request->query('name');
        $objective = $request->query('objective');
        $brief = $request->query('brief');
        $budget = $request->query('budget_total');

        return view('pages.scrutium.campaigns.create', [
            'title' => 'New campaign',
            'stages' => CampaignStage::options(),
            'statuses' => CampaignStatus::options(),
            'toolSeed' => [
                'name' => Str::limit(is_string($name) ? trim($name) : '', 180, ''),
                'objective' => Str::limit(is_string($objective) ? trim($objective) : '', 180, ''),
                'brief' => Str::limit(is_string($brief) ? $brief : '', 10000, ''),
                'budget_total' => is_numeric($budget)
                    ? min(max((float) $budget, 0), 999999999)
                    : 0,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'objective' => ['nullable', 'string', 'max:180'],
            'brief' => ['nullable', 'string', 'max:10000'],
            'stage' => ['required', Rule::enum(CampaignStage::class)],
            'status' => ['required', Rule::enum(CampaignStatus::class)],
            'budget_total' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $campaign = Campaign::create([
            ...$data,
            'tenant_id' => $request->user()->tenant_id,
            'owner_id' => $request->user()->id,
            'slug' => $this->uniqueSlug($data['name']),
            'budget_spent' => 0,
            'currency' => $request->user()->tenant->currency,
        ]);

        return redirect()->route('campaigns.show', $campaign)->with('success', 'Campaign created.');
    }

    public function show(Campaign $campaign): View
    {
        abort_unless($campaign->tenant_id === auth()->user()->tenant_id, 404);
        $campaign->load(['owner', 'influencers', 'deliverables.influencer', 'contentPosts.influencer']);
        $availableCreators = Influencer::bookable()
            ->whereNotIn('influencers.id', $campaign->influencers()->select('influencers.id'))
            ->orderByDesc('pulse_score')
            ->limit(30)
            ->get();

        return view('pages.scrutium.campaigns.show', compact('campaign', 'availableCreators') + [
            'title' => $campaign->name,
            'stages' => CampaignStage::options(),
            'statuses' => CampaignStatus::options(),
            'deliverableTypes' => DeliverableType::options(),
            'platforms' => Platform::options(),
        ]);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'objective' => ['nullable', 'string', 'max:180'],
            'brief' => ['nullable', 'string', 'max:10000'],
            'stage' => ['required', Rule::enum(CampaignStage::class)],
            'status' => ['required', Rule::enum(CampaignStatus::class)],
            'budget_total' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $campaign->update($data);

        return back()->with('success', 'Campaign details updated.');
    }

    public function addCreator(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->tenant_id === $request->user()->tenant_id, 404);

        $data = $request->validate([
            'influencer_id' => ['required', Rule::exists('influencers', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'role' => ['nullable', 'string', 'max:80'],
            'status' => ['required', Rule::in(['invited', 'confirmed', 'declined'])],
            'agreed_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $creator = Influencer::bookable()->findOrFail($data['influencer_id']);
        $campaign->influencers()->syncWithoutDetaching([
            $creator->id => [
                'role' => $data['role'] ?? 'creator',
                'status' => $data['status'],
                'agreed_fee' => $data['agreed_fee'] ?? null,
            ],
        ]);

        return back()->with('success', 'Creator added to the campaign roster.');
    }

    public function removeCreator(Request $request, Campaign $campaign, Influencer $influencer): RedirectResponse
    {
        abort_unless($campaign->tenant_id === $request->user()->tenant_id, 404);
        abort_unless($influencer->tenant_id === $campaign->tenant_id, 404);

        $campaign->influencers()->detach($influencer->id);

        return back()->with('success', 'Creator removed from the roster.');
    }

    public function storeDeliverable(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->tenant_id === $request->user()->tenant_id, 404);

        $data = $request->validate([
            'influencer_id' => ['required', Rule::exists('campaign_influencer', 'influencer_id')->where('campaign_id', $campaign->id)],
            'title' => ['required', 'string', 'max:180'],
            'type' => ['required', Rule::enum(DeliverableType::class)],
            'platform' => ['required', Rule::enum(Platform::class)],
            'contracted_units' => ['required', 'integer', 'min:1', 'max:1000'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'due_at' => ['nullable', 'date'],
        ]);

        $creator = $campaign->influencers()
            ->where('influencers.id', $data['influencer_id'])
            ->firstOrFail();

        abort_unless($creator->tenant_id === $campaign->tenant_id, 422);

        $deliverable = $campaign->deliverables()->create([
            ...$data,
            'influencer_id' => $creator->id,
            'tenant_id' => $campaign->tenant_id,
            'status' => DeliverableStatus::Pending->value,
            'delivered_units' => 0,
        ]);
        $deliverable->auditEvents()->create(['action' => 'created', 'to_status' => $deliverable->status->value]);

        return back()->with('success', 'Deliverable added.');
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'campaign';
        $slug = $base;
        $suffix = 2;

        while (Campaign::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}

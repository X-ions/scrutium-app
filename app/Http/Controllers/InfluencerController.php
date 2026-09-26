<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\VettingStatus;
use App\Models\Influencer;
use App\Models\InfluencerTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InfluencerController extends Controller
{
    public function index(Request $request): View
    {
        $creators = Influencer::query()
            ->with('latestScore')
            ->withCount(['campaigns', 'deliverables'])
            ->search($request->string('search')->toString())
            ->when($request->filled('platform'), fn ($query) => $query->where('platform', $request->string('platform')))
            ->when($request->filled('status'), fn ($query) => $query->where('vetting_status', $request->string('status')))
            ->orderByDesc('pulse_score')
            ->paginate(15)
            ->withQueryString();

        return view('pages.scrutium.influencers.index', compact('creators') + [
            'title' => 'Influencers',
            'platforms' => Platform::options(),
            'statuses' => VettingStatus::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'handle' => ['required', 'string', 'max:120'],
            'platform' => ['required', Rule::enum(Platform::class)],
            'full_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'followers' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'engagement_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $handle = '@'.ltrim($data['handle'], '@');
        $exists = Influencer::where('platform', $data['platform'])->where('handle', $handle)->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['handle' => 'This creator is already in your workspace on that platform.']);
        }

        Influencer::create([
            ...$data,
            'handle' => $handle,
            'tenant_id' => $request->user()->tenant_id,
            'tier' => InfluencerTier::fromFollowers($data['followers'])->value,
            'pulse_score' => null,
            'last_synced_at' => now(),
        ]);

        return redirect()->route('influencers')->with('success', 'Creator added to the sourcing pipeline.');
    }

    public function updateVetting(Request $request, Influencer $influencer): RedirectResponse
    {
        abort_unless($influencer->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate([
            'vetting_status' => ['required', Rule::enum(VettingStatus::class)],
        ]);

        $influencer->update($data);

        return back()->with('success', 'Creator vetting status updated.');
    }
}

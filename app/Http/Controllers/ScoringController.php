<?php

namespace App\Http\Controllers;

use App\Models\Influencer;
use App\Models\InfluencerScore;
use App\Models\ScoreConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ScoringController extends Controller
{
    public function index(): View
    {
        $config = ScoreConfig::query()->default()->firstOrFail();
        $scores = InfluencerScore::query()->with('influencer')->latest('computed_at')->paginate(20);

        return view('pages.scrutium.scoring.index', compact('config', 'scores') + ['title' => 'Scoring']);
    }

    public function updateConfig(Request $request, ScoreConfig $config): RedirectResponse
    {
        abort_unless($config->tenant_id === $request->user()->tenant_id, 404);

        $data = $request->validate([
            'engagement_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'audience_quality' => ['required', 'numeric', 'min:0', 'max:1'],
            'content_relevance' => ['required', 'numeric', 'min:0', 'max:1'],
            'reliability' => ['required', 'numeric', 'min:0', 'max:1'],
            'cost_efficiency' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);
        $weights = collect($data)->map(fn ($value) => (float) $value)->all();

        if (abs(array_sum($weights) - 1.0) > 0.0001) {
            return back()->withInput()->withErrors(['weights' => 'Scoring weights must add up to 1.0.']);
        }

        $config->update(['weights' => $weights]);

        return back()->with('success', 'Scoring configuration saved.');
    }

    public function recalculate(): RedirectResponse
    {
        $config = ScoreConfig::query()->default()->firstOrFail();

        DB::transaction(function () use ($config): void {
            Influencer::query()->chunkById(100, function ($creators) use ($config): void {
                foreach ($creators as $creator) {
                    $approved = $creator->deliverables()->where('status', 'approved')->count();
                    $total = max(1, $creator->deliverables()->count());
                    $reach = (int) $creator->contentPosts()->sum('reach');
                    $engagements = (int) $creator->contentPosts()->sum('likes')
                        + (int) $creator->contentPosts()->sum('comments')
                        + (int) $creator->contentPosts()->sum('shares')
                        + (int) $creator->contentPosts()->sum('saves');
                    $fee = (float) $creator->deliverables()->where('status', 'approved')->sum('fee');
                    $components = [
                        'engagement_rate' => min(100, (float) $creator->engagement_rate * 10),
                        'audience_quality' => min(100, 40 + min(60, (int) $creator->followers / 25000)),
                        'content_relevance' => min(100, $reach > 0 ? ($engagements / $reach) * 1000 : 35),
                        'reliability' => ($approved / $total) * 100,
                        'cost_efficiency' => min(100, $engagements > 0 ? max(0, 100 - (($fee / max(1, $engagements)) * 100)) : 25),
                    ];
                    $score = 0.0;
                    foreach ($components as $metric => $component) {
                        $score += $component * $config->weightFor($metric);
                    }

                    InfluencerScore::create([
                        'tenant_id' => $creator->tenant_id,
                        'influencer_id' => $creator->id,
                        'score_config_id' => $config->id,
                        'score' => round($score, 2),
                        'components' => $components,
                        'computed_at' => now(),
                    ]);
                    $creator->update(['pulse_score' => round($score, 2)]);
                }
            });
        });

        return back()->with('success', 'Creator scores recalculated from workspace data.');
    }
}

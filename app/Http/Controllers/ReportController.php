<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\Deliverable;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(): View
    {
        return view('pages.scrutium.reports.index', [
            'title' => 'Reports',
            'reports' => Report::with('generator')->latest('created_at')->paginate(15),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'type' => ['required', Rule::in(['performance', 'deliverables', 'integrity', 'roi'])],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        $report = DB::transaction(function () use ($data, $request): Report {
            $last = Report::query()->lockForUpdate()->latest('id')->first();
            $report = Report::create([
                ...$data,
                'tenant_id' => $request->user()->tenant_id,
                'generated_by' => $request->user()->id,
                'status' => ReportStatus::Draft->value,
                'version' => ((int) $last?->version) + 1,
                'payload' => $this->snapshot($request),
            ]);
            $report->freeze($request->user());

            return $report;
        });

        return back()->with('success', 'Report generated and frozen as version '.$report->version.'.');
    }

    public function publish(Request $request, Report $report): RedirectResponse
    {
        abort_unless($report->tenant_id === $request->user()->tenant_id, 404);
        abort_if($report->statusEnum() === ReportStatus::Draft, 422, 'Freeze the report before publishing it.');

        $report->publish();

        return back()->with('success', 'Report published.');
    }

    public function download(Report $report)
    {
        abort_unless($report->tenant_id === auth()->user()->tenant_id, 404);
        abort_if($report->statusEnum() === ReportStatus::Draft, 422, 'Draft reports cannot be downloaded.');

        $filename = Str::slug($report->title).'-v'.$report->version.'.json';

        return response()->json($report->payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    protected function snapshot(Request $request): array
    {
        $campaigns = Campaign::query()->withCount('deliverables')->get();
        $deliverables = Deliverable::query()->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'workspace' => ['id' => $request->user()->tenant_id, 'name' => $request->user()->tenant->name],
            'campaigns' => $campaigns->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
                'stage' => $campaign->stage->value,
                'budget_total' => $campaign->budget_total,
                'budget_spent' => $campaign->budget_spent,
                'deliverables_count' => $campaign->deliverables_count,
                'attainment_percent' => $campaign->attainmentPercent(),
            ])->all(),
            'deliverables' => $deliverables->map(fn (Deliverable $deliverable) => [
                'id' => $deliverable->id,
                'campaign' => $deliverable->campaign?->name,
                'title' => $deliverable->title,
                'status' => $deliverable->status->value,
                'fee' => $deliverable->fee,
                'due_at' => $deliverable->due_at?->toIso8601String(),
                'overdue' => $deliverable->isOverdue(),
            ])->all(),
        ];
    }
}
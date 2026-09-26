<?php

namespace App\Http\Controllers;

use App\Enums\DeliverableStatus;
use App\Models\Campaign;
use App\Models\Deliverable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DeliverableController extends Controller
{
    public function index(Request $request): View
    {
        $deliverables = Deliverable::query()
            ->with(['campaign', 'influencer'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->boolean('overdue'), fn ($query) => $query->overdue())
            ->orderBy('due_at')
            ->paginate(15)
            ->withQueryString();

        return view('pages.scrutium.deliverables.index', compact('deliverables') + [
            'title' => 'Deliverables',
            'statuses' => DeliverableStatus::options(),
            'campaigns' => Campaign::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Deliverable $deliverable): View
    {
        abort_unless($deliverable->tenant_id === auth()->user()->tenant_id, 404);
        $deliverable->load(['campaign', 'influencer', 'verifier', 'contentPost', 'auditEvents.user']);

        return view('pages.scrutium.deliverables.show', compact('deliverable') + ['title' => $deliverable->title]);
    }

    public function submit(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $data = $request->validate([
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,mp4', 'max:10240', 'required_without:evidence_url'],
            'evidence_url' => ['nullable', 'url', 'max:2048', 'required_without:evidence'],
            'delivered_units' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        abort_unless($deliverable->tenant_id === $request->user()->tenant_id, 404);

        // Approved and Rejected are both terminal. The view hides the submit form
        // for each, so guard both here or a crafted POST could resurrect a
        // rejected deliverable and wipe its rejection reason.
        abort_if(
            in_array($deliverable->statusEnum(), [DeliverableStatus::Approved, DeliverableStatus::Rejected], true),
            422,
            'Approved or rejected deliverables cannot be resubmitted.'
        );

        $disk = config('filesystems.evidence_disk', config('filesystems.default', 'public'));
        $path = $data['evidence_url'] ?? null;

        if ($request->hasFile('evidence')) {
            $path = $request->file('evidence')->store(
                'deliverables/'.$deliverable->tenant_id,
                $disk
            );
        }

        $deliverable->update(['delivered_units' => $data['delivered_units']]);
        $deliverable->markSubmitted($path, $request->user());

        return back()->with('success', 'Evidence submitted for verification.');
    }

    public function approve(Request $request, Deliverable $deliverable): RedirectResponse
    {
        abort_unless($deliverable->tenant_id === $request->user()->tenant_id, 404);
        abort_unless($deliverable->statusEnum() === DeliverableStatus::Submitted, 422, 'Only submitted deliverables can be approved.');
        abort_if(blank($deliverable->evidence_path), 422, 'Submitted deliverables must include evidence before approval.');

        $deliverable->approve($request->user());
        $campaign = $deliverable->campaign;
        $campaign->update(['budget_spent' => $campaign->deliverables()->where('status', DeliverableStatus::Approved->value)->sum('fee')]);

        return back()->with('success', 'Deliverable approved and spend reconciled.');
    }

    public function reject(Request $request, Deliverable $deliverable): RedirectResponse
    {
        abort_unless($deliverable->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        abort_unless($deliverable->statusEnum() === DeliverableStatus::Submitted, 422, 'Only submitted deliverables can be rejected.');

        $deliverable->reject($data['reason'], $request->user());

        return back()->with('success', 'Deliverable rejected with a reason.');
    }

    public function destroy(Deliverable $deliverable): RedirectResponse
    {
        abort_unless($deliverable->tenant_id === $request->user()->tenant_id, 404);
        abort_if($deliverable->statusEnum() !== DeliverableStatus::Pending, 422, 'Only pending deliverables can be deleted.');

        $disk = config('filesystems.evidence_disk', config('filesystems.default', 'public'));
        if ($deliverable->evidence_path && str_starts_with($deliverable->evidence_path, 'deliverables/')) {
            if (Storage::disk($disk)->exists($deliverable->evidence_path)) {
                Storage::disk($disk)->delete($deliverable->evidence_path);
            } elseif (Storage::disk('public')->exists($deliverable->evidence_path)) {
                Storage::disk('public')->delete($deliverable->evidence_path);
            }
        }

        $deliverable->delete();

        return redirect()->route('deliverables')->with('success', 'Pending deliverable removed.');
    }
}

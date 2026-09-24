<?php

namespace App\Http\Controllers;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function index(Request $request): View
    {
        $alerts = Alert::query()
            ->with('assignee')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->string('severity')))
            ->mostSevereFirst()
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('pages.scrutium.alerts.index', compact('alerts') + [
            'title' => 'Alerts',
            'statuses' => AlertStatus::options(),
            'severities' => AlertSeverity::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:80'],
            'severity' => ['required', Rule::enum(AlertSeverity::class)],
            'title' => ['required', 'string', 'max:180'],
            'message' => ['nullable', 'string', 'max:3000'],
        ]);

        Alert::create([...$data, 'tenant_id' => $request->user()->tenant_id, 'status' => AlertStatus::Open->value]);

        return back()->with('success', 'Alert created.');
    }

    public function acknowledge(Request $request, Alert $alert): RedirectResponse
    {
        abort_unless($alert->tenant_id === $request->user()->tenant_id, 404);
        $alert->acknowledge($request->user());

        return back()->with('success', 'Alert acknowledged.');
    }

    public function resolve(Request $request, Alert $alert): RedirectResponse
    {
        abort_unless($alert->tenant_id === $request->user()->tenant_id, 404);
        $alert->resolve($request->user());

        return back()->with('success', 'Alert resolved.');
    }

    public function subscriptions(Request $request): View
    {
        return view('pages.scrutium.alerts.subscriptions', [
            'title' => 'Alert subscriptions',
            'subscriptions' => $request->user()->alertSubscriptions()->latest()->get(),
        ]);
    }

    public function updateSubscription(Request $request, AlertSubscription $subscription): RedirectResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);

        $subscription->update(['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Notification preference updated.');
    }
}
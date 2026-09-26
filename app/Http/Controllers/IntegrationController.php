<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationStatus;
use App\Models\Integration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(): View
    {
        return view('pages.scrutium.integrations.index', [
            'title' => 'Integrations',
            'integrations' => Integration::orderBy('provider')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['instagram', 'tiktok', 'youtube', 'x', 'linkedin', 'shopify', 'ga4'])],
            'name' => ['required', 'string', 'max:120'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'auto_verify' => ['boolean'],
        ]);

        if (Integration::where('provider', $data['provider'])->exists()) {
            return back()->withInput()->withErrors(['provider' => 'That provider is already configured.']);
        }

        $token = $data['access_token'] ?? null;
        Integration::create([
            'tenant_id' => $request->user()->tenant_id,
            'provider' => $data['provider'],
            'name' => $data['name'],
            'status' => IntegrationStatus::Disconnected->value,
            'credentials' => $token ? ['access_token' => $token] : null,
            'auto_verify' => $request->boolean('auto_verify'),
        ]);

        return back()->with('success', 'Integration added. Connect it when provider credentials are ready.');
    }

    public function connect(Request $request, Integration $integration): RedirectResponse
    {
        abort_unless($integration->tenant_id === $request->user()->tenant_id, 404);
        abort_if(empty($integration->credentials['access_token']), 422, 'Add an access token before connecting this integration.');

        $integration->markConnected();

        return back()->with('success', $integration->name.' marked connected.');
    }

    public function disconnect(Request $request, Integration $integration): RedirectResponse
    {
        abort_unless($integration->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $integration->markDisconnected($data['reason'] ?: 'Disconnected by workspace administrator.');

        return back()->with('success', 'Integration disconnected.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Integration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(): View
    {
        $connections = Integration::orderBy('provider')->get()->keyBy('provider');
        $providers = collect(config('integrations.providers'))->map(function (array $provider, string $key) use ($connections): array {
            $integration = $connections->get($key);

            return $provider + [
                'provider' => $key,
                'integration' => $integration,
                'status' => ! $integration
                    ? 'Available'
                    : ($integration->needsReconnect() ? 'Reconnect required' : 'Connected'),
            ];
        });

        return view('pages.scrutium.integrations.partner-index', [
            'title' => 'Partner integrations',
            'categories' => config('integrations.categories'),
            'providers' => $providers,
            'connectedCount' => $connections->filter(fn (Integration $integration): bool => $integration->isHealthy())->count(),
            'campaigns' => Campaign::where('status', CampaignStatus::Active->value)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function oauth(string $provider): RedirectResponse
    {
        $supportedProviders = ['facebook', 'instagram', 'youtube', 'tiktok', 'x', 'linkedin', 'pinterest'];

        if (! in_array($provider, $supportedProviders, true)) {
            return redirect()->route('partnerintegrations')->with('error', 'This provider does not support secure OAuth authorization yet.');
        }

        $providerConfig = config('socialhub.providers.'.$provider.'.oauth', []);

        if (blank($providerConfig['client_id'] ?? null) || blank($providerConfig['client_secret'] ?? null)) {
            return redirect()->route('partnerintegrations')->with('error', 'This provider is not configured in the app environment yet.');
        }

        return redirect()->route('socialhub.accounts.connect', $provider);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(config('integrations.providers')))],
            'name' => ['nullable', 'string', 'max:120'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'auto_verify' => ['boolean'],
            'scope' => ['required', Rule::in(['workspace', 'campaign'])],
            'campaign_id' => [
                Rule::requiredIf($request->input('scope') === 'campaign'),
                'nullable',
                Rule::exists('campaigns', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $request->user()->tenant_id)
                    ->where('status', CampaignStatus::Active->value)),
            ],
        ]);

        $integration = Integration::firstOrNew([
            'tenant_id' => $request->user()->tenant_id,
            'provider' => $data['provider'],
        ]);
        $token = trim((string) ($data['access_token'] ?? ''));
        $credentials = $integration->credentials ?? [];

        if ($token !== '') {
            $credentials['access_token'] = $token;
        }

        $integration->fill([
            'name' => $data['name'] ?: config('integrations.providers.'.$data['provider'].'.name'),
            'scope' => $data['scope'],
            'campaign_id' => $data['scope'] === 'campaign' ? $data['campaign_id'] : null,
            'credentials' => $credentials ?: null,
            'auto_verify' => $request->boolean('auto_verify'),
        ]);
        $integration->save();

        if (! empty($credentials['access_token'])) {
            $integration->markConnected();
        } else {
            $integration->markDisconnected('Authentication is needed to connect this provider.');
        }

        return redirect()->route('partnerintegrations')->with('success', 'Integration settings saved.');
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

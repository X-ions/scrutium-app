<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationStatus;
use App\Models\Integration;
use App\Services\IntegrationHealthCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class IntegrationController extends Controller
{
    public function index(): View
    {
        $integrations = Integration::orderBy('provider')->get();

        return view('pages.scrutium.integrations.index', [
            'title' => 'Integrations',
            'integrations' => $integrations,
            'providers' => IntegrationHealthCheck::providers(),
            'connectedCount' => $integrations->where('status', IntegrationStatus::Connected)->count(),
            'degradedCount' => $integrations->where('status', IntegrationStatus::Degraded)->count(),
            'disconnectedCount' => $integrations->where('status', IntegrationStatus::Disconnected)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateWithoutFlashingSecrets($request, [
            'provider' => ['required', Rule::in(array_keys(IntegrationHealthCheck::providers()))],
            'name' => ['required', 'string', 'max:120'],
            'access_token' => ['required', 'string', 'max:8192'],
            'shop_domain' => [Rule::requiredIf($request->input('provider') === 'shopify'), 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i'],
        ]);

        if (Integration::where('provider', $data['provider'])->exists()) {
            return back()->withInput($request->except('access_token'))->withErrors(['provider' => 'That provider is already configured.']);
        }

        $integration = Integration::create([
            'tenant_id' => $request->user()->tenant_id,
            'provider' => $data['provider'],
            'name' => $data['name'],
            'status' => IntegrationStatus::Disconnected->value,
            'credentials' => array_filter([
                'access_token' => $data['access_token'],
                'shop_domain' => $data['shop_domain'] ?? null,
            ], fn ($value) => filled($value)),
        ]);

        return $this->verify($integration);
    }

    public function updateCredentials(Request $request, Integration $integration): RedirectResponse
    {
        abort_unless($integration->tenant_id === $request->user()->tenant_id, 404);

        $data = $this->validateWithoutFlashingSecrets($request, [
            'name' => ['required', 'string', 'max:120'],
            'access_token' => [Rule::requiredIf(! filled(data_get($integration->credentials, 'access_token'))), 'nullable', 'string', 'max:8192'],
            'shop_domain' => [Rule::requiredIf($integration->provider === 'shopify'), 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i'],
        ]);

        $credentials = $integration->credentials ?? [];
        if (filled($data['access_token'] ?? null)) {
            $credentials['access_token'] = $data['access_token'];
        }
        if ($integration->provider === 'shopify') {
            $credentials['shop_domain'] = $data['shop_domain'];
        }

        $integration->update([
            'name' => $data['name'],
            'credentials' => $credentials,
        ]);

        return $this->verify($integration->fresh());
    }

    public function connect(Request $request, Integration $integration): RedirectResponse
    {
        abort_unless($integration->tenant_id === $request->user()->tenant_id, 404);

        return $this->verify($integration);
    }

    public function disconnect(Request $request, Integration $integration): RedirectResponse
    {
        abort_unless($integration->tenant_id === $request->user()->tenant_id, 404);
        $integration->markDisconnected('Disconnected by workspace administrator.');

        return back()->with('success', 'Integration disconnected and its stored credentials were removed.');
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validateWithoutFlashingSecrets(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $request->merge(['access_token' => null]);
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function verify(Integration $integration): RedirectResponse
    {
        try {
            $message = app(IntegrationHealthCheck::class)->check($integration);
            $integration->markConnected();

            return back()->with('success', $integration->name.': '.$message);
        } catch (RuntimeException $exception) {
            $integration->markDegraded($exception->getMessage());

            return back()->with('error', $integration->name.': '.$exception->getMessage());
        }
    }
}

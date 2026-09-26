<?php

use App\Enums\IntegrationStatus;
use App\Models\Integration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IntegrationHealthCheck;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());
afterEach(fn () => TenantContext::forget());

it('verifies accessible accounts for each supported provider', function (string $provider, string $host, array $payload) {
    Http::fake(fn (ClientRequest $request) => Http::response(
        parse_url($request->url(), PHP_URL_HOST) === $host ? $payload : []
    ));

    $credentials = ['access_token' => 'provider-token'];
    if ($provider === 'shopify') {
        $credentials['shop_domain'] = 'store-name.myshopify.com';
    }

    $integration = Integration::factory()->create([
        'provider' => $provider,
        'credentials' => $credentials,
    ]);

    expect(app(IntegrationHealthCheck::class)->check($integration))->toContain('Connection verified');
    Http::assertSent(fn (ClientRequest $request): bool => parse_url($request->url(), PHP_URL_HOST) === $host);
})->with([
    'Instagram' => ['instagram', 'graph.instagram.com', ['id' => 'ig-1', 'username' => 'scrutium']],
    'TikTok' => ['tiktok', 'open.tiktokapis.com', ['data' => ['user' => ['open_id' => 'tt-1']]]],
    'YouTube' => ['youtube', 'www.googleapis.com', ['items' => [['id' => 'yt-1', 'snippet' => ['title' => 'Scrutium']]]]],
    'X' => ['x', 'api.x.com', ['data' => ['id' => 'x-1', 'username' => 'scrutium']]],
    'LinkedIn' => ['linkedin', 'api.linkedin.com', ['sub' => 'li-1', 'name' => 'Scrutium']],
    'Shopify' => ['shopify', 'store-name.myshopify.com', ['shop' => ['id' => 1, 'name' => 'Scrutium']]],
    'Google Analytics 4' => ['ga4', 'analyticsadmin.googleapis.com', ['accounts' => [['name' => 'accounts/1']]]],
]);

it('verifies provider credentials and stores them encrypted', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->owner()->for($tenant)->create();
    $token = 'provider-secret-token';

    Http::fake([
        'https://api.x.com/2/users/me' => Http::response([
            'data' => ['id' => 'account-123', 'username' => 'scrutium'],
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('integrations.store'), [
            'provider' => 'x',
            'name' => 'Scrutium X',
            'access_token' => $token,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $integration = Integration::query()->firstOrFail();

    expect($integration->status)->toBe(IntegrationStatus::Connected)
        ->and($integration->last_checked_at)->not->toBeNull()
        ->and($integration->credentials['access_token'])->toBe($token)
        ->and(DB::table('integrations')->value('credentials'))->not->toContain($token);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.x.com/2/users/me'
        && $request->hasHeader('Authorization', 'Bearer '.$token));
});

it('marks credentials degraded when the provider rejects the token', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->owner()->for($tenant)->create();

    Http::fake([
        'https://api.x.com/2/users/me' => Http::response(['title' => 'Unauthorized'], 401),
    ]);

    $this->actingAs($user)
        ->post(route('integrations.store'), [
            'provider' => 'x',
            'name' => 'Scrutium X',
            'access_token' => 'rejected-token',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $integration = Integration::query()->firstOrFail();

    expect($integration->status)->toBe(IntegrationStatus::Degraded)
        ->and($integration->last_error)->toContain('rejected this token')
        ->and($integration->last_checked_at)->not->toBeNull();
});

it('does not allow one workspace to disconnect another workspace integration', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user = User::factory()->owner()->for($tenant)->create();
    $integration = Integration::factory()->create([
        'tenant_id' => $otherTenant->id,
        'provider' => 'x',
        'credentials' => ['access_token' => 'keep-this-token'],
    ]);

    $this->actingAs($user)
        ->post(route('integrations.disconnect', $integration))
        ->assertNotFound();

    $stillConnected = Integration::withoutGlobalScopes()->findOrFail($integration->id);
    expect($stillConnected->credentials['access_token'])->toBe('keep-this-token');
});

it('does not flash credentials when provider-specific input is invalid', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->owner()->for($tenant)->create();

    $this->actingAs($user)
        ->from(route('integrations'))
        ->post(route('integrations.store'), [
            'provider' => 'shopify',
            'name' => 'Shopify store',
            'access_token' => 'private-token',
        ])
        ->assertRedirect(route('integrations'))
        ->assertSessionHasErrors('shop_domain')
        ->assertSessionMissing('_old_input.access_token');
});

<?php

use App\Models\Campaign;
use App\Models\Integration;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());
afterEach(fn () => TenantContext::forget());

it('renders the partner integration catalog with category and status filters', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get('/partnerintegrations')
        ->assertOk()
        ->assertSee('Integration Hub')
        ->assertSee('Social & Creator')
        ->assertSee('E-Commerce & Sales')
        ->assertSee('Available')
        ->assertSee('Instagram / Meta API')
        ->assertSee('17 connected', false);
});

it('links the discover integrations item to the partner integrations route', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Partner integrations')
        ->assertSee(route('partnerintegrations'));
});

it('redirects the integration OAuth flow to the provider authorization endpoint', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('integrations.oauth', 'instagram'))
        ->assertRedirect(route('socialhub.accounts.connect', 'instagram'));
});

it('stores credentials encrypted and connects a workspace integration', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('integrations.store'), [
            'provider' => 'slack',
            'name' => 'Scrutium Alerts',
            'access_token' => 'test-provider-secret',
            'scope' => 'workspace',
        ])
        ->assertRedirect(route('partnerintegrations'));

    $integration = Integration::where('provider', 'slack')->firstOrFail();

    expect($integration->isHealthy())->toBeTrue()
        ->and($integration->scope)->toBe('workspace')
        ->and($integration->credentials['access_token'])->toBe('test-provider-secret');

    $storedCredentials = DB::table('integrations')->where('id', $integration->id)->value('credentials');
    expect($storedCredentials)->not->toContain('test-provider-secret');

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('1/1', false);
});

it('reconnects using an already saved credential without requesting the token again', function () {
    $owner = User::factory()->owner()->create();
    $integration = Integration::factory()->for($owner->tenant)->disconnected()->create([
        'provider' => 'slack',
        'credentials' => ['access_token' => 'saved-provider-secret'],
    ]);

    $this->actingAs($owner)
        ->post(route('integrations.store'), [
            'provider' => 'slack',
            'name' => $integration->name,
            'scope' => 'workspace',
        ])
        ->assertRedirect(route('partnerintegrations'));

    expect($integration->fresh()->isHealthy())->toBeTrue();
});

it('maps an integration to an active campaign in the current workspace', function () {
    $owner = User::factory()->owner()->create();
    $campaign = Campaign::factory()->for($owner->tenant)->active()->create();

    $this->actingAs($owner)
        ->post(route('integrations.store'), [
            'provider' => 'shopify',
            'name' => 'Store sales',
            'access_token' => 'campaign-secret',
            'scope' => 'campaign',
            'campaign_id' => $campaign->id,
        ])
        ->assertRedirect(route('partnerintegrations'));

    $integration = Integration::where('provider', 'shopify')->firstOrFail();
    expect($integration->scope)->toBe('campaign')
        ->and($integration->campaign_id)->toBe($campaign->id);
});

it('rejects campaign mappings outside the current workspace', function () {
    $owner = User::factory()->owner()->create();
    $otherTenant = Tenant::factory()->create();
    $otherCampaign = Campaign::factory()->for($otherTenant)->active()->create();

    $this->actingAs($owner)
        ->from('/partnerintegrations')
        ->post(route('integrations.store'), [
            'provider' => 'shopify',
            'name' => 'Other store',
            'access_token' => 'campaign-secret',
            'scope' => 'campaign',
            'campaign_id' => $otherCampaign->id,
        ])
        ->assertRedirect('/partnerintegrations')
        ->assertSessionHasErrors('campaign_id');

    expect(Integration::where('provider', 'shopify')->exists())->toBeFalse();
});

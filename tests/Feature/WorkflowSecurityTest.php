<?php

use App\Enums\DeliverableStatus;
use App\Models\Campaign;
use App\Models\ContentPost;
use App\Models\Deliverable;
use App\Models\Influencer;
use App\Models\InfluencerScore;
use App\Models\ScoreConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());
afterEach(fn () => TenantContext::forget());

it('sets baseline security headers on public pages', function () {
    $this->get('/signin')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
});

it('requires evidence before a deliverable can be submitted', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->owner()->for($tenant)->create();
    $deliverable = Deliverable::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->post(route('deliverables.submit', $deliverable), [
            'delivered_units' => 1,
        ])
        ->assertSessionHasErrors(['evidence', 'evidence_url']);

    expect($deliverable->fresh()->status)->toBe(DeliverableStatus::Pending);
});

it('only allows submitted deliverables to be approved or rejected', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->owner()->for($tenant)->create();
    $pending = Deliverable::factory()->create(['tenant_id' => $tenant->id]);
    $submitted = Deliverable::factory()->submitted()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->post(route('deliverables.reject', $pending), ['reason' => 'Not ready'])
        ->assertStatus(422);

    $this->actingAs($user)
        ->post(route('deliverables.approve', $submitted))
        ->assertRedirect();

    expect($submitted->fresh()->status)->toBe(DeliverableStatus::Approved);
});

it('recalculates creator scores from workspace evidence', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->owner()->for($tenant)->create();
    $config = ScoreConfig::factory()->default()->for($tenant)->create();
    $campaign = Campaign::factory()->for($tenant)->create();
    $creator = Influencer::factory()->for($tenant)->create([
        'engagement_rate' => 5,
        'followers' => 250_000,
        'pulse_score' => 0,
    ]);

    Deliverable::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'campaign_id' => $campaign->id,
        'influencer_id' => $creator->id,
        'fee' => 100,
    ]);
    ContentPost::factory()->create([
        'tenant_id' => $tenant->id,
        'campaign_id' => $campaign->id,
        'influencer_id' => $creator->id,
        'reach' => 1_000,
        'likes' => 100,
        'comments' => 10,
        'shares' => 5,
        'saves' => 5,
    ]);

    $this->actingAs($user)
        ->post(route('scoring.recalculate'))
        ->assertRedirect();

    $score = InfluencerScore::query()
        ->where('score_config_id', $config->id)
        ->where('influencer_id', $creator->id)
        ->latest('id')
        ->firstOrFail();

    expect((float) $score->score)->toBeGreaterThan(0)
        ->and((float) $creator->fresh()->pulse_score)->toBe((float) $score->score)
        ->and($score->componentValue('reliability'))->toBe(100.0);
});

it('does not allow a workspace to update another workspace scoring config', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user = User::factory()->owner()->for($tenant)->create();
    $config = ScoreConfig::factory()->create([
        'tenant_id' => $otherTenant->id,
        'weights' => ScoreConfig::DEFAULT_WEIGHTS,
    ]);

    $this->actingAs($user)
        ->patch(route('scoring.config', $config), [
            'engagement_rate' => 1,
            'audience_quality' => 0,
            'content_relevance' => 0,
            'reliability' => 0,
            'cost_efficiency' => 0,
        ])
        ->assertNotFound();

    $freshConfig = ScoreConfig::withoutGlobalScopes()->findOrFail($config->id);

    expect($freshConfig->weights)->toEqual(ScoreConfig::DEFAULT_WEIGHTS);
});

<?php

use App\Enums\CampaignStage;
use App\Enums\CampaignStatus;
use App\Enums\DeliverableStatus;
use App\Enums\InfluencerTier;
use App\Enums\VettingStatus;
use App\Models\Alert;
use App\Models\Campaign;
use App\Models\ContentPost;
use App\Models\Deliverable;
use App\Models\Influencer;
use App\Models\Integration;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\ScrutiumDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());

afterEach(fn () => TenantContext::forget());

it('runs every Scrutium migration', function () {
    $tables = [
        'tenants', 'users', 'influencers', 'campaigns', 'campaign_influencer',
        'deliverables', 'deliverable_events', 'content_posts', 'score_configs',
        'influencer_scores', 'reports', 'alerts', 'alert_subscriptions', 'integrations',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table: {$table}");
    }

    foreach (['tenant_id', 'role', 'job_title', 'avatar_path', 'last_active_at'] as $column) {
        expect(Schema::hasColumn('users', $column))->toBeTrue("missing users.{$column}");
    }
});

it('derives the influencer tier from follower count', function () {
    expect(InfluencerTier::fromFollowers(500))->toBe(InfluencerTier::Nano)
        ->and(InfluencerTier::fromFollowers(50_000))->toBe(InfluencerTier::Micro)
        ->and(InfluencerTier::fromFollowers(500_000))->toBe(InfluencerTier::Macro)
        ->and(InfluencerTier::fromFollowers(2_000_000))->toBe(InfluencerTier::Mega);
});

it('exposes enum options for form selects', function () {
    expect(CampaignStage::options())->toHaveKey('brief')
        ->and(CampaignStatus::options())->toHaveKey('active')
        ->and(DeliverableStatus::options())->toHaveKey('approved')
        ->and(VettingStatus::options())->toHaveKeys(['sourced', 'vetted']);
});

it('stamps the resolved tenant when creating a model', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::set($tenant);

    $influencer = Influencer::create([
        'handle' => '@auto-stamped',
        'platform' => 'instagram',
    ]);

    expect($influencer->tenant_id)->toBe($tenant->id);
});

it('keeps tenant data isolated from other tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Influencer::factory()->for($tenantA)->count(3)->create();
    Influencer::factory()->for($tenantB)->count(2)->create();

    $this->actingAs(User::factory()->for($tenantA)->create());
    expect(Influencer::count())->toBe(3);

    $this->actingAs(User::factory()->for($tenantB)->create());
    expect(Influencer::count())->toBe(2);
});

it('does not scope queries when no tenant is resolved', function () {
    Influencer::factory()->for(Tenant::factory()->create())->count(2)->create();
    Influencer::factory()->for(Tenant::factory()->create())->count(3)->create();

    expect(Influencer::count())->toBe(5);
});

it('records an audit trail as a deliverable moves through verification', function () {
    $deliverable = Deliverable::factory()->create();
    $actor = User::factory()->for($deliverable->tenant)->create();

    $deliverable->markSubmitted('deliverables/evidence/proof.jpg', $actor);
    $deliverable->approve($actor);

    $fresh = $deliverable->fresh();

    expect($fresh->status)->toBe(DeliverableStatus::Approved)
        ->and($fresh->verified_by)->toBe($actor->id)
        ->and($fresh->verified_at)->not->toBeNull()
        ->and($fresh->delivered_units)->toBe($fresh->contracted_units)
        ->and($deliverable->auditEvents()->pluck('action')->all())->toBe(['approved', 'submitted']);
});

it('flags deliverables that are past due and unapproved', function () {
    $deliverable = Deliverable::factory()->create(['due_at' => now()->subDays(3)]);

    expect($deliverable->isOverdue())->toBeTrue();

    $deliverable->markSubmitted()->approve();

    expect($deliverable->fresh()->isOverdue())->toBeFalse();
});

it('keeps every deliverable, campaign and influencer in one tenant', function () {
    $this->seed(ScrutiumDemoSeeder::class);

    $mismatched = Deliverable::withoutGlobalScope('tenant')
        ->with(['campaign', 'influencer'])
        ->get()
        ->filter(fn (Deliverable $deliverable) => $deliverable->campaign->tenant_id !== $deliverable->tenant_id
            || $deliverable->influencer->tenant_id !== $deliverable->tenant_id);

    expect($mismatched)->toBeEmpty();
});

it('seeds two workspaces with data in every module', function () {
    $this->seed(ScrutiumDemoSeeder::class);

    expect(Tenant::count())->toBe(2)
        ->and(User::count())->toBe(8)
        ->and(Influencer::count())->toBeGreaterThan(20)
        ->and(Campaign::count())->toBe(8)
        ->and(Deliverable::count())->toBeGreaterThan(0)
        ->and(ContentPost::count())->toBeGreaterThan(0)
        ->and(Alert::count())->toBe(8)
        ->and(Report::count())->toBe(6)
        ->and(Integration::count())->toBe(6);

    expect(ContentPost::flagged()->count())->toBeGreaterThan(0)
        ->and(Influencer::bookable()->count())->toBeGreaterThan(0);
});

it('gives each workspace a known login account', function () {
    $this->seed(ScrutiumDemoSeeder::class);

    $owner = User::where('email', 'owner@northwind.test')->first();

    expect($owner)->not->toBeNull()
        ->and($owner->tenant->slug)->toBe('northwind')
        ->and($owner->canManageWorkspace())->toBeTrue();
});

it('renders the dashboard for an authenticated workspace owner', function () {
    $this->seed(ScrutiumDemoSeeder::class);
    $owner = User::where('email', 'owner@northwind.test')->firstOrFail();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Integrity score');
});

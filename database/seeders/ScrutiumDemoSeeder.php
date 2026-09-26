<?php

namespace Database\Seeders;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\CampaignStage;
use App\Enums\CampaignStatus;
use App\Enums\DeliverableStatus;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Enums\VettingStatus;
use App\Models\Alert;
use App\Models\AlertSubscription;
use App\Models\Campaign;
use App\Models\ContentPost;
use App\Models\Deliverable;
use App\Models\Influencer;
use App\Models\InfluencerScore;
use App\Models\Integration;
use App\Models\Report;
use App\Models\ScoreConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Builds a coherent demo workspace so every Scrutium module has real data
 * to render. Two tenants are created to prove tenant isolation.
 */
class ScrutiumDemoSeeder extends Seeder
{
    public function run(): void
    {
        TenantContext::forget();

        $this->seedWorkspace(
            name: 'Northwind Collective',
            slug: 'northwind',
            plan: 'enterprise',
            influencerCount: 24,
            campaignCount: 6,
        );

        $this->seedWorkspace(
            name: 'Halcyon Agency',
            slug: 'halcyon',
            plan: 'growth',
            influencerCount: 6,
            campaignCount: 2,
        );

        TenantContext::forget();
    }

    protected function seedWorkspace(
        string $name,
        string $slug,
        string $plan,
        int $influencerCount,
        int $campaignCount,
    ): void {
        $tenant = Tenant::factory()->create([
            'name' => $name,
            'slug' => $slug,
            'domain' => $slug.'.scrutium.test',
            'plan' => $plan,
            'currency' => 'USD',
        ]);

        // Pin the context so every model below is stamped and filtered to
        // this tenant by the BelongsToTenant global scope.
        TenantContext::set($tenant);

        $team = $this->seedTeam($tenant, $slug);
        $scoreConfig = ScoreConfig::factory()->default()->for($tenant)->create();
        $influencers = $this->seedInfluencers($tenant, $influencerCount);
        $this->seedCampaigns($tenant, $influencers, $team, $campaignCount);
        $this->seedScores($tenant, $influencers, $scoreConfig);
        $this->seedReports($tenant, $team['owner']);
        $this->seedAlerts($tenant, $team['manager']);
        $this->seedIntegrations($tenant);

        TenantContext::forget();

        if ($this->command) {
            $this->command->info("Seeded workspace: {$name} ({$slug})");
        }
    }

    /**
     * @return array{owner: User, manager: User, analyst: User, viewer: User}
     */
    protected function seedTeam(Tenant $tenant, string $slug): array
    {
        $owner = User::factory()->for($tenant)->owner()->create([
            'name' => 'Ava Rivera',
            'email' => "owner@{$slug}.test",
            'job_title' => 'Head of Influencer Marketing',
        ]);

        $manager = User::factory()->for($tenant)->manager()->create([
            'name' => 'Marcus Chen',
            'email' => "manager@{$slug}.test",
            'job_title' => 'Campaign Operations Lead',
        ]);

        $analyst = User::factory()->for($tenant)->role(UserRole::Analyst)->create([
            'name' => 'Priya Patel',
            'email' => "analyst@{$slug}.test",
            'job_title' => 'Performance Analyst',
        ]);

        $viewer = User::factory()->for($tenant)->create([
            'name' => 'Jonah Blake',
            'email' => "viewer@{$slug}.test",
            'job_title' => 'Brand Stakeholder',
        ]);

        AlertSubscription::factory()->for($tenant)->for($owner)->create(['channel' => 'in_app']);
        AlertSubscription::factory()->for($tenant)->for($manager)->email()->create([
            'type' => 'missing_disclosure',
        ]);

        return compact('owner', 'manager', 'analyst', 'viewer');
    }

    /**
     * @return Collection<int, Influencer>
     */
    protected function seedInfluencers(Tenant $tenant, int $count): Collection
    {
        $influencers = Influencer::factory()
            ->for($tenant)
            ->count($count)
            ->create();

        // Most of the roster is vetted; keep a few in the pipeline.
        $influencers->each(function (Influencer $influencer, int $index) {
            $status = match (true) {
                $index < 2 => VettingStatus::Pending,
                $index < 3 => VettingStatus::Rejected,
                default => VettingStatus::Vetted,
            };

            $influencer->update(['vetting_status' => $status]);
        });

        return $influencers;
    }

    /**
     * @param  Collection<int, Influencer>  $influencers
     * @param  array{owner: User, manager: User, analyst: User, viewer: User}  $team
     */
    protected function seedCampaigns(Tenant $tenant, Collection $influencers, array $team, int $count): void
    {
        $bookable = $influencers->filter(fn (Influencer $influencer) => $influencer->isBookable())
            ->values();

        if ($bookable->isEmpty()) {
            return;
        }

        $stages = [
            CampaignStage::Brief,
            CampaignStage::Casting,
            CampaignStage::Live,
            CampaignStage::Live,
            CampaignStage::Reconcile,
            CampaignStage::Completed,
        ];

        for ($i = 0; $i < $count; $i++) {
            $stage = $stages[$i % count($stages)];

            $campaign = Campaign::factory()->for($tenant)->create([
                'owner_id' => $i % 2 === 0 ? $team['owner']->id : $team['manager']->id,
                'stage' => $stage->value,
                'status' => $stage === CampaignStage::Completed
                    ? CampaignStatus::Completed->value
                    : CampaignStatus::Active->value,
            ]);

            $roster = $bookable->shuffle()->take(min(6, $bookable->count()));

            foreach ($roster as $influencer) {
                $campaign->influencers()->attach($influencer->id, [
                    'role' => 'creator',
                    'status' => 'confirmed',
                    'agreed_fee' => fake()->numberBetween(500, 9_000),
                ]);
            }

            $this->seedDeliverables($campaign, $roster, $stage);
            $this->seedContentPosts($campaign);

            // Recompute spend from approved deliverables so budget views are truthful.
            $campaign->update([
                'budget_spent' => $campaign->deliverables()
                    ->where('status', DeliverableStatus::Approved->value)
                    ->sum('fee'),
            ]);
        }
    }

    /**
     * @param  Collection<int, Influencer>  $roster
     */
    protected function seedDeliverables(Campaign $campaign, Collection $roster, CampaignStage $stage): void
    {
        foreach ($roster as $influencer) {
            $units = fake()->numberBetween(1, 3);

            for ($unit = 0; $unit < $units; $unit++) {
                $deliverable = Deliverable::factory()->for($campaign->tenant)->create([
                    'campaign_id' => $campaign->id,
                    'influencer_id' => $influencer->id,
                    'status' => DeliverableStatus::Pending->value,
                ]);

                $this->advanceDeliverable($deliverable, $stage);
            }
        }
    }

    /**
     * Drag a deliverable through the lifecycle appropriate to its campaign
     * stage, writing real audit-log entries along the way.
     */
    protected function advanceDeliverable(Deliverable $deliverable, CampaignStage $stage): void
    {
        $deliverable->auditEvents()->create([
            'action' => 'created',
            'to_status' => DeliverableStatus::Pending->value,
        ]);

        if ($stage === CampaignStage::Brief || $stage === CampaignStage::Casting) {
            if (fake()->boolean(20)) {
                $deliverable->update([
                    'status' => DeliverableStatus::Late->value,
                    'due_at' => now()->subDays(fake()->numberBetween(1, 6)),
                ]);
            }

            return;
        }

        if ($stage === CampaignStage::Live) {
            $deliverable->markSubmitted($this->demoEvidencePath($deliverable));

            return;
        }

        $deliverable->markSubmitted($this->demoEvidencePath($deliverable));

        if (fake()->boolean(75)) {
            $deliverable->approve();

            return;
        }

        $deliverable->reject('Disclosure missing from caption.');
    }

    /**
     * A plausible evidence path for a seeded submission.
     *
     * Without one, every seeded Submitted deliverable is un-approvable through
     * the UI: approve() refuses rows with no evidence, so the demo worklist
     * looked complete but could not actually be worked through.
     */
    protected function demoEvidencePath(Deliverable $deliverable): string
    {
        return 'deliverables/'.$deliverable->tenant_id.'/demo-evidence-'.$deliverable->id.'.jpg';
    }

    protected function seedContentPosts(Campaign $campaign): void
    {
        $deliverables = $campaign->deliverables()
            ->whereIn('status', [
                DeliverableStatus::Submitted->value,
                DeliverableStatus::Approved->value,
            ])
            ->get();

        foreach ($deliverables as $index => $deliverable) {
            $post = ContentPost::factory()->for($campaign->tenant)->create([
                'campaign_id' => $campaign->id,
                'influencer_id' => $deliverable->influencer_id,
                'deliverable_id' => $deliverable->id,
                'platform' => $deliverable->platform->value,
                'posted_at' => $deliverable->posted_at ?? now(),
            ]);

            // Deterministically breach an integrity rule on every fifth post so
            // the seeded workspace always exercises the flagged states.
            if ($index % 5 === 0) {
                $post->update([
                    'has_disclosure' => false,
                    'provenance_score' => 48.5,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, Influencer>  $influencers
     */
    protected function seedScores(Tenant $tenant, Collection $influencers, ScoreConfig $config): void
    {
        $top = $influencers
            ->sortByDesc(fn (Influencer $influencer) => (float) $influencer->pulse_score)
            ->take(10);

        foreach ($top as $influencer) {
            InfluencerScore::factory()->for($tenant)->create([
                'influencer_id' => $influencer->id,
                'score_config_id' => $config->id,
                'score' => (float) $influencer->pulse_score,
                'computed_at' => now(),
            ]);
        }
    }

    protected function seedReports(Tenant $tenant, User $owner): void
    {
        $blueprint = [
            [ReportStatus::Draft, 'Monthly performance', 1],
            [ReportStatus::Frozen, 'Q3 deliverable accountability', 2],
            [ReportStatus::Published, 'Integrity audit', 3],
        ];

        foreach ($blueprint as [$status, $title, $version]) {
            Report::factory()->for($tenant)->create([
                'generated_by' => $owner->id,
                'title' => $title,
                'status' => $status->value,
                'version' => $version,
                'frozen_at' => $status->isImmutable() ? now() : null,
            ]);
        }
    }

    protected function seedAlerts(Tenant $tenant, User $manager): void
    {
        Alert::factory()->for($tenant)->create([
            'type' => 'missing_disclosure',
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
            'title' => 'Disclosure missing on live posts',
            'message' => 'Approved deliverables are live without a paid-partnership disclosure.',
        ]);

        Alert::factory()->for($tenant)->create([
            'type' => 'late_deliverable',
            'severity' => AlertSeverity::High->value,
            'status' => AlertStatus::Acknowledged->value,
            'assigned_to' => $manager->id,
            'title' => 'Deliverables past their due date',
        ]);

        Alert::factory()->for($tenant)->create([
            'type' => 'sync_failure',
            'severity' => AlertSeverity::Medium->value,
            'status' => AlertStatus::Open->value,
            'title' => 'Platform sync degraded',
        ]);

        Alert::factory()->for($tenant)->resolved()->create([
            'type' => 'provenance_drop',
            'severity' => AlertSeverity::Low->value,
            'assigned_to' => $manager->id,
            'title' => 'Provenance score dipped below threshold',
        ]);
    }

    protected function seedIntegrations(Tenant $tenant): void
    {
        $blueprint = [
            [Platform::Instagram, IntegrationStatus::Connected, true],
            [Platform::TikTok, IntegrationStatus::Degraded, true],
            [Platform::YouTube, IntegrationStatus::Disconnected, false],
        ];

        foreach ($blueprint as [$platform, $status, $autoVerify]) {
            Integration::factory()->for($tenant)->create([
                'provider' => $platform->value,
                'name' => $platform->label().' connector',
                'status' => $status->value,
                'auto_verify' => $autoVerify,
                'last_error' => $status->isHealthy()
                    ? null
                    : 'Provider rejected the last sync request.',
            ]);
        }
    }
}

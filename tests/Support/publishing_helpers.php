<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Enums\SocialPlatform;
use App\Jobs\Publishing\PublishPostJob;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Publishing\StructuredLog;
use App\Services\Social\SocialProviderRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\Fakes\FakeFacebookProvider;
use Tests\Support\Fakes\FakeInstagramProvider;
use Tests\Support\Fakes\FakeNetworkProvider;
use Tests\Support\Fakes\RecordingQueueJob;

if (! function_exists('publishingTenant')) {
    /**
     * A workspace with the SocialHub entitlement columns populated.
     */
    function publishingTenant(array $attributes = []): Tenant
    {
        return Tenant::factory()->create($attributes + [
            'scheduled_posts_limit' => 25,
            'social_accounts_limit' => 10,
        ]);
    }

    function connectedAccount(Tenant|int|string $tenant, SocialPlatform $platform): SocialAccount
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        $account = SocialAccount::factory()->create([
            'tenant_id' => $tenantId,
            'provider' => $platform->value,
            'status' => 'connected',
            'provider_account_id' => (string) Str::random(10),
            'metadata' => ['page_access_token' => 'page-access-token', 'granted_scopes' => ['pages_manage_posts']],
        ]);

        SocialAccountToken::factory()->create([
            'social_account_id' => $account->getKey(),
            'access_token' => 'page-access-token',
        ]);

        return $account;
    }

    function postWithVariants(Tenant $tenant, array $platforms, array $variantOverrides = []): Post
    {
        $author = User::factory()->create(['tenant_id' => $tenant->getKey()]);

        $post = Post::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $author->getKey(),
            'status' => PostStatus::Draft->value,
        ]);

        foreach ($platforms as $index => $platform) {
            $account = connectedAccount($tenant, $platform instanceof SocialPlatform ? $platform : SocialPlatform::from($platform));

            PostVariant::factory()->create($variantOverrides + [
                'media_ids' => ['media-'.$index],
                'post_id' => $post->getKey(),
                'social_account_id' => $account->getKey(),
                'provider' => $platform instanceof SocialPlatform ? $platform->value : $platform,
                'caption' => sprintf('Variant %d caption', $index + 1),
            ]);
        }

        return $post->refresh();
    }

    /**
     * Register a stand-in provider for a network that has no implementation yet,
     * so the tests can exercise two independent networks in one post.
     *
     * @param  class-string<\App\Services\Social\Contracts\SocialProviderInterface>|null  $class
     */
    function registerFakeNetwork(string $key, ?string $class = null): void
    {
        $class ??= FakeNetworkProvider::class;

        app()->instance($class, new $class);

        app(SocialProviderRegistry::class)->register($key, $class);
    }

    function configureFacebookCredentials(): void
    {
        config()->set('socialhub.providers.facebook.credentials', [
            'client_id' => 'test-app-id',
            'client_secret' => 'test-app-secret',
        ]);

        // SocialHubServiceProvider's container binding for the rate limiter does
        // not resolve; bind the instance so the registry can build providers in
        // tests. See the blocker posted to main.
        app()->instance(
            \App\Services\Social\ProviderRateLimiter::class,
            new \App\Services\Social\ProviderRateLimiter(redis: null),
        );
    }

    /**
     * Stand in for every network the publishing tests use.
     *
     * Two networks are needed to prove per-variant isolation, and the provider
     * layer's own suite already covers each concrete provider against the live
     * API shape. These stand-ins keep the verified capability matrices and route
     * every call through `Http::fake()`, which is what the publishing engine
     * actually depends on.
     */
    function registerFakeNetworks(): void
    {
        registerFakeNetwork('facebook', FakeFacebookProvider::class);
        registerFakeNetwork('instagram', FakeInstagramProvider::class);
    }

    /**
     * Build the ScheduledPost + job pair the queue would otherwise create, so a
     * test can run a single job without going through dispatch.
     */
    function queuedJob(PostVariant $variant, int $attempts = 1): PublishPostJob
    {
        $scheduled = ScheduledPost::firstOrCreate(
            ['post_variant_id' => $variant->getKey()],
            [
                'scheduled_at' => now(),
                'timezone' => 'UTC',
                'status' => 'queued',
                'job_id' => StructuredLog::jobId(),
                'attempts' => 0,
                'max_attempts' => (int) config('socialhub.publishing.max_attempts', 5),
            ],
        );

        return new PublishPostJob(
            postVariantId: (int) $variant->getKey(),
            scheduledPostId: (int) $scheduled->getKey(),
            jobId: (string) ($scheduled->job_id ?: StructuredLog::jobId()),
        );
    }

    function runJob(PublishPostJob $job, ?RecordingQueueJob $queueJob = null): RecordingQueueJob
    {
        $queueJob ??= new RecordingQueueJob;

        $job->setJob($queueJob);

        $job->handle(
            app(\App\Services\Social\SocialProviderRegistry::class),
            app(\App\Services\Publishing\VariantCapabilityGate::class),
            app(\App\Services\Publishing\PostStatusResolver::class),
            app(\App\Services\Publishing\DeadLetterService::class),
        );

        return $queueJob;
    }

    function authorOf(Post $post): User
    {
        return $post->author()->firstOrFail();
    }

    /**
     * Drive one variant through the whole retry budget, the way a worker would,
     * so a test can reach the terminal state deterministically.
     */
    function publishUntilTerminal(PostVariant $variant, int $maxRuns = 8): PostVariant
    {
        for ($run = 0; $run < $maxRuns; $run++) {
            $variant->refresh();

            if ($variant->isPublished() || $variant->statusEnum() === PostVariantStatus::Failed) {
                return $variant;
            }

            runJob(queuedJob($variant));
        }

        return $variant->refresh();
    }

    function variantOf(Post $post, SocialPlatform $platform): PostVariant
    {
        return $post->variants()->where('provider', $platform->value)->firstOrFail();
    }

    function pinTenant(Tenant $tenant): void
    {
        TenantContext::set($tenant);
    }
}

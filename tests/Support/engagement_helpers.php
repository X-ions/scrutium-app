<?php

declare(strict_types=1);

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Engagement\CommentSyncService;
use App\Services\Social\SocialProviderRegistry;
use App\Support\TenantContext;
use Tests\Support\Engagement\FakeEngagementProvider;
use Tests\Support\Engagement\FakeFacebookProvider;
use Tests\Support\Engagement\FakeInstagramProvider;
use Tests\Support\Engagement\FakeLinkedInProvider;
use Tests\Support\Engagement\FakePinterestProvider;
use Tests\Support\Engagement\FakeTikTokProvider;
use Tests\Support\Engagement\FakeXProvider;
use Tests\Support\Engagement\FakeYouTubeProvider;

if (! function_exists('engagementTenant')) {
    /**
     * A workspace with a resolved tenant, which every model read depends on.
     */
    function engagementTenant(array $attributes = []): Tenant
    {
        $tenant = Tenant::factory()->create($attributes + ['timezone' => 'UTC']);

        TenantContext::set($tenant);

        return $tenant;
    }

    function engagementUser(?Tenant $tenant = null): User
    {
        $tenant ??= engagementTenant();

        return User::factory()->create(['tenant_id' => $tenant->getKey()]);
    }

    function engagementAccount(Tenant $tenant, SocialPlatform $platform, array $attributes = []): SocialAccount
    {
        return SocialAccount::factory()->create(array_merge([
            'tenant_id' => $tenant->getKey(),
            'provider' => $platform,
            'status' => SocialAccountStatus::Connected->value,
        ], $attributes));
    }

    /**
     * A published variant, which is what a provider comment can be attached to:
     * a comment always belongs to a post we published ourselves.
     */
    function publishedVariant(Tenant $tenant, SocialAccount $account, ?string $providerPostId = null): PostVariant
    {
        $post = Post::factory()->create(['tenant_id' => $tenant->getKey()]);

        return PostVariant::factory()->create([
            'post_id' => $post->getKey(),
            'social_account_id' => $account->getKey(),
            'provider' => $account->provider->value,
            'status' => 'published',
            'provider_post_id' => $providerPostId ?? 'prov-'.uniqid(),
        ]);
    }

    /**
     * Replace the registry with one whose every platform resolves to a
     * stand-in that keeps that platform's real capability matrix.
     *
     * A fresh registry is built rather than the existing one being mutated, so a
     * test that registers a provider twice cannot leak into the next test.
     *
     * @return array<string, class-string<\Tests\Support\Engagement\FakeEngagementProvider>>
     */
    function useFakeEngagementProviders(): array
    {
        FakeEngagementProvider::reset();

        $classes = [
            'facebook' => FakeFacebookProvider::class,
            'instagram' => FakeInstagramProvider::class,
            'youtube' => FakeYouTubeProvider::class,
            'tiktok' => FakeTikTokProvider::class,
            'x' => FakeXProvider::class,
            'linkedin' => FakeLinkedInProvider::class,
            'pinterest' => FakePinterestProvider::class,
        ];

        app()->instance(SocialProviderRegistry::class, new SocialProviderRegistry(app(), $classes));

        return $classes;
    }

    function commentSync(): CommentSyncService
    {
        return app(CommentSyncService::class);
    }

    /**
     * A provider comment bound to a variant we actually published.
     */
    function providerComment(
        PostVariant $variant,
        string $providerCommentId,
        string $content = 'Nice work',
        array $raw = [],
    ): \App\Services\Social\Data\ProviderComment {
        return new \App\Services\Social\Data\ProviderComment(
            provider: (string) $variant->provider->value,
            providerCommentId: $providerCommentId,
            providerPostId: (string) $variant->provider_post_id,
            content: $content,
            authorProviderId: 'author-'.$providerCommentId,
            authorUsername: 'someone_'.$providerCommentId,
            authorDisplayName: 'Someone',
            createdAt: now()->subHour(),
            raw: $raw,
        );
    }

    function commentOn(Tenant $tenant, SocialAccount $account, ?string $content = null): Comment
    {
        $variant = publishedVariant($tenant, $account);

        return Comment::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'post_variant_id' => $variant->getKey(),
            'social_account_id' => $account->getKey(),
            'provider' => $account->provider->value,
            'content' => $content ?? 'Great post',
        ]);
    }

    function tokenExpiringIn(SocialAccount $account, string $expiresAt = '+6 hours'): SocialAccountToken
    {
        return SocialAccountToken::factory()->create([
            'social_account_id' => $account->getKey(),
            'expires_at' => now()->parse($expiresAt),
        ]);
    }
}

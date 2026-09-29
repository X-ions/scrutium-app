<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Enums\SocialPlatform;
use App\Services\Publishing\PostStatusResolver;
use App\Services\Publishing\StructuredLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/publishing_helpers.php';

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = publishingTenant();
    $this->resolver = app(PostStatusResolver::class);
});

/**
 * @param  array<string, PostVariantStatus>  $statuses  keyed by platform value
 */
function variantStatuses(\App\Models\Post $post, array $statuses): \App\Models\Post
{
    foreach ($statuses as $platform => $status) {
        $variant = variantOf($post, SocialPlatform::from($platform));

        $variant->forceFill([
            'status' => $status->value,
            'published_at' => $status === PostVariantStatus::Published ? now() : null,
        ])->save();
    }

    $post->unsetRelation('variants');

    return $post;
}

it('is published only when every variant reached a network', function () {
    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    variantStatuses($post, [
        SocialPlatform::Facebook->value => PostVariantStatus::Published,
        SocialPlatform::Instagram->value => PostVariantStatus::Published,
    ]);

    expect($this->resolver->resolve($post))->toBe(PostStatus::Published);
});

it('is publishing while any variant is still in flight', function () {
    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    variantStatuses($post, [
        SocialPlatform::Facebook->value => PostVariantStatus::Published,
        SocialPlatform::Instagram->value => PostVariantStatus::Publishing,
    ]);

    expect($this->resolver->resolve($post))->toBe(PostStatus::Publishing);
});

it('is failed when one network is live and another is not', function () {
    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    variantStatuses($post, [
        SocialPlatform::Facebook->value => PostVariantStatus::Published,
        SocialPlatform::Instagram->value => PostVariantStatus::Failed,
    ]);

    expect($this->resolver->resolve($post))->toBe(PostStatus::Failed);
});

it('is scheduled when nothing has gone out yet', function () {
    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook]);

    variantStatuses($post, [SocialPlatform::Facebook->value => PostVariantStatus::Scheduled]);

    expect($this->resolver->resolve($post))->toBe(PostStatus::Scheduled);
});

it('is cancelled when every variant was cancelled', function () {
    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook]);

    variantStatuses($post, [SocialPlatform::Facebook->value => PostVariantStatus::Cancelled]);

    expect($this->resolver->resolve($post))->toBe(PostStatus::Cancelled);
});

it('stays a draft when only one of several variants is cancelled', function () {
    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    variantStatuses($post, [
        SocialPlatform::Facebook->value => PostVariantStatus::Cancelled,
        SocialPlatform::Instagram->value => PostVariantStatus::Pending,
    ]);

    expect($this->resolver->resolve($post))->toBe(PostStatus::Draft);
});

it('stamps published_at on the post only when the whole post is live', function () {
    $post = postWithVariants($this->tenant, [SocialPlatform::Facebook, SocialPlatform::Instagram]);

    variantStatuses($post, [
        SocialPlatform::Facebook->value => PostVariantStatus::Published,
        SocialPlatform::Instagram->value => PostVariantStatus::Publishing,
    ]);

    $this->resolver->sync($post->refresh());

    expect($post->refresh()->published_at)->toBeNull();

    variantStatuses($post, [SocialPlatform::Instagram->value => PostVariantStatus::Published]);

    $this->resolver->sync($post->refresh());

    expect($post->refresh()->published_at)->not->toBeNull();
});

it('mints a short job id that is greppable in the log', function () {
    $id = StructuredLog::jobId();

    expect($id)->toStartWith('job_')
        ->and(substr($id, 4))->toHaveLength(6)
        ->and($id)->toMatch('/^job_[a-z0-9]{6}$/')
        ->and(StructuredLog::jobId())->not->toBe($id);
});

it('emits one traceable line per publishing event', function () {
    Log::shouldReceive('info')->once()->with('socialhub.publishing', Mockery::on(
        function (array $line): bool {
            return $line['event'] === 'publishing.variant_published'
                && str_starts_with((string) $line['job_id'], 'job_')
                && $line['provider'] === 'facebook'
                && $line['status'] === 'published'
                && array_key_exists('organization_id', $line)
                && array_key_exists('post_id', $line)
                && array_key_exists('variant_id', $line)
                && array_key_exists('attempt', $line)
                && array_key_exists('error_code', $line)
                && array_key_exists('retry_in_seconds', $line);
        },
    ));

    StructuredLog::write('publishing.variant_published', [
        'job_id' => 'job_abc123',
        'organization_id' => 7,
        'post_id' => 3,
        'variant_id' => 9,
        'provider' => 'facebook',
        'status' => 'published',
        'attempt' => 1,
        'error_code' => null,
        'retry_in_seconds' => null,
    ]);
});

it('never writes a token to the log even if a caller passes one', function () {
    Log::spy();

    StructuredLog::error('publishing.dead_lettered', [
        'job_id' => 'job_abc123',
        'variant_id' => 9,
        'error_code' => 'token_revoked',
        'response_payload' => ['access_token' => 'page-access-token'],
    ]);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $message === 'socialhub.publishing'
            && ! str_contains((string) json_encode($context), 'page-access-token'),
    );
});

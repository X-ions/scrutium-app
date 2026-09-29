<?php

use App\Enums\PostStatus;
use App\Enums\SocialAccountStatus;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSnapshot;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\MediaAsset;
use App\Models\OauthState;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostVariant;
use App\Models\PublishingAttempt;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\SocialHubNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());

afterEach(fn () => TenantContext::forget());

$newTables = [
    'social_accounts' => ['tenant_id', 'provider', 'provider_account_id', 'status', 'deleted_at'],
    'social_account_tokens' => ['social_account_id', 'access_token', 'refresh_token', 'expires_at'],
    'posts' => ['tenant_id', 'user_id', 'campaign_id', 'status', 'published_at', 'deleted_at'],
    'post_variants' => ['post_id', 'social_account_id', 'caption', 'status', 'provider_post_id', 'deleted_at'],
    'media_assets' => ['tenant_id', 'user_id', 'mime_type', 'storage_disk', 'deleted_at'],
    'post_media' => ['post_variant_id', 'media_asset_id', 'sort_order'],
    'scheduled_posts' => ['post_variant_id', 'scheduled_at', 'status', 'job_id'],
    'publishing_attempts' => ['post_variant_id', 'scheduled_post_id', 'attempt_number', 'status'],
    'analytics_metrics' => ['tenant_id', 'social_account_id', 'post_variant_id', 'metric_type', 'period_start'],
    'analytics_snapshots' => ['tenant_id', 'social_account_id', 'date', 'period', 'metrics'],
    'comments' => ['tenant_id', 'post_variant_id', 'provider_comment_id', 'is_hidden', 'deleted_at'],
    'comment_replies' => ['comment_id', 'user_id', 'social_account_id', 'status'],
    'socialhub_notifications' => ['tenant_id', 'user_id', 'type', 'is_read', 'priority'],
    'webhook_events' => ['tenant_id', 'provider', 'event_id', 'event_type', 'payload'],
    'oauth_states' => ['tenant_id', 'provider', 'state', 'code_verifier', 'expires_at'],
    'audit_logs' => ['tenant_id', 'user_id', 'event', 'auditable_id'],
];

it('creates every SocialHub table with its key columns', function () use ($newTables) {
    foreach ($newTables as $table => $columns) {
        expect(Schema::hasTable($table))->toBeTrue("missing table: {$table}");

        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))
                ->toBeTrue("missing column: {$table}.{$column}");
        }
    }
});

it('adds the SocialHub limit columns to tenants', function () {
    foreach ([
        'plan',
        'social_accounts_limit',
        'scheduled_posts_limit',
        'team_members_limit',
        'analytics_retention_days',
        'storage_limit_mb',
        'ai_credits_monthly',
    ] as $column) {
        expect(Schema::hasColumn('tenants', $column))->toBeTrue("missing tenants.{$column}");
    }
});

it('rejects duplicate social accounts for the same tenant and provider', function () {
    $account = SocialAccount::factory()->create();

    expect(fn () => SocialAccount::factory()->create([
        'tenant_id' => $account->tenant_id,
        'provider' => $account->provider->value,
        'provider_account_id' => $account->provider_account_id,
    ]))->toThrow(QueryException::class);
});

it('allows the same provider account id under a different tenant', function () {
    $account = SocialAccount::factory()->create();

    $other = SocialAccount::factory()->create([
        'provider' => $account->provider->value,
        'provider_account_id' => $account->provider_account_id,
    ]);

    expect($other->tenant_id)->not->toBe($account->tenant_id);
});

it('rejects duplicate variants for the same post and account', function () {
    $variant = PostVariant::factory()->create();

    expect(fn () => PostVariant::factory()->create([
        'post_id' => $variant->post_id,
        'social_account_id' => $variant->social_account_id,
    ]))->toThrow(QueryException::class);
});

it('rejects duplicate schedules, provider comments and webhook events', function () {
    $scheduled = ScheduledPost::factory()->create();
    expect(fn () => ScheduledPost::factory()->create([
        'post_variant_id' => $scheduled->post_variant_id,
    ]))->toThrow(QueryException::class);

    $comment = Comment::factory()->create();
    expect(fn () => Comment::factory()->create([
        'provider' => $comment->provider->value,
        'provider_comment_id' => $comment->provider_comment_id,
    ]))->toThrow(QueryException::class);

    $event = WebhookEvent::factory()->create();
    expect(fn () => WebhookEvent::factory()->create([
        'provider' => $event->provider->value,
        'event_id' => $event->event_id,
    ]))->toThrow(QueryException::class);

    $state = OauthState::factory()->create();
    expect(fn () => OauthState::factory()->create([
        'state' => $state->state,
    ]))->toThrow(QueryException::class);
});

it('rejects duplicate media attached to the same variant', function () {
    $pivot = PostMedia::factory()->create();

    expect(fn () => PostMedia::factory()->create([
        'post_variant_id' => $pivot->post_variant_id,
        'media_asset_id' => $pivot->media_asset_id,
    ]))->toThrow(QueryException::class);
});

it('cascades every SocialHub row away when the tenant is deleted', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $account = SocialAccount::factory()->create(['tenant_id' => $tenant->id]);
    $token = SocialAccountToken::factory()->create(['social_account_id' => $account->id]);
    $post = Post::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);
    $variant = PostVariant::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);
    $asset = MediaAsset::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);
    $scheduled = ScheduledPost::factory()->create(['post_variant_id' => $variant->id]);
    $attempt = PublishingAttempt::factory()->create([
        'post_variant_id' => $variant->id,
        'scheduled_post_id' => $scheduled->id,
    ]);
    $comment = Comment::factory()->create([
        'tenant_id' => $tenant->id,
        'post_variant_id' => $variant->id,
        'social_account_id' => $account->id,
    ]);
    $reply = CommentReply::factory()->create([
        'comment_id' => $comment->id,
        'social_account_id' => $account->id,
    ]);
    $metric = AnalyticsMetric::factory()->create([
        'tenant_id' => $tenant->id,
        'social_account_id' => $account->id,
    ]);
    $snapshot = AnalyticsSnapshot::factory()->create([
        'tenant_id' => $tenant->id,
        'social_account_id' => $account->id,
    ]);
    $notification = SocialHubNotification::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
    ]);
    $event = WebhookEvent::factory()->create(['tenant_id' => $tenant->id]);
    $state = OauthState::factory()->create(['tenant_id' => $tenant->id]);
    $audit = AuditLog::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);
    $pivot = PostMedia::factory()->create([
        'post_variant_id' => $variant->id,
        'media_asset_id' => $asset->id,
    ]);

    $tenant->forceDelete();

    foreach ([
        'social_account_tokens' => $token->id,
        'posts' => $post->id,
        'post_variants' => $variant->id,
        'media_assets' => $asset->id,
        'post_media' => $pivot->id,
        'scheduled_posts' => $scheduled->id,
        'publishing_attempts' => $attempt->id,
        'comments' => $comment->id,
        'comment_replies' => $reply->id,
        'analytics_metrics' => $metric->id,
        'analytics_snapshots' => $snapshot->id,
        'socialhub_notifications' => $notification->id,
        'webhook_events' => $event->id,
        'oauth_states' => $state->id,
        'audit_logs' => $audit->id,
        'social_accounts' => $account->id,
    ] as $table => $id) {
        expect(DB::table($table)->where('id', $id)->exists())
            ->toBeFalse("{$table} #{$id} survived the tenant cascade");
    }

    // users are not owned by the tenant cascade in the pre-existing schema.
    expect(User::withoutGlobalScopes()->whereKey($user->id)->exists())->toBeTrue();
});

it('never exposes social account tokens through serialization', function () {
    $account = SocialAccount::factory()->withToken()->create()->fresh();

    $serialized = $account->toArray();

    expect($serialized)->not->toHaveKey('token');
    expect(array_keys($serialized))
        ->not->toContain('access_token', 'refresh_token', 'id_token', 'code_verifier');

    $account->load('token');

    expect($account->toArray())->not->toHaveKey('token');
    expect(json_encode($account->toArray()))
        ->not->toContain('access_token')
        ->not->toContain('refresh_token');

    $token = SocialAccountToken::firstOrFail();
    expect($token->toArray())->not->toHaveKeys(['access_token', 'refresh_token', 'id_token']);
});

it('exposes a credential-free public status payload', function () {
    $account = SocialAccount::factory()->withToken()->create()->load('token');

    $status = $account->publicStatus();

    expect($status)->toHaveKeys(['id', 'provider', 'status', 'has_token', 'token_expires_at']);
    expect(array_keys($status))
        ->not->toContain('access_token', 'refresh_token', 'id_token', 'permissions', 'metadata');
    expect($status['has_token'])->toBeTrue();
});

it('stores social account tokens as ciphertext', function () {
    $account = SocialAccount::factory()->withToken()->create();
    $token = SocialAccountToken::firstOrFail();

    $raw = DB::table('social_account_tokens')->where('id', $token->id)->first();

    expect($raw->access_token)->not->toBe($token->access_token);
    expect($raw->access_token)->not->toContain($token->access_token);
    expect($token->access_token)->toStartWith('access_');
});

it('scopes SocialHub reads to the resolved tenant', function () {
    $mine = SocialAccount::factory()->create();
    $theirs = SocialAccount::factory()->create();

    TenantContext::set(Tenant::find($mine->tenant_id));

    expect(SocialAccount::pluck('id')->all())->toBe([$mine->id]);
    expect(PostVariant::pluck('id')->all())->toBe($mine->postVariants()->pluck('id')->all());
    expect(SocialAccountToken::pluck('id')->all())->toBe([]);
});

it('exposes enum options, badges and transitions', function () {
    expect(SocialAccountStatus::options())->toHaveKey('connected');
    expect(PostStatus::options())->toHaveKey('draft');
    expect(SocialAccountStatus::Revoked->isTerminal())->toBeTrue();
    expect(SocialAccountStatus::Connected->canTransitionTo(SocialAccountStatus::Expired))->toBeTrue();
    expect(SocialAccountStatus::Revoked->canTransitionTo(SocialAccountStatus::Connected))->toBeFalse();
    expect(PostStatus::Draft->badgeColor())->toContain('dark:');
    expect(SocialAccount::factory()->expired()->create()->status)->toBe(SocialAccountStatus::Expired);
});

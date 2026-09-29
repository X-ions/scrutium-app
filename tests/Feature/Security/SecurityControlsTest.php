<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialAccountToken;
use App\Models\SocialHubNotification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

/**
 * The checks that would let a breach through, written so a regression fails
 * loudly rather than being argued about in review.
 */
final class SecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->owner = User::factory()->owner()->create(['tenant_id' => $this->tenant->id]);
    }

    // --- Token handling -------------------------------------------------

    public function test_tokens_are_encrypted_at_rest_not_stored_in_the_clear(): void
    {
        $account = SocialAccount::factory()->create(['tenant_id' => $this->tenant->id]);

        $account->token()->create([
            'access_token' => 'plain-access-token-value',
            'refresh_token' => 'plain-refresh-token-value',
            'expires_at' => now()->addDays(30),
        ]);

        $raw = DB::table('social_account_tokens')->first();

        $this->assertNotSame('plain-access-token-value', $raw->access_token);
        $this->assertNotSame('plain-refresh-token-value', $raw->refresh_token);

        // ...and it still round-trips, so encryption is not corrupting anything.
        $this->assertSame('plain-access-token-value', $account->token()->first()->access_token);
    }

    public function test_no_socialhub_api_response_ever_carries_a_token(): void
    {
        $account = SocialAccount::factory()->create(['tenant_id' => $this->tenant->id]);
        $account->token()->create([
            'access_token' => 'plain-access-token-value',
            'refresh_token' => 'plain-refresh-token-value',
            'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($this->owner);

        $encoded = json_encode($account->fresh()->toArray()).json_encode($account->publicStatus());

        $this->assertStringNotContainsString('plain-access-token-value', $encoded);
        $this->assertStringNotContainsString('plain-refresh-token-value', $encoded);
        $this->assertStringNotContainsString('access_token', $encoded);
        $this->assertStringNotContainsString('refresh_token', $encoded);
    }

    public function test_disconnecting_deletes_the_stored_credential(): void
    {
        $account = SocialAccount::factory()->create(['tenant_id' => $this->tenant->id]);
        $account->token()->create([
            'access_token' => 'plain-access-token-value',
            'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($this->owner)
            ->delete(route('socialhub.accounts.disconnect', $account))
            ->assertRedirect();

        $this->assertSame(0, SocialAccountToken::query()->count());
    }

    // --- Log redaction --------------------------------------------------

    public function test_a_token_quoted_into_a_log_message_is_still_redacted(): void
    {
        $logger = new Logger('probe', [$handler = new TestHandler]);
        $logger->pushProcessor(new \App\Logging\RedactsCredentials);

        $logger->info('token refresh failed: access_token=super-secret-value-3', [
            'provider' => 'facebook',
        ]);

        $record = $handler->getRecords()[0];

        $this->assertStringNotContainsString('super-secret-value-3', $record->message);
        $this->assertStringContainsString('[redacted]', $record->message);
        $this->assertSame('facebook', $record->context['provider']);
    }

    public function test_the_configured_log_channels_carry_the_redaction_processor(): void
    {
        foreach (['single', 'daily', 'stderr', 'structured'] as $channel) {
            $processors = (array) config("logging.channels.{$channel}.processors", []);

            $this->assertContains(
                \App\Logging\RedactsCredentials::class,
                $processors,
                "The {$channel} log channel must redact credentials.",
            );
        }
    }

    public function test_redaction_covers_context_keys_and_bearer_values(): void
    {
        $logger = new Logger('probe', [$handler = new TestHandler]);
        $logger->pushProcessor(new \App\Logging\RedactsCredentials);

        $logger->info('job', [
            'access_token' => 'super-secret-value-1',
            'meta' => ['refresh_token' => 'super-secret-value-2', 'provider' => 'instagram'],
            'authorization' => 'Bearer super-secret-value-4',
            'provider' => 'facebook',
        ]);

        $record = $handler->getRecords()[0];

        $this->assertSame('[redacted]', $record->context['access_token']);
        $this->assertSame('[redacted]', $record->context['meta']['refresh_token']);
        $this->assertSame('instagram', $record->context['meta']['provider'], 'A non-sensitive sibling must survive.');
        $this->assertSame('[redacted]', $record->context['authorization']);
        $this->assertSame('facebook', $record->context['provider']);
    }

    public function test_a_notification_never_contains_a_token(): void
    {
        $account = SocialAccount::factory()->create(['tenant_id' => $this->tenant->id]);
        $account->token()->create(['access_token' => 'super-secret-value', 'expires_at' => now()->addDay()]);

        app(\App\Services\Engagement\NotificationService::class)->notifyMembers(
            (int) $this->tenant->id,
            'test.type',
            'Refresh failed',
            'The refresh call failed with access_token=super-secret-value in the response.',
            ['account_id' => $account->id, 'access_token' => 'super-secret-value'],
        );

        $encoded = json_encode(SocialHubNotification::query()->first()->toArray());

        $this->assertStringNotContainsString('super-secret-value', $encoded);
    }

    // --- Tenant isolation -----------------------------------------------

    public function test_every_socialhub_resource_hides_another_workspaces_data(): void
    {
        $other = Tenant::factory()->create();
        $stranger = User::factory()->create(['tenant_id' => $other->id, 'role' => 'owner']);

        $foreignPost = Post::factory()->create(['tenant_id' => $other->id, 'user_id' => $stranger->id]);

        // A cross-tenant id must not resolve. A 404 rather than a 403 is
        // deliberate: a 403 would confirm the record exists.
        $this->actingAs($this->owner)
            ->get(route('socialhub.posts.show', $foreignPost))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->get(route('socialhub.posts.index'))
            ->assertOk()
            ->assertDontSee($foreignPost->title);
    }

    public function test_a_viewer_cannot_reach_any_mutating_socialhub_action(): void
    {
        $viewer = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'viewer']);
        $account = SocialAccount::factory()->create(['tenant_id' => $this->tenant->id]);
        $post = Post::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $viewer->id]);

        $this->actingAs($viewer)->post(route('socialhub.posts.publish', $post))->assertForbidden();
        $this->actingAs($viewer)->delete(route('socialhub.accounts.disconnect', $account))->assertForbidden();
        $this->actingAs($viewer)->post(route('socialhub.comments.sync'))->assertForbidden();
    }

    // --- Webhooks -------------------------------------------------------

    public function test_a_webhook_without_a_valid_signature_is_rejected_and_never_recorded(): void
    {
        $payload = ['event_id' => 'evt-forged'];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            '/webhooks/facebook',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.str_repeat('0', 64),
            ],
            content: $raw,
        );

        $response->assertStatus(401);
        $this->assertSame(0, \App\Models\WebhookEvent::query()->count());
        $this->assertSame(0, Comment::query()->count());
    }

    public function test_a_webhook_signed_with_the_wrong_secret_is_rejected(): void
    {
        $payload = ['event_id' => 'evt-wrong'];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/webhooks/facebook',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'not-the-real-secret'),
            ],
            content: $raw,
        )->assertStatus(401);

        $this->assertSame(0, \App\Models\WebhookEvent::query()->count());
    }

    // --- Uploads --------------------------------------------------------

    public function test_a_spoofed_upload_is_refused_from_its_bytes_not_its_name(): void
    {
        Storage::fake('local');

        $spoofed = UploadedFile::fake()->createWithContent('avatar.png', '<?php system($_GET["c"]);');

        $this->actingAs($this->owner)
            ->post(route('socialhub.media.store'), ['files' => [$spoofed]])
            ->assertRedirect();

        $this->assertSame(0, \App\Models\MediaAsset::query()->count());
    }

    public function test_a_double_extension_upload_is_refused(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('shell.png.php', 'harmless text');

        $this->actingAs($this->owner)
            ->post(route('socialhub.media.store'), ['files' => [$file]])
            ->assertRedirect();

        $this->assertSame(0, \App\Models\MediaAsset::query()->count());
    }

    public function test_uploads_are_never_written_to_the_web_servable_public_disk(): void
    {
        config()->set('socialhub.media.disk', 'public');
        app()['env'] = 'production';

        $storage = new \App\Services\Media\MediaStorage(app('filesystem'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/web-servable/');

        $storage->disk('public');
    }

    // --- Headers and throttling -----------------------------------------

    public function test_responses_carry_the_security_headers(): void
    {
        $response = $this->actingAs($this->owner)->get(route('socialhub.dashboard'));

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('X-Powered-By');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_oauth_connect_route_is_rate_limited(): void
    {
        $responses = [];

        for ($i = 0; $i < 14; $i++) {
            $responses[] = $this->actingAs($this->owner)->get(route('socialhub.accounts.connect', 'facebook'));
        }

        $throttled = collect($responses)->filter(fn ($response) => $response->getStatusCode() === 429);

        $this->assertGreaterThan(0, $throttled->count(), 'The OAuth start route must throttle.');
    }

    public function test_a_guest_cannot_reach_any_socialhub_page(): void
    {
        foreach ([
            'socialhub.dashboard',
            'socialhub.posts.index',
            'socialhub.calendar',
            'socialhub.media.index',
            'socialhub.comments.index',
            'socialhub.analytics.index',
            'socialhub.accounts.index',
        ] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }
}

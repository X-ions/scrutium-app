<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\PostVariantStatus;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Walks the workflow a real user takes, and asserts the two properties the
 * product depends on: a user only ever sees their own workspace, and one
 * network failing does not stop the others.
 */
final class SocialHubWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private SocialAccount $facebook;

    private SocialAccount $instagram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Acme Media']);
        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'owner',
        ]);

        $this->facebook = SocialAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => SocialPlatform::Facebook,
            'status' => SocialAccountStatus::Connected,
        ]);

        $this->instagram = SocialAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => SocialPlatform::Instagram,
            'status' => SocialAccountStatus::Connected,
        ]);
    }

    public function test_dashboard_renders_for_a_connected_workspace(): void
    {
        $response = $this->actingAs($this->owner)->get(route('socialhub.dashboard'));

        $response->assertOk();
    }

    public function test_accounts_page_never_renders_a_token(): void
    {
        $this->facebook->token()->create([
            'access_token' => 'super-secret-access-token',
            'refresh_token' => 'super-secret-refresh-token',
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($this->owner)->get(route('socialhub.accounts.index'));

        $response->assertOk();
        $response->assertDontSee('super-secret-access-token');
        $response->assertDontSee('super-secret-refresh-token');
    }

    public function test_a_user_cannot_read_another_workspaces_post(): void
    {
        $other = Tenant::factory()->create();
        $stranger = User::factory()->create(['tenant_id' => $other->id, 'role' => 'owner']);
        $foreignPost = Post::factory()->create(['tenant_id' => $other->id, 'user_id' => $stranger->id]);

        // The tenant scope hides the row entirely, so a 404 is returned rather
        // than a 403 — a 403 would confirm the record exists.
        $this->actingAs($this->owner)
            ->get(route('socialhub.posts.show', $foreignPost))
            ->assertNotFound();
    }

    public function test_a_viewer_cannot_see_another_workspaces_post_in_a_list(): void
    {
        $other = Tenant::factory()->create();
        $stranger = User::factory()->create(['tenant_id' => $other->id, 'role' => 'owner']);
        $foreignPost = Post::factory()->create([
            'tenant_id' => $other->id,
            'user_id' => $stranger->id,
            'title' => 'Confidential competitor post',
        ]);

        $this->actingAs($this->owner)
            ->get(route('socialhub.posts.index'))
            ->assertOk()
            ->assertDontSee('Confidential competitor post');

        unset($foreignPost);
    }

    public function test_composer_creates_one_variant_per_selected_network(): void
    {
        $response = $this->actingAs($this->owner)->post(route('socialhub.posts.store'), [
            'title' => 'Spring launch',
            'variants' => [
                ['social_account_id' => $this->facebook->id, 'caption' => 'Hello Facebook'],
                ['social_account_id' => $this->instagram->id, 'caption' => 'Hello Instagram'],
            ],
        ]);

        $response->assertRedirect();

        $post = Post::query()->where('title', 'Spring launch')->firstOrFail();

        $this->assertCount(2, $post->variants);
        $this->assertSame(PostStatus::Draft, $post->statusEnum());
        $this->assertEqualsCanonicalizing(
            [SocialPlatform::Facebook, SocialPlatform::Instagram],
            $post->variants->pluck('provider')->all(),
        );
    }

    public function test_composer_rejects_an_account_from_another_workspace(): void
    {
        $other = Tenant::factory()->create();
        $foreign = SocialAccount::factory()->create([
            'tenant_id' => $other->id,
            'provider' => SocialPlatform::Facebook,
            'status' => SocialAccountStatus::Connected,
        ]);

        $this->actingAs($this->owner)
            ->post(route('socialhub.posts.store'), [
                'variants' => [['social_account_id' => $foreign->id, 'caption' => 'nope']],
            ])
            ->assertSessionHasErrors('variants.0.social_account_id');

        $this->assertSame(0, Post::query()->count());
    }

    public function test_composer_rejects_a_caption_longer_than_the_platform_allows(): void
    {
        $x = SocialAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => SocialPlatform::X,
            'status' => SocialAccountStatus::Connected,
        ]);

        $this->actingAs($this->owner)
            ->post(route('socialhub.posts.store'), [
                'variants' => [
                    ['social_account_id' => $x->id, 'caption' => str_repeat('a', 281)],
                ],
            ])
            ->assertSessionHasErrors('variants.0.caption');
    }

    public function test_a_viewer_cannot_publish(): void
    {
        $viewer = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'viewer']);
        $post = $this->makePost();

        $this->actingAs($viewer)
            ->post(route('socialhub.posts.publish', $post))
            ->assertForbidden();
    }

    public function test_one_network_failing_does_not_stop_the_others(): void
    {
        $post = $this->makePost();

        $failed = $post->variants()->where('provider', SocialPlatform::Facebook->value)->firstOrFail();
        $succeeded = $post->variants()->where('provider', SocialPlatform::Instagram->value)->firstOrFail();

        $failed->update([
            'status' => PostVariantStatus::Failed,
            'error_message' => 'The connected account is missing permission to publish.',
        ]);

        $succeeded->update([
            'status' => PostVariantStatus::Published,
            'provider_post_id' => '12345',
            'provider_post_url' => 'https://instagram.com/p/12345',
            'published_at' => now(),
        ]);

        $this->assertSame(PostStatus::Draft, $post->fresh()->statusEnum());

        $response = $this->actingAs($this->owner)->get(route('socialhub.posts.show', $post));

        $response->assertOk();
        $response->assertSee('Publishing failed', escape: false);
        $response->assertSee('missing permission to publish', escape: false);
        $response->assertSee('12345');
    }

    public function test_duplicating_a_post_creates_a_draft_and_keeps_no_provider_ids(): void
    {
        $post = $this->makePost();
        $post->variants()->update(['status' => PostVariantStatus::Published, 'provider_post_id' => 'original']);

        $response = $this->actingAs($this->owner)->post(route('socialhub.posts.duplicate', $post));

        $response->assertRedirect();

        $copy = Post::query()->where('id', '!=', $post->id)->firstOrFail();

        $this->assertSame(PostStatus::Draft, $copy->statusEnum());
        $this->assertNull($copy->variants->first()->provider_post_id);
    }

    public function test_media_library_stores_the_upload_and_rejects_a_spoofed_type(): void
    {
        Storage::fake('local');

        $png = UploadedFile::fake()->createWithContent('shot.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ));

        $this->actingAs($this->owner)
            ->post(route('socialhub.media.store'), ['files' => [$png], 'folder' => 'launch'])
            ->assertRedirect();

        $asset = MediaAsset::query()->firstOrFail();

        $this->assertSame('image/png', $asset->mime_type);
        $this->assertSame('launch', $asset->folder);
        $this->assertStringEndsWith('.png', $asset->stored_filename);

        // The name the client sent is kept as metadata but never used on disk.
        $this->assertSame('shot.png', $asset->filename);
        $this->assertNotSame('shot.png', $asset->stored_filename);

        // A PHP script wearing a .png name is refused from the bytes alone.
        // The refusal is reported per file, so a good file in the same batch
        // is still stored.
        $spoofed = UploadedFile::fake()->createWithContent('payload.png', '<?php echo "not an image";');
        $good = UploadedFile::fake()->createWithContent('second.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ));

        $response = $this->actingAs($this->owner)
            ->post(route('socialhub.media.store'), ['files' => [$spoofed, $good]]);

        $response->assertRedirect();
        $this->assertStringContainsString('payload.png', (string) session('error'));

        $this->assertSame(2, MediaAsset::query()->count());
        $this->assertFalse(
            MediaAsset::query()->where('filename', 'payload.png')->exists(),
            'A rejected upload must not leave a media row behind.',
        );
    }

    public function test_calendar_and_analytics_render(): void
    {
        $this->makePost();

        $this->actingAs($this->owner)->get(route('socialhub.calendar'))->assertOk();
        $this->actingAs($this->owner)->get(route('socialhub.analytics.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('socialhub.media.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('socialhub.comments.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('socialhub.providers.index'))->assertOk();
    }

    public function test_guests_are_redirected_away_from_the_workspace(): void
    {
        $this->get(route('socialhub.dashboard'))->assertRedirect(route('login'));
    }

    private function makePost(): Post
    {
        $post = Post::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'title' => 'Launch post',
            'status' => PostStatus::Draft,
        ]);

        foreach ([$this->facebook, $this->instagram] as $account) {
            PostVariant::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'provider' => $account->provider,
                'caption' => 'Hello',
                'status' => PostVariantStatus::Pending,
            ]);
        }

        return $post->fresh();
    }
}

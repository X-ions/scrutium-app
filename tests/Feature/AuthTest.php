<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());
afterEach(fn () => TenantContext::forget());

it('renders the authentication pages', function () {
    $this->get('/signin')->assertOk();
    $this->get('/signup')->assertOk();
    $this->get(route('password.request'))->assertOk();
});

it('configures Resend through the built-in SMTP transport', function () {
    expect(config('mail.mailers.resend'))->toMatchArray([
        'transport' => 'smtp',
        'scheme' => 'smtps',
        'host' => 'smtp.resend.com',
        'port' => 465,
        'username' => 'resend',
    ]);
});

it('sends and accepts a password reset link', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'reset@example.test']);
    $token = null;

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect()
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$token): bool {
        $token = $notification->token;

        return $notification->toMail($user)->actionUrl === route('password.reset', [
            'token' => $token,
            'email' => $user->email,
        ]);
    });

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertOk();

    $newPassword = 'a-secure-new-password';
    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ])->assertRedirect(route('dashboard'))
        ->assertSessionHas('success');

    $this->assertAuthenticatedAs($user);
    expect(Hash::check($newPassword, $user->fresh()->password))->toBeTrue();
});

it('shows a generic error when password reset email delivery fails', function () {
    $broker = Mockery::mock();
    $broker->shouldReceive('sendResetLink')
        ->once()
        ->with(['email' => 'reset@example.test'])
        ->andThrow(new RuntimeException('Resend API key is missing'));

    Password::shouldReceive('broker')
        ->once()
        ->with('users')
        ->andReturn($broker);

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'reset@example.test'])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasErrors([
            'email' => 'We could not send a reset link right now. Please try again shortly.',
        ]);
});

it('does not expose diagnostic routes', function () {
    $this->get('/health/db')->assertNotFound();
    $this->get('/debug/auth')->assertNotFound();
    $this->get('/debug/dashboard')->assertNotFound();
    $this->get('/debug/dashboard-render')->assertNotFound();
    $this->get('/debug/forgot-password')->assertNotFound();
    $this->post('/debug/forgot-password')->assertNotFound();
});

it('registers a workspace and authenticates its owner', function () {
    $response = $this->post('/register', [
        'name' => 'Workspace Owner',
        'email' => 'owner@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'workspace_name' => 'Example Collective',
        'workspace_slug' => 'example-collective',
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::where('email', 'owner@example.test')->firstOrFail();
    expect($user->tenant->slug)->toBe('example-collective')
        ->and($user->canManageWorkspace())->toBeTrue();
});

it('shows the workspace owner summary in the sidebar', function () {
    $owner = User::factory()->owner()->create([
        'name' => 'Alicia Stone',
        'job_title' => 'Workspace owner',
    ]);
    $owner->tenant->forceFill(['name' => 'Scrutium Inc'])->save();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('SI')
        ->assertSee('Scrutium Inc')
        ->assertSee('Workspace owner');
});

it('keeps guests out and signs a user in', function () {
    $this->get('/campaigns')->assertRedirect(route('login'));

    $user = User::factory()->owner()->create([
        'email' => 'login@example.test',
        'password' => 'correct-horse-battery-staple',
    ]);

    $this->post('/login', [
        'email' => 'login@example.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertRedirect(route('campaigns'));

    $this->assertAuthenticatedAs($user);
});

it('does not allow a user to read another workspace campaign', function () {
    $user = User::factory()->owner()->create();
    $otherTenant = Tenant::factory()->create();
    $campaign = $otherTenant->campaigns()->create([
        'name' => 'Private campaign',
        'slug' => 'private-campaign',
        'stage' => 'brief',
        'status' => 'draft',
    ]);

    $this->actingAs($user)->get(route('campaigns.show', $campaign))->assertNotFound();
});

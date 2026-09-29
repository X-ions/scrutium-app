<?php

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\WelcomeAccount;
use App\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

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

it('sends the branded verification email with a 24-hour signed link', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create([
        'name' => 'Avery Stone',
        'email' => 'verify@example.test',
    ]);

    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $url = $mail->viewData['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $mail->subject === 'Verify your email for Scrutium'
            && $mail->view === 'emails.verify-email'
            && $mail->viewData['first_name'] === 'Avery'
            && (int) ($query['expires'] ?? 0) === now()->addHours(24)->timestamp;
    });
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

        return $notification->toMail($user)->viewData['url'] === route('password.reset', [
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

it('registers a workspace and holds the owner out until the address is verified', function () {
    $response = $this->post('/register', [
        'name' => 'Workspace Owner',
        'email' => 'owner@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'workspace_name' => 'Example Collective',
        'workspace_slug' => 'example-collective',
    ]);

    // Registration creates the workspace and sends the verification email. The
    // owner is deliberately not signed in yet: an unverified address must not
    // be able to reach a workspace.
    $response->assertRedirect(route('verification.notice'));
    $this->assertGuest();

    $user = User::where('email', 'owner@example.test')->firstOrFail();
    expect($user->tenant->slug)->toBe('example-collective')
        ->and($user->canManageWorkspace())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    // Verifying the address through the signed link is what grants access.
    $this->actingAs($user)->get(URL::temporarySignedRoute(
        'verification.verify',
        now()->addHour(),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
    ))->assertRedirect();

    $this->assertTrue($user->fresh()->hasVerifiedEmail());
});

it('sends a welcome email with account details after workspace signup', function () {
    Notification::fake();

    $this->post('/register', [
        'name' => 'Workspace Owner',
        'email' => 'welcome-owner@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'workspace_name' => 'Welcome Collective',
        'workspace_slug' => 'welcome-collective',
    ])->assertRedirect(route('verification.notice'));

    $user = User::where('email', 'welcome-owner@example.test')->firstOrFail();

    Notification::assertSentTo($user, WelcomeAccount::class, function (WelcomeAccount $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        return $mail->view === 'emails.welcome'
            && $mail->viewData === [
                'workspace_name' => 'Welcome Collective',
                'user_name' => 'Workspace Owner',
                'user_id' => $user->id,
            ];
    });
});

it('sends a welcome email when a workspace adds a team member', function () {
    Notification::fake();
    $owner = User::factory()->owner()->create();
    $owner->tenant->update(['name' => 'Team Workspace']);

    $this->actingAs($owner)
        ->post(route('settings.members.store'), [
            'name' => 'Taylor Member',
            'email' => 'team-member@example.test',
            'role' => UserRole::Viewer->value,
            'temporary_password' => 'a-temporary-passphrase',
        ])
        ->assertRedirect();

    $member = User::where('email', 'team-member@example.test')->firstOrFail();

    Notification::assertSentTo($member, WelcomeAccount::class, function (WelcomeAccount $notification) use ($member): bool {
        return $notification->toMail($member)->viewData === [
            'workspace_name' => 'Team Workspace',
            'user_name' => 'Taylor Member',
            'user_id' => $member->id,
        ];
    });
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
        // The sidebar identifies the workspace by name and the signed-in user
        // by name. A job title is shown on the profile page, not here, so it
        // is not asserted on.
        ->assertSee('Scrutium Inc')
        ->assertSee('Alicia Stone');
});

it('shows the workspace team dropdown and member list in the sidebar', function () {
    $owner = User::factory()->owner()->create(['name' => 'Alicia Stone']);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Open workspace team menu')
        ->assertSee('Create Team')
        ->assertSee('Invite')
        ->assertSee('Alicia Stone')
        ->assertSee('You');
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

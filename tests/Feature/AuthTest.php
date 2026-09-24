<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());
afterEach(fn () => TenantContext::forget());

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

it('keeps guests out and signs a user in', function () {
    $this->get('/campaigns')->assertRedirect(route('login'));

    $user = User::factory()->owner()->create([
        'email' => 'login@example.test',
        'password' => 'correct-horse-battery-staple',
    ]);

    $this->post('/login', [
        'email' => 'login@example.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertRedirect(route('dashboard'));

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

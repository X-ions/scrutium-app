<?php

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => TenantContext::forget());
afterEach(fn () => TenantContext::forget());

it('renders the workspace settings modal for workspace managers', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('settings'))
        ->assertOk()
        ->assertSee('workspace-settings-title')
        ->assertSee('Workspace Description')
        ->assertSee('Default Currency')
        ->assertSee('Compact Layout')
        ->assertSee('Auto-Save');
});

it('persists workspace preferences and starts the name cooldown', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->patchJson(route('settings.workspace'), [
            'name' => 'Updated Workspace',
            'timezone' => 'UTC',
            'currency' => 'EUR',
            'description' => 'A workspace description.',
            'language' => 'ar',
            'compact_layout' => true,
            'auto_save' => true,
        ])
        ->assertOk()
        ->assertJsonPath('workspace.language', 'ar')
        ->assertJsonPath('workspace.compact_layout', true)
        ->assertJsonPath('workspace.auto_save', true);

    $workspace = $owner->tenant()->firstOrFail();
    expect($workspace->name)->toBe('Updated Workspace')
        ->and($workspace->currency)->toBe('EUR')
        ->and($workspace->description)->toBe('A workspace description.')
        ->and($workspace->name_updated_at)->not->toBeNull();
});

it('rejects a workspace name change during the seven-day cooldown', function () {
    $owner = User::factory()->owner()->create();
    $workspace = $owner->tenant;
    $workspace->update(['name_updated_at' => now()->subDays(3)]);
    $originalName = $workspace->name;

    $this->actingAs($owner)
        ->from(route('settings'))
        ->patch(route('settings.workspace'), [
            'name' => 'Too Soon Workspace',
            'timezone' => $workspace->timezone,
            'currency' => $workspace->currency,
        ])
        ->assertRedirect(route('settings'))
        ->assertSessionHasErrors('name');

    expect($workspace->fresh()->name)->toBe($originalName);
});

it('subscribes the current user to security alerts through the settings endpoint', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->postJson(route('settings.subscriptions.store'), [
            'type' => 'security',
            'channel' => 'email',
            'is_active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('is_active', true);

    $this->assertDatabaseHas('alert_subscriptions', [
        'tenant_id' => $owner->tenant_id,
        'user_id' => $owner->id,
        'type' => 'security',
        'channel' => 'email',
        'is_active' => true,
    ]);
});

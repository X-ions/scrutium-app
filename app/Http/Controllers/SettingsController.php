<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AlertSubscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        return view('pages.scrutium.settings.index', [
            'title' => 'Settings',
            'tenant' => $request->user()->tenant,
            'members' => $request->user()->tenant->users()->orderBy('name')->get(),
            'roles' => UserRole::options(),
            'subscriptions' => $request->user()->alertSubscriptions()->latest()->get(),
        ]);
    }

    public function updateWorkspace(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'],
            'currency' => ['required', 'string', 'size:3', 'in:USD,EUR,GBP,AED,SAR'],
        ]);

        $request->user()->tenant->update($data);

        return back()->with('success', 'Workspace settings updated.');
    }

    public function storeMember(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'temporary_password' => ['required', 'string', 'min:12', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:120'],
        ]);

        User::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['temporary_password'],
            'role' => $data['role'],
            'job_title' => $data['job_title'] ?? null,
        ]);

        return back()->with('success', 'Team member added. Share the temporary password securely.');
    }

    public function updateMember(Request $request, User $member): RedirectResponse
    {
        abort_unless($member->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['role' => ['required', Rule::enum(UserRole::class)]]);
        abort_if($member->is($request->user()) && $data['role'] !== UserRole::Owner->value, 422, 'You cannot remove your own owner access.');

        $member->update($data);

        return back()->with('success', 'Team member role updated.');
    }

    public function storeSubscription(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:80'],
            'channel' => ['required', Rule::in(['in_app', 'email'])],
        ]);

        AlertSubscription::updateOrCreate([
            'user_id' => $request->user()->id,
            'type' => $data['type'] ?? null,
            'channel' => $data['channel'],
        ], ['tenant_id' => $request->user()->tenant_id, 'is_active' => true]);

        return back()->with('success', 'Notification subscription added.');
    }
}

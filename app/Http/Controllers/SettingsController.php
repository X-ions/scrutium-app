<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AlertSubscription;
use App\Models\User;
use App\Notifications\WelcomeAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    public function updateWorkspace(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'],
            'currency' => ['required', 'string', 'size:3', 'in:USD,EUR,GBP,AED,SAR'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'language' => ['sometimes', Rule::in(array_keys(LocaleController::SUPPORTED_LOCALES))],
            'compact_layout' => ['sometimes', 'boolean'],
            'auto_save' => ['sometimes', 'boolean'],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $workspace = $request->user()->tenant;
        if ($workspace->name !== $data['name'] && $workspace->name_updated_at?->copy()->addDays(7)->isFuture()) {
            return back()->withErrors([
                'name' => 'Workspace name can next be changed on '.$workspace->name_updated_at->copy()->addDays(7)->toFormattedDateString().'.',
            ])->withInput();
        }

        $changes = collect($data)->only([
            'name',
            'timezone',
            'currency',
            'description',
            'language',
            'compact_layout',
            'auto_save',
        ])->all();

        if ($workspace->name !== $data['name']) {
            $changes['name_updated_at'] = now();
        }

        $diskName = config('filesystems.evidence_disk');
        if ($request->hasFile('logo')) {
            $oldLogoPath = $workspace->logo_path;
            $changes['logo_path'] = $request->file('logo')->storePublicly("workspaces/{$workspace->id}", $diskName);
        }

        $workspace->update($changes);

        if (isset($oldLogoPath)) {
            Storage::disk($diskName)->delete($oldLogoPath);
        }

        if (isset($data['language'])) {
            $locale = $data['language'];
            $direction = LocaleController::SUPPORTED_LOCALES[$locale]['dir'];
            session(['locale' => $locale, 'dir' => $direction]);
            cookie()->queue('locale', $locale, 60 * 24 * 365);
            cookie()->queue('dir', $direction, 60 * 24 * 365);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Workspace settings saved.',
                'workspace' => [
                    'language' => $workspace->language,
                    'direction' => LocaleController::SUPPORTED_LOCALES[$workspace->language]['dir'],
                    'compact_layout' => $workspace->compact_layout,
                    'auto_save' => $workspace->auto_save,
                    'logo_url' => $workspace->logo_path ? Storage::disk($diskName)->url($workspace->logo_path) : null,
                ],
            ]);
        }

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

        $member = User::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['temporary_password'],
            'role' => $data['role'],
            'job_title' => $data['job_title'] ?? null,
        ]);
        $member->notify(new WelcomeAccount);

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

    public function storeSubscription(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:80'],
            'channel' => ['required', Rule::in(['in_app', 'email'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $subscription = AlertSubscription::updateOrCreate([
            'user_id' => $request->user()->id,
            'type' => $data['type'] ?? null,
            'channel' => $data['channel'],
        ], [
            'tenant_id' => $request->user()->tenant_id,
            'is_active' => $data['is_active'] ?? true,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['is_active' => $subscription->is_active]);
        }

        return back()->with('success', 'Notification subscription added.');
    }
}

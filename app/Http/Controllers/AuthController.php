<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AlertSubscription;
use App\Models\ScoreConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('pages.auth.signin', ['title' => 'Sign in']);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();
        $user = $request->user();
        $user->forceFill(['last_active_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister(): View
    {
        return view('pages.auth.signup', ['title' => 'Create workspace']);
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:12'],
            'workspace_name' => ['required', 'string', 'max:120'],
            'workspace_slug' => ['nullable', 'string', 'max:80', 'alpha_dash'],
        ]);

        try {
            $user = DB::transaction(function () use ($data): User {
                $baseSlug = Str::lower(Str::slug($data['workspace_slug'] ?: $data['workspace_name'])) ?: 'workspace';
                $slug = $baseSlug;
                $suffix = 2;

                while (Tenant::where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$suffix++;
                }

                $tenant = Tenant::create([
                    'name' => $data['workspace_name'],
                    'slug' => $slug,
                    'plan' => 'standard',
                    'currency' => 'USD',
                ]);

                TenantContext::set($tenant);

                $user = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'role' => UserRole::Owner,
                    'job_title' => 'Workspace owner',
                ]);

                ScoreConfig::create([
                    'name' => 'Default creator score',
                    'description' => 'Balanced starting weights for creator evaluation.',
                    'weights' => ScoreConfig::DEFAULT_WEIGHTS,
                    'is_default' => true,
                ]);

                AlertSubscription::create([
                    'user_id' => $user->id,
                    'channel' => 'in_app',
                    'is_active' => true,
                ]);

                return $user;
            });
        } finally {
            TenantContext::forget();
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_active_at' => now()])->save();

        return redirect()->route('dashboard')->with('success', 'Your workspace is ready.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been signed out.');
    }
}

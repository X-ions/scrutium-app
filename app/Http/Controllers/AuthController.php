<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AlertSubscription;
use App\Models\ScoreConfig;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\WelcomeAccount;
use App\Services\Security\SecurityEventDetector;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
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

    public function login(Request $request, SecurityEventDetector $detector): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $this->recordFailedAttempt($request, $credentials['email'], $detector);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();
        $user = $request->user();
        $user->forceFill(['last_active_at' => now()])->save();

        $detector->detectAndRecord($user, $request, 'login');

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Record a failed sign-in so the user can be warned when guessing turns
     * into a real attack. Repeated attempts from one address are folded into
     * a single event so the audit log stays readable.
     */
    private function recordFailedAttempt(Request $request, string $email, SecurityEventDetector $detector): void
    {
        $user = User::where('email', $email)->first();

        if ($user === null) {
            return;
        }

        $window = now()->subMinutes((int) config('auth.passwords.users.throttle', 60) ?: 60);

        $recent = SecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'login_failed')
            ->where('ip_address', $request->ip())
            ->where('occurred_at', '>=', $window)
            ->count();

        $attempts = $recent + 1;
        $maxAttempts = (int) config('security.failed_login_threshold', 5);

        $detector->detectAndRecord($user, $request, 'login_failed', [
            'failed_attempts' => $attempts,
            'threshold' => $maxAttempts,
            'exceeds_threshold' => $attempts >= $maxAttempts,
        ]);
    }

    public function showRegister(): View
    {
        return view('pages.auth.signup', ['title' => 'Create workspace']);
    }

    public function checkWorkspaceName(Request $request): JsonResponse
    {
        $name = trim((string) $request->query('name', ''));

        if ($name === '' || mb_strlen($name) > 120) {
            return response()->json(['available' => false]);
        }

        $query = Tenant::query()->where('name_key', mb_strtolower($name));

        if ($tenantId = $request->user()?->tenant_id) {
            $query->where('id', '<>', $tenantId);
        }

        return response()->json(['available' => ! $query->exists()]);
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:12'],
            'workspace_name' => [
                'required',
                'string',
                'max:120',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Tenant::query()->where('name_key', mb_strtolower(trim((string) $value)))->exists()) {
                        $fail('That workspace name is already in use. Choose another name.');
                    }
                },
            ],
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
        } catch (QueryException $exception) {
            if (Tenant::query()->where('name_key', mb_strtolower(trim($data['workspace_name'])))->exists()) {
                throw ValidationException::withMessages([
                    'workspace_name' => 'That workspace name is already in use. Choose another name.',
                ]);
            }

            throw $exception;
        } finally {
            TenantContext::forget();
        }

        $user->sendEmailVerificationNotification();
        $user->notify(new WelcomeAccount);

        return redirect()->route('verification.notice')
            ->with('success', 'Your workspace is ready! Please verify your email address to continue.');
    }

    public function logout(Request $request, SecurityEventDetector $detector): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $detector->detectAndRecord($user, $request, 'logout');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been signed out.');
    }
}

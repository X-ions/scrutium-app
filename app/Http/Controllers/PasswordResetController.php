<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('pages.auth.forgot-password', ['title' => 'Forgot password']);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            Password::broker('users')->sendResetLink(
                $request->only('email')
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'email' => 'We could not send a reset link right now. Please try again shortly.',
            ]);
        }

        return back()->with(
            'status',
            'If that email is in our system, we sent a reset link. It expires in 60 minutes.'
        );
    }

    public function edit(Request $request, string $token): View
    {
        return view('pages.auth.reset-password', [
            'title' => 'Reset password',
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:12'],
        ]);

        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'last_active_at' => now(),
                ])->save();

                Auth::login($user);
                $request->session()->regenerate();
                Auth::logoutOtherDevices($password);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()
                ->route('dashboard')
                ->with('success', 'Your password has been updated.');
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Security\SecurityEventDetector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('pages.profile', [
            'title' => 'Profile',
            'user' => $request->user(),
        ]);
    }

    public function update(Request $request, SecurityEventDetector $detector): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'job_title' => ['nullable', 'string', 'max:120'],
        ]);

        $emailChanged = strcasecmp((string) $user->email, $data['email']) !== 0;
        $previousEmail = (string) $user->email;

        $user->update($data);

        if ($emailChanged) {
            // The new address must be proven before it can be used to sign in.
            $user->forceFill(['email_verified_at' => null])->save();
            $user->sendEmailVerificationNotification();

            $detector->detectAndRecord($user, $request, 'email_changed', [
                'previous_email' => $previousEmail,
                'new_email' => $data['email'],
            ]);

            return back()->with(
                'success',
                'Your profile has been updated. We sent a verification link to your new email address.'
            );
        }

        return back()->with('success', 'Your profile has been updated.');
    }

    public function updatePassword(Request $request, SecurityEventDetector $detector): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'min:12'],
        ]);

        $user->update(['password' => $data['password']]);

        $request->session()->regenerate();
        Auth::logoutOtherDevices($data['password']);

        $detector->detectAndRecord($user, $request, 'password_changed', [
            'other_sessions_revoked' => true,
        ]);

        return back()->with('success', 'Your password has been changed and other sessions have been signed out.');
    }
}

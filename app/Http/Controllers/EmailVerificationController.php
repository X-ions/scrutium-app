<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(): View
    {
        return view('pages.auth.verify-email', ['title' => 'Verify your email']);
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A new verification link has been sent to your email address.');
    }

    public function verify(Request $request): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->route('verification.notice')
                ->withErrors(['email' => 'The verification link is invalid or has expired.']);
        }

        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->route('dashboard')->with('success', 'Your email has been verified.');
    }
}
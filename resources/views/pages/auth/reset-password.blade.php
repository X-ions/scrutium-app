@extends('layouts.fullscreen-layout')

@section('content')
    <p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Account recovery</p>
    <h1 class="mt-2 text-3xl font-bold text-gray-800 dark:text-white/90">Choose a new password</h1>
    <p class="mt-2 text-gray-500 dark:text-gray-400">Use at least 12 characters. This link expires 60 minutes after it was sent.</p>

    <div class="mt-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-8">
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-700 dark:bg-error-500/15 dark:text-error-400">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <div>
                <label for="password" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">New password</label>
                <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <div>
                <label for="password_confirmation" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <button type="submit" class="flex w-full items-center justify-center rounded-lg bg-[#0B1B33] px-4 py-3 text-sm font-semibold text-white hover:bg-black">
                Update password
            </button>
        </form>
    </div>
@endsection

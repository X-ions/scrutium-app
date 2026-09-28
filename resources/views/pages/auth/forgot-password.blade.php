@extends('layouts.fullscreen-layout')

@section('content')
    <p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Account recovery</p>
    <h1 class="mt-2 text-3xl font-bold text-gray-800 dark:text-white/90">Forgot your password?</h1>
    <p class="mt-2 text-gray-500 dark:text-gray-400">Enter your email and we will send a reset link if an account exists.</p>

    <div class="mt-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-8">
        @if (session('status'))
            <div class="mb-4 rounded-lg bg-success-50 px-3 py-2 text-sm text-success-700 dark:bg-success-500/15 dark:text-success-400">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-700 dark:bg-error-500/15 dark:text-error-400">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
            @csrf
            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <button type="submit" class="flex w-full items-center justify-center rounded-lg bg-[#0B1B33] px-4 py-3 text-sm font-semibold text-white hover:bg-black">
                Send reset link
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
        Remembered it?
        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Sign in</a>
    </p>
@endsection

@extends('layouts.fullscreen-layout')

@section('content')
<div class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] p-8">
            <div class="text-center mb-8">
                <a href="{{ config('app.url') }}" class="inline-flex items-center gap-2" aria-label="Scrutium Home">
                    <svg class="w-10 h-10 text-brand-600 dark:text-brand-400" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="36" height="36" rx="10" fill="currentColor"/>
                        <path d="M12 18L16.5 22.5L24 15" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="text-2xl font-bold text-gray-900 dark:text-white">Scrutium</span>
                </a>
                <h1 class="mt-6 text-title-xl font-bold text-gray-900 dark:text-white">Verify your email address</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Thanks for creating your workspace! We've sent a verification link to
                    <strong class="text-gray-900 dark:text-white">{{ auth()->user()->email }}</strong>.
                </p>
            </div>

            @if (session('status'))
                <div class="mb-6 rounded-lg bg-green-50 p-4 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-400" role="alert">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-400" role="alert">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="space-y-4">
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <button type="submit"
                        class="w-full btn-primary py-3 text-sm font-semibold rounded-xl transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        {{ auth()->user()->hasVerifiedEmail() ? 'Email already verified' : 'Resend verification email' }}
                    </button>
                </form>

                <p class="text-center text-sm text-gray-500 dark:text-gray-400">
                    Didn't receive the email? Check your spam folder or
                    <a href="{{ route('login') }}" class="text-brand-600 hover:text-brand-500 font-medium">sign in again</a>.
                </p>
            </div>
        </div>

        <p class="mt-6 text-center text-xs text-gray-400 dark:text-gray-500">
            &copy; {{ date('Y') }}/2026 X-ion, Inc. All Rights Reserved.
        </p>
    </div>
</div>
@endsection
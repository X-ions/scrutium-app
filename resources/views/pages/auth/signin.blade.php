@extends('layouts.fullscreen-layout')

@section('content')
    @if (session('success'))
        <div x-data="{ visible: true }" x-show="visible" x-transition.opacity.duration.200ms x-init="setTimeout(() => visible = false, 5000)"
            role="status" aria-live="polite"
            class="fixed end-4 top-4 z-999999 flex w-[calc(100%-2rem)] max-w-sm items-start gap-3 rounded-xl border border-success-200 bg-white p-4 shadow-theme-lg dark:border-success-500/30 dark:bg-gray-900">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none"><path d="m5 10 3.2 3.2L15.5 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
            </span>
            <p class="flex-1 pt-1 text-sm font-medium text-gray-700 dark:text-gray-200">{{ session('success') }}</p>
            <button type="button" @click="visible = false" aria-label="Dismiss notification" class="rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:hover:bg-white/10 dark:hover:text-white">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /></svg>
            </button>
        </div>
    @endif
    @if ($errors->any())
        <div x-data="{ visible: true }" x-show="visible" x-transition.opacity.duration.200ms x-init="setTimeout(() => visible = false, 7000)"
            role="alert" aria-live="assertive"
            class="fixed end-4 top-4 z-999999 flex w-[calc(100%-2rem)] max-w-sm items-start gap-3 rounded-xl border border-error-200 bg-white p-4 shadow-theme-lg dark:border-error-500/30 dark:bg-gray-900">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none"><path d="M10 6v4m0 3h.01M3.8 15.2 8.6 4.8a1.55 1.55 0 0 1 2.8 0l4.8 10.4a1.55 1.55 0 0 1-1.4 2.2H5.2a1.55 1.55 0 0 1-1.4-2.2Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
            </span>
            <p class="flex-1 pt-1 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $errors->first() }}</p>
            <button type="button" @click="visible = false" aria-label="Dismiss notification" class="rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:hover:bg-white/10 dark:hover:text-white">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /></svg>
            </button>
        </div>
    @endif

    <p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Welcome back</p>
    <h1 class="mt-2 text-3xl font-bold text-gray-800 dark:text-white/90">Sign in to Scrutium</h1>
    <p class="mt-2 text-gray-500 dark:text-gray-400">Access your influencer intelligence workspace.</p>

    <div class="mt-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-8">
        <form method="POST" action="{{ url('/login') }}" class="space-y-5">
            @csrf
            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
                    <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">Forgot password?</a>
                </div>
                <div x-data="{ showPassword: false }" class="relative">
                    <input id="password" name="password" :type="showPassword ? 'text' : 'password'" required autocomplete="current-password"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 pe-12 text-sm text-gray-800 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <button type="button" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'" :aria-pressed="showPassword"
                        class="absolute inset-y-0 end-0 flex items-center px-4 text-gray-400 transition hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-brand-500/30 dark:hover:text-brand-400">
                        <svg x-show="!showPassword" class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M2.2 10s2.8-5 7.8-5 7.8 5 7.8 5-2.8 5-7.8 5-7.8-5-7.8-5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.2" stroke="currentColor" stroke-width="1.5"/></svg>
                        <svg x-show="showPassword" class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m3 3 14 14M8.6 5.2A8.3 8.3 0 0 1 10 5c5 0 7.8 5 7.8 5a13 13 0 0 1-2.2 2.8M6.1 6.1C3.6 7.3 2.2 10 2.2 10s2.8 5 7.8 5c.9 0 1.7-.2 2.4-.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M8.6 8.6a2 2 0 0 0 2.8 2.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    </button>
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <input type="checkbox" name="remember" value="1" class="rounded border-gray-300 text-brand-500 focus:ring-brand-500/20">
                Remember me
            </label>
            <button type="submit" class="group flex w-full items-center justify-center gap-2 rounded-xl bg-brand-900 px-4 py-3.5 text-sm font-semibold text-white shadow-theme-md transition duration-200 hover:-translate-y-0.5 hover:bg-brand-800 hover:shadow-theme-lg focus:outline-none focus:ring-4 focus:ring-brand-500/25 active:translate-y-0 dark:bg-brand-700 dark:hover:bg-brand-600">
                <span>Sign in</span>
                <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
        Need a workspace?
        <a href="{{ route('signup') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Create one</a>
    </p>
@endsection

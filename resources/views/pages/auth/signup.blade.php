@extends('layouts.fullscreen-layout')

@section('content')
    <p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Get started</p>
    <h1 class="mt-2 text-3xl font-bold text-gray-800 dark:text-white/90">Create your workspace</h1>
    <p class="mt-2 text-gray-500 dark:text-gray-400">Set up an isolated workspace for your team and campaigns.</p>

    <div class="mt-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-8">
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-700 dark:bg-error-500/15 dark:text-error-400">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('register') }}" class="space-y-5" x-data="{ isSubmitting: false }" @submit="isSubmitting = true">
            @csrf
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="name" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Your name</label>
                    <input id="name" name="name" value="{{ old('name') }}" required autofocus autocomplete="name"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                </div>
                <div>
                    <label for="email" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Email address</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-form.workspace-name name="workspace_name" :value="old('workspace_name', '')" placeholder="Acme Corp Global" />
                </div>
                <div>
                    <label for="workspace_slug" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Workspace URL <span class="font-normal text-gray-400">(optional)</span></label>
                    <input id="workspace_slug" name="workspace_slug" value="{{ old('workspace_slug') }}" placeholder="acme"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="password" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
                    <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <p class="mt-1 text-xs text-gray-400">Use at least 12 characters.</p>
                </div>
                <div>
                    <label for="password_confirmation" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                </div>
            </div>
            <button type="submit" :disabled="isSubmitting" class="group relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-br from-brand-700 via-brand-900 to-brand-950 px-4 py-3.5 text-sm font-semibold text-white shadow-theme-lg transition duration-200 hover:-translate-y-0.5 hover:shadow-theme-xl focus:outline-none focus:ring-4 focus:ring-brand-500/30 active:translate-y-0 disabled:cursor-wait disabled:opacity-80 dark:from-brand-700 dark:via-brand-800 dark:to-brand-950">
                <span class="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/10 to-transparent transition-transform duration-700 group-hover:translate-x-full" aria-hidden="true"></span>
                <svg x-show="isSubmitting" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" opacity=".25"/><path d="M17 10a7 7 0 0 0-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span class="relative" x-text="isSubmitting ? 'Creating workspace...' : 'Create workspace'">Create workspace</span>
                <svg x-show="!isSubmitting" x-cloak class="relative h-4 w-4 transition-transform duration-200 group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
        Already have an account?
        <a href="{{ route('login') }}" data-auth-switch class="font-medium text-brand-600 hover:underline dark:text-brand-400">Sign in</a>
    </p>
@endsection

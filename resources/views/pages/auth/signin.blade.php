@extends('layouts.fullscreen-layout')

@section('content')
    <p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Welcome back</p>
    <h1 class="mt-2 text-3xl font-bold text-gray-800 dark:text-white/90">Sign in to Scrutium</h1>
    <p class="mt-2 text-gray-500 dark:text-gray-400">Access your influencer intelligence workspace.</p>

    <div class="mt-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-8">
        @if (session('success'))
            <div class="mb-4 rounded-lg bg-success-50 px-3 py-2 text-sm text-success-700 dark:bg-success-500/15 dark:text-success-400">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-700 dark:bg-error-500/15 dark:text-error-400">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ url('/login') }}" class="space-y-5">
            @csrf
            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <div>
                <label for="password" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <input type="checkbox" name="remember" value="1" class="rounded border-gray-300 text-brand-500 focus:ring-brand-500/20">
                Remember me
            </label>
            <button type="submit" class="flex w-full items-center justify-center rounded-lg bg-[#0B1B33] px-4 py-3 text-sm font-semibold text-white hover:bg-black">
                Sign in
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
        Need a workspace?
        <a href="{{ route('signup') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Create one</a>
    </p>
@endsection

@extends('layouts.fullscreen-layout')

@section('content')

    <div class="mx-auto max-w-xl">
        <div class="mb-8 text-center"><p class="text-sm font-semibold uppercase tracking-widest text-brand-600 dark:text-brand-400">Scrutium</p><h1 class="mt-2 text-3xl font-bold text-gray-800 dark:text-white/90">Create your workspace</h1><p class="mt-2 text-gray-500 dark:text-gray-400">Set up an isolated workspace for your team and campaigns.</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-8">
            @if($errors->any())<div class="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-700 dark:bg-error-500/15 dark:text-error-400">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('register') }}" class="space-y-5">
                @csrf
                <div class="grid gap-5 sm:grid-cols-2">
                    <div><label for="name" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Your name</label><input id="name" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></div>
                    <div><label for="email" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></div>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div><label for="workspace_name" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Workspace name</label><input id="workspace_name" name="workspace_name" value="{{ old('workspace_name') }}" required placeholder="Northwind Collective" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></div>
                    <div><label for="workspace_slug" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Workspace URL <span class="font-normal text-gray-400">(optional)</span></label><input id="workspace_slug" name="workspace_slug" value="{{ old('workspace_slug') }}" placeholder="northwind" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></div>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div><label for="password" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Password</label><input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"><p class="mt-1 text-xs text-gray-400">Use at least 12 characters.</p></div>
                    <div><label for="password_confirmation" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></div>
                </div>
                <button type="submit" class="flex w-full items-center justify-center rounded-lg bg-brand-500 px-4 py-3 text-sm font-semibold text-white hover:bg-brand-600">Create workspace</button>
            </form>
        </div>
    </div>
@endsection

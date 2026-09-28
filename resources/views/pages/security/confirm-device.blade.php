@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb page-title="Confirm this device" />

    <div class="mx-auto max-w-xl">
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 p-6 dark:border-gray-800">
                <h2 class="text-title-md font-bold text-gray-900 dark:text-white">Was this you?</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Confirming marks this device as trusted and stops future sign-in emails for it.
                </p>
            </div>

            <dl class="divide-y divide-gray-100 text-sm dark:divide-gray-800">
                <div class="flex justify-between gap-4 px-6 py-4">
                    <dt class="text-gray-500 dark:text-gray-400">Device</dt>
                    <dd class="text-end font-medium text-gray-900 dark:text-white">
                        {{ $trusted->device?->getDisplayName() ?? 'Unknown device' }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4 px-6 py-4">
                    <dt class="text-gray-500 dark:text-gray-400">IP address</dt>
                    <dd class="text-end font-mono text-xs text-gray-900 dark:text-white">{{ $trusted->trusted_ip ?? 'Unknown' }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-6 py-4">
                    <dt class="text-gray-500 dark:text-gray-400">Approximate location</dt>
                    <dd class="text-end font-medium text-gray-900 dark:text-white">
                        {{ collect([$trusted->trusted_location_city, $trusted->trusted_location_country])->filter()->join(', ') ?: 'Unknown' }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4 px-6 py-4">
                    <dt class="text-gray-500 dark:text-gray-400">First seen</dt>
                    <dd class="text-end font-medium text-gray-900 dark:text-white">
                        {{ $trusted->created_at->format('j M Y, H:i') }}
                    </dd>
                </div>
            </dl>

            <div class="space-y-3 border-t border-gray-100 p-6 dark:border-gray-800">
                @unless ($trusted->browser || $trusted->os)
                    <p class="rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                        We could not read detailed device information for this sign-in. Only confirm it if you
                        recognise the location and it happened around the time you signed in.
                    </p>
                @endunless

                <form method="POST" action="{{ route('security.devices.confirm', $trusted->confirmation_token) }}">
                    @csrf
                    <button type="submit" class="btn-primary w-full rounded-xl py-3 text-sm font-semibold">
                        Yes, this was me
                    </button>
                </form>

                <form method="POST" action="{{ route('security.devices.revoke', $trusted->device_id) }}">
                    @csrf
                    <button type="submit"
                        class="w-full rounded-xl border border-gray-300 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                        No, this was not me
                    </button>
                </form>

                <p class="text-center text-xs text-gray-500 dark:text-gray-400">
                    If this was not you, end the session, review your
                    <a href="{{ route('security.sessions') }}" class="text-brand-600 hover:underline">active sessions</a>
                    and change your password.
                </p>
            </div>
        </div>
    </div>
@endsection

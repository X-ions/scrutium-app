@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb page-title="Devices" />

    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 p-6 dark:border-gray-800">
                <h2 class="text-title-md font-bold text-gray-900 dark:text-white">Trusted devices</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Sign-ins from these devices never trigger a security email.
                </p>
            </div>

            @if ($trustedDevices->isEmpty())
                <p class="p-6 text-sm text-gray-500 dark:text-gray-400">You have not confirmed any devices yet.</p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($trustedDevices as $device)
                        <li class="flex flex-wrap items-center justify-between gap-4 p-6">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $device->getDisplayName() }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Last used {{ $device->last_seen_at->diffForHumans() }}
                                    @if ($device->first_location_city) &middot; {{ $device->first_location_city }} @endif
                                    &middot; {{ $device->login_count }} sign-in{{ $device->login_count === 1 ? '' : 's' }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('security.devices.revoke', $device) }}">
                                @csrf
                                <button type="submit"
                                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                                    Remove
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($untrustedDevices->isNotEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 p-6 dark:border-gray-800">
                    <h2 class="text-title-md font-bold text-gray-900 dark:text-white">Not yet confirmed</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Recognised but unconfirmed. Confirm the ones you use, remove the rest.
                    </p>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($untrustedDevices as $device)
                        <li class="flex flex-wrap items-center justify-between gap-4 p-6">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $device->getDisplayName() }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    First seen {{ $device->first_seen_at->diffForHumans() }}
                                    @if ($device->first_location_city) &middot; {{ $device->first_location_city }} @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <form method="POST" action="{{ route('security.devices.trust', $device) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">
                                        Trust
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('security.devices.block', $device) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                                        Block
                                    </button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($blockedDevices->isNotEmpty())
            <div class="rounded-2xl border border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/20">
                <div class="border-b border-red-100 p-6 dark:border-red-800">
                    <h2 class="text-title-md font-bold text-red-900 dark:text-red-300">Blocked devices</h2>
                    <p class="mt-1 text-sm text-red-800 dark:text-red-300/90">Sign-ins from these devices are signed out immediately.</p>
                </div>
                <ul class="divide-y divide-red-100 dark:divide-red-800">
                    @foreach ($blockedDevices as $device)
                        <li class="flex flex-wrap items-center justify-between gap-4 p-6">
                            <div>
                                <p class="text-sm font-semibold text-red-900 dark:text-red-300">{{ $device->getDisplayName() }}</p>
                                <p class="mt-1 text-xs text-red-700 dark:text-red-300/80">Blocked {{ $device->blocked_at?->diffForHumans() }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endsection

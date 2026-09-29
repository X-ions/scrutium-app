@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Connected accounts</h3>
            @if (empty($accounts))
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No accounts are connected.</p>
            @else
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            <th class="py-2 text-start font-medium">Account</th>
                            <th class="py-2 text-start font-medium">Status</th>
                            <th class="py-2 text-end font-medium">Last sync</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($accounts as $account)
                            <tr>
                                <td class="py-2">
                                    <div class="flex items-center gap-2">
                                        <x-socialhub.platform-chip :provider="$account['provider']" />
                                        <span class="text-gray-700 dark:text-gray-200">{{ $account['label'] }}</span>
                                    </div>
                                </td>
                                <td class="py-2 text-gray-600 dark:text-gray-300">{{ $account['status'] }}</td>
                                <td class="py-2 text-end text-gray-600 dark:text-gray-300">{{ $account['last_synced_at'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <x-socialhub.line-chart :series="$followerGrowth" label="Follower growth" />

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Metric availability by network</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                A network that does not expose a metric is never estimated or filled with zero.
            </p>
            <ul class="mt-3 space-y-1.5 text-sm">
                @foreach (($availability['platforms'] ?? []) as $platform => $metrics)
                    <li class="flex flex-wrap items-center gap-2">
                        <x-socialhub.platform-chip :provider="$platform" />
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ collect($metrics)->filter(fn ($available) => $available)->keys()->implode(', ') ?: 'no metrics reported' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection

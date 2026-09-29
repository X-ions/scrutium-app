@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="space-y-6">
        <form method="GET" action="{{ route('socialhub.dashboard') }}"
            class="flex flex-wrap items-end gap-3 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div>
                <label for="from" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">From</label>
                <input type="date" id="from" name="from" value="{{ $filters['from'] }}"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
            </div>
            <div>
                <label for="to" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">To</label>
                <input type="date" id="to" name="to" value="{{ $filters['to'] }}"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
            </div>
            <div>
                <label for="platform" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Network</label>
                <select id="platform" name="platform[]" multiple size="3"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                    @foreach ($platforms as $platform)
                        <option value="{{ $platform->value }}" @selected(in_array($platform->value, $filters['platform'], true))>
                            {{ $platform->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="account" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Account</label>
                <select id="account" name="account[]" multiple size="3"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                    @foreach ($accounts as $account)
                        <option value="{{ $account['id'] }}" @selected(in_array($account['id'], $filters['account'], true))>
                            {{ $account['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit"
                class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500">
                Apply
            </button>
            <a href="{{ route('socialhub.dashboard') }}" class="px-2 py-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
        </form>

        @if ($needsAttention !== [])
            <div class="rounded-2xl border border-warning-200 bg-warning-50/50 p-4 dark:border-warning-500/30 dark:bg-warning-500/5">
                <h2 class="text-sm font-semibold text-warning-800 dark:text-warning-400">Accounts need attention</h2>
                <ul class="mt-2 space-y-1 text-sm text-warning-900 dark:text-warning-200">
                    @foreach ($needsAttention as $account)
                        <li>
                            <span class="font-medium">{{ $account['label'] ?? $account['platform'] }}</span>
                            — {{ $account['status_label'] }}
                            @if ($account['error'])
                                <span class="text-warning-700 dark:text-warning-300">{{ $account['error'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('socialhub.accounts.index') }}" class="mt-3 inline-block text-sm font-medium text-warning-800 underline dark:text-warning-300">Manage accounts</a>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-socialhub.stat-card label="Total reach" :value="$headline['reach'] ?? 0" hint="Reported by the selected networks" />
            <x-socialhub.stat-card label="Engagement" :value="$headline['engagement'] ?? 0" :denominator="$headline['engagement_denominator_label'] ?? null" />
            <x-socialhub.stat-card label="Views" :value="$headline['views'] ?? 0" />
            <x-socialhub.stat-card label="Followers" :value="$headline['followers'] ?? 0" :trend="$headline['follower_growth_percent'] ?? null" />
            <x-socialhub.stat-card label="Scheduled" :value="$counters['scheduled']" tone="brand" />
            <x-socialhub.stat-card label="Failed" :value="$counters['failed']" :tone="$counters['failed'] > 0 ? 'error' : 'default'" />
        </div>

        @if (! empty($headline['comparability']['warnings'] ?? []))
            <div class="rounded-2xl border border-gray-200 bg-gray-50/60 p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-300">
                <p class="font-medium text-gray-800 dark:text-white/90">About these totals</p>
                <ul class="mt-1 list-disc space-y-0.5 ps-5">
                    @foreach ($headline['comparability']['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-2">
            <x-socialhub.line-chart :series="$viewsSeries" label="Views over time" />
            <x-socialhub.line-chart :series="$engagementSeries" label="Engagement over time" />
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-2">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Upcoming</h3>
                @if ($upcoming === [])
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Nothing scheduled.</p>
                @else
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($upcoming as $item)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <div class="min-w-0">
                                    <a href="{{ route('socialhub.posts.show', $item['post_id']) }}"
                                        class="block truncate text-sm font-medium text-gray-800 hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-300">
                                        {{ $item['title'] }}
                                    </a>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $item['scheduled_human'] }}</p>
                                </div>
                                <x-socialhub.platform-chip :provider="$item['provider']" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Needs a reply</h3>
                <p class="mt-2 text-3xl font-semibold tracking-tight text-gray-800 dark:text-white/90">{{ $unhandledComments }}</p>
                <a href="{{ route('socialhub.comments.index', ['replies' => 'unhandled']) }}"
                    class="mt-3 inline-block text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-300">Open the inbox</a>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Recent posts</h3>
                @if ($recent === [])
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Nothing published yet.</p>
                @else
                    <ul class="mt-3 space-y-3">
                        @foreach ($recent as $item)
                            <li class="flex items-start justify-between gap-3">
                                <a href="{{ route('socialhub.posts.show', $item['id']) }}"
                                    class="min-w-0 text-sm font-medium text-gray-800 hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-300">
                                    {{ $item['title'] }}
                                </a>
                                <div class="flex shrink-0 flex-wrap justify-end gap-1">
                                    @foreach ($item['networks'] as $network)
                                        <x-socialhub.platform-chip :provider="$network['provider']" />
                                    @endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Quick actions</h3>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ route('socialhub.posts.create') }}"
                        class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">New post</a>
                    <a href="{{ route('socialhub.calendar') }}"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Calendar</a>
                    <a href="{{ route('socialhub.media.index') }}"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Media</a>
                    <a href="{{ route('socialhub.accounts.index') }}"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Social accounts</a>
                </div>
            </div>
        </div>
    </div>
@endsection

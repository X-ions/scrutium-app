@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @include('pages.socialhub.analytics.partials.filters', ['filters' => $filters, 'platforms' => $platforms, 'accounts' => $accounts])

    <div class="space-y-6">
        @if (! empty($headline['comparability']['warnings'] ?? []))
            <div class="rounded-2xl border border-gray-200 bg-gray-50/60 p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-300">
                <p class="font-medium text-gray-800 dark:text-white/90">Metrics are not directly comparable</p>
                <ul class="mt-1 list-disc space-y-0.5 ps-5">
                    @foreach ($headline['comparability']['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-socialhub.stat-card label="Reach" :value="$headline['reach'] ?? 0" hint="Only networks reporting reach" />
            <x-socialhub.stat-card label="Engagement" :value="$headline['engagement'] ?? 0" :denominator="$headline['engagement_denominator_label'] ?? null" />
            <x-socialhub.stat-card label="Views" :value="$headline['views'] ?? 0" />
            <x-socialhub.stat-card label="Followers" :value="$headline['followers'] ?? 0" :trend="$headline['follower_growth_percent'] ?? null" />
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <x-socialhub.line-chart :series="$viewsSeries" label="Views over time" />
            <x-socialhub.line-chart :series="$engagementSeries" label="Engagement over time" />
            <x-socialhub.line-chart :series="$followerGrowth" label="Follower growth" />
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">By network</h3>
                @if (empty($platformComparison))
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No comparable data for this selection.</p>
                @else
                    <table class="mt-3 w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                <th class="py-2 text-start font-medium">Network</th>
                                <th class="py-2 text-end font-medium">Engagement</th>
                                <th class="py-2 text-end font-medium">Reach</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($platformComparison as $row)
                                <tr>
                                    <td class="py-2"><x-socialhub.platform-chip :provider="$row['platform'] ?? ($row['provider'] ?? null)" /></td>
                                    <td class="py-2 text-end text-gray-700 dark:text-gray-200">{{ number_format((float) ($row['engagement'] ?? 0)) }}</td>
                                    <td class="py-2 text-end text-gray-700 dark:text-gray-200">{{ number_format((float) ($row['reach'] ?? 0)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Metric availability</h3>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    A metric is only totalled from the networks that actually report it.
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

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Top performing posts</h3>
            @if (empty($topPosts))
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No engagement reported in this period.</p>
            @else
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($topPosts as $row)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <a href="{{ route('socialhub.posts.show', $row['post_id'] ?? ($row['id'] ?? 0)) }}"
                                class="min-w-0 truncate text-sm font-medium text-gray-800 hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-300">
                                {{ $row['title'] ?? 'Post' }}
                            </a>
                            <span class="shrink-0 text-sm text-gray-600 dark:text-gray-300">
                                {{ number_format((float) ($row['engagement'] ?? ($row['value'] ?? 0))) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="space-y-6">
        @include('pages.socialhub.analytics.partials.filters', ['filters' => $filters, 'platforms' => $platforms, 'accounts' => $accounts])

        <div class="grid gap-4 xl:grid-cols-2">
            <x-socialhub.line-chart :series="$viewsSeries" label="Views per day" />
            <x-socialhub.line-chart :series="$postingFrequency" label="Posts published" />
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Top posts by engagement</h3>
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

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Performance by content type</h3>
            @if (empty($contentTypes))
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Not enough data yet.</p>
            @else
                <ul class="mt-3 space-y-2">
                    @foreach ($contentTypes as $row)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="text-gray-700 dark:text-gray-200">{{ $row['content_type'] ?? $row['label'] ?? 'Unknown' }}</span>
                            <span class="text-gray-600 dark:text-gray-300">{{ number_format((float) ($row['engagement'] ?? ($row['value'] ?? 0))) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection

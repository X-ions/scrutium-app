@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <p class="mb-4 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
        These are the operations each network's official API supports. Anything marked unavailable is
        genuinely unsupported — the app hides the control rather than failing after you have written the content.
    </p>

    <div class="space-y-4">
        @foreach ($providers as $descriptor)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $descriptor->name }}</h3>
                    <div class="flex items-center gap-2">
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $descriptor->implemented,
                            'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => ! $descriptor->implemented,
                        ])>{{ $descriptor->implemented ? 'Integrated' : 'Not yet integrated' }}</span>
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $descriptor->configured,
                            'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => ! $descriptor->configured,
                        ])>{{ $descriptor->configured ? 'Configured' : 'Not configured' }}</span>
                    </div>
                </div>

                @if (! $descriptor->implemented)
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        {{ $descriptor->notConfiguredReason ?: 'The provider class has not been written yet. It is registered so the app can report the gap honestly rather than pretending to support it.' }}
                    </p>
                @endif

                @if ($descriptor->requiresAppReview)
                    <p class="mt-2 text-sm text-warning-700 dark:text-warning-400">
                        Publishing requires platform app review/verification. Until that is granted the account can
                        be connected but write operations will be refused by the platform.
                    </p>
                @endif

                <ul class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($descriptor->capabilities->toArray() as $flag => $available)
                        @continue(! is_bool($available))
                        <li @class([
                            'rounded px-2 py-0.5 text-[11px] font-medium',
                            'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' => $available,
                            'bg-gray-100 text-gray-400 line-through dark:bg-white/5 dark:text-gray-600' => ! $available,
                        ])>{{ str_replace(['Publishing', ' '], '', ucwords(str_replace('_', ' ', (string) $flag))) }}</li>
                    @endforeach
                </ul>

                @if ($descriptor->docsUrl)
                    <a href="{{ $descriptor->docsUrl }}" target="_blank" rel="noopener noreferrer"
                        class="mt-3 inline-block text-xs font-medium text-brand-600 dark:text-brand-300">Official documentation</a>
                @endif
            </div>
        @endforeach
    </div>
@endsection

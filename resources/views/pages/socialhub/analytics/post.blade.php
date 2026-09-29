@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="space-y-6">
        <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($post->variants as $variant)
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between gap-2">
                        <x-socialhub.platform-chip :provider="$variant->provider" size="md" />
                        <x-socialhub.status-badge :status="$variant->statusEnum()" />
                    </div>

                    <dl class="mt-3 space-y-1 text-sm">
                        @foreach ($variant->metrics as $metric)
                            <div class="flex justify-between gap-2">
                                <dt class="text-gray-500 dark:text-gray-400">{{ $metric->metric_type?->label() ?? $metric->metric_type }}</dt>
                                <dd class="font-medium text-gray-800 dark:text-white/90">{{ number_format((float) $metric->value) }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($variant->metrics->isEmpty())
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                            This network has not reported metrics for this post yet.
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        <x-socialhub.line-chart :series="$series" label="Engagement over time" />
    </div>
@endsection

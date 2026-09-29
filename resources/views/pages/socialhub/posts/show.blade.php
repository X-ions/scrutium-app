@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $post->title ?: 'Untitled post' }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        By {{ $post->author?->name ?? 'unknown' }} · {{ $post->created_at->format('j M Y, H:i') }}
                    </p>
                    @if ($post->notes)
                        <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ $post->notes }}</p>
                    @endif
                </div>
                <x-socialhub.status-badge :status="$post->statusEnum()" />
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @can('update', $post)
                    <a href="{{ route('socialhub.posts.edit', $post) }}"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Edit</a>
                @endcan

                @can('publish', $post)
                    <form method="POST" action="{{ route('socialhub.posts.publish', $post) }}">
                        @csrf
                        <button class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Publish now</button>
                    </form>
                @endcan

                @can('schedule', $post)
                    <form method="POST" action="{{ route('socialhub.posts.cancel', $post) }}">
                        @csrf
                        <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Cancel scheduled</button>
                    </form>
                @endcan

                @can('duplicate', $post)
                    <form method="POST" action="{{ route('socialhub.posts.duplicate', $post) }}">
                        @csrf
                        <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Duplicate</button>
                    </form>
                @endcan

                @can('delete', $post)
                    <form method="POST" action="{{ route('socialhub.posts.destroy', $post) }}"
                        onsubmit="return confirm('Delete this post?')">
                        @csrf
                        @method('DELETE')
                        <button class="rounded-lg border border-error-200 px-3 py-2 text-sm font-medium text-error-600 hover:bg-error-50 dark:border-error-500/30">Delete</button>
                    </form>
                @endcan
            </div>
        </div>

        <div>
            <h3 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">Per-network status</h3>
            @if ($post->variants->isEmpty())
                <x-socialhub.empty-state title="No networks selected" description="Edit this post to choose which networks it targets." />
            @else
                <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                    @foreach ($post->variants as $variant)
                        <x-socialhub.variant-card :variant="$variant" :canRetry="auth()->user()->can('retryVariant', $post)" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection

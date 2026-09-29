@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Search posts"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
            <select name="status" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                <option value="">All statuses</option>
                @foreach ($statuses as $value => $caption)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $caption }}</option>
                @endforeach
            </select>
            <button class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Filter</button>
        </form>

        <a href="{{ route('socialhub.posts.create') }}"
            class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-700">New post</a>
    </div>

    @if ($posts->isEmpty())
        <x-socialhub.empty-state
            title="No posts yet"
            description="Compose once and send to every connected network. Each network is tracked separately, so one failure never blocks the others."
            actionLabel="Compose your first post"
            actionUrl="{{ route('socialhub.posts.create') }}" />
    @else
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-start text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3 text-start font-medium">Post</th>
                            <th class="px-4 py-3 text-start font-medium">Networks</th>
                            <th class="px-4 py-3 text-start font-medium">Status</th>
                            <th class="px-4 py-3 text-start font-medium">Author</th>
                            <th class="px-4 py-3 text-end font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($posts as $post)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('socialhub.posts.show', $post) }}"
                                        class="font-medium text-gray-800 hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-300">
                                        {{ $post->title ?: 'Untitled post' }}
                                    </a>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $post->created_at->diffForHumans() }}
                                        @if ($post->published_at)
                                            · published {{ $post->published_at->diffForHumans() }}
                                        @endif
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($post->variants as $variant)
                                            <x-socialhub.platform-chip :provider="$variant->provider" />
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <x-socialhub.status-badge :status="$post->statusEnum()" />
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $post->author?->name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <a href="{{ route('socialhub.posts.show', $post) }}"
                                            class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Open</a>
                                        <form method="POST" action="{{ route('socialhub.posts.duplicate', $post) }}">
                                            @csrf
                                            <button class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Duplicate</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">{{ $posts->links() }}</div>
    @endif
@endsection

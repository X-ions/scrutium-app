@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="space-y-6">
        <form method="GET" class="flex flex-wrap items-end gap-2 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div>
                <label for="search" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Search</label>
                <input type="search" id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Comment or author"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
            </div>
            <div>
                <label for="replies" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Replies</label>
                <select id="replies" name="replies" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
                    <option value="">All</option>
                    <option value="unhandled" @selected(($filters['replies'] ?? '') === 'unhandled')>No reply yet</option>
                    <option value="mine" @selected(($filters['replies'] ?? '') === 'mine')>Replied</option>
                </select>
            </div>
            <button class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Filter</button>
            <a href="{{ route('socialhub.comments.index') }}" class="px-2 py-2 text-sm text-gray-500 dark:text-gray-400">Reset</a>
        </form>

        @can('sync', App\Models\Comment::class)
            <div class="flex justify-end">
                <form method="POST" action="{{ route('socialhub.comments.sync') }}">
                    @csrf
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Sync all accounts</button>
                </form>
            </div>
        @endcan

        @if ($breakdown !== [])
            <div class="flex flex-wrap gap-2">
                @foreach ($breakdown as $platform => $stats)
                    @php $query = array_merge(request()->query(), ['platform' => [$platform]]); @endphp
                    <a href="{{ route('socialhub.comments.index', $query) }}"
                        @class([
                            'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium',
                            'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-300' => $stats['degraded'],
                            'border-gray-200 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-white/5 dark:text-gray-200' => ! $stats['degraded'],
                        ])
                        @if ($stats['degraded']) title="{{ $stats['last_error'] }}" @endif>
                        <x-socialhub.platform-chip :provider="$platform" />
                        <span class="text-gray-500 dark:text-gray-400">{{ $stats['comments'] }}</span>
                        @if ($stats['degraded'])
                            <span class="text-warning-700 dark:text-warning-400">degraded</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif

        @if ($comments->isEmpty())
            <x-socialhub.empty-state
                title="No comments to handle"
                description="Comments appear here once a connected network is synced. Networks without a comments API simply contribute nothing."
                icon="chat" />
        @else
            <ul class="space-y-3">
                @foreach ($comments as $comment)
                    <li class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $comment->authorDisplayLabel() }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $comment->provider_created_at?->diffForHumans() }}
                                    on
                                    <a href="{{ route('socialhub.posts.show', $comment->postVariant?->post_id) }}" class="hover:underline">
                                        {{ $comment->postVariant?->post?->title ?: 'your post' }}
                                    </a>
                                </p>
                            </div>
                            <x-socialhub.platform-chip :provider="$comment->provider" />
                        </div>

                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $comment->content }}</p>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <a href="{{ route('socialhub.comments.show', $comment) }}"
                                class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">
                                {{ $inbox->replyState($comment)['sent'] > 0 ? 'View' : 'Open' }}
                            </a>
                            @if ($comment->like_count > 0)
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $comment->like_count }} likes</span>
                            @endif
                            @if ($comment->reply_count > 0)
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $comment->reply_count }} replies</span>
                            @endif
                            @if ($counters['unreplied'] > 0)
                                <span class="ms-auto text-xs text-gray-500 dark:text-gray-400">{{ $counters['unreplied'] }} without a reply</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">{{ $comments->links() }}</div>
        @endif
    </div>
@endsection

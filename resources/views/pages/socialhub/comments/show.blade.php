@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <article class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <header class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        @if ($comment->author_avatar_url)
                            <img src="{{ $comment->author_avatar_url }}" alt="" class="h-10 w-10 rounded-full object-cover">
                        @else
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-gray-100 text-sm font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                {{ mb_substr($comment->authorDisplayLabel(), 0, 1) }}
                            </span>
                        @endif
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $comment->authorDisplayLabel() }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $comment->provider_created_at?->diffForHumans() }}
                                on
                                <a href="{{ route('socialhub.posts.show', $comment->postVariant?->post_id) }}" class="hover:underline">
                                    {{ $comment->postVariant?->post?->title ?: 'your post' }}
                                </a>
                            </p>
                        </div>
                    </div>
                    <x-socialhub.platform-chip :provider="$comment->provider" />
                </header>

                <p class="mt-4 whitespace-pre-line text-sm leading-relaxed text-gray-700 dark:text-gray-200">{{ $comment->content }}</p>

                @if ($comment->provider_post_url ?? $comment->postVariant?->provider_post_url)
                    <a href="{{ $comment->postVariant?->provider_post_url }}" target="_blank" rel="noopener noreferrer"
                        class="mt-3 inline-block text-xs font-medium text-brand-600 dark:text-brand-300">Open original post</a>
                @endif
            </article>

            @if ($comment->thread->isNotEmpty())
                <div class="mt-4 space-y-3">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Thread</h3>
                    @foreach ($comment->thread as $child)
                        <div class="ms-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $child->authorDisplayLabel() }}</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $child->content }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($comment->replies->isNotEmpty())
                <div class="mt-4">
                    <h3 class="mb-2 text-sm font-semibold text-gray-800 dark:text-white/90">Your replies</h3>
                    <ul class="space-y-2">
                        @foreach ($comment->replies as $reply)
                            <li class="rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-800 dark:bg-white/[0.03]">
                                <p class="text-gray-700 dark:text-gray-200">{{ $reply->content }}</p>
                                <p @class([
                                    'mt-1 text-xs',
                                    'text-success-600 dark:text-success-500' => $reply->status === 'sent',
                                    'text-error-600 dark:text-error-500' => $reply->status === 'failed',
                                    'text-gray-500 dark:text-gray-400' => $reply->status === 'pending',
                                ])>{{ ucfirst((string) $reply->status) }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Reply</h3>

                @if ($canReply)
                    <form method="POST" action="{{ route('socialhub.comments.reply', $comment) }}" class="mt-3 space-y-3">
                        @csrf
                        <label for="body" class="sr-only">Reply</label>
                        <textarea id="body" name="body" rows="4" required
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90"
                            placeholder="Write a reply…">{{ old('body') }}</textarea>
                        @error('body') <p class="text-xs text-error-600 dark:text-error-400">{{ $message }}</p> @enderror
                        <button class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Send reply</button>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Replies are sent in the background, so the network may take a moment.</p>
                    </form>
                @else
                    <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        {{ $replyUnavailableReason }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="mb-4 flex justify-end">
        <form method="POST" action="{{ route('socialhub.notifications.read-all') }}">
            @csrf
            <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">Mark all read</button>
        </form>
    </div>

    @if ($notifications->isEmpty())
        <x-socialhub.empty-state title="No notifications" description="Publishing failures, expiring authorizations and new comments appear here." icon="email" />
    @else
        <ul class="space-y-2">
            @foreach ($notifications as $notification)
                <li @class([
                    'rounded-xl border p-4',
                    'border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]' => $notification->is_read,
                    'border-brand-200 bg-brand-50/50 dark:border-brand-500/30 dark:bg-brand-500/5' => ! $notification->is_read,
                ])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $notification->title }}</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $notification->message }}</p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $notification->created_at->diffForHumans() }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if (in_array($notification->priority, ['high', 'critical'], true))
                                <span class="rounded-full bg-error-50 px-2 py-0.5 text-[11px] font-medium text-error-600 dark:bg-error-500/15 dark:text-error-500">
                                    {{ ucfirst($notification->priority) }}
                                </span>
                            @endif
                            <form method="POST" action="{{ route('socialhub.notifications.read', $notification) }}">
                                @csrf
                                <button class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/5">
                                    {{ $notification->action_url ? 'Open' : 'Mark read' }}
                                </button>
                            </form>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $notifications->links() }}</div>
    @endif
@endsection

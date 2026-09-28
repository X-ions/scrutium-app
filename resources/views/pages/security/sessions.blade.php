@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb page-title="Active sessions" />

    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 p-6 dark:border-gray-800">
                <div>
                    <h2 class="text-title-md font-bold text-gray-900 dark:text-white">Active sessions</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        End anything you do not recognise. Ending a session signs that device out immediately.
                    </p>
                </div>
                @if ($sessions->count() > 1)
                    <form method="POST" action="{{ route('security.sessions.revoke-others') }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                            End all other sessions
                        </button>
                    </form>
                @endif
            </div>

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($sessions as $session)
                    <li class="flex flex-wrap items-center justify-between gap-4 p-6">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ $session->getDisplayName() }}
                                @if ($session->session_id === $currentSessionId)
                                    <span class="ms-2 rounded-full bg-green-50 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-900/20 dark:text-green-400">This device</span>
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $session->ip_address }}
                                @if ($session->location_country) &middot; {{ $session->location_country }} @endif
                                &middot; started {{ $session->started_at->diffForHumans() }}
                            </p>
                        </div>

                        @if ($session->session_id !== $currentSessionId)
                            <form method="POST" action="{{ route('security.sessions.revoke', $session) }}">
                                @csrf
                                <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                                    End session
                                </button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection

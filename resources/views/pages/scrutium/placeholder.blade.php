@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$pageTitle ?? $title" />
    <div class="rounded-2xl border border-gray-200 bg-white px-5 py-8 dark:border-gray-800 dark:bg-white/[0.03] md:px-8">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-theme-sm text-gray-500 dark:text-gray-400">Scrutium workspace</p>
                <h3 class="mt-1 text-title-sm font-semibold text-gray-800 dark:text-white/90">{{ $pageTitle ?? $title }}</h3>
            </div>
            <a href="/campaigns" class="inline-flex items-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
                New campaign
            </a>
        </div>
        <p class="max-w-2xl text-sm text-gray-500 dark:text-gray-400">
            This module is wired into navigation and ready for product data. Dummy records below keep the layout usable until models are connected.
        </p>
        <div class="mt-8 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-white/[0.03]">
                    <tr>
                        <th class="px-4 py-3 text-start text-theme-xs font-medium text-gray-500">Name</th>
                        <th class="px-4 py-3 text-start text-theme-xs font-medium text-gray-500">Owner</th>
                        <th class="px-4 py-3 text-start text-theme-xs font-medium text-gray-500">Status</th>
                        <th class="px-4 py-3 text-start text-theme-xs font-medium text-gray-500">Updated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-800 dark:text-white/90">Spring launch roster</td>
                        <td class="px-4 py-3 text-sm text-gray-500">A. Rivera</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-success-50 px-2.5 py-0.5 text-theme-xs font-medium text-success-600 dark:bg-success-500/15 dark:text-success-500">On track</span></td>
                        <td class="px-4 py-3 text-sm text-gray-500">Today</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-800 dark:text-white/90">Q3 always-on creators</td>
                        <td class="px-4 py-3 text-sm text-gray-500">M. Chen</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-warning-50 px-2.5 py-0.5 text-theme-xs font-medium text-warning-600 dark:bg-warning-500/15 dark:text-warning-500">Needs review</span></td>
                        <td class="px-4 py-3 text-sm text-gray-500">Yesterday</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-800 dark:text-white/90">Holiday seeding wave</td>
                        <td class="px-4 py-3 text-sm text-gray-500">J. Patel</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-blue-light-50 px-2.5 py-0.5 text-theme-xs font-medium text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400">Draft</span></td>
                        <td class="px-4 py-3 text-sm text-gray-500">3 days ago</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection

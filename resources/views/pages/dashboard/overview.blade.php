@extends('layouts.app')

@section('content')
  <div class="mb-6">
    <p class="text-theme-sm text-gray-500 dark:text-gray-400">Workspace overview</p>
    <h2 class="text-title-sm font-semibold text-gray-800 dark:text-white/90">Campaign operations</h2>
  </div>

  <div class="grid grid-cols-12 gap-4 md:gap-6">
    <div class="col-span-12 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
      @foreach ([
        ['label' => 'Active campaigns', 'value' => '18', 'delta' => '+3', 'ok' => true],
        ['label' => 'Creators in roster', 'value' => '246', 'delta' => '+12', 'ok' => true],
        ['label' => 'Deliverables due', 'value' => '41', 'delta' => '8 late', 'ok' => false],
        ['label' => 'Integrity score', 'value' => '94%', 'delta' => '+2.1%', 'ok' => true],
      ] as $metric)
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
          <span class="text-sm text-gray-500 dark:text-gray-400">{{ $metric['label'] }}</span>
          <div class="mt-3 flex items-end justify-between">
            <h4 class="font-bold text-gray-800 text-title-sm dark:text-white/90">{{ $metric['value'] }}</h4>
            <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $metric['ok'] ? 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' : 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500' }}">
              {{ $metric['delta'] }}
            </span>
          </div>
        </div>
      @endforeach
    </div>

    <div class="col-span-12 xl:col-span-8">
      <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <h3 class="mb-5 font-semibold text-gray-800 dark:text-white/90">Data lifecycle</h3>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          @foreach ([
            ['step' => 'Brief', 'count' => 6, 'tone' => 'bg-brand-50 text-brand-700'],
            ['step' => 'Casting', 'count' => 9, 'tone' => 'bg-blue-light-50 text-blue-light-700'],
            ['step' => 'Live', 'count' => 18, 'tone' => 'bg-success-50 text-success-700'],
            ['step' => 'Reconcile', 'count' => 4, 'tone' => 'bg-warning-50 text-warning-700'],
          ] as $stage)
            <div class="rounded-xl border border-gray-100 p-4 dark:border-gray-800">
              <p class="text-theme-xs uppercase tracking-wide text-gray-400">{{ $stage['step'] }}</p>
              <p class="mt-2 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $stage['count'] }}</p>
              <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-theme-xs font-medium {{ $stage['tone'] }}">campaigns</span>
            </div>
          @endforeach
        </div>
      </div>
    </div>

    <div class="col-span-12 xl:col-span-4">
      <div class="h-full rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <h3 class="mb-4 font-semibold text-gray-800 dark:text-white/90">Attainment</h3>
        <p class="text-theme-sm text-gray-500 dark:text-gray-400">Deliverable completion vs contracted volume this month.</p>
        <div class="mt-6">
          <div class="mb-2 flex items-center justify-between text-theme-sm">
            <span class="text-gray-500">Posted</span>
            <span class="font-medium text-gray-800 dark:text-white/90">78%</span>
          </div>
          <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
            <div class="h-full w-[78%] rounded-full bg-brand-500"></div>
          </div>
          <div class="mt-5 mb-2 flex items-center justify-between text-theme-sm">
            <span class="text-gray-500">Approved</span>
            <span class="font-medium text-gray-800 dark:text-white/90">64%</span>
          </div>
          <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
            <div class="h-full w-[64%] rounded-full bg-success-500"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-span-12">
      <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <div class="mb-5 flex items-center justify-between">
          <h3 class="font-semibold text-gray-800 dark:text-white/90">System integrity</h3>
          <span class="rounded-full bg-success-50 px-2.5 py-0.5 text-theme-xs font-medium text-success-600 dark:bg-success-500/15 dark:text-success-500">Healthy</span>
        </div>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
            <p class="text-theme-xs text-gray-500">Missing captions</p>
            <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">2 posts</p>
          </div>
          <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
            <p class="text-theme-xs text-gray-500">Disclosure flags</p>
            <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">0 open</p>
          </div>
          <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
            <p class="text-theme-xs text-gray-500">Sync lag</p>
            <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">4 min</p>
          </div>
        </div>
      </div>
    </div>
  </div>
@endsection

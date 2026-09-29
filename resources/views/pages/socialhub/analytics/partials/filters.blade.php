<form method="GET" action="{{ route('socialhub.analytics.index') }}"
    class="flex flex-wrap items-end gap-3 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
    <div>
        <label for="from" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">From</label>
        <input type="date" id="from" name="from" value="{{ $filters['from'] }}"
            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
    </div>
    <div>
        <label for="to" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">To</label>
        <input type="date" id="to" name="to" value="{{ $filters['to'] }}"
            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
    </div>
    <div>
        <label for="platform" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Network</label>
        <select id="platform" name="platform[]" multiple size="3"
            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
            @foreach ($platforms as $platform)
                <option value="{{ $platform->value }}" @selected(in_array($platform->value, $filters['platform'], true))>{{ $platform->label() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="account" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Account</label>
        <select id="account" name="account[]" multiple size="3"
            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/5 dark:text-white/90">
            @foreach ($accounts as $account)
                <option value="{{ $account['id'] }}" @selected(in_array($account['id'], $filters['account'], true))>{{ $account['label'] }}</option>
            @endforeach
        </select>
    </div>
    <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Apply</button>
    <a href="{{ route('socialhub.analytics.index') }}" class="px-2 py-2 text-sm text-gray-500 dark:text-gray-400">Reset</a>
</form>

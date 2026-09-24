@if(session('success'))
    <div class="mb-5 rounded-xl border border-success-500 bg-success-50 px-4 py-3 text-sm text-success-700 dark:border-success-500/30 dark:bg-success-500/15 dark:text-success-400" role="status">
        {{ session('success') }}
    </div>
@endif

@if($errors->any())
    <div class="mb-5 rounded-xl border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-700 dark:border-error-500/30 dark:bg-error-500/15 dark:text-error-400" role="alert">
        <p class="font-medium">Please correct the following:</p>
        <ul class="mt-1 list-inside list-disc">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
@props(['user' => null])

@if ($user && ! $user->hasVerifiedEmail())
    {{-- Non-dismissible gate. There is no close button, no escape handler and
         no state to flip, so the only way past it is verifying the address.
         It re-renders on every request while the address is unconfirmed. --}}
    <div x-data="document.documentElement.style.overflow = 'hidden'; () => { document.documentElement.style.overflow = '' }"
        x-cloak
        class="fixed inset-0 z-999999 flex items-center justify-center p-4 bg-gray-900/70 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="verify-modal-title"
        aria-describedby="verify-modal-desc">

        <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white shadow-theme-xl dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-6 py-5 dark:border-gray-800">
                <div class="flex items-start gap-4">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-500/15">
                        <svg class="size-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                        </svg>
                    </span>
                    <div>
                        <h2 id="verify-modal-title" class="text-title-sm font-bold text-gray-900 dark:text-white">
                            Verify your email to continue
                        </h2>
                        <p id="verify-modal-desc" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Hi {{ $user->name }}, we need to confirm this email address belongs to you.
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-5 px-6 py-6">
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Verification link sent to
                    </p>
                    <p class="mt-1 break-all text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $user->email }}
                    </p>
                </div>

                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Open the link we emailed you to activate your account. The link expires in 24 hours.
                    Until then your workspace is read-only.
                </p>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Nothing arrived? Check your spam folder, then resend below. If you no longer have access to this
                    address, sign out and register again with one you can open.
                </p>

                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <button type="submit" class="btn-primary w-full rounded-xl py-3 text-sm font-semibold">
                        Resend verification email
                    </button>
                </form>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full rounded-xl py-2.5 text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        Sign out
                    </button>
                </form>
            </div>
        </div>
    </div>
@endif

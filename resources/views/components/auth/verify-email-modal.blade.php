@props(['user' => null])

@if ($user && !$user->hasVerifiedEmail())
<div x-data="{ open: true }" x-show="open" x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
    @keydown.escape.window="open = false"
    role="dialog"
    aria-modal="true"
    aria-labelledby="verify-modal-title"
    aria-describedby="verify-modal-desc">
    
    <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] shadow-xl overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-800">
            <h2 id="verify-modal-title" class="text-title-md font-bold text-gray-900 dark:text-white">Verify your email</h2>
        </div>
        
        <div class="px-6 py-6 space-y-4">
            <p id="verify-modal-desc" class="text-sm text-gray-600 dark:text-gray-300">
                Welcome to Scrutium, <strong class="text-gray-900 dark:text-white">{{ $user->name }}</strong>!
                To access your workspace, you need to verify your email address.
            </p>
            
            <p class="text-sm text-gray-500 dark:text-gray-400">
                We've sent a verification link to <strong class="text-gray-900 dark:text-white">{{ $user->email }}</strong>.
                Please check your inbox (and spam folder) and click the link to continue.
            </p>
            
            <div class="rounded-lg bg-amber-50 p-4 border border-amber-200 dark:bg-amber-900/20 dark:border-amber-800">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 mt-0.5 text-amber-600 dark:text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <p class="text-sm text-amber-800 dark:text-amber-200">
                        This step is required for security. You cannot skip email verification.
                    </p>
                </div>
            </div>
            
            <form method="POST" action="{{ route('verification.send') }}" class="space-y-3">
                @csrf
                <button type="submit"
                    class="w-full btn-primary py-3 text-sm font-semibold rounded-xl transition-colors"
                    :disabled="sending"
                    x-ref="submitBtn">
                    <span x-show="!sending">Resend verification email</span>
                    <span x-show="sending" class="flex items-center justify-center gap-2">
                        <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        Sending...
                    </span>
                </button>
            </form>
            
            <p class="text-center text-xs text-gray-500 dark:text-gray-400">
                The link expires in 60 minutes. If you no longer have access to this email,
                please contact support.
            </p>
        </div>
    </div>
</div>
@endif
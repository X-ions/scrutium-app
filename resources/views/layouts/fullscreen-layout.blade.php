<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} | Scrutium</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        (function () {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>
<body class="min-h-screen bg-gray-50 font-outfit text-gray-800 dark:bg-gray-900 dark:text-white/90">
    <div class="grid min-h-screen lg:grid-cols-2">
        <aside class="relative hidden overflow-hidden bg-[#0B1B33] px-12 py-10 text-white lg:flex lg:flex-col lg:justify-between">
            <x-brand-logo size="lg" wordmarkClass="text-white" />
            <div class="max-w-md">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-300">Influencer intelligence</p>
                <h2 class="mt-4 text-4xl font-semibold leading-tight">Campaigns, proof, and performance in one workspace.</h2>
                <p class="mt-4 text-base text-white/70">Monitor lifecycles, verify deliverables, and measure ROI from briefing through financial decision.</p>
            </div>
            <p class="text-sm text-white/40">&copy; {{ date('Y') }} Scrutium</p>
        </aside>
        <main class="flex items-center justify-center px-6 py-12">
            <div class="w-full max-w-md">
                <div class="mb-8 lg:hidden">
                    <x-brand-logo wordmarkClass="text-gray-900 dark:text-white" />
                </div>
                @yield('content')
            </div>
        </main>
    </div>
    @stack('scripts')
</body>
</html>

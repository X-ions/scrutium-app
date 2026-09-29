<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\SocialHubPublishingServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Which proxies may set X-Forwarded-* is a security decision, not a
        // convenience. Trusting every hop lets a client forge X-Forwarded-For
        // and walk straight past any per-IP rate limit, and forge
        // X-Forwarded-Proto to suppress the HSTS header.
        //
        // The deployment behind the Vercel edge cannot be identified by a fixed
        // CIDR, so the operator opts in explicitly with TRUSTED_PROXIES=* and
        // accepts that trade-off in front of an edge that overwrites the header.
        // The default trusts nothing: if you terminate TLS at a load balancer,
        // set TRUSTED_PROXIES to its address, or to * only when nothing but
        // that edge can reach the app.
        $middleware->trustProxies(
            at: (string) env('TRUSTED_PROXIES', ''),
            headers: (int) (env('TRUSTED_PROXY_HEADERS', 0) ?: 0),
        );

        $middleware->alias([
            'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
            'guest' => \Illuminate\Auth\Middleware\RedirectIfAuthenticated::class,
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'operate' => \App\Http\Middleware\EnsureCanOperate::class,
            'workspace-admin' => \App\Http\Middleware\EnsureWorkspaceAdministrator::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Application exception handling is provided by Laravel's default handler.
    })
    ->create();

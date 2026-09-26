<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the Vercel edge the app only ever receives plain HTTP, so without
        // this Laravel ignores X-Forwarded-Proto, $request->isSecure() stays false and
        // the HSTS header is never emitted. Laravel 12 has no config/trustedproxy.php
        // in this project, so the proxy CIDR blocks are declared here.
        //
        // X-Forwarded-Host is deliberately NOT trusted. Vercel serves the app from
        // several names at once (the project domain plus per-deployment preview and
        // alias domains), and the forwarded host is not always the one the browser is
        // actually on. Honouring it makes route() render absolute URLs against a
        // different host, so a form posts cross-origin without its session cookie and
        // every login fails with a 419. Trusting forwarded-for/proto/port still gives
        // us the correct client IP and isSecure() (so HSTS keeps working).
        $middleware->trustProxies(
            at: '**',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT,
        );

        $middleware->alias([
            'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
            'guest' => \Illuminate\Auth\Middleware\RedirectIfAuthenticated::class,
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'operate' => \App\Http\Middleware\EnsureCanOperate::class,
            'workspace-admin' => \App\Http\Middleware\EnsureWorkspaceAdministrator::class,
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

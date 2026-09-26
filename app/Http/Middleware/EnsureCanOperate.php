<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanOperate
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        abort_unless($request->user()?->canOperate(), 403, 'Your role cannot change workspace data.');

        return $next($request);
    }
}

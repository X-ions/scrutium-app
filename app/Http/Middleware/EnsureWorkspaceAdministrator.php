<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canManageWorkspace(), 403, 'Workspace administrator access is required.');

        return $next($request);
    }
}

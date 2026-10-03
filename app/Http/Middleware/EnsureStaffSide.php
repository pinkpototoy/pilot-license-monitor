<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Staff-side screens. Students get 404, not 403, so record existence is not revealed (AC-03). */
class EnsureStaffSide
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->role->isStaffSide(), 404);

        return $next($request);
    }
}

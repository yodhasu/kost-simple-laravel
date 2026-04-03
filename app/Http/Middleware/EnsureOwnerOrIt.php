<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnerOrIt
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->user()?->profile?->role;

        if (! in_array($role, ['owner', 'it'], true)) {
            if ($request->expectsJson()) {
                abort(403, 'Akses fitur ini hanya untuk Owner.');
            }

            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorization, not just authentication. `auth:api` only proves a request
 * carries a valid token for *some* user - it says nothing about which role
 * that user has. Every privileged route must stack this middleware on top
 * of `auth:api`, e.g. `->middleware(['auth:api', 'role:admin'])`.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== UserRole::from($role)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\FrontendOrigins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server gate for the Next.js BFF. CORS cannot do this job:
 * the browser never calls Laravel, so an allowlisted origin alone does
 * not stop another Next deploy that knows LARAVEL_API_URL.
 *
 * Both must match: a shared secret (proves it is our BFF) and the browser
 * origin that BFF observed (must be in FRONTEND_URLS).
 */
class EnsureTrustedBff
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.frontend.bff_secret');

        if ($secret === '') {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'BFF secret is not configured.');
        }

        $provided = (string) $request->header('X-BFF-Secret', '');

        if ($provided === '' || ! hash_equals($secret, $provided)) {
            abort(Response::HTTP_FORBIDDEN, 'This API only accepts requests from the trusted application.');
        }

        $origin = FrontendOrigins::normalize((string) $request->header('X-Frontend-Origin', ''));

        if ($origin === null || ! FrontendOrigins::contains($origin)) {
            abort(Response::HTTP_FORBIDDEN, 'This API only accepts requests from the trusted application.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTusHook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('media-hls.hook_secret');

        if ($secret === '') {
            return response()->json([
                'message' => 'Tus hook secret is not configured.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $provided = (string) ($request->header('X-Tus-Hook-Secret') ?: $request->query('secret', ''));

        if ($provided === '' || ! hash_equals($secret, $provided)) {
            return response()->json([
                'message' => 'Invalid tus hook secret.',
            ], Response::HTTP_FORBIDDEN);
        }

        $address = (string) $request->ip();

        if (! in_array($address, ['127.0.0.1', '::1'], true)) {
            return response()->json([
                'message' => 'Tus hooks are local only.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}

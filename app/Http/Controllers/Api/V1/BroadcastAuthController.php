<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpFoundation\Response;

class BroadcastAuthController extends Controller
{
    /**
     * Sanctum token-authenticated stand-in for Laravel's session `/broadcasting/auth`.
     * Echo sends `socket_id` and `channel_name`; the response is Pusher's auth
     * payload (not this API's `{ message, data }` envelope).
     */
    public function authenticate(Request $request): Response
    {
        $request->validate([
            'socket_id' => ['required', 'string'],
            'channel_name' => ['required', 'string'],
        ]);

        $result = Broadcast::auth($request);

        return $result instanceof Response
            ? $result
            : response()->json($result);
    }
}

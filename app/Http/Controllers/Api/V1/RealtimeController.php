<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\RealtimePrivateTestEvent;
use App\Events\ReverbPingEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeController extends Controller
{
    /**
     * Dispatch a public diagnostic broadcast on the "reverb-ping" channel.
     * ShouldBroadcastNow so the console works without a queue worker.
     */
    public function ping(Request $request): JsonResponse
    {
        $event = new ReverbPingEvent(
            message: (string) $request->query('message', 'Hello from Laravel Reverb'),
            triggeredAt: now()->toIso8601String(),
            source: 'admin-console',
        );

        broadcast($event);

        return $this->apiSuccess('Event dispatched.', [
            'channel' => 'reverb-ping',
            'event' => 'ping',
            'payload' => $event->broadcastWith(),
        ]);
    }

    /**
     * Effective broadcasting target (no secrets) for deployment checks.
     */
    public function health(): JsonResponse
    {
        $connection = (string) config('broadcasting.default');
        $options = (array) config("broadcasting.connections.{$connection}.options", []);
        $key = (string) config("broadcasting.connections.{$connection}.key", '');

        return $this->apiSuccess('Broadcasting configuration.', [
            'broadcast_connection' => $connection,
            'host' => $options['host'] ?? null,
            'port' => $options['port'] ?? null,
            'scheme' => $options['scheme'] ?? null,
            'app_key_preview' => $key === '' ? null : substr($key, 0, 8).'...',
        ]);
    }

    /**
     * Send a private diagnostic event to the authenticated user.
     */
    public function testNotification(Request $request): JsonResponse
    {
        $user = $request->user();

        $event = new RealtimePrivateTestEvent(
            user: $user,
            message: 'Private channel push sent at '.now()->toIso8601String(),
            triggeredAt: now()->toIso8601String(),
        );

        broadcast($event);

        return $this->apiSuccess('Private test event dispatched.', [
            'channel' => 'App.Models.User.'.$user->id,
            'event' => 'realtime.test',
            'payload' => $event->broadcastWith(),
        ]);
    }
}

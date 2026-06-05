<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * CameraController — PTZ command queue for local camera-control.py
 *
 * Browser POSTs PTZ commands → stored in Laravel cache.
 * camera-control.py (running locally) polls and executes via Hikvision ISAPI.
 *
 * Architecture:
 *   Browser → POST /api/surveillance/cameras/{id}/ptz
 *          → Cache::push command
 *          ← camera-control.py polls GET /ptz/poll
 *          → Sends ISAPI to camera (192.168.1.x)
 *          → POST /ptz/ack with result
 */
class CameraController extends Controller
{
    const TOKEN_CACHE_KEY   = 'surveillance_tunnel_hls_url'; // reuse TunnelController constant
    const CMD_QUEUE_TTL     = 60 * 5;  // 5 minutes
    const STATUS_TTL        = 60 * 60; // 1 hour

    // ── PTZ Actions ──────────────────────────────────────────────────────────
    const VALID_ACTIONS = [
        'zoom_in', 'zoom_out', 'zoom_stop',
        'pan_left', 'pan_right', 'pan_stop',
        'tilt_up', 'tilt_down', 'tilt_stop',
        'home', 'stop',
    ];

    /**
     * POST /api/surveillance/cameras/{id}/ptz
     *
     * Queue a PTZ command to be picked up by camera-control.py.
     * Body: { "action": "zoom_in", "speed": 3, "duration": 500 }
     */
    public function ptzCommand(Request $request, string $cameraId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $action   = $request->input('action', 'stop');
        $speed    = (int) $request->input('speed', 3);   // 1-7
        $duration = (int) $request->input('duration', 0); // ms, 0=continuous

        if (! in_array($action, self::VALID_ACTIONS)) {
            return response()->json(['error' => "Invalid action: {$action}"], 422);
        }

        $camera = $this->findCamera($cameraId);
        if (! $camera) {
            return response()->json(['error' => "Camera {$cameraId} not found"], 404);
        }

        $command = [
            'id'        => uniqid('ptz_'),
            'camera_id' => $cameraId,
            'camera_ip' => $camera['ip'] ?? null,
            'action'    => $action,
            'speed'     => max(1, min(7, $speed)),
            'duration'  => $duration,
            'queued_at' => now()->toISOString(),
        ];

        // Push to camera-specific queue
        $queueKey = "ptz_queue_{$cameraId}";
        $queue    = Cache::get($queueKey, []);
        $queue[]  = $command;
        Cache::put($queueKey, $queue, self::CMD_QUEUE_TTL);

        \Log::info('[PTZ] Command queued', $command);

        return response()->json([
            'success' => true,
            'command' => $command,
            'message' => 'PTZ command queued. Execute by running camera-control.py locally.',
        ]);
    }

    /**
     * GET /api/surveillance/cameras/{id}/ptz/poll
     *
     * Called by camera-control.py to retrieve pending PTZ commands.
     * Clears the queue after returning.
     */
    public function ptzPoll(Request $request, string $cameraId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $queueKey = "ptz_queue_{$cameraId}";
        $queue    = Cache::get($queueKey, []);
        Cache::forget($queueKey);

        return response()->json([
            'camera_id' => $cameraId,
            'commands'  => $queue,
            'count'     => count($queue),
        ]);
    }

    /**
     * POST /api/surveillance/cameras/{id}/ptz/ack
     *
     * camera-control.py reports the result of a PTZ command execution.
     * Body: { "command_id": "ptz_xxx", "success": true, "error": null }
     */
    public function ptzAck(Request $request, string $cameraId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $statusKey = "ptz_status_{$cameraId}";
        $status    = [
            'command_id' => $request->input('command_id'),
            'success'    => $request->boolean('success'),
            'error'      => $request->input('error'),
            'executed_at' => now()->toISOString(),
        ];

        Cache::put($statusKey, $status, self::STATUS_TTL);
        \Log::info('[PTZ] Command acknowledged', array_merge(['camera_id' => $cameraId], $status));

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/surveillance/cameras/{id}/status
     *
     * Returns last known PTZ status for the camera.
     */
    public function status(string $cameraId): JsonResponse
    {
        $ptzStatus = Cache::get("ptz_status_{$cameraId}");
        $camera    = $this->findCamera($cameraId);

        return response()->json([
            'camera_id'  => $cameraId,
            'camera'     => $camera ? ['label' => $camera['label'], 'ip' => $camera['ip'] ?? null] : null,
            'ptz_status' => $ptzStatus,
        ]);
    }

    /**
     * POST /api/surveillance/cameras/{id}/settings
     *
     * Update camera settings (quality & fps) from browser.
     * Body: { "quality": "hd|sd|ultra", "fps": 1|5|10|15|30 }
     */
    public function updateSettings(Request $request, string $cameraId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $quality = $request->input('quality', 'hd');
        $fps     = (int) $request->input('fps', 15);

        if (! in_array($quality, ['hd', 'sd', 'ultra'])) {
            return response()->json(['error' => "Invalid quality: {$quality}"], 422);
        }

        if (! in_array($fps, [1, 5, 10, 15, 30])) {
            return response()->json(['error' => "Invalid fps: {$fps}"], 422);
        }

        $camera = $this->findCamera($cameraId);
        if (! $camera) {
            return response()->json(['error' => "Camera {$cameraId} not found"], 404);
        }

        $settings = [
            'quality'    => $quality,
            'fps'        => $fps,
            'updated_at' => now()->toISOString(),
        ];

        Cache::put("camera_settings_{$cameraId}", $settings, self::STATUS_TTL);

        \Log::info('[Surveillance] Settings updated', array_merge(['camera_id' => $cameraId], $settings));

        return response()->json([
            'success'  => true,
            'settings' => $settings,
            'message'  => 'Camera settings updated successfully.',
        ]);
    }

    /**
     * GET /api/surveillance/cameras/settings
     *
     * Called by camera-control.py to retrieve settings for all cameras.
     */
    public function getAllSettings(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $cameras = config('surveillance.cameras', []);
        $allSettings = [];

        foreach ($cameras as $cam) {
            if (! ($cam['enabled'] ?? false)) {
                continue;
            }
            $id = $cam['id'];
            $settings = Cache::get("camera_settings_{$id}");

            if (! $settings) {
                // Return default settings
                $settings = [
                    'quality' => 'hd',
                    'fps'     => 15,
                ];
            }

            $allSettings[$id] = $settings;
        }

        return response()->json([
            'settings' => $allSettings,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function isAuthorized(Request $request): bool
    {
        $token  = config('surveillance.api_token');
        if (empty($token)) return false;
        return $request->header('Authorization', '') === "Bearer {$token}";
    }

    private function findCamera(string $id): ?array
    {
        return collect(config('surveillance.cameras'))
            ->firstWhere('id', $id);
    }
}

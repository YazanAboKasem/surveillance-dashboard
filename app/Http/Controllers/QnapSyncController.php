<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\QnapSetting;
use App\Services\JetsonWebSocketService;

class QnapSyncController extends Controller
{
    private $wsService;

    public function __construct(JetsonWebSocketService $wsService)
    {
        $this->wsService = $wsService;
    }

    /**
     * GET /api/surveillance/qnap/settings
     */
    public function getSettings(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $setting = QnapSetting::first();

        return response()->json([
            'success' => true,
            'settings' => $setting ? [
                'host' => $setting->qnap_host,
                'port' => $setting->qnap_port,
                'protocol' => $setting->qnap_protocol,
                'username' => $setting->qnap_username,
                'password' => $setting->qnap_password,
                'remote_path' => $setting->qnap_remote_path,
            ] : null
        ]);
    }

    /**
     * POST /api/surveillance/qnap/settings
     */
    public function saveSettings(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'host' => 'required|string',
            'port' => 'required|integer',
            'protocol' => 'required|string|in:http,https',
            'username' => 'required|string',
            'password' => 'required|string',
            'remote_path' => 'nullable|string',
        ]);

        $setting = QnapSetting::first() ?: new QnapSetting();
        $setting->fill([
            'qnap_host' => $request->input('host'),
            'qnap_port' => $request->input('port'),
            'qnap_protocol' => $request->input('protocol'),
            'qnap_username' => $request->input('username'),
            'qnap_password' => $request->input('password'),
            'qnap_remote_path' => $request->input('remote_path'),
        ]);
        $setting->save();

        return response()->json([
            'success' => true,
            'message' => 'QNAP settings saved successfully.'
        ]);
    }

    /**
     * POST /api/surveillance/sync/start
     */
    public function start(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $this->wsService->isOnline()) {
            return response()->json([
                'success' => false,
                'error' => 'Jetson is offline. Cannot start sync.'
            ], 400);
        }

        $request->validate([
            'host' => 'required|string',
            'port' => 'required|integer',
            'protocol' => 'required|string|in:http,https',
            'username' => 'required|string',
            'password' => 'required|string',
            'remote_path' => 'nullable|string',
            'scope' => 'required|string|in:all,today,last_n_days,cameras',
            'cameras' => 'nullable|array',
            'days' => 'nullable|integer',
            'delete_after_upload' => 'boolean',
            'overwrite_existing' => 'boolean',
            'remember' => 'boolean'
        ]);

        $host = $request->input('host');
        $port = (int) $request->input('port');
        $protocol = $request->input('protocol');
        $username = $request->input('username');
        $password = $request->input('password');
        $remotePath = $request->input('remote_path', '/Recordings/RoadShield/');

        // If remember is checked, save settings
        if ($request->boolean('remember')) {
            $setting = QnapSetting::first() ?: new QnapSetting();
            $setting->fill([
                'qnap_host' => $host,
                'qnap_port' => $port,
                'qnap_protocol' => $protocol,
                'qnap_username' => $username,
                'qnap_password' => $password,
                'qnap_remote_path' => $remotePath,
            ]);
            $setting->save();
        }

        $requestId = 'sync_' . uniqid();

        $qnapConfig = [
            'host' => $host,
            'port' => $port,
            'protocol' => $protocol,
            'username' => $username,
            'password' => $password,
            'remote_path' => $remotePath
        ];

        $options = [
            'scope' => $request->input('scope'),
            'cameras' => $request->input('cameras', []),
            'days' => $request->input('days') ? (int) $request->input('days') : null,
            'delete_after_upload' => $request->boolean('delete_after_upload'),
            'overwrite_existing' => $request->boolean('overwrite_existing')
        ];

        // Send sync start command via WS
        $this->wsService->sendSyncStart($requestId, $qnapConfig, $options);

        return response()->json([
            'success' => true,
            'request_id' => $requestId,
            'message' => 'Sync recordings command sent to Jetson.'
        ]);
    }

    /**
     * GET /api/surveillance/sync/progress/{requestId}
     */
    public function progress(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check for sync.start.ack, sync.progress, sync.complete
        $startAck = Cache::get("ws_response_sync.start.ack_{$requestId}");
        $progress = Cache::get("ws_response_sync.progress_{$requestId}");
        $complete = Cache::get("ws_response_sync.complete_{$requestId}");

        return response()->json([
            'request_id' => $requestId,
            'start_ack' => $startAck,
            'progress' => $progress,
            'complete' => $complete
        ]);
    }

    /**
     * POST /api/surveillance/sync/pause/{requestId}
     */
    public function pause(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->wsService->sendEvent('sync.pause', ['request_id' => $requestId]);

        return response()->json([
            'success' => true,
            'message' => 'Pause command sent.'
        ]);
    }

    /**
     * POST /api/surveillance/sync/resume/{requestId}
     */
    public function resume(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->wsService->sendEvent('sync.resume', ['request_id' => $requestId]);

        return response()->json([
            'success' => true,
            'message' => 'Resume command sent.'
        ]);
    }

    /**
     * POST /api/surveillance/sync/cancel/{requestId}
     */
    public function cancel(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->wsService->sendEvent('sync.cancel', ['request_id' => $requestId]);

        return response()->json([
            'success' => true,
            'message' => 'Cancel command sent.'
        ]);
    }

    /**
     * Helper to validate token
     */
    private function isAuthorized(Request $request): bool
    {
        $token = config('surveillance.api_token');
        if (empty($token)) return false;
        return $request->header('Authorization', '') === "Bearer {$token}";
    }
}

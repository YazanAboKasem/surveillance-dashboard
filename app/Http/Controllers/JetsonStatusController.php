<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\JetsonWebSocketService;

class JetsonStatusController extends Controller
{
    private $wsService;

    public function __construct(JetsonWebSocketService $wsService)
    {
        $this->wsService = $wsService;
    }

    /**
     * GET /api/surveillance/jetson/status
     */
    public function status(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json(
            $this->wsService->getConnectionInfo()
        );
    }

    private function isAuthorized(Request $request): bool
    {
        $token = config('surveillance.api_token');
        if (empty($token)) return false;
        return $request->header('Authorization', '') === "Bearer {$token}";
    }
}

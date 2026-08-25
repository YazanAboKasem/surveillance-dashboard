<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * EventController — ingestion endpoint for AI-detected events (RoadShield AI
 * spec §26/§28/§29/§36).
 *
 * Deliberately authenticated differently from the rest of the surveillance
 * API: those controllers check a single shared token and trust a
 * client-supplied device/jetson id. Events attribute data to a specific
 * device, so device identity here comes only from which device's own
 * dedicated token was presented — never from the request body.
 */
class EventController extends Controller
{
    /**
     * POST /api/surveillance/events
     *
     * Multipart body: event_type, camera_key, track_id, confidence,
     * metadata (JSON string), occurred_at, snapshot (file).
     */
    public function store(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);
        if (! $device) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'event_type' => 'required|string|max:100',
            'camera_key' => 'required|string|max:50',
            'track_id' => 'nullable|string|max:100',
            'confidence' => 'nullable|numeric|min:0|max:1',
            'metadata' => 'nullable|string',
            'occurred_at' => 'nullable|date',
            'snapshot' => 'nullable|image|max:10240',
        ]);

        $snapshotPath = null;
        if ($request->hasFile('snapshot')) {
            $snapshotPath = $request->file('snapshot')->store("events/{$device->device_id}", 'public');
        }

        $event = Event::create([
            'device_id' => $device->id,
            'camera_key' => $validated['camera_key'],
            'event_type' => $validated['event_type'],
            'track_id' => $validated['track_id'] ?? null,
            'confidence' => $validated['confidence'] ?? null,
            'metadata' => isset($validated['metadata']) ? json_decode($validated['metadata'], true) : null,
            'snapshot_path' => $snapshotPath,
            'occurred_at' => $validated['occurred_at'] ?? now(),
        ]);

        return response()->json([
            'success' => true,
            'event_id' => $event->id,
        ]);
    }

    /**
     * Resolves the calling device from its own dedicated token (Device::api_token),
     * set at registration time — see DeviceController::register()/update().
     * Returns null (→ 401) for a missing/unknown/shared token.
     */
    private function resolveDevice(Request $request): ?Device
    {
        $token = $request->bearerToken();
        if (empty($token)) {
            return null;
        }

        return Device::where('api_token', $token)->where('status', 'registered')->first();
    }
}

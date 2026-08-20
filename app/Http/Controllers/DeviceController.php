<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceCamera;
use App\Services\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles the device registry itself — completing registration for a
 * device that has been auto-discovered (connected with an id nobody
 * typed in yet) and listing devices still awaiting registration.
 */
class DeviceController extends Controller
{
    public function __construct(private DeviceRegistry $deviceRegistry)
    {
    }

    /**
     * GET /api/surveillance/devices/pending
     * Polled by the Devices page to show newly-discovered devices live.
     */
    public function pending(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $pending = $this->deviceRegistry->pendingDevices()->map(fn (Device $d) => [
            'device_id' => $d->device_id,
            'hostname' => $d->hostname,
            'first_seen_at' => $d->first_seen_at?->diffForHumans(),
            'last_seen_at' => $d->last_seen_at?->diffForHumans(),
        ]);

        return response()->json(['pending' => $pending]);
    }

    /**
     * POST /api/surveillance/devices/{deviceId}/register
     *
     * Completes registration for a discovered device: name, location,
     * ports, and its camera list. Flips status from `pending` to
     * `registered` so it starts appearing as a normal device everywhere.
     *
     * Body:
     *   name, location, host, hls_port, webrtc_port
     *   cameras: [{ camera_key, label, ip, username, password, channel, ptz }, ...]
     */
    public function register(Request $request, string $deviceId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $device = Device::where('device_id', $deviceId)->first();
        if (! $device) {
            return response()->json(['error' => "Device {$deviceId} not found"], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'host' => 'nullable|string|max:255',
            'hls_port' => 'nullable|integer',
            'webrtc_port' => 'nullable|integer',
            'cameras' => 'required|array|min:1',
            'cameras.*.camera_key' => 'required|string|max:50',
            'cameras.*.label' => 'nullable|string|max:255',
            'cameras.*.ip' => 'nullable|string|max:100',
            'cameras.*.username' => 'nullable|string|max:100',
            'cameras.*.password' => 'nullable|string|max:255',
            'cameras.*.channel' => 'nullable|integer|min:1',
            'cameras.*.ptz' => 'boolean',
        ]);

        $device->update([
            'name' => $validated['name'],
            'location' => $validated['location'] ?? null,
            'host' => $validated['host'] ?: config('surveillance.media_server.host'),
            'hls_port' => $validated['hls_port'] ?? 8888,
            'webrtc_port' => $validated['webrtc_port'] ?? 8889,
            'tunnel_cache_key' => "surveillance_tunnel_hls_url_{$device->device_id}",
            'status' => 'registered',
            'registered_at' => now(),
        ]);

        foreach ($validated['cameras'] as $cam) {
            DeviceCamera::updateOrCreate(
                ['device_id' => $device->id, 'camera_key' => $cam['camera_key']],
                [
                    'label' => $cam['label'] ?? $cam['camera_key'],
                    'ip' => $cam['ip'] ?? null,
                    'username' => $cam['username'] ?? null,
                    'password' => $cam['password'] ?? null,
                    'channel' => $cam['channel'] ?? 1,
                    'ptz' => $cam['ptz'] ?? false,
                    'enabled' => true,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Device {$deviceId} registered.",
            'redirect' => route('surveillance.device-settings', $device->device_id),
        ]);
    }

    /**
     * GET /api/surveillance/devices/{deviceId}
     *
     * Full editable device + camera data (no passwords) — fetched by the
     * dashboard's Edit modal to prefill its form.
     */
    public function show(Request $request, string $deviceId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $device = Device::where('device_id', $deviceId)->with('cameras')->first();
        if (! $device) {
            return response()->json(['error' => "Device {$deviceId} not found"], 404);
        }

        return response()->json([
            'device_id' => $device->device_id,
            'name' => $device->name,
            'location' => $device->location,
            'host' => $device->host,
            'hls_port' => $device->hls_port,
            'webrtc_port' => $device->webrtc_port,
            'cameras' => $device->cameras->map(fn (DeviceCamera $c) => [
                'camera_key' => $c->camera_key,
                'label' => $c->label,
                'ip' => $c->ip,
                'username' => $c->username,
                'channel' => $c->channel,
                'ptz' => $c->ptz,
                // password intentionally omitted — never sent back to the browser
            ]),
        ]);
    }

    /**
     * PUT /api/surveillance/devices/{deviceId}
     *
     * Edit an already-registered device: name, location, ports, and its
     * camera list (add/update/remove). Camera passwords are only
     * overwritten when a new one is actually submitted — leaving the
     * field blank in the edit form keeps the existing password.
     */
    public function update(Request $request, string $deviceId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $device = Device::where('device_id', $deviceId)->where('status', 'registered')->first();
        if (! $device) {
            return response()->json(['error' => "Registered device {$deviceId} not found"], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'host' => 'nullable|string|max:255',
            'hls_port' => 'nullable|integer',
            'webrtc_port' => 'nullable|integer',
            'cameras' => 'required|array|min:1',
            'cameras.*.camera_key' => 'required|string|max:50',
            'cameras.*.label' => 'nullable|string|max:255',
            'cameras.*.ip' => 'nullable|string|max:100',
            'cameras.*.username' => 'nullable|string|max:100',
            'cameras.*.password' => 'nullable|string|max:255',
            'cameras.*.channel' => 'nullable|integer|min:1',
            'cameras.*.ptz' => 'boolean',
        ]);

        $device->update([
            'name' => $validated['name'],
            'location' => $validated['location'] ?? null,
            'host' => $validated['host'] ?: $device->host,
            'hls_port' => $validated['hls_port'] ?? $device->hls_port,
            'webrtc_port' => $validated['webrtc_port'] ?? $device->webrtc_port,
        ]);

        $submittedKeys = [];
        foreach ($validated['cameras'] as $cam) {
            $submittedKeys[] = $cam['camera_key'];

            $data = [
                'label' => $cam['label'] ?? $cam['camera_key'],
                'ip' => $cam['ip'] ?? null,
                'username' => $cam['username'] ?? null,
                'channel' => $cam['channel'] ?? 1,
                'ptz' => $cam['ptz'] ?? false,
                'enabled' => true,
            ];
            if (!empty($cam['password'])) {
                $data['password'] = $cam['password'];
            }

            DeviceCamera::updateOrCreate(
                ['device_id' => $device->id, 'camera_key' => $cam['camera_key']],
                $data
            );
        }

        // Cameras removed in the edit form are removed from the device too
        DeviceCamera::where('device_id', $device->id)
            ->whereNotIn('camera_key', $submittedKeys)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "Device {$deviceId} updated.",
        ]);
    }

    private function isAuthorized(Request $request): bool
    {
        $token = config('surveillance.api_token');
        if (empty($token)) {
            return false;
        }
        return $request->header('Authorization', '') === "Bearer {$token}";
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceCamera;
use App\Services\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            'cameras.*.role' => 'nullable|string|in:FRONT,REAR_FIXED,REAR_PTZ',
            'cameras.*.ip' => 'nullable|string|max:100',
            'cameras.*.username' => 'nullable|string|max:100',
            'cameras.*.password' => 'nullable|string|max:255',
            'cameras.*.channel' => 'nullable|integer|min:1',
            'cameras.*.type' => 'nullable|string|in:hikvision,generic',
            'cameras.*.rtsp_port' => 'nullable|integer',
            'cameras.*.rtsp_main_url' => 'nullable|string|max:500',
            'cameras.*.rtsp_sub_url' => 'nullable|string|max:500',
            'cameras.*.ptz' => 'boolean',
        ]);

        $device->update([
            'name' => $validated['name'],
            'location' => $validated['location'] ?? null,
            'host' => $validated['host'] ?? config('surveillance.media_server.host'),
            'hls_port' => $validated['hls_port'] ?? 8888,
            'webrtc_port' => $validated['webrtc_port'] ?? 8889,
            'tunnel_cache_key' => "surveillance_tunnel_hls_url_{$device->device_id}",
            'status' => 'registered',
            'registered_at' => now(),
            // Dedicated credential for the AI/event-ingestion endpoint — kept
            // separate from the shared agent token so an event's device_id
            // can be derived from the token instead of a client-supplied field.
            'api_token' => $device->api_token ?? Str::random(40),
        ]);

        foreach ($validated['cameras'] as $cam) {
            DeviceCamera::updateOrCreate(
                ['device_id' => $device->id, 'camera_key' => $cam['camera_key']],
                [
                    'label' => $cam['label'] ?? $cam['camera_key'],
                    'role' => $cam['role'] ?? null,
                    'ip' => $cam['ip'] ?? null,
                    'username' => $cam['username'] ?? null,
                    'password' => $cam['password'] ?? null,
                    'channel' => $cam['channel'] ?? 1,
                    'type' => $cam['type'] ?? 'hikvision',
                    'rtsp_port' => $cam['rtsp_port'] ?? 554,
                    'rtsp_main_url' => $cam['rtsp_main_url'] ?? null,
                    'rtsp_sub_url' => $cam['rtsp_sub_url'] ?? null,
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
                'role' => $c->role,
                'ip' => $c->ip,
                'username' => $c->username,
                'channel' => $c->channel,
                'type' => $c->type,
                'rtsp_port' => $c->rtsp_port,
                'rtsp_main_url' => $c->rtsp_main_url,
                'rtsp_sub_url' => $c->rtsp_sub_url,
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
            'cameras.*.role' => 'nullable|string|in:FRONT,REAR_FIXED,REAR_PTZ',
            'cameras.*.ip' => 'nullable|string|max:100',
            'cameras.*.username' => 'nullable|string|max:100',
            'cameras.*.password' => 'nullable|string|max:255',
            'cameras.*.channel' => 'nullable|integer|min:1',
            'cameras.*.type' => 'nullable|string|in:hikvision,generic',
            'cameras.*.rtsp_port' => 'nullable|integer',
            'cameras.*.rtsp_main_url' => 'nullable|string|max:500',
            'cameras.*.rtsp_sub_url' => 'nullable|string|max:500',
            'cameras.*.ptz' => 'boolean',
        ]);

        $device->update([
            'name' => $validated['name'],
            'location' => $validated['location'] ?? null,
            'host' => $validated['host'] ?? $device->host,
            'hls_port' => $validated['hls_port'] ?? $device->hls_port,
            'webrtc_port' => $validated['webrtc_port'] ?? $device->webrtc_port,
            'api_token' => $device->api_token ?? Str::random(40),
        ]);

        $submittedKeys = [];
        foreach ($validated['cameras'] as $cam) {
            $submittedKeys[] = $cam['camera_key'];

            $data = [
                'label' => $cam['label'] ?? $cam['camera_key'],
                'role' => $cam['role'] ?? null,
                'ip' => $cam['ip'] ?? null,
                'username' => $cam['username'] ?? null,
                'channel' => $cam['channel'] ?? 1,
                'type' => $cam['type'] ?? 'hikvision',
                'rtsp_port' => $cam['rtsp_port'] ?? 554,
                'rtsp_main_url' => $cam['rtsp_main_url'] ?? null,
                'rtsp_sub_url' => $cam['rtsp_sub_url'] ?? null,
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

    /**
     * GET /api/surveillance/devices/{deviceId}/camera-config
     *
     * Called by the device itself (agent-to-server auth) at startup, so it
     * can build its local mediamtx.yml and camera-control.py CAMERAS dict
     * from whatever was entered in the dashboard's Register/Edit form,
     * instead of needing those hand-edited on every device. Unlike show(),
     * this DOES include camera passwords — the device needs them to
     * actually connect to its cameras.
     *
     * Returns an empty cameras list (not an error) for an unregistered or
     * unknown device id, so the caller can safely treat "nothing to sync
     * yet" as a no-op instead of a failure.
     */
    public function cameraConfig(Request $request, string $deviceId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $device = Device::where('device_id', $deviceId)
            ->where('status', 'registered')
            ->with('cameras')
            ->first();

        if (! $device) {
            return response()->json(['device_id' => $deviceId, 'registered' => false, 'cameras' => []]);
        }

        return response()->json([
            'device_id' => $device->device_id,
            'registered' => true,
            // Dedicated token for POST /api/surveillance/events — lets the
            // AI process authenticate as *this* device without the endpoint
            // having to trust a client-supplied device_id.
            'event_api_token' => $device->api_token,
            'cameras' => $device->cameras->where('enabled', true)->values()->map(fn (DeviceCamera $c) => [
                'camera_key' => $c->camera_key,
                'label' => $c->label,
                'role' => $c->role,
                'ip' => $c->ip,
                'username' => $c->username,
                'password' => $c->password,
                'channel' => $c->channel,
                'type' => $c->type,
                'rtsp_port' => $c->rtsp_port,
                'rtsp_main_url' => $c->rtsp_main_url,
                'rtsp_sub_url' => $c->rtsp_sub_url,
                'ptz' => $c->ptz,
            ]),
        ]);
    }

    /**
     * DELETE /api/surveillance/devices/{deviceId}
     *
     * Removes a device (and its cameras, cascade) from the registry
     * entirely — e.g. to clear out old seeded/test devices. This does not
     * touch anything on the physical device itself; if it's still running
     * and connected, it will just show back up under "Discovered Devices"
     * on its next heartbeat/hello.
     */
    public function destroy(Request $request, string $deviceId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $device = Device::where('device_id', $deviceId)->first();
        if (! $device) {
            return response()->json(['error' => "Device {$deviceId} not found"], 404);
        }

        $device->delete(); // cascades to device_cameras

        return response()->json([
            'success' => true,
            'message' => "Device {$deviceId} deleted.",
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

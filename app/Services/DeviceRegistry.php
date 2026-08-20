<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * Replaces the old static config('surveillance.devices') array with a
 * DB-backed registry, so onboarding a new physical device is "register it
 * from the dashboard" instead of "hand-edit config/surveillance.php and
 * redeploy". Also the seed point for auto-discovery: any device that
 * connects with an id not yet in the `devices` table is recorded as
 * `pending` instead of silently accepted or dropped.
 */
class DeviceRegistry
{
    /**
     * All registered (fully configured) devices, shaped exactly like the
     * old config('surveillance.devices') array so existing callers only
     * need to swap their data source, not their downstream logic.
     */
    public function registeredDevices(): Collection
    {
        return Device::where('status', 'registered')
            ->with(['cameras' => fn ($q) => $q->orderBy('camera_key')])
            ->orderBy('name')
            ->get()
            ->map(fn (Device $d) => $this->toConfigArray($d));
    }

    /**
     * A single registered device by its device_id, or null.
     */
    public function find(string $deviceId): ?array
    {
        $device = Device::where('device_id', $deviceId)
            ->where('status', 'registered')
            ->with(['cameras' => fn ($q) => $q->orderBy('camera_key')])
            ->first();

        return $device ? $this->toConfigArray($device) : null;
    }

    /**
     * Devices that have connected (WS hello or heartbeat) but were never
     * registered from the dashboard — shown under "Discovered Devices".
     */
    public function pendingDevices(): Collection
    {
        return Device::where('status', 'pending')
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * Record that a device with this id is alive. Called from the WS hello
     * handler and the heartbeat endpoint. Unknown ids are captured as
     * `pending` (discovery) rather than ignored; known ids just get their
     * last_seen_at bumped.
     */
    public function seen(string $deviceId, ?string $hostname = null): Device
    {
        $device = Device::where('device_id', $deviceId)->first();
        $now = now();

        if ($device) {
            $device->update([
                'last_seen_at' => $now,
                'hostname' => $hostname ?: $device->hostname,
            ]);
            return $device;
        }

        return Device::create([
            'device_id' => $deviceId,
            'hostname' => $hostname,
            'status' => 'pending',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    private function toConfigArray(Device $device): array
    {
        return [
            'id' => $device->device_id,
            'name' => $device->name ?: $device->device_id,
            'location' => $device->location,
            'host' => $device->host ?: config('surveillance.media_server.host'),
            'hls_port' => $device->hls_port,
            'webrtc_port' => $device->webrtc_port,
            'hls_base_url' => $device->hls_base_url,
            'webrtc_base_url' => $device->webrtc_base_url,
            'tunnel_cache_key' => $device->tunnel_cache_key ?: "surveillance_tunnel_hls_url_{$device->device_id}",
            'api_token' => $device->api_token ?: config('surveillance.api_token'),
            'enabled' => true,
            'cameras' => $device->cameras->where('enabled', true)->values()->map(fn ($c) => [
                'id' => $c->camera_key,
                'label' => $c->label ?: $c->camera_key,
                'path' => $c->camera_key,
                'path_sub' => "{$c->camera_key}_sub",
                'ip' => $c->ip,
                'enabled' => $c->enabled,
                'ptz' => $c->ptz,
            ])->all(),
        ];
    }
}

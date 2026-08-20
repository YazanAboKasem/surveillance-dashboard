<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Carry the two devices that were hardcoded in config/surveillance.php
     * into the new devices/device_cameras tables as already-registered, so
     * the live system (rock1 is actively streaming/recording) keeps working
     * without any manual re-entry.
     */
    public function up(): void
    {
        $devices = config('surveillance.devices', []);
        $now = now();

        foreach ($devices as $device) {
            if (empty($device['id'])) {
                continue;
            }

            $deviceRowId = DB::table('devices')->insertGetId([
                'device_id'        => $device['id'],
                'name'             => $device['name'] ?? $device['id'],
                'location'         => $device['location'] ?? null,
                'host'             => $device['host'] ?? null,
                'hls_port'         => $device['hls_port'] ?? 8888,
                'webrtc_port'      => $device['webrtc_port'] ?? 8889,
                'hls_base_url'     => $device['hls_base_url'] ?? null,
                'webrtc_base_url'  => $device['webrtc_base_url'] ?? null,
                'tunnel_cache_key' => $device['tunnel_cache_key'] ?? null,
                'api_token'        => $device['api_token'] ?? null,
                'status'           => 'registered',
                'first_seen_at'    => $now,
                'last_seen_at'     => $now,
                'registered_at'    => $now,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            foreach ($device['cameras'] ?? [] as $cam) {
                if (empty($cam['id'])) {
                    continue;
                }

                DB::table('device_cameras')->insert([
                    'device_id'  => $deviceRowId,
                    'camera_key' => $cam['id'],
                    'label'      => $cam['label'] ?? $cam['id'],
                    'ip'         => $cam['ip'] ?? null,
                    'username'   => null, // not present in config/surveillance.php — lives on the device itself
                    'password'   => null,
                    'channel'    => 1,
                    'ptz'        => (bool) ($cam['ptz'] ?? false),
                    'enabled'    => (bool) ($cam['enabled'] ?? true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data-seeding migration — no schema to reverse. Leave rows in place
        // (dropping the tables in the earlier migrations' down() covers cleanup).
    }
};

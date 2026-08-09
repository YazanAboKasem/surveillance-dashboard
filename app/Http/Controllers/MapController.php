<?php

namespace App\Http\Controllers;

use App\Models\DeviceAgent;
use App\Models\DeviceLocationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class MapController extends Controller
{
    /**
     * GET /surveillance/map
     * Display the fleet map page.
     */
    public function index(): View
    {
        $deviceConfigs = config('surveillance.devices', []);
        $devices = collect($deviceConfigs)->where('enabled', true)->values();

        return view('surveillance.map', compact('devices'));
    }

    /**
     * GET /api/surveillance/map/devices
     * Return all devices with their latest GPS positions as JSON.
     */
    public function devicesGeoJson(): JsonResponse
    {
        $deviceConfigs = config('surveillance.devices', []);
        $enabledDevices = collect($deviceConfigs)->where('enabled', true);

        $features = [];

        foreach ($enabledDevices as $deviceConfig) {
            $deviceId = $deviceConfig['id'];

            // Try to get location from database first
            $agent = DeviceAgent::where('jetson_id', $deviceId)->first();

            $lat = $agent->latitude ?? null;
            $lng = $agent->longitude ?? null;
            $lastLocationAt = $agent->last_location_at ?? null;

            // Also try cache (WebSocket updates may be more recent)
            $cachedGps = Cache::get("gps_location_{$deviceId}");
            if ($cachedGps) {
                $cachedTime = $cachedGps['timestamp'] ?? 0;
                $dbTime = $lastLocationAt ? $lastLocationAt->timestamp : 0;
                if ($cachedTime > $dbTime || $lat === null || $lng === null) {
                    $lat = $cachedGps['latitude'];
                    $lng = $cachedGps['longitude'];
                    $lastLocationAt = \Carbon\Carbon::createFromTimestamp($cachedTime);
                }
            }

            // Fallback to latest DeviceLocationLog if lat/lng are still empty
            if (($lat === null || $lng === null || ($lat == 0 && $lng == 0))) {
                $latestLog = DeviceLocationLog::where('device_id', $deviceId)->orderBy('recorded_at', 'desc')->first();
                if ($latestLog) {
                    $lat = $latestLog->latitude;
                    $lng = $latestLog->longitude;
                    $lastLocationAt = $latestLog->recorded_at;
                }
            }

            // Determine online status
            $isOnline = (bool) Cache::get("jetson_ws_online_{$deviceId}", false);
            if (!$isOnline && $agent) {
                $isOnline = $agent->online && $agent->last_seen && $agent->last_seen->diffInMinutes(now()) < 2;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type'        => 'Point',
                    'coordinates' => [$lng ?? 0, $lat ?? 0],
                ],
                'properties' => [
                    'id'        => $deviceId,
                    'name'      => $deviceConfig['name'] ?? $deviceId,
                    'location'  => $deviceConfig['location'] ?? 'Unknown',
                    'is_online' => $isOnline,
                    'latitude'  => $lat,
                    'longitude' => $lng,
                    'has_gps'   => ($lat !== null && $lng !== null && $lat != 0 && $lng != 0),
                    'last_seen' => $agent && $agent->last_seen ? $agent->last_seen->timezone('Asia/Dubai')->format('Y-m-d H:i:s') : ($lastLocationAt ? $lastLocationAt->timezone('Asia/Dubai')->format('Y-m-d H:i:s') : null),
                    'cpu'       => $agent->cpu ?? 0,
                    'ram'       => $agent->ram ?? 0,
                    'temperature' => $agent->temperature ?? 0,
                    'camera_count' => count($deviceConfig['cameras'] ?? []),
                    'url'       => route('surveillance.device-settings', $deviceId),
                ],
            ];
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * GET /api/surveillance/map/route/{deviceId}
     * Return location log for a device within a date range.
     */
    public function deviceRoute(Request $request, string $deviceId): JsonResponse
    {
        $from = $request->query('from', now()->subHours(24)->toDateTimeString());
        $to   = $request->query('to', now()->toDateTimeString());

        $points = DeviceLocationLog::where('device_id', $deviceId)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at', 'asc')
            ->limit(5000)
            ->get(['latitude', 'longitude', 'speed', 'altitude', 'recorded_at']);

        $coordinates = $points->map(fn($p) => [
            'lat'         => $p->latitude,
            'lng'         => $p->longitude,
            'speed'       => $p->speed,
            'altitude'    => $p->altitude,
            'recorded_at' => $p->recorded_at->timezone('Asia/Dubai')->format('Y-m-d H:i:s'),
        ]);

        // Build available dates for the date picker
        $availableDates = DeviceLocationLog::where('device_id', $deviceId)
            ->selectRaw('DATE(recorded_at) as date')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(90)
            ->pluck('date');

        return response()->json([
            'device_id'       => $deviceId,
            'from'            => $from,
            'to'              => $to,
            'point_count'     => $points->count(),
            'coordinates'     => $coordinates,
            'available_dates' => $availableDates,
        ]);
    }
}

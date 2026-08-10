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
     * Return location log for a device grouped into Trips (رحلات).
     */
    public function deviceRoute(Request $request, string $deviceId): JsonResponse
    {
        $from = $request->query('from', now()->subHours(24)->toDateTimeString());
        $to   = $request->query('to', now()->toDateTimeString());

        $logs = DeviceLocationLog::where('device_id', $deviceId)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at', 'asc')
            ->limit(5000)
            ->get(['session_id', 'latitude', 'longitude', 'speed', 'altitude', 'recorded_at']);

        // Group logs into trips
        $trips = [];
        $currentTrip = null;
        $tripIndex = 1;

        foreach ($logs as $log) {
            $logTime = $log->recorded_at;
            $sessionId = $log->session_id;

            $isNewTrip = false;

            if ($currentTrip === null) {
                $isNewTrip = true;
            } elseif ($sessionId !== null && $currentTrip['session_id'] !== null) {
                if ($sessionId !== $currentTrip['session_id']) {
                    $isNewTrip = true;
                }
            } else {
                // Fallback: If time gap > 15 minutes (900 seconds), consider it a new trip
                $lastPoint = end($currentTrip['points']);
                $lastLogTime = $lastPoint['_timestamp'] ?? 0;
                if ($logTime->timestamp - $lastLogTime > 900) {
                    $isNewTrip = true;
                }
            }

            if ($isNewTrip) {
                if ($currentTrip !== null) {
                    $trips[] = $this->finalizeTrip($currentTrip);
                }
                $currentTrip = [
                    'trip_id'      => "trip_{$tripIndex}",
                    'trip_number'  => $tripIndex++,
                    'session_id'   => $sessionId,
                    'start_time'   => $logTime->timezone('Asia/Dubai')->format('Y-m-d H:i:s'),
                    'end_time'     => $logTime->timezone('Asia/Dubai')->format('Y-m-d H:i:s'),
                    'points'       => [],
                ];
            }

            $currentTrip['end_time'] = $logTime->timezone('Asia/Dubai')->format('Y-m-d H:i:s');
            $currentTrip['points'][] = [
                'lat'         => $log->latitude,
                'lng'         => $log->longitude,
                'speed'       => $log->speed,
                'altitude'    => $log->altitude,
                'recorded_at' => $logTime->timezone('Asia/Dubai')->format('Y-m-d H:i:s'),
                'time_short'  => $logTime->timezone('Asia/Dubai')->format('H:i:s'),
                '_timestamp'  => $logTime->timestamp,
            ];
        }

        if ($currentTrip !== null) {
            $trips[] = $this->finalizeTrip($currentTrip);
        }

        // Available dates
        $availableDates = DeviceLocationLog::where('device_id', $deviceId)
            ->selectRaw('DATE(recorded_at) as date')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(90)
            ->pluck('date');

        // Flat coordinates for backward compatibility
        $allCoordinates = $logs->map(fn($p) => [
            'lat'         => $p->latitude,
            'lng'         => $p->longitude,
            'speed'       => $p->speed,
            'altitude'    => $p->altitude,
            'recorded_at' => $p->recorded_at->timezone('Asia/Dubai')->format('Y-m-d H:i:s'),
            'time_short'  => $p->recorded_at->timezone('Asia/Dubai')->format('H:i:s'),
        ]);

        return response()->json([
            'device_id'       => $deviceId,
            'from'            => $from,
            'to'              => $to,
            'total_trips'     => count($trips),
            'point_count'     => $logs->count(),
            'trips'           => $trips,
            'coordinates'     => $allCoordinates,
            'available_dates' => $availableDates,
        ]);
    }

    private function finalizeTrip(array $trip): array
    {
        $points = $trip['points'];
        $count = count($points);
        $totalDistance = 0;
        $maxSpeed = 0;

        for ($i = 0; $i < $count - 1; $i++) {
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $totalDistance += $this->haversineDistance($p1['lat'], $p1['lng'], $p2['lat'], $p2['lng']);
            if (isset($p1['speed']) && $p1['speed'] > $maxSpeed) {
                $maxSpeed = $p1['speed'];
            }
        }
        if ($count > 0 && isset($points[$count - 1]['speed']) && $points[$count - 1]['speed'] > $maxSpeed) {
            $maxSpeed = $points[$count - 1]['speed'];
        }

        $startTime = \Carbon\Carbon::parse($trip['start_time']);
        $endTime   = \Carbon\Carbon::parse($trip['end_time']);
        $durationMinutes = max(1, round($endTime->diffInMinutes($startTime)));

        $cleanPoints = array_map(function($p) {
            unset($p['_timestamp']);
            return $p;
        }, $points);

        return [
            'trip_id'          => $trip['trip_id'],
            'trip_number'      => $trip['trip_number'],
            'title'            => "رحلة " . $trip['trip_number'],
            'session_id'       => $trip['session_id'],
            'start_time'       => $trip['start_time'],
            'end_time'         => $trip['end_time'],
            'start_time_short' => $startTime->format('H:i'),
            'end_time_short'   => $endTime->format('H:i'),
            'duration_minutes' => $durationMinutes,
            'distance_km'      => round($totalDistance, 2),
            'max_speed'        => round($maxSpeed, 1),
            'point_count'      => $count,
            'coordinates'      => $cleanPoints,
        ];
    }

    private function haversineDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}


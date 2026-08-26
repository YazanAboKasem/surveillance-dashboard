<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DevicePowerLog;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AlertsController — dashboard page for browsing AI-detected events
 * (RoadShield AI). Read-only: events are written by EventController, this
 * only displays them.
 *
 * Filtering is trip-first: a "trip" is a device_power_logs row (started
 * when the device's WS connects, ended when it disconnects — see
 * JetsonWebSocketHandler). Pick a device, then a trip of that device,
 * and only that trip's events show — trips are never mixed together.
 */
class AlertsController extends Controller
{
    /**
     * GET /surveillance/alerts
     */
    public function index(Request $request): View
    {
        $devices = Device::where('status', 'registered')->orderBy('name')->get(['device_id', 'name']);

        // Default to the first device with any recorded trips, so the page
        // isn't empty on first load.
        $deviceId = $request->query('device_id')
            ?: DevicePowerLog::whereIn('device_id', $devices->pluck('device_id'))
                ->latest('started_at')->value('device_id');

        $trips = $deviceId
            ? DevicePowerLog::where('device_id', $deviceId)->orderByDesc('started_at')->get()
            : collect();

        // Default to the most recent trip (usually the active one) for
        // the selected device.
        $tripId = $request->query('trip_id') ?: $trips->first()?->id;

        $events = collect();
        $eventTypes = collect();
        if ($tripId) {
            $query = Event::with('device')->where('device_power_log_id', $tripId)->orderByDesc('occurred_at');
            if ($eventType = $request->query('event_type')) {
                $query->where('event_type', $eventType);
            }
            $events = $query->paginate(30)->withQueryString();
            $eventTypes = Event::where('device_power_log_id', $tripId)->select('event_type')->distinct()->pluck('event_type');
        }

        return view('surveillance.alerts', [
            'devices' => $devices,
            'trips' => $trips,
            'selectedDeviceId' => $deviceId,
            'selectedTripId' => $tripId,
            'events' => $events,
            'eventTypes' => $eventTypes,
        ]);
    }

    /**
     * GET /surveillance/alerts/{event}/snapshot
     *
     * Streams the snapshot directly from storage rather than relying on
     * `php artisan storage:link` having been run — keeps this working
     * regardless of that deployment step.
     */
    public function snapshot(Event $event): StreamedResponse
    {
        abort_unless($event->snapshot_path && Storage::disk('public')->exists($event->snapshot_path), 404);

        return Storage::disk('public')->response($event->snapshot_path);
    }
}

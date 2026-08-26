<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * AlertsController — dashboard page for browsing AI-detected events
 * (RoadShield AI). Read-only: events are written by EventController, this
 * only displays them.
 */
class AlertsController extends Controller
{
    /**
     * GET /surveillance/alerts
     */
    public function index(Request $request): View
    {
        $query = Event::with('device')->orderByDesc('occurred_at');

        if ($deviceId = $request->query('device_id')) {
            $query->whereHas('device', fn ($q) => $q->where('device_id', $deviceId));
        }
        if ($eventType = $request->query('event_type')) {
            $query->where('event_type', $eventType);
        }

        $events = $query->paginate(30)->withQueryString();
        $devices = Device::where('status', 'registered')->orderBy('name')->get(['device_id', 'name']);
        $eventTypes = Event::select('event_type')->distinct()->pluck('event_type');

        return view('surveillance.alerts', compact('events', 'devices', 'eventTypes'));
    }

    /**
     * GET /surveillance/alerts/{event}/snapshot
     *
     * Streams the snapshot directly from storage rather than relying on
     * `php artisan storage:link` having been run — keeps this working
     * regardless of that deployment step.
     */
    public function snapshot(Event $event): Response
    {
        abort_unless($event->snapshot_path && Storage::disk('public')->exists($event->snapshot_path), 404);

        return Storage::disk('public')->response($event->snapshot_path);
    }
}

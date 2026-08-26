@extends('layouts.surveillance')

@section('title', 'AI Alerts')
@section('page-title', 'AI Alerts')

@section('content')

    {{-- Breadcrumb --}}
    <div class="sv-breadcrumb">
        <a href="{{ route('surveillance.index') }}"><i class="bi bi-grid-3x3-gap-fill"></i> Monitoring Room</a>
        <i class="bi bi-chevron-right"></i>
        <span>AI Alerts</span>
    </div>

    <div class="sv-settings-section">
        <div class="sv-settings-card">
            <div class="sv-settings-card-header">
                <div class="sv-settings-card-title">
                    <i class="bi bi-bell-fill" style="color:var(--accent)"></i>
                    AI-Detected Events
                </div>
            </div>

            <div class="sv-settings-card-body" style="padding: 20px 24px;">
                {{-- Trip-first filters: device, then one of its trips, then optionally event type --}}
                <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:18px">
                    <select name="device_id" class="sv-input-sm" style="width:200px" onchange="this.form.submit()">
                        <option value="">Select a device…</option>
                        @foreach ($devices as $device)
                            <option value="{{ $device->device_id }}" {{ $selectedDeviceId === $device->device_id ? 'selected' : '' }}>
                                {{ $device->name ?: $device->device_id }}
                            </option>
                        @endforeach
                    </select>

                    <select name="trip_id" class="sv-input-sm" style="width:280px" onchange="this.form.submit()" {{ $trips->isEmpty() ? 'disabled' : '' }}>
                        @forelse ($trips as $trip)
                            <option value="{{ $trip->id }}" {{ (int) $selectedTripId === $trip->id ? 'selected' : '' }}>
                                {{ $trip->started_at?->format('Y-m-d H:i') }}
                                →
                                {{ $trip->stopped_at ? $trip->stopped_at->format('H:i') : 'ACTIVE NOW' }}
                            </option>
                        @empty
                            <option value="">No trips recorded for this device</option>
                        @endforelse
                    </select>

                    <select name="event_type" class="sv-input-sm" style="width:180px" onchange="this.form.submit()" {{ !$selectedTripId ? 'disabled' : '' }}>
                        <option value="">All event types</option>
                        @foreach ($eventTypes as $type)
                            <option value="{{ $type }}" {{ request('event_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>
                </form>

                @if (!$selectedTripId)
                    <div class="sv-empty-state">
                        <i class="bi bi-signpost-split" style="font-size:40px;opacity:0.3;display:block;margin-bottom:12px"></i>
                        No trip selected.
                        <div class="sv-empty-hint">A trip starts when the device connects and ends when it disconnects. Pick a device and a trip above to see its events.</div>
                    </div>
                @elseif ($events->isEmpty())
                    <div class="sv-empty-state">
                        <i class="bi bi-bell-slash" style="font-size:40px;opacity:0.3;display:block;margin-bottom:12px"></i>
                        No AI events during this trip.
                    </div>
                @else
                    @php $activeTrip = $trips->firstWhere('id', (int) $selectedTripId); @endphp
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;color:var(--text-secondary);font-size:13px">
                        <i class="bi bi-signpost-split" style="color:var(--accent)"></i>
                        Trip: {{ $activeTrip?->started_at?->format('Y-m-d H:i') }}
                        →
                        @if ($activeTrip && !$activeTrip->stopped_at)
                            <span style="color:var(--green)">ACTIVE NOW</span>
                        @else
                            {{ $activeTrip?->stopped_at?->format('Y-m-d H:i') }}
                        @endif
                    </div>

                    <div class="sv-table-responsive">
                        <table class="sv-table">
                            <thead>
                                <tr>
                                    <th style="width:70px">Snapshot</th>
                                    <th>Event</th>
                                    <th>Camera</th>
                                    <th>Track</th>
                                    <th>Confidence</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($events as $event)
                                    <tr>
                                        <td>
                                            @if ($event->snapshot_path)
                                                <a href="{{ route('surveillance.alerts.snapshot', $event) }}" target="_blank">
                                                    <img src="{{ route('surveillance.alerts.snapshot', $event) }}"
                                                         alt="snapshot" style="width:56px;height:42px;object-fit:cover;border-radius:4px;border:1px solid var(--border)">
                                                </a>
                                            @else
                                                <span style="color:var(--text-muted)">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span style="background:rgba(255,171,64,0.15);color:var(--amber);padding:2px 8px;border-radius:4px;font-weight:600;font-size:11px">
                                                {{ $event->event_type }}
                                            </span>
                                            @if ($event->sub_zone)
                                                <div style="color:var(--text-muted);font-size:11px;margin-top:3px">{{ $event->sub_zone }}</div>
                                            @endif
                                        </td>
                                        <td class="mono">{{ $event->camera_key }}</td>
                                        <td class="mono">{{ $event->track_id ?? '—' }}</td>
                                        <td>{{ $event->confidence !== null ? number_format($event->confidence * 100, 0) . '%' : '—' }}</td>
                                        <td style="color:var(--text-secondary);white-space:nowrap">{{ $event->occurred_at?->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px">
                        <span style="color:var(--text-muted);font-size:12px">
                            Showing {{ $events->firstItem() }}–{{ $events->lastItem() }} of {{ $events->total() }}
                        </span>
                        <div style="display:flex;gap:8px">
                            @if ($events->previousPageUrl())
                                <a href="{{ $events->previousPageUrl() }}" class="sv-btn sv-btn-secondary" style="padding:6px 12px;font-size:12px">
                                    <i class="bi bi-chevron-left"></i> Newer
                                </a>
                            @endif
                            @if ($events->nextPageUrl())
                                <a href="{{ $events->nextPageUrl() }}" class="sv-btn sv-btn-secondary" style="padding:6px 12px;font-size:12px">
                                    Older <i class="bi bi-chevron-right"></i>
                                </a>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

@endsection

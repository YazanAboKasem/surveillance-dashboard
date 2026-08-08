@extends('layouts.surveillance')

@section('title', 'Fleet Map')
@section('page-title', 'Fleet Map')

@push('styles')
    {{-- Leaflet CSS --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin="" />
    <style>
        /* ── Map Container ───────────────────────────────────── */
        .sv-map-wrapper {
            position: relative;
            width: 100%;
            height: calc(100vh - 80px);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border);
        }

        #fleet-map {
            width: 100%;
            height: 100%;
            background: var(--surface-2);
        }

        /* ── Map Stats Overlay ───────────────────────────────── */
        .sv-map-stats-overlay {
            position: absolute;
            top: 12px;
            left: 60px;
            z-index: 1000;
            display: flex;
            gap: 8px;
            pointer-events: none;
        }

        .sv-map-stat-chip {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            background: rgba(15, 17, 23, 0.88);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--text-primary, #fff);
            font-size: 12px;
            font-weight: 600;
            font-family: var(--font-mono, monospace);
            pointer-events: auto;
        }

        .sv-map-stat-chip .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .sv-map-stat-chip .dot.green { background: #22c55e; box-shadow: 0 0 8px rgba(34, 197, 94, 0.5); }
        .sv-map-stat-chip .dot.red { background: #ef4444; box-shadow: 0 0 8px rgba(239, 68, 68, 0.5); }
        .sv-map-stat-chip .dot.gray { background: #6b7280; }

        /* ── Custom Marker Styles ────────────────────────────── */
        .sv-marker-online,
        .sv-marker-offline,
        .sv-marker-nogps {
            position: relative;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: #fff;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(0,0,0,0.35);
            transition: transform 0.2s;
        }

        .sv-marker-online {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            border: 2px solid rgba(255,255,255,0.3);
        }
        .sv-marker-online::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            border: 2px solid rgba(34, 197, 94, 0.4);
            animation: sv-pulse-ring 2s infinite;
        }

        .sv-marker-offline {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: 2px solid rgba(255,255,255,0.2);
        }

        .sv-marker-nogps {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            border: 2px solid rgba(255,255,255,0.15);
        }

        @keyframes sv-pulse-ring {
            0% { transform: scale(1); opacity: 0.6; }
            70% { transform: scale(1.4); opacity: 0; }
            100% { transform: scale(1.4); opacity: 0; }
        }

        /* ── Leaflet Popup Override ──────────────────────────── */
        .leaflet-popup-content-wrapper {
            background: rgba(15, 17, 23, 0.95) !important;
            backdrop-filter: blur(12px) !important;
            border: 1px solid rgba(255,255,255,0.1) !important;
            border-radius: 12px !important;
            box-shadow: 0 12px 40px rgba(0,0,0,0.5) !important;
            color: #e2e8f0 !important;
            padding: 0 !important;
        }

        .leaflet-popup-tip {
            background: rgba(15, 17, 23, 0.95) !important;
            border: 1px solid rgba(255,255,255,0.08) !important;
        }

        .leaflet-popup-content {
            margin: 0 !important;
            min-width: 220px !important;
        }

        .sv-popup-inner {
            padding: 16px;
        }

        .sv-popup-name {
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sv-popup-name .badge {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .sv-popup-name .badge.online { background: rgba(34,197,94,0.2); color: #22c55e; }
        .sv-popup-name .badge.offline { background: rgba(239,68,68,0.2); color: #ef4444; }

        .sv-popup-meta {
            font-size: 11px;
            color: #94a3b8;
            margin-bottom: 12px;
        }

        .sv-popup-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-bottom: 12px;
        }

        .sv-popup-stat {
            background: rgba(255,255,255,0.04);
            border-radius: 6px;
            padding: 6px 8px;
            font-size: 10px;
        }

        .sv-popup-stat .label {
            color: #64748b;
            display: block;
            margin-bottom: 2px;
        }

        .sv-popup-stat .value {
            color: #e2e8f0;
            font-family: var(--font-mono, monospace);
            font-weight: 600;
        }

        .sv-popup-link {
            display: block;
            width: 100%;
            padding: 8px 12px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff !important;
            text-align: center;
            text-decoration: none !important;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            transition: opacity 0.2s;
        }

        .sv-popup-link:hover { opacity: 0.85; }

        /* ── Route Modal ─────────────────────────────────────── */
        .sv-route-modal-map {
            width: 100%;
            height: 400px;
            border-radius: 8px;
            border: 1px solid var(--border);
            margin-top: 12px;
        }

        .sv-route-controls {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .sv-route-controls .sv-input {
            max-width: 180px;
        }

        .sv-route-info {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
        }

        /* ── Auto-refresh indicator ──────────────────────────── */
        .sv-map-refresh-indicator {
            position: absolute;
            bottom: 12px;
            left: 60px;
            z-index: 1000;
            background: rgba(15, 17, 23, 0.85);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 11px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .sv-map-refresh-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #22c55e;
            animation: sv-blink 2s infinite;
        }

        @keyframes sv-blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
    </style>
@endpush

@section('content')

    <div class="sv-map-wrapper" id="sv-map-wrapper">
        {{-- Stats overlay --}}
        <div class="sv-map-stats-overlay" id="sv-map-stats">
            <div class="sv-map-stat-chip">
                <span class="dot green"></span>
                Online: <span id="map-count-online">0</span>
            </div>
            <div class="sv-map-stat-chip">
                <span class="dot red"></span>
                Offline: <span id="map-count-offline">0</span>
            </div>
            <div class="sv-map-stat-chip">
                <span class="dot gray"></span>
                No GPS: <span id="map-count-nogps">0</span>
            </div>
        </div>

        {{-- Map --}}
        <div id="fleet-map"></div>

        {{-- Refresh indicator --}}
        <div class="sv-map-refresh-indicator">
            <span class="sv-map-refresh-dot"></span>
            Live — auto-refresh every 10s
        </div>
    </div>

    {{-- Route History Modal --}}
    <div id="route-modal" class="sv-modal-backdrop hidden" onclick="closeRouteModal(event)">
        <div class="sv-modal-card" style="max-width: 800px; width: 95%; background: var(--surface-1);" onclick="event.stopPropagation()">
            <div class="sv-modal-header">
                <h3 class="sv-modal-title">
                    <i class="bi bi-signpost-2-fill" style="color:var(--accent)"></i>
                    <span id="route-modal-title">Route History</span>
                </h3>
                <button class="sv-modal-close" onclick="document.getElementById('route-modal').classList.add('hidden')">&times;</button>
            </div>
            <div class="sv-modal-body" style="padding: 20px;">
                <div class="sv-route-controls">
                    <label class="sv-label" style="min-width:auto">Date:</label>
                    <input type="date" id="route-date-input" class="sv-input" style="max-width:180px"
                           onchange="loadRouteForDate()" />
                    <button class="sv-btn sv-btn-secondary" onclick="loadRouteForDate()">
                        <i class="bi bi-arrow-clockwise"></i> Load
                    </button>
                    <span class="sv-route-info" id="route-point-count"></span>
                </div>
                <div id="route-modal-map" class="sv-route-modal-map"></div>
            </div>
            <div class="sv-modal-footer">
                <button class="sv-btn sv-btn-secondary" onclick="document.getElementById('route-modal').classList.add('hidden')">Close</button>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    {{-- Leaflet JS --}}
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""></script>

    <script src="{{ asset('js/map.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
@endpush

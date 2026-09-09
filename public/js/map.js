/**
 * RoadShield — Fleet Map (Google Maps JS API)
 * ====================================
 * Live fleet map showing all Jetson devices with color-coded markers.
 * Green = Online, Red = Offline, Gray = No GPS.
 * Auto-refreshes every 10 seconds.
 */

(function () {
    'use strict';

    // ── Config ──────────────────────────────────────────────────────
    const REFRESH_INTERVAL = 10000; // 10 seconds
    const API_URL = '/api/surveillance/map/devices';
    const ROUTE_API_URL = '/api/surveillance/map/route';
    const DEFAULT_CENTER = { lat: 25.2048, lng: 55.2708 }; // Dubai
    const DEFAULT_ZOOM = 10;

    // Dark map theme — mirrors the previous CartoDB "dark_all" tile look.
    const DARK_MAP_STYLE = [
        { elementType: 'geometry', stylers: [{ color: '#1a1d23' }] },
        { elementType: 'labels.text.stroke', stylers: [{ color: '#1a1d23' }] },
        { elementType: 'labels.text.fill', stylers: [{ color: '#8a8f98' }] },
        { featureType: 'administrative', elementType: 'geometry', stylers: [{ color: '#3a3f4b' }] },
        { featureType: 'administrative.country', elementType: 'labels.text.fill', stylers: [{ color: '#94a3b8' }] },
        { featureType: 'poi', elementType: 'labels.text.fill', stylers: [{ color: '#6b7280' }] },
        { featureType: 'poi.park', elementType: 'geometry', stylers: [{ color: '#1f2a20' }] },
        { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#2a2e37' }] },
        { featureType: 'road', elementType: 'labels.text.fill', stylers: [{ color: '#6b7280' }] },
        { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#38414e' }] },
        { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: '#212a37' }] },
        { featureType: 'road.highway', elementType: 'labels.text.fill', stylers: [{ color: '#9ca5b3' }] },
        { featureType: 'transit', elementType: 'geometry', stylers: [{ color: '#2a2e37' }] },
        { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0f172a' }] },
        { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#4b5563' }] },
    ];

    // ── State ───────────────────────────────────────────────────────
    let map = null;
    let markers = [];
    let sharedInfoWindow = null;
    let routeMap = null;
    let routePolylines = [];
    let routeMarkers = [];
    let routeInfoWindow = null;
    let currentRouteDeviceId = null;
    let refreshTimer = null;

    // ── Initialize Map (called by Google Maps JS API via ?callback=) ──
    window.initFleetMap = function () {
        const mapEl = document.getElementById('fleet-map');
        if (!mapEl) return;

        map = new google.maps.Map(mapEl, {
            center: DEFAULT_CENTER,
            zoom: DEFAULT_ZOOM,
            styles: DARK_MAP_STYLE,
            disableDefaultUI: true,
            zoomControl: true,
            zoomControlOptions: { position: google.maps.ControlPosition.RIGHT_TOP },
        });

        sharedInfoWindow = new google.maps.InfoWindow();

        // Initial load
        loadDevices();

        // Auto-refresh
        refreshTimer = setInterval(loadDevices, REFRESH_INTERVAL);
    };

    // ── Load Devices ────────────────────────────────────────────────
    function loadDevices() {
        fetch(API_URL)
            .then(r => r.json())
            .then(geojson => {
                renderMarkers(geojson);
                updateStats(geojson);
            })
            .catch(err => console.error('[FleetMap] Load error:', err));
    }

    // ── Render Markers ──────────────────────────────────────────────
    function renderMarkers(geojson) {
        markers.forEach(m => m.setMap(null));
        markers = [];

        const features = geojson.features || [];
        const bounds = new google.maps.LatLngBounds();
        let hasPoints = false;

        features.forEach(feature => {
            const props = feature.properties;
            const coords = feature.geometry.coordinates; // [lng, lat]
            const lat = coords[1];
            const lng = coords[0];

            if (!props.has_gps) {
                // Device has no GPS data — skip.
                return;
            }

            const position = { lat, lng };
            const color = props.is_online ? '#22c55e' : '#ef4444';

            const marker = new google.maps.Marker({
                position,
                map,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 12,
                    fillColor: color,
                    fillOpacity: 1,
                    strokeColor: '#fff',
                    strokeWeight: 2,
                },
            });

            // Build popup
            const statusBadge = props.is_online
                ? '<span class="badge online">Online</span>'
                : '<span class="badge offline">Offline</span>';

            const popupHtml = `
                <div class="sv-popup-inner">
                    <div class="sv-popup-name">
                        ${props.name}
                        ${statusBadge}
                    </div>
                    <div class="sv-popup-meta">
                        <i class="bi bi-geo-alt-fill"></i> ${props.location}
                        ${props.last_seen ? ' · Last seen: ' + props.last_seen : ''}
                    </div>
                    <div class="sv-popup-stats">
                        <div class="sv-popup-stat">
                            <span class="label">CPU</span>
                            <span class="value">${props.cpu}%</span>
                        </div>
                        <div class="sv-popup-stat">
                            <span class="label">RAM</span>
                            <span class="value">${props.ram}%</span>
                        </div>
                        <div class="sv-popup-stat">
                            <span class="label">Temp</span>
                            <span class="value">${props.temperature}°C</span>
                        </div>
                        <div class="sv-popup-stat">
                            <span class="label">Cameras</span>
                            <span class="value">${props.camera_count}</span>
                        </div>
                    </div>
                    <div style="display:flex;gap:6px">
                        <a href="${props.url}" class="sv-popup-link" style="flex:1">
                            <i class="bi bi-gear-fill"></i> Device Page
                        </a>
                        <button onclick="window.openRouteModal('${props.id}', '${props.name}')" class="sv-popup-link" style="flex:1;border:none;cursor:pointer;background:linear-gradient(135deg,#0ea5e9,#06b6d4)">
                            <i class="bi bi-signpost-2-fill"></i> Routes
                        </button>
                    </div>
                </div>
            `;

            marker.addListener('click', () => {
                sharedInfoWindow.setContent(popupHtml);
                sharedInfoWindow.open(map, marker);
            });

            markers.push(marker);
            bounds.extend(position);
            hasPoints = true;
        });

        // Fit bounds on first load only
        if (hasPoints && !window._mapFittedOnce) {
            map.fitBounds(bounds, 50);
            window._mapFittedOnce = true;
        }
    }

    // ── Update Stats ────────────────────────────────────────────────
    function updateStats(geojson) {
        const features = geojson.features || [];
        let online = 0, offline = 0, nogps = 0;

        features.forEach(f => {
            if (!f.properties.has_gps) {
                nogps++;
            } else if (f.properties.is_online) {
                online++;
            } else {
                offline++;
            }
        });

        setText('map-count-online', online);
        setText('map-count-offline', offline);
        setText('map-count-nogps', nogps);
    }

    // ── Route History Modal ─────────────────────────────────────────

    window.openRouteModal = function (deviceId, deviceName) {
        currentRouteDeviceId = deviceId;

        // Set modal title
        const titleEl = document.getElementById('route-modal-title');
        if (titleEl) titleEl.textContent = `Route History — ${deviceName}`;

        // Set default date to today (local time)
        const dateInput = document.getElementById('route-date-input');
        if (dateInput) {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            dateInput.value = `${year}-${month}-${day}`;
        }

        // Show modal
        document.getElementById('route-modal').classList.remove('hidden');

        // Initialize route map if not exists
        setTimeout(() => {
            if (!routeMap) {
                routeMap = new google.maps.Map(document.getElementById('route-modal-map'), {
                    center: DEFAULT_CENTER,
                    zoom: DEFAULT_ZOOM,
                    styles: DARK_MAP_STYLE,
                    disableDefaultUI: true,
                    zoomControl: true,
                });
                routeInfoWindow = new google.maps.InfoWindow();
            } else {
                google.maps.event.trigger(routeMap, 'resize');
            }

            loadRouteForDate();
        }, 200);
    };

    let currentRouteData = null;
    let selectedTripId = 'all';

    window.loadRouteForDate = function () {
        if (!currentRouteDeviceId || !routeMap) return;

        const dateInput = document.getElementById('route-date-input');
        const date = dateInput ? dateInput.value : new Date().toISOString().split('T')[0];

        const from = `${date} 00:00:00`;
        const to = `${date} 23:59:59`;

        fetch(`${ROUTE_API_URL}/${currentRouteDeviceId}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
            .then(r => r.json())
            .then(data => {
                currentRouteData = data;
                selectedTripId = 'all';
                renderTripsUI(data);
                renderRoute(data, 'all');
            })
            .catch(err => console.error('[FleetMap] Route load error:', err));
    };

    function renderTripsUI(data) {
        const wrapper = document.getElementById('route-trips-wrapper');
        const listEl = document.getElementById('route-trips-list');
        const summaryEl = document.getElementById('route-trips-summary');

        if (!wrapper || !listEl) return;

        const trips = data.trips || [];
        if (trips.length === 0) {
            wrapper.style.display = 'none';
            return;
        }

        wrapper.style.display = 'block';

        let totalDist = trips.reduce((acc, t) => acc + (t.distance_km || 0), 0);
        let totalDuration = trips.reduce((acc, t) => acc + (t.duration_minutes || 0), 0);
        if (summaryEl) {
            summaryEl.textContent = `الرحلات: ${trips.length} | المسافة: ${totalDist.toFixed(1)} كم | المدة: ${totalDuration} دقيقة`;
        }

        let html = `
            <button class="sv-trip-chip ${selectedTripId === 'all' ? 'active' : ''}" onclick="window.selectTrip('all')">
                <i class="bi bi-layers-fill"></i> جميع الرحلات (${trips.length})
            </button>
        `;

        trips.forEach(t => {
            const isActive = selectedTripId === t.trip_id ? 'active' : '';
            html += `
                <button class="sv-trip-chip ${isActive}" onclick="window.selectTrip('${t.trip_id}')">
                    <i class="bi bi-geo-fill"></i> ${t.title}
                    <span class="badge-meta">(${t.start_time_short} - ${t.end_time_short} · ${t.distance_km} كم)</span>
                </button>
            `;
        });

        listEl.innerHTML = html;
    }

    window.selectTrip = function (tripId) {
        selectedTripId = tripId;
        if (currentRouteData) {
            renderTripsUI(currentRouteData);
            renderRoute(currentRouteData, tripId);
        }
    };

    function clearRoute() {
        routePolylines.forEach(p => p.setMap(null));
        routePolylines = [];
        routeMarkers.forEach(m => m.setMap(null));
        routeMarkers = [];
    }

    function renderRoute(data, tripFilter = 'all') {
        clearRoute();

        const countEl = document.getElementById('route-point-count');
        const trips = data.trips || [];

        let activeTrips = trips;
        if (tripFilter !== 'all') {
            activeTrips = trips.filter(t => t.trip_id === tripFilter);
        }

        let pointsToDraw = [];
        activeTrips.forEach(t => {
            pointsToDraw = pointsToDraw.concat(t.coordinates || []);
        });

        if (pointsToDraw.length === 0) {
            let msg = 'No data recorded for this date.';
            if (data.available_dates && data.available_dates.length > 0) {
                msg += ` Available dates: ${data.available_dates.slice(0, 5).join(', ')}`;
            }
            if (countEl) countEl.textContent = msg;
            return;
        }

        if (countEl) {
            countEl.textContent = `${activeTrips.length} رحلة · ${pointsToDraw.length} نقطة مسجلة`;
        }

        const bounds = new google.maps.LatLngBounds();
        let hasPoints = false;

        activeTrips.forEach((trip, tIdx) => {
            const points = trip.coordinates || [];
            if (points.length === 0) return;

            const latLngs = points.map(p => ({ lat: p.lat, lng: p.lng }));
            latLngs.forEach(p => { bounds.extend(p); hasPoints = true; });

            const tripColor = getTripColor(tIdx);

            const polyline = new google.maps.Polyline({
                path: latLngs,
                strokeColor: tripColor,
                strokeWeight: 4,
                strokeOpacity: 0.85,
                map: routeMap,
            });
            polyline.addListener('click', (e) => {
                routeInfoWindow.setContent(`
                    <div class="sv-popup-inner" style="padding:10px">
                        <b>🚗 ${trip.title}</b><br>
                        <span style="font-size:11px;color:#94a3b8">
                            🕒 التوقيت: ${trip.start_time_short} - ${trip.end_time_short} (${trip.duration_minutes} دقيقة)<br>
                            📏 المسافة: ${trip.distance_km} كم<br>
                            ⚡ أعلى سرعة: ${trip.max_speed} كم/س
                        </span>
                    </div>
                `);
                routeInfoWindow.setPosition(e.latLng);
                routeInfoWindow.open(routeMap);
            });
            routePolylines.push(polyline);

            // Start marker
            const startMarker = new google.maps.Marker({
                position: latLngs[0],
                map: routeMap,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 9,
                    fillColor: '#22c55e',
                    fillOpacity: 1,
                    strokeColor: '#fff',
                    strokeWeight: 2,
                },
                label: { text: 'S', color: '#fff', fontSize: '10px', fontWeight: 'bold' },
            });
            startMarker.addListener('click', () => {
                routeInfoWindow.setContent(`
                    <div class="sv-popup-inner" style="padding:10px">
                        <b style="color:#22c55e">🚩 بداية الرحلة (${trip.title})</b><br>
                        <b>🕒 الوقت:</b> ${points[0].recorded_at}<br>
                        <b>🚗 السرعة:</b> ${points[0].speed !== null && points[0].speed !== undefined ? points[0].speed + ' كم/س' : '—'}<br>
                        <b>⛰️ الارتفاع:</b> ${points[0].altitude !== null && points[0].altitude !== undefined ? points[0].altitude + ' م' : '—'}
                    </div>
                `);
                routeInfoWindow.open(routeMap, startMarker);
            });
            routeMarkers.push(startMarker);

            // End marker
            const endMarker = new google.maps.Marker({
                position: latLngs[latLngs.length - 1],
                map: routeMap,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 9,
                    fillColor: '#ef4444',
                    fillOpacity: 1,
                    strokeColor: '#fff',
                    strokeWeight: 2,
                },
                label: { text: 'E', color: '#fff', fontSize: '10px', fontWeight: 'bold' },
            });
            endMarker.addListener('click', () => {
                routeInfoWindow.setContent(`
                    <div class="sv-popup-inner" style="padding:10px">
                        <b style="color:#ef4444">🏁 نهاية الرحلة (${trip.title})</b><br>
                        <b>🕒 الوقت:</b> ${points[points.length - 1].recorded_at}<br>
                        <b>🚗 السرعة:</b> ${points[points.length - 1].speed !== null && points[points.length - 1].speed !== undefined ? points[points.length - 1].speed + ' كم/س' : '—'}<br>
                        <b>⛰️ الارتفاع:</b> ${points[points.length - 1].altitude !== null && points[points.length - 1].altitude !== undefined ? points[points.length - 1].altitude + ' م' : '—'}
                    </div>
                `);
                routeInfoWindow.open(routeMap, endMarker);
            });
            routeMarkers.push(endMarker);

            // Intermediate clickable points
            const sampleStep = Math.max(1, Math.floor(points.length / 50));
            for (let i = 1; i < points.length - 1; i += sampleStep) {
                const pt = points[i];
                const ptMarker = new google.maps.Marker({
                    position: { lat: pt.lat, lng: pt.lng },
                    map: routeMap,
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 4,
                        fillColor: tripColor,
                        fillOpacity: 0.8,
                        strokeColor: '#fff',
                        strokeWeight: 1,
                    },
                });
                ptMarker.addListener('click', () => {
                    routeInfoWindow.setContent(`
                        <div class="sv-popup-inner" style="padding:10px">
                            <b>📍 نقطة على المسار (${trip.title})</b><br>
                            <b>🕒 الوقت:</b> ${pt.recorded_at}<br>
                            <b>🚗 السرعة:</b> ${pt.speed !== null && pt.speed !== undefined ? pt.speed + ' كم/س' : '—'}<br>
                            <b>⛰️ الارتفاع:</b> ${pt.altitude !== null && pt.altitude !== undefined ? pt.altitude + ' م' : '—'}
                        </div>
                    `);
                    routeInfoWindow.open(routeMap, ptMarker);
                });
                routeMarkers.push(ptMarker);
            }
        });

        if (hasPoints) {
            routeMap.fitBounds(bounds, 30);
        }
    }

    const TRIP_COLORS = ['#0ea5e9', '#8b5cf6', '#f59e0b', '#10b981', '#ec4899', '#6366f1', '#14b8a6'];
    function getTripColor(index) {
        return TRIP_COLORS[index % TRIP_COLORS.length];
    }


    // ── Close route modal ───────────────────────────────────────────
    window.closeRouteModal = function (e) {
        if (!e || e.target === document.getElementById('route-modal')) {
            document.getElementById('route-modal').classList.add('hidden');
        }
    };

    // ── Utility: Generate color gradient ────────────────────────────
    function generateGradient(startHex, endHex, steps) {
        const start = hexToRgb(startHex);
        const end = hexToRgb(endHex);
        const colors = [];

        for (let i = 0; i < steps; i++) {
            const ratio = i / Math.max(steps - 1, 1);
            const r = Math.round(start.r + (end.r - start.r) * ratio);
            const g = Math.round(start.g + (end.g - start.g) * ratio);
            const b = Math.round(start.b + (end.b - start.b) * ratio);
            colors.push(`rgb(${r},${g},${b})`);
        }

        return colors;
    }

    function hexToRgb(hex) {
        hex = hex.replace('#', '');
        return {
            r: parseInt(hex.substring(0, 2), 16),
            g: parseInt(hex.substring(2, 4), 16),
            b: parseInt(hex.substring(4, 6), 16),
        };
    }

    function setText(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    }

    // ── Boot ────────────────────────────────────────────────────────
    // initFleetMap() is invoked by the Google Maps JS API itself via the
    // `callback=initFleetMap` query param once the API script has loaded —
    // see the <script> tag at the bottom of map.blade.php. No manual boot
    // needed here (and none is possible, since `google` isn't defined yet
    // at DOMContentLoaded time when the API loads asynchronously).
})();

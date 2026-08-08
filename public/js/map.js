/**
 * RoadShield — Fleet Map (Leaflet.js)
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
    const DEFAULT_CENTER = [25.2048, 55.2708]; // Dubai
    const DEFAULT_ZOOM = 10;

    // ── State ───────────────────────────────────────────────────────
    let map = null;
    let markersLayer = null;
    let routeMap = null;
    let routeLayer = null;
    let routeMarkersLayer = null;
    let currentRouteDeviceId = null;
    let refreshTimer = null;

    // ── Initialize Map ──────────────────────────────────────────────
    function initMap() {
        const mapEl = document.getElementById('fleet-map');
        if (!mapEl) return;

        map = L.map('fleet-map', {
            center: DEFAULT_CENTER,
            zoom: DEFAULT_ZOOM,
            zoomControl: false,
            attributionControl: false,
        });

        // Dark tile layer
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 19,
            subdomains: 'abcd',
        }).addTo(map);

        // Zoom control — top-right
        L.control.zoom({ position: 'topright' }).addTo(map);

        // Attribution — bottom-right
        L.control.attribution({ position: 'bottomright', prefix: false })
            .addAttribution('© <a href="https://carto.com/">CARTO</a> · © <a href="https://osm.org/copyright">OSM</a>')
            .addTo(map);

        markersLayer = L.layerGroup().addTo(map);

        // Initial load
        loadDevices();

        // Auto-refresh
        refreshTimer = setInterval(loadDevices, REFRESH_INTERVAL);
    }

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
        markersLayer.clearLayers();

        const features = geojson.features || [];
        const boundsArr = [];

        features.forEach(feature => {
            const props = feature.properties;
            const coords = feature.geometry.coordinates; // [lng, lat]
            const lat = coords[1];
            const lng = coords[0];

            if (!props.has_gps) {
                // Device has no GPS data — skip or place at 0,0 marker area
                return;
            }

            // Create custom icon
            const markerClass = props.is_online ? 'sv-marker-online' : 'sv-marker-offline';
            const icon = L.divIcon({
                className: '',
                html: `<div class="${markerClass}"><i class="bi bi-truck" style="font-size:14px"></i></div>`,
                iconSize: [36, 36],
                iconAnchor: [18, 18],
                popupAnchor: [0, -22],
            });

            const marker = L.marker([lat, lng], { icon }).addTo(markersLayer);

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

            marker.bindPopup(popupHtml, { maxWidth: 280, closeButton: false });
            boundsArr.push([lat, lng]);
        });

        // Fit bounds if we have markers
        if (boundsArr.length > 0) {
            // Only fit on first load
            if (!window._mapFittedOnce) {
                map.fitBounds(boundsArr, { padding: [50, 50], maxZoom: 14 });
                window._mapFittedOnce = true;
            }
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

        // Set default date to today
        const dateInput = document.getElementById('route-date-input');
        if (dateInput) {
            dateInput.value = new Date().toISOString().split('T')[0];
        }

        // Show modal
        document.getElementById('route-modal').classList.remove('hidden');

        // Initialize route map if not exists
        setTimeout(() => {
            if (!routeMap) {
                routeMap = L.map('route-modal-map', {
                    center: DEFAULT_CENTER,
                    zoom: DEFAULT_ZOOM,
                    zoomControl: true,
                    attributionControl: false,
                });

                L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                    maxZoom: 19,
                    subdomains: 'abcd',
                }).addTo(routeMap);

                routeLayer = L.layerGroup().addTo(routeMap);
                routeMarkersLayer = L.layerGroup().addTo(routeMap);
            } else {
                routeMap.invalidateSize();
            }

            loadRouteForDate();
        }, 200);
    };

    window.loadRouteForDate = function () {
        if (!currentRouteDeviceId || !routeMap) return;

        const dateInput = document.getElementById('route-date-input');
        const date = dateInput ? dateInput.value : new Date().toISOString().split('T')[0];

        const from = `${date} 00:00:00`;
        const to = `${date} 23:59:59`;

        fetch(`${ROUTE_API_URL}/${currentRouteDeviceId}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
            .then(r => r.json())
            .then(data => {
                renderRoute(data);
            })
            .catch(err => console.error('[FleetMap] Route load error:', err));
    };

    function renderRoute(data) {
        routeLayer.clearLayers();
        routeMarkersLayer.clearLayers();

        const points = data.coordinates || [];
        const countEl = document.getElementById('route-point-count');

        if (points.length === 0) {
            if (countEl) countEl.textContent = 'No data for this date.';
            return;
        }

        if (countEl) countEl.textContent = `${points.length} points recorded`;

        // Build polyline
        const latLngs = points.map(p => [p.lat, p.lng]);

        // Gradient polyline: draw segments with color gradient
        const gradient = generateGradient('#0ea5e9', '#8b5cf6', latLngs.length);

        for (let i = 0; i < latLngs.length - 1; i++) {
            L.polyline([latLngs[i], latLngs[i + 1]], {
                color: gradient[i],
                weight: 3,
                opacity: 0.85,
            }).addTo(routeLayer);
        }

        // Start marker
        const startIcon = L.divIcon({
            className: '',
            html: '<div style="width:14px;height:14px;border-radius:50%;background:#22c55e;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3)"></div>',
            iconSize: [14, 14],
            iconAnchor: [7, 7],
        });
        L.marker(latLngs[0], { icon: startIcon })
            .bindPopup(`<div class="sv-popup-inner" style="padding:8px"><b>Start</b><br>${points[0].recorded_at}</div>`)
            .addTo(routeMarkersLayer);

        // End marker
        const endIcon = L.divIcon({
            className: '',
            html: '<div style="width:14px;height:14px;border-radius:50%;background:#ef4444;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3)"></div>',
            iconSize: [14, 14],
            iconAnchor: [7, 7],
        });
        L.marker(latLngs[latLngs.length - 1], { icon: endIcon })
            .bindPopup(`<div class="sv-popup-inner" style="padding:8px"><b>End</b><br>${points[points.length - 1].recorded_at}</div>`)
            .addTo(routeMarkersLayer);

        // Fit bounds
        routeMap.fitBounds(latLngs, { padding: [30, 30] });
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
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMap);
    } else {
        initMap();
    }
})();

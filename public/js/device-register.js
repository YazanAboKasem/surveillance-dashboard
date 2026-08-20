/**
 * RoadShield — Device Discovery & Registration
 * Handles the "Discovered Devices" list (auto-refreshing) and the
 * Register modal that turns a pending device into a fully configured one.
 */
(function () {
    'use strict';

    let cameraRowCount = 0;
    let pendingPollInterval = null;

    // ─── Discovered devices polling ──────────────────────────────────────────
    window.addEventListener('DOMContentLoaded', function () {
        if (document.getElementById('sv-pending-devices-list')) {
            pollPendingDevices();
            pendingPollInterval = setInterval(pollPendingDevices, 8000);
        }
    });

    function pollPendingDevices() {
        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';

        fetch('/api/surveillance/devices/pending', {
            headers: { 'Authorization': `Bearer ${token}` }
        })
        .then(r => r.json())
        .then(data => renderPendingDevices(data.pending || []))
        .catch(err => console.error('[Devices] Failed to poll pending devices:', err));
    }

    function renderPendingDevices(pending) {
        const section = document.getElementById('sv-pending-devices-section');
        const list = document.getElementById('sv-pending-devices-list');
        if (!list) return;

        if (section) section.style.display = pending.length ? '' : 'none';

        list.innerHTML = pending.map(p => `
            <div class="sv-device-card pending" id="pending-device-card-${p.device_id}">
                <div class="sv-device-card-header">
                    <div class="sv-device-card-info">
                        <div class="sv-device-status-indicator online">
                            <span class="sv-device-status-dot"></span>
                        </div>
                        <div class="sv-device-card-text">
                            <div class="sv-device-card-name mono">${escapeHtml(p.device_id)}</div>
                            <div class="sv-device-card-meta">
                                <i class="bi bi-hdd-network"></i> ${escapeHtml(p.hostname || 'Unknown host')}
                                <span class="sv-device-card-sep">·</span>
                                Last seen ${escapeHtml(p.last_seen_at || '—')}
                            </div>
                        </div>
                    </div>
                    <button class="sv-btn sv-btn-accent" onclick="openDeviceRegisterModal('${escapeHtml(p.device_id)}')">
                        <i class="bi bi-plus-circle-fill"></i> Register
                    </button>
                </div>
            </div>`).join('');
    }

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    // ─── Register modal ───────────────────────────────────────────────────────
    window.openDeviceRegisterModal = function (deviceId) {
        const modal = document.getElementById('device-register-modal');
        if (!modal) return;

        document.getElementById('register-modal-device-id').textContent = deviceId;
        modal.dataset.deviceId = deviceId;
        document.getElementById('reg-device-name').value = '';
        document.getElementById('reg-device-location').value = '';
        document.getElementById('reg-device-hls-port').value = '8888';
        document.getElementById('reg-device-webrtc-port').value = '8889';
        document.getElementById('register-camera-rows').innerHTML = '';
        document.getElementById('device-register-error-msg').classList.add('hidden');
        cameraRowCount = 0;

        // Start with one camera row pre-filled — most devices have 1-3 cameras
        addRegisterCameraRow();

        modal.classList.remove('hidden');
    };

    window.closeDeviceRegisterModal = function () {
        document.getElementById('device-register-modal')?.classList.add('hidden');
    };

    window.addRegisterCameraRow = function () {
        cameraRowCount++;
        const idx = cameraRowCount;
        const defaultKey = `cam${idx}`;

        const row = document.createElement('div');
        row.className = 'sv-camera-row';
        row.id = `register-camera-row-${idx}`;
        row.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px;border:1px solid var(--border);border-radius:8px;margin-top:8px';
        row.innerHTML = `
            <input type="text" class="sv-input-sm" style="width:70px" placeholder="cam1" value="${defaultKey}" data-field="camera_key" title="Camera key (must match the device's local CAMERAS id)">
            <input type="text" class="sv-input-sm" style="flex:1;min-width:120px" placeholder="Label (e.g. Front View)" data-field="label">
            <input type="text" class="sv-input-sm" style="width:130px" placeholder="IP address" data-field="ip">
            <input type="text" class="sv-input-sm" style="width:90px" placeholder="Username" data-field="username">
            <input type="password" class="sv-input-sm" style="width:90px" placeholder="Password" data-field="password">
            <input type="number" class="sv-input-sm" style="width:60px" placeholder="Ch." value="1" min="1" data-field="channel">
            <label class="sv-checkbox-label" style="margin:0">
                <input type="checkbox" data-field="ptz"><span>PTZ</span>
            </label>
            <button type="button" class="sv-btn-sm sv-btn-danger" onclick="document.getElementById('register-camera-row-${idx}').remove()" title="Remove camera">
                <i class="bi bi-trash"></i>
            </button>`;

        document.getElementById('register-camera-rows').appendChild(row);
    };

    window.submitDeviceRegisterForm = function (e) {
        e.preventDefault();

        const modal = document.getElementById('device-register-modal');
        const deviceId = modal?.dataset.deviceId;
        const errLabel = document.getElementById('device-register-error-msg');
        errLabel.classList.add('hidden');

        if (!deviceId) return;

        const cameras = Array.from(document.querySelectorAll('#register-camera-rows .sv-camera-row')).map(row => {
            const get = (field) => row.querySelector(`[data-field="${field}"]`);
            return {
                camera_key: get('camera_key').value.trim(),
                label: get('label').value.trim(),
                ip: get('ip').value.trim(),
                username: get('username').value.trim(),
                password: get('password').value,
                channel: parseInt(get('channel').value, 10) || 1,
                ptz: get('ptz').checked,
            };
        }).filter(c => c.camera_key);

        if (cameras.length === 0) {
            errLabel.textContent = 'Add at least one camera.';
            errLabel.classList.remove('hidden');
            return;
        }

        const payload = {
            name: document.getElementById('reg-device-name').value.trim(),
            location: document.getElementById('reg-device-location').value.trim(),
            hls_port: parseInt(document.getElementById('reg-device-hls-port').value, 10) || 8888,
            webrtc_port: parseInt(document.getElementById('reg-device-webrtc-port').value, 10) || 8889,
            cameras: cameras,
        };

        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

        fetch(`/api/surveillance/devices/${encodeURIComponent(deviceId)}/register`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(payload),
        })
        .then(r => {
            if (!r.ok) return r.json().then(err => { throw new Error(err.error || (err.errors && Object.values(err.errors)[0]?.[0]) || 'Registration failed') });
            return r.json();
        })
        .then(data => {
            if (data.success) {
                window.location.href = data.redirect || window.location.href;
            }
        })
        .catch(err => {
            errLabel.textContent = err.message;
            errLabel.classList.remove('hidden');
        });
    };

})();

/**
 * RoadShield — Device Discovery & Registration
 * Handles the "Discovered Devices" list (auto-refreshing) and the
 * Register modal that turns a pending device into a fully configured one.
 */
(function () {
    'use strict';

    let cameraRowCount = 0;
    let pendingPollInterval = null;

    // ─── Delete device ────────────────────────────────────────────────────────
    window.deleteDevice = function (deviceId, deviceName) {
        if (!confirm(`Delete "${deviceName}" (${deviceId})? This removes it and its cameras from the dashboard. The physical device itself is not affected — if it's still running it will reappear under Discovered Devices.`)) {
            return;
        }

        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

        fetch(`/api/surveillance/devices/${encodeURIComponent(deviceId)}`, {
            method: 'DELETE',
            headers: {
                'Authorization': `Bearer ${token}`,
                'X-CSRF-TOKEN': csrf,
            },
        })
        .then(r => {
            if (!r.ok) return r.json().then(err => { throw new Error(err.error || 'Delete failed') });
            return r.json();
        })
        .then(data => {
            if (data.success) {
                document.getElementById(`device-card-${deviceId}`)?.remove();
            }
        })
        .catch(err => alert(err.message));
    };

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

    // ─── Register / Edit modal (same modal, two modes) ───────────────────────
    function resetModalChrome(deviceId) {
        document.getElementById('register-modal-device-id').textContent = deviceId;
        document.getElementById('register-camera-rows').innerHTML = '';
        document.getElementById('device-register-error-msg').classList.add('hidden');
        cameraRowCount = 0;
    }

    window.openDeviceRegisterModal = function (deviceId) {
        const modal = document.getElementById('device-register-modal');
        if (!modal) return;

        modal.dataset.deviceId = deviceId;
        modal.dataset.mode = 'register';
        resetModalChrome(deviceId);

        document.getElementById('register-modal-icon').className = 'bi bi-plus-circle-fill';
        document.getElementById('register-modal-title-text').textContent = 'Register Device';
        document.getElementById('register-modal-submit-text').textContent = 'Register Device';
        document.getElementById('register-modal-hint').textContent =
            "This device connected on its own with a unique auto-generated id. Give it a name and its camera details to finish setup — no file edits needed.";

        document.getElementById('reg-device-name').value = '';
        document.getElementById('reg-device-location').value = '';
        document.getElementById('reg-device-hls-port').value = '8888';
        document.getElementById('reg-device-webrtc-port').value = '8889';

        // Start with one camera row pre-filled — most devices have 1-3 cameras
        addRegisterCameraRow();

        modal.classList.remove('hidden');
    };

    window.openDeviceEditModal = function (deviceId) {
        const modal = document.getElementById('device-register-modal');
        if (!modal) return;

        modal.dataset.deviceId = deviceId;
        modal.dataset.mode = 'edit';
        resetModalChrome(deviceId);

        document.getElementById('register-modal-icon').className = 'bi bi-pencil-fill';
        document.getElementById('register-modal-title-text').textContent = 'Edit Device';
        document.getElementById('register-modal-submit-text').textContent = 'Save Changes';
        document.getElementById('register-modal-hint').textContent =
            'Update this device\'s details or camera list. Leave a camera password blank to keep its current value.';

        modal.classList.remove('hidden');

        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        fetch(`/api/surveillance/devices/${encodeURIComponent(deviceId)}`, {
            headers: { 'Authorization': `Bearer ${token}` }
        })
        .then(r => {
            if (!r.ok) throw new Error('Failed to load device details');
            return r.json();
        })
        .then(data => {
            document.getElementById('reg-device-name').value = data.name || '';
            document.getElementById('reg-device-location').value = data.location || '';
            document.getElementById('reg-device-hls-port').value = data.hls_port || 8888;
            document.getElementById('reg-device-webrtc-port').value = data.webrtc_port || 8889;

            (data.cameras || []).forEach(cam => addRegisterCameraRow(cam));
            if ((data.cameras || []).length === 0) {
                addRegisterCameraRow();
            }
        })
        .catch(err => {
            const errLabel = document.getElementById('device-register-error-msg');
            errLabel.textContent = err.message;
            errLabel.classList.remove('hidden');
        });
    };

    window.closeDeviceRegisterModal = function () {
        document.getElementById('device-register-modal')?.classList.add('hidden');
    };

    window.addRegisterCameraRow = function (prefill) {
        cameraRowCount++;
        const idx = cameraRowCount;
        const isEdit = document.getElementById('device-register-modal')?.dataset.mode === 'edit';
        const v = prefill || {};
        const defaultKey = v.camera_key || `cam${idx}`;

        const row = document.createElement('div');
        row.className = 'sv-camera-row';
        row.id = `register-camera-row-${idx}`;
        row.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px;border:1px solid var(--border);border-radius:8px;margin-top:8px';
        const camType = v.type || 'hikvision';
        row.innerHTML = `
            <input type="text" class="sv-input-sm" style="width:70px" placeholder="cam1" value="${escapeAttr(defaultKey)}" data-field="camera_key" title="Camera key (must match the device's local CAMERAS id)">
            <input type="text" class="sv-input-sm" style="flex:1;min-width:120px" placeholder="Label (e.g. Front View)" value="${escapeAttr(v.label || '')}" data-field="label">
            <select class="sv-input-sm" style="width:100px" data-field="type" title="Determines the RTSP URL layout used to reach this camera">
                <option value="hikvision" ${camType === 'hikvision' ? 'selected' : ''}>Hikvision</option>
                <option value="generic" ${camType === 'generic' ? 'selected' : ''}>Generic/ONVIF</option>
            </select>
            <input type="text" class="sv-input-sm" style="width:120px" placeholder="IP address" value="${escapeAttr(v.ip || '')}" data-field="ip">
            <input type="number" class="sv-input-sm" style="width:70px" placeholder="RTSP port" value="${v.rtsp_port || 554}" min="1" data-field="rtsp_port" title="RTSP port">
            <input type="text" class="sv-input-sm" style="width:90px" placeholder="Username" value="${escapeAttr(v.username || '')}" data-field="username">
            <input type="password" class="sv-input-sm" style="width:90px" placeholder="${isEdit ? 'Keep current' : 'Password'}" data-field="password">
            <input type="number" class="sv-input-sm" style="width:50px" placeholder="Ch." value="${v.channel || 1}" min="1" data-field="channel" title="Channel number">
            <label class="sv-checkbox-label" style="margin:0">
                <input type="checkbox" data-field="ptz" ${v.ptz ? 'checked' : ''}><span>PTZ</span>
            </label>
            <button type="button" class="sv-btn-sm sv-btn-danger" onclick="document.getElementById('register-camera-row-${idx}').remove()" title="Remove camera">
                <i class="bi bi-trash"></i>
            </button>`;

        document.getElementById('register-camera-rows').appendChild(row);
    };

    function escapeAttr(text) {
        return String(text).replace(/"/g, '&quot;');
    }

    window.submitDeviceRegisterForm = function (e) {
        e.preventDefault();

        const modal = document.getElementById('device-register-modal');
        const deviceId = modal?.dataset.deviceId;
        const mode = modal?.dataset.mode || 'register';
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
                type: get('type').value,
                rtsp_port: parseInt(get('rtsp_port').value, 10) || 554,
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

        const url = mode === 'edit'
            ? `/api/surveillance/devices/${encodeURIComponent(deviceId)}`
            : `/api/surveillance/devices/${encodeURIComponent(deviceId)}/register`;
        const method = mode === 'edit' ? 'PUT' : 'POST';

        fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(payload),
        })
        .then(r => {
            if (!r.ok) return r.json().then(err => { throw new Error(err.error || (err.errors && Object.values(err.errors)[0]?.[0]) || 'Save failed') });
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

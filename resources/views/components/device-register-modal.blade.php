<div id="device-register-modal" class="sv-modal-backdrop hidden">
    <div class="sv-modal-card">
        <div class="sv-modal-header">
            <h3 class="sv-modal-title">
                <i class="bi bi-plus-circle-fill" style="color:var(--accent)"></i>
                Register Device — <span id="register-modal-device-id" class="mono"></span>
            </h3>
            <button class="sv-modal-close" onclick="closeDeviceRegisterModal()">&times;</button>
        </div>
        <form id="device-register-form" onsubmit="submitDeviceRegisterForm(event)">
            <div class="sv-modal-body">

                <div class="sv-form-group" style="background:var(--surface-2);border-radius:8px;padding:12px 16px;border:1px solid var(--border);margin-bottom:16px">
                    <div style="display:flex;align-items:center;gap:8px;color:var(--text-muted);font-size:0.85rem">
                        <i class="bi bi-info-circle-fill" style="color:var(--accent)"></i>
                        <span>This device connected on its own with a unique auto-generated id. Give it a name and its camera details to finish setup — no file edits needed.</span>
                    </div>
                </div>

                <div class="sv-form-group">
                    <label class="sv-label">Device Name</label>
                    <input type="text" id="reg-device-name" class="sv-input" placeholder="e.g. Rock 5B — Main Gate" required>
                </div>

                <div class="sv-form-group">
                    <label class="sv-label">Location</label>
                    <input type="text" id="reg-device-location" class="sv-input" placeholder="e.g. Front Entrance">
                </div>

                <div class="sv-form-group" style="display:flex;gap:12px">
                    <div style="flex:1">
                        <label class="sv-label">HLS Port</label>
                        <input type="number" id="reg-device-hls-port" class="sv-input" value="8888">
                    </div>
                    <div style="flex:1">
                        <label class="sv-label">WebRTC Port</label>
                        <input type="number" id="reg-device-webrtc-port" class="sv-input" value="8889">
                    </div>
                </div>

                <div class="sv-form-group">
                    <label class="sv-label" style="display:flex;align-items:center;justify-content:space-between">
                        <span>Cameras</span>
                        <button type="button" class="sv-btn-sm sv-btn-secondary" onclick="addRegisterCameraRow()">
                            <i class="bi bi-plus-lg"></i> Add Camera
                        </button>
                    </label>
                    <div id="register-camera-rows"></div>
                </div>

            </div>
            <div class="sv-modal-footer">
                <div class="sv-modal-error hidden" id="device-register-error-msg"></div>
                <button type="button" class="sv-btn sv-btn-secondary" onclick="closeDeviceRegisterModal()">Cancel</button>
                <button type="submit" class="sv-btn sv-btn-accent">
                    <i class="bi bi-check-circle-fill"></i> Register Device
                </button>
            </div>
        </form>
    </div>
</div>

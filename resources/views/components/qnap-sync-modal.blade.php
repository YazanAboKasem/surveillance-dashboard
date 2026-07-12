<div id="qnap-sync-modal" class="sv-modal-backdrop hidden">
    <div class="sv-modal-card">
        <div class="sv-modal-header">
            <h3 class="sv-modal-title">
                <i class="bi bi-hdd-network-fill" style="color:var(--accent)"></i>
                Sync Recordings to QNAP NAS
            </h3>
            <button class="sv-modal-close" onclick="closeSyncModal()">&times;</button>
        </div>
        <form id="qnap-sync-form" onsubmit="submitSyncForm(event)">
            <div class="sv-modal-body">
                <!-- Host & Protocol -->
                <div class="sv-form-row">
                    <div class="sv-form-group flex-3">
                        <label for="qnap-host" class="sv-label">QNAP Host/IP</label>
                        <input type="text" id="qnap-host" class="sv-input" required placeholder="e.g. nas.local or 192.168.1.100">
                    </div>
                    <div class="sv-form-group flex-1">
                        <label for="qnap-port" class="sv-label">Port</label>
                        <input type="number" id="qnap-port" class="sv-input" value="443" required>
                    </div>
                    <div class="sv-form-group flex-1">
                        <label for="qnap-protocol" class="sv-label">Protocol</label>
                        <select id="qnap-protocol" class="sv-input">
                            <option value="https">HTTPS</option>
                            <option value="http">HTTP</option>
                        </select>
                    </div>
                </div>

                <!-- Username & Password -->
                <div class="sv-form-row">
                    <div class="sv-form-group">
                        <label for="qnap-username" class="sv-label">Username</label>
                        <input type="text" id="qnap-username" class="sv-input" required autocomplete="username">
                    </div>
                    <div class="sv-form-group">
                        <label for="qnap-password" class="sv-label">Password</label>
                        <input type="password" id="qnap-password" class="sv-input" required autocomplete="current-password">
                    </div>
                </div>

                <!-- Remote Path -->
                <div class="sv-form-group">
                    <label for="qnap-remote-path" class="sv-label">Remote Path</label>
                    <input type="text" id="qnap-remote-path" class="sv-input" value="/Recordings/RoadShield/" required>
                </div>

                <!-- Sync Options -->
                <div class="sv-form-group">
                    <label class="sv-label">Sync Options</label>
                    <div class="sv-radio-group">
                        <label class="sv-radio-label">
                            <input type="radio" name="sync-scope" value="all" checked onchange="toggleScopeInputs()">
                            <span>All recordings</span>
                        </label>
                        <label class="sv-radio-label">
                            <input type="radio" name="sync-scope" value="today" onchange="toggleScopeInputs()">
                            <span>Only today's recordings</span>
                        </label>
                        <label class="sv-radio-label">
                            <input type="radio" name="sync-scope" value="last_n_days" onchange="toggleScopeInputs()">
                            <span>Only last N days:</span>
                            <input type="number" id="sync-days" class="sv-input-inline" value="7" min="1" disabled>
                        </label>
                        <label class="sv-radio-label">
                            <input type="radio" name="sync-scope" value="cameras" onchange="toggleScopeInputs()">
                            <span>Only specific cameras</span>
                        </label>
                    </div>
                </div>

                <!-- Cameras Selection (hidden by default) -->
                <div class="sv-form-group hidden" id="cameras-selection-row">
                    <label class="sv-label">Select Cameras</label>
                    <div class="sv-checkbox-group">
                        @foreach(config('surveillance.cameras', []) as $cam)
                            @if($cam['enabled'] ?? false)
                            <label class="sv-checkbox-label">
                                <input type="checkbox" name="sync-cameras" value="{{ $cam['id'] }}" checked>
                                <span>{{ $cam['label'] }}</span>
                            </label>
                            @endif
                        @endforeach
                    </div>
                </div>

                <!-- Extra Toggles -->
                <div class="sv-form-group checkbox-only">
                    <label class="sv-checkbox-label">
                        <input type="checkbox" id="delete-after-upload" checked>
                        <span>Delete local files after successful upload</span>
                    </label>
                    <label class="sv-checkbox-label">
                        <input type="checkbox" id="overwrite-existing">
                        <span>Overwrite existing files on QNAP</span>
                    </label>
                    <label class="sv-checkbox-label">
                        <input type="checkbox" id="remember-settings" checked>
                        <span>Remember settings (encrypted on server)</span>
                    </label>
                </div>
            </div>
            <div class="sv-modal-footer">
                <div class="sv-modal-error hidden" id="qnap-error-msg"></div>
                <button type="button" class="sv-btn sv-btn-secondary" onclick="closeSyncModal()">Cancel</button>
                <button type="submit" class="sv-btn sv-btn-accent">
                    <i class="bi bi-play-fill"></i> Start Sync
                </button>
            </div>
        </form>
    </div>
</div>

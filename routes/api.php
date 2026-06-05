<?php

use App\Http\Controllers\CameraController;
use App\Http\Controllers\TunnelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RoadShield Surveillance — API Routes
|--------------------------------------------------------------------------
|
| These routes are called by connect-to-server.sh and camera-control.py.
| Authentication: Bearer token from SURVEILLANCE_TOKEN in .env
|
*/

// ── Tunnel URL Registration ───────────────────────────────────────────────────
Route::post('/surveillance/register-tunnel',   [TunnelController::class, 'register']);
Route::delete('/surveillance/register-tunnel', [TunnelController::class, 'clear']);
Route::get('/surveillance/tunnel-status',      [TunnelController::class, 'status']);

// ── Camera PTZ Control ────────────────────────────────────────────────────────
// Browser sends commands → queued in cache → camera-control.py executes locally
Route::post('/surveillance/cameras/{id}/ptz',       [CameraController::class, 'ptzCommand']);
Route::get('/surveillance/cameras/{id}/ptz/poll',   [CameraController::class, 'ptzPoll']);
Route::post('/surveillance/cameras/{id}/ptz/ack',   [CameraController::class, 'ptzAck']);
Route::get('/surveillance/cameras/{id}/status',     [CameraController::class, 'status']);

// ── Camera Dynamic Settings ───────────────────────────────────────────────────
Route::post('/surveillance/cameras/{id}/settings',   [CameraController::class, 'updateSettings']);
Route::get('/surveillance/cameras/settings',        [CameraController::class, 'getAllSettings']);


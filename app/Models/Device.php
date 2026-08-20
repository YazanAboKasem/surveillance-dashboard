<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    protected $fillable = [
        'device_id',
        'name',
        'location',
        'host',
        'hls_port',
        'webrtc_port',
        'hls_base_url',
        'webrtc_base_url',
        'tunnel_cache_key',
        'api_token',
        'status',
        'hostname',
        'first_seen_at',
        'last_seen_at',
        'registered_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'registered_at' => 'datetime',
    ];

    public function cameras(): HasMany
    {
        return $this->hasMany(DeviceCamera::class);
    }

    public function isRegistered(): bool
    {
        return $this->status === 'registered';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}

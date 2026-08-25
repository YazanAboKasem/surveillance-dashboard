<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCamera extends Model
{
    protected $fillable = [
        'device_id',
        'camera_key',
        'label',
        'role',
        'ip',
        'username',
        'password',
        'channel',
        'type',
        'rtsp_port',
        'rtsp_main_url',
        'rtsp_sub_url',
        'ptz',
        'enabled',
    ];

    protected $casts = [
        'ptz' => 'boolean',
        'enabled' => 'boolean',
    ];

    protected $hidden = [
        'password',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}

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
        'ip',
        'username',
        'password',
        'channel',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLocationLog extends Model
{
    protected $fillable = [
        'device_id',
        'latitude',
        'longitude',
        'speed',
        'altitude',
        'recorded_at',
    ];

    protected $casts = [
        'latitude'    => 'float',
        'longitude'   => 'float',
        'speed'       => 'float',
        'altitude'    => 'float',
        'recorded_at' => 'datetime',
    ];
}

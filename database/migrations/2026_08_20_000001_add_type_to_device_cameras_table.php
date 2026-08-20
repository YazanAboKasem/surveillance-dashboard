<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Needed so the device itself can pull its camera config from Laravel
     * and build the right RTSP URL — Hikvision cameras and generic/ONVIF
     * cameras use different URL layouts and default ports.
     */
    public function up(): void
    {
        Schema::table('device_cameras', function (Blueprint $table) {
            $table->string('type')->default('hikvision')->after('channel'); // hikvision | generic
            $table->unsignedInteger('rtsp_port')->default(554)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('device_cameras', function (Blueprint $table) {
            $table->dropColumn(['type', 'rtsp_port']);
        });
    }
};

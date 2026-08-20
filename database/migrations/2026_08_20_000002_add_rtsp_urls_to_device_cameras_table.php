<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a camera be configured with an explicit full RTSP URL for its
     * Main/Sub streams instead of relying on the ip+type+channel+rtsp_port
     * composition — simpler and more robust for cameras that don't follow
     * either the Hikvision channel convention or the generic /0,/1 guess.
     * When set, these take priority over the composed URL in
     * sync_camera_config.py; ip/username/password/channel/type are still
     * used for PTZ control and as a fallback URL builder.
     */
    public function up(): void
    {
        Schema::table('device_cameras', function (Blueprint $table) {
            $table->string('rtsp_main_url')->nullable()->after('rtsp_port');
            $table->string('rtsp_sub_url')->nullable()->after('rtsp_main_url');
        });
    }

    public function down(): void
    {
        Schema::table('device_cameras', function (Blueprint $table) {
            $table->dropColumn(['rtsp_main_url', 'rtsp_sub_url']);
        });
    }
};

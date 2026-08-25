<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal Event shell (RoadShield AI spec §26/§28/§30). zone_id is a
     * plain string (no FK) since WorkZone assignment isn't wired up yet in
     * this phase — the AI PoC only reports device/camera-scoped events.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('zone_id')->nullable();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('camera_key');
            $table->string('event_type');
            $table->string('sub_zone')->nullable(); // DIVERSION_ZONE | RED_ZONE
            $table->string('track_id')->nullable();
            $table->float('confidence')->nullable();
            $table->json('metadata')->nullable();
            $table->string('snapshot_path')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal WorkZone shell (RoadShield AI spec §16) — one TMA per zone in
     * this phase. Deliberately schema-only: no diversion/red-zone geometry
     * yet, that's a future phase built on top of this table.
     */
    public function up(): void
    {
        Schema::create('work_zones', function (Blueprint $table) {
            $table->id();
            $table->string('zone_id')->unique();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('status')->default('ACTIVE'); // ACTIVE | COMPLETED
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_zones');
    }
};

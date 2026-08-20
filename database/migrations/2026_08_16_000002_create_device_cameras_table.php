<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_cameras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('camera_key'); // e.g. "cam1" — local id used by the agent's CAMERAS dict
            $table->string('label')->nullable();
            $table->string('ip')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->unsignedInteger('channel')->default(1);
            $table->boolean('ptz')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['device_id', 'camera_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_cameras');
    }
};

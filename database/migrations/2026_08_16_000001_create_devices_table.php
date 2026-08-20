<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->unique(); // auto-generated id from device_identity.py
            $table->string('name')->nullable();
            $table->string('location')->nullable();
            $table->string('host')->nullable();
            $table->unsignedInteger('hls_port')->default(8888);
            $table->unsignedInteger('webrtc_port')->default(8889);
            $table->string('hls_base_url')->nullable();
            $table->string('webrtc_base_url')->nullable();
            $table->string('tunnel_cache_key')->nullable();
            $table->string('api_token')->nullable(); // per-device override; falls back to global token
            $table->string('status')->default('pending'); // pending | registered
            $table->string('hostname')->nullable(); // reported by the agent, shown while pending
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

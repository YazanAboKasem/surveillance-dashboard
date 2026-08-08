<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('device_agents', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('system_info');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('last_location_at')->nullable()->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_agents', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'last_location_at']);
        });
    }
};

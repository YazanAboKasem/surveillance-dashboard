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
        Schema::table('device_location_logs', function (Blueprint $table) {
            $table->string('session_id')->nullable()->after('device_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_location_logs', function (Blueprint $table) {
            $table->dropColumn('session_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties every event to the "trip" it happened during — a trip is a
     * device_power_logs row (started_at when the Jetson's WS connects,
     * stopped_at when it disconnects). Nullable: an event can't always be
     * matched to an open power log (e.g. none exists yet for a very first
     * connection race), so this must degrade gracefully rather than block
     * ingestion.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('device_power_log_id')->nullable()->after('device_id')
                ->constrained('device_power_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_power_log_id');
        });
    }
};

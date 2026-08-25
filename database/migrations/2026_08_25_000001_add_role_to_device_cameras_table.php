<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Camera role (FRONT / REAR_FIXED / REAR_PTZ) drives which AI processing
     * runs on a given stream — see RoadShield AI spec §2-4, §17-18. Nullable
     * so existing cameras aren't broken until an admin assigns a role.
     */
    public function up(): void
    {
        Schema::table('device_cameras', function (Blueprint $table) {
            $table->string('role')->nullable()->after('label'); // FRONT | REAR_FIXED | REAR_PTZ
        });
    }

    public function down(): void
    {
        Schema::table('device_cameras', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};

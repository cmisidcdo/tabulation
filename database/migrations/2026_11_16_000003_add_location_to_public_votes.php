<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People's Choice geofence: where each vote was cast (only filled when the event's geofence is on).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_votes', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('user_agent');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->float('location_accuracy')->nullable()->after('longitude');
            $table->unsignedInteger('distance_m')->nullable()->after('location_accuracy');
        });
    }

    public function down(): void
    {
        Schema::table('public_votes', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_accuracy', 'distance_m']);
        });
    }
};

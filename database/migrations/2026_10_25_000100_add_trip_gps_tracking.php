<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['scheduled_departure_at', 'actual_departure_at', 'actual_arrival_at'] as $column) {
            if (! Schema::hasColumn('Trips', $column)) {
                Schema::table('Trips', fn (Blueprint $table) => $table->dateTime($column, 3)->nullable());
            }
        }
        if (! Schema::hasColumn('Trips', 'completion_notes')) {
            Schema::table('Trips', fn (Blueprint $table) => $table->text('completion_notes')->nullable());
        }
        if (! Schema::hasTable('Trip_GPS_Points')) {
            Schema::create('Trip_GPS_Points', function (Blueprint $table) {
                $table->bigIncrements('point_id');
                $table->integer('trip_id');
                $table->integer('vehicle_id');
                $table->integer('driver_id');
                $table->uuid('client_point_id')->unique();
                $table->decimal('latitude', 10, 8);
                $table->decimal('longitude', 11, 8);
                $table->decimal('accuracy_m', 8, 2);
                $table->decimal('speed_kmh', 5, 2)->nullable();
                $table->dateTime('captured_at', 3);
                $table->dateTime('received_at', 3);
                $table->index(['trip_id', 'captured_at']);
                $table->index(['vehicle_id', 'captured_at']);
                $table->foreign('trip_id')->references('trip_id')->on('Trips')->restrictOnDelete();
                $table->foreign('vehicle_id')->references('vehicle_id')->on('Vehicles')->restrictOnDelete();
                $table->foreign('driver_id')->references('driver_id')->on('Drivers')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Tracking history is deliberately retained; rollback must not erase GPS records.
    }
};

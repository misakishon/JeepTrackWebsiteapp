<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('Analytics_Settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
        });
        Schema::table('Trips', function (Blueprint $t) {
            $t->dateTime('scheduled_arrival_at', 3)->nullable();
            $t->string('travel_direction', 20)->nullable();
        });
        Schema::create('Vehicle_Availability', function (Blueprint $t) {
            $t->increments('availability_id');
            $t->integer('vehicle_id');
            $t->date('date');
            $t->decimal('available_hours', 5, 2);
            $t->unique(['vehicle_id', 'date']);
            $t->foreign('vehicle_id')->references('vehicle_id')->on('Vehicles');
        });
        Schema::create('Passenger_Journeys', function (Blueprint $t) {
            $t->increments('journey_id');
            $t->uuid('request_id')->unique();
            $t->integer('trip_id');
            $t->integer('passenger_id')->nullable();
            $t->integer('boarding_stop_id');
            $t->integer('destination_stop_id')->nullable();
            $t->integer('alighting_stop_id')->nullable();
            $t->dateTime('boarded_at', 3);
            $t->dateTime('alighted_at', 3)->nullable();
            $t->string('status', 20)->default('Active');
            $t->string('source', 30)->default('App journey');
            $t->text('correction_reason')->nullable();
            $t->dateTime('corrected_at', 3)->nullable();
            $t->foreign('trip_id')->references('trip_id')->on('Trips');
            $t->foreign('passenger_id')->references('passenger_id')->on('Passengers');
            $t->index(['trip_id', 'status']);
        });
        Schema::create('Trip_Delay_Reports', function (Blueprint $t) {
            $t->increments('delay_id');
            $t->uuid('request_id')->unique();
            $t->integer('trip_id');
            $t->string('reason', 40);
            $t->unsignedInteger('estimated_minutes')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('reported_at', 3);
            $t->foreign('trip_id')->references('trip_id')->on('Trips');
        });
        Schema::table('Feedback', function (Blueprint $t) {
            $t->uuid('request_id')->nullable()->unique();
            $t->integer('journey_id')->nullable();
            $t->integer('trip_id')->nullable();
            $t->dateTime('submitted_at', 3)->nullable();
            $t->string('category', 40)->nullable();
            $t->string('feedback_type', 20)->nullable();
            $t->string('resolution_status', 20)->nullable();
            $t->text('admin_response')->nullable();
            $t->text('internal_notes')->nullable();
            $t->dateTime('resolved_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        // Operational history is deliberately retained.
    }
};

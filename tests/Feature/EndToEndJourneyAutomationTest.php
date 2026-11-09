<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EndToEndJourneyAutomationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'transport.database_enabled' => true,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');

        // Base tables
        Schema::create('Users', function (Blueprint $t) {
            $t->increments('user_id');
            $t->string('fullname');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role');
        });
        Schema::create('Drivers', function (Blueprint $t) {
            $t->increments('driver_id');
            $t->integer('user_id');
            $t->string('license_number');
        });
        Schema::create('Passengers', function (Blueprint $t) {
            $t->increments('passenger_id');
            $t->integer('user_id');
        });
        Schema::create('Vehicles', function (Blueprint $t) {
            $t->increments('vehicle_id');
            $t->string('plate_number');
            $t->string('type');
            $t->integer('capacity');
        });
        Schema::create('Routes', function (Blueprint $t) {
            $t->increments('route_id');
            $t->string('route_name');
            $t->string('origin');
            $t->string('destination');
        });
        Schema::create('Stops', function (Blueprint $t) {
            $t->increments('stop_id');
            $t->integer('route_id');
            $t->string('stop_name');
            $t->decimal('latitude');
            $t->decimal('longitude');
        });
        Schema::create('Trips', function (Blueprint $t) {
            $t->increments('trip_id');
            $t->integer('vehicle_id');
            $t->integer('driver_id');
            $t->integer('route_id');
            $t->dateTime('start_time');
            $t->dateTime('end_time')->nullable();
            $t->string('status');
        });
        Schema::create('GPS_Locations', function (Blueprint $t) {
            $t->increments('gps_id');
            $t->integer('vehicle_id');
            $t->decimal('latitude');
            $t->decimal('longitude');
            $t->decimal('speed')->nullable();
            $t->dateTime('timestamp');
        });
        Schema::create('Feedback', function (Blueprint $t) {
            $t->increments('feedback_id');
            $t->integer('passenger_id');
            $t->integer('rating')->nullable();
            $t->text('comments')->nullable();
        });

        // Tracking & Analytics migrations
        (require database_path('migrations/2026_10_25_000100_add_trip_gps_tracking.php'))->up();
        (require database_path('migrations/2026_10_25_000200_add_analytics_records.php'))->up();

        // Seed base transport data
        DB::table('Vehicles')->insert([
            'vehicle_id' => 1,
            'plate_number' => 'TEST-PUJ-01',
            'type' => 'Modern Jeepney',
            'capacity' => 24
        ]);
        DB::table('Routes')->insert([
            'route_id' => 1,
            'route_name' => 'Batac–Laoag Express',
            'origin' => 'Batac Terminal',
            'destination' => 'Laoag Terminal'
        ]);
        DB::table('Stops')->insert([
            ['stop_id' => 1, 'route_id' => 1, 'stop_name' => 'Batac Terminal', 'latitude' => 18.055, 'longitude' => 120.561],
            ['stop_id' => 2, 'route_id' => 1, 'stop_name' => 'San Nicolas Plaza', 'latitude' => 18.173, 'longitude' => 120.596],
            ['stop_id' => 3, 'route_id' => 1, 'stop_name' => 'Laoag Terminal', 'latitude' => 18.198, 'longitude' => 120.593]
        ]);
    }

    protected function user(string $role = 'Admin', string $password = 'test-password'): User
    {
        $id = DB::table('Users')->insertGetId([
            'fullname' => 'Admin User',
            'email' => strtolower($role) . '@example.test',
            'password' => $password,
            'role' => $role
        ]);

        return User::find($id);
    }

    public function test_full_system_flow_from_account_creation_to_analytics(): void
    {
        // -------------------------------------------------------------
        // STEP 1: PASSENGER REGISTRATION & SIGN-IN
        // -------------------------------------------------------------
        $passengerEmail = 'maria.passenger@example.com';
        $registerPayload = [
            'name' => 'Maria Santos',
            'email' => $passengerEmail,
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'terms' => true,
        ];

        $registerRes = $this->postJson('/register', $registerPayload);
        $registerRes->assertStatus(201)
            ->assertJsonPath('redirect', '/passenger/home')
            ->assertJsonPath('user.role', 'Passenger')
            ->assertJsonPath('user.email', $passengerEmail);

        $passengerUser = User::where('email', $passengerEmail)->firstOrFail();
        $this->assertAuthenticatedAs($passengerUser);

        // -------------------------------------------------------------
        // STEP 2: ADMIN LOGS IN & PROVISIONS A NEW DRIVER
        // -------------------------------------------------------------
        $admin = $this->user('Admin', 'admin12345');
        $this->actingAs($admin);

        $driverEmail = 'driver.juan@example.com';
        $driverPayload = [
            'fullname' => 'Juan Driver',
            'email' => $driverEmail,
            'license_number' => 'LIC-BATAC-2026',
            'password' => 'driverPass123',
            'password_confirmation' => 'driverPass123',
        ];

        $driverRes = $this->postJson('/api/admin/drivers', $driverPayload);
        $driverRes->assertStatus(201)
            ->assertJsonPath('driver.fullname', 'Juan Driver')
            ->assertJsonPath('driver.license_number', 'LIC-BATAC-2026');

        $driverUser = User::where('email', $driverEmail)->firstOrFail();
        $driverRecord = DB::table('Drivers')->where('user_id', $driverUser->getKey())->first();
        $this->assertNotNull($driverRecord);

        // -------------------------------------------------------------
        // STEP 3: DRIVER LOGS IN & PLANS/SCHEDULES A TRIP
        // -------------------------------------------------------------
        $this->actingAs($driverUser);

        $schedulePayload = [
            'route_id' => 1,
            'vehicle_id' => 1,
        ];

        $planRes = $this->postJson('/api/transport/trips', $schedulePayload);
        $planRes->assertOk();
        $tripId = $planRes->json('trip.trip_id');
        $this->assertNotNull($tripId);

        // -------------------------------------------------------------
        // STEP 4: DRIVER STARTS TRIP & BROADCASTS GPS FIXES
        // -------------------------------------------------------------
        $initialPoint = [
            'client_point_id' => (string) Str::uuid(),
            'latitude' => 18.05510,
            'longitude' => 120.56120,
            'accuracy_m' => 8.5,
            'speed_kmh' => 15.0,
            'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];

        $startRes = $this->postJson("/api/transport/trips/{$tripId}/start", [
            'vehicle_id' => 1,
            'route_id' => 1,
            'point' => $initialPoint,
        ]);
        $startRes->assertOk();
        $this->assertDatabaseHas('Trips', ['trip_id' => $tripId, 'status' => 'In Progress']);

        // Driver streams intermediate GPS telemetry fix
        $movingPoint = [
            'client_point_id' => (string) Str::uuid(),
            'latitude' => 18.17300,
            'longitude' => 120.59600,
            'accuracy_m' => 5.2,
            'speed_kmh' => 38.5,
            'captured_at' => CarbonImmutable::now('UTC')->addSeconds(5)->toIso8601String(),
        ];
        $gpsRes = $this->postJson("/api/transport/trips/{$tripId}/gps", $movingPoint);
        $gpsRes->assertOk();

        // -------------------------------------------------------------
        // STEP 5: PASSENGER TRACKS & BOARDS THE JEEPNEY
        // -------------------------------------------------------------
        $this->actingAs($passengerUser);

        // Check snapshot contains live vehicle and active trip
        $snapshot = $this->getJson('/api/transport/snapshot');
        $snapshot->assertOk()
            ->assertJsonPath('activeTrips.0.trip_id', $tripId);

        // Board at Batac Terminal (stop 1) heading to Laoag (stop 3)
        $boardRequestId = (string) Str::uuid();
        $boardRes = $this->postJson('/api/transport/journeys', [
            'request_id' => $boardRequestId,
            'trip_id' => $tripId,
            'boarding_stop_id' => 1,
            'destination_stop_id' => 3,
        ]);
        $boardRes->assertOk();
        $this->assertDatabaseHas('Passenger_Journeys', [
            'trip_id' => $tripId,
            'status' => 'Active',
            'boarding_stop_id' => 1,
        ]);
        $journeyRecord = DB::table('Passenger_Journeys')->where('trip_id', $tripId)->first();
        $journeyId = $journeyRecord->journey_id;

        // -------------------------------------------------------------
        // STEP 6: PASSENGER ARRIVES & SUBMITS FEEDBACK
        // -------------------------------------------------------------
        $arriveRes = $this->postJson("/api/transport/journeys/{$journeyId}/arrive", [
            'alighting_stop_id' => 3,
        ]);
        $arriveRes->assertOk();
        $this->assertDatabaseHas('Passenger_Journeys', [
            'journey_id' => $journeyId,
            'status' => 'Completed',
            'alighting_stop_id' => 3,
        ]);

        // Submit trip rating & feedback
        $feedbackRes = $this->postJson("/api/transport/journeys/{$journeyId}/feedback", [
            'request_id' => (string) Str::uuid(),
            'rating' => 5,
            'type' => 'Rating',
            'category' => 'Service quality',
            'message' => 'Clean jeepney and safe driving experience!',
        ]);
        $feedbackRes->assertOk();
        $this->assertDatabaseHas('Feedback', [
            'journey_id' => $journeyId,
            'rating' => 5,
        ]);

        // -------------------------------------------------------------
        // STEP 7: DRIVER COMPLETES THE TRIP
        // -------------------------------------------------------------
        $this->actingAs($driverUser);

        $endRes = $this->postJson("/api/transport/trips/{$tripId}/end", [
            'destination' => 'Laoag Terminal',
            'completion_notes' => 'Trip arrived safely with all passengers alighted.',
        ]);
        $endRes->assertOk();
        $this->assertDatabaseHas('Trips', ['trip_id' => $tripId, 'status' => 'Completed']);

        // -------------------------------------------------------------
        // STEP 8: ADMIN ACCESSES THE ANALYTICS SUITE
        // -------------------------------------------------------------
        $this->actingAs($admin);

        // Fetch aggregated dataset used by AdminAnalytics.jsx
        $analyticsRes = $this->getJson('/api/admin/analytics');
        $analyticsRes->assertOk();

        // Verify the entire journey and trip are compiled in analytics
        $analyticsRes->assertJsonPath('meta.analyticsReady', true)
            ->assertJsonPath('trips.0.id', "trip-{$tripId}")
            ->assertJsonPath('trips.0.status', 'Completed')
            ->assertJsonPath('journeys.0.id', "journey-{$journeyId}")
            ->assertJsonPath('journeys.0.trip', "trip-{$tripId}")
            ->assertJsonPath('feedback.0.rating', 5)
            ->assertJsonPath('feedback.0.message', 'Clean jeepney and safe driving experience!');

        // Admin resolves the passenger feedback
        $feedbackId = DB::table('Feedback')->where('journey_id', $journeyId)->value('feedback_id');
        $resolveRes = $this->patchJson("/api/admin/analytics/feedback/{$feedbackId}", [
            'status' => 'Resolved',
            'response' => 'Thank you for your commendation! We appreciate your feedback.',
            'internalNote' => 'Commended driver Juan Driver.',
        ]);
        $resolveRes->assertOk();

        $this->assertDatabaseHas('Feedback', [
            'feedback_id' => $feedbackId,
            'resolution_status' => 'Resolved',
        ]);
    }
}

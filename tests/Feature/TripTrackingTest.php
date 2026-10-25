<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TripTrackingTest extends TransportDatabaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require database_path('migrations/2026_10_25_000100_add_trip_gps_tracking.php');
        $migration->up();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function assignment(): User
    {
        $user = $this->user('Driver');
        DB::table('Drivers')->insert(['driver_id' => 1, 'user_id' => $user->getKey(), 'license_number' => 'TEST-GPS-DRIVER']);
        DB::table('Vehicles')->insert(['vehicle_id' => 1, 'plate_number' => 'TEST-GPS-001', 'type' => 'Jeepney', 'capacity' => 26]);
        DB::table('Routes')->insert(['route_id' => 1, 'route_name' => 'Batac–Laoag', 'origin' => 'Batac Terminal', 'destination' => 'Laoag Public Market']);
        DB::table('Trips')->insert(['trip_id' => 1, 'driver_id' => 1, 'vehicle_id' => 1, 'route_id' => 1, 'start_time' => '2026-10-25 17:00:00', 'status' => 'Scheduled']);

        return $user;
    }

    private function point(array $overrides = []): array
    {
        return array_replace(['client_point_id' => (string) Str::uuid(), 'latitude' => 18.0554, 'longitude' => 120.5649, 'accuracy_m' => 8.5, 'speed_kmh' => 0, 'captured_at' => CarbonImmutable::now('UTC')->toIso8601String()], $overrides);
    }

    private function start(User $user, array $point = [])
    {
        return $this->actingAs($user)->postJson('/api/transport/trips/1/start', ['vehicle_id' => 1, 'route_id' => 1, 'point' => $point ?: $this->point()]);
    }

    public function test_start_persists_trip_and_real_fix_without_losing_the_schedule(): void
    {
        $user = $this->assignment();
        $point = $this->point();
        $this->start($user, $point)->assertOk()->assertJsonPath('trip.status', 'In Progress')->assertJsonPath('snapshot.locations.0.trip_id', 1)->assertJsonPath('snapshot.locations.0.accuracy_m', 8.5);
        $this->assertDatabaseHas('Trips', ['trip_id' => 1, 'status' => 'In Progress', 'scheduled_departure_at' => '2026-10-25 09:00:00.000', 'actual_departure_at' => '2026-10-25 10:00:00.000']);
        $this->assertDatabaseHas('Trip_GPS_Points', ['trip_id' => 1, 'vehicle_id' => 1, 'driver_id' => 1, 'client_point_id' => $point['client_point_id']]);
        $this->assertDatabaseCount('GPS_Locations', 1);
    }

    public function test_start_requires_a_fresh_valid_gps_fix_and_confirmed_assignment(): void
    {
        $user = $this->assignment();
        foreach ([$this->point(['latitude' => 95]), $this->point(['accuracy_m' => 1500]), $this->point(['captured_at' => '2026-10-25T09:55:00Z'])] as $point) {
            $this->start($user, $point)->assertUnprocessable();
        }
        $this->actingAs($user)->postJson('/api/transport/trips/1/start', ['vehicle_id' => 2, 'route_id' => 1, 'point' => $this->point()])->assertUnprocessable();
        $this->assertDatabaseHas('Trips', ['trip_id' => 1, 'status' => 'Scheduled']);
        $this->assertDatabaseCount('Trip_GPS_Points', 0);
    }

    public function test_duplicate_start_does_not_reset_departure_or_duplicate_the_point(): void
    {
        $user = $this->assignment();
        $point = $this->point();
        $this->start($user, $point)->assertOk();
        CarbonImmutable::setTestNow('2026-10-25T10:00:10Z');
        $this->start($user, $point)->assertOk();
        $this->assertDatabaseCount('Trip_GPS_Points', 1);
        $this->assertDatabaseHas('Trips', ['actual_departure_at' => '2026-10-25 10:00:00.000']);
    }

    public function test_a_second_active_trip_or_busy_vehicle_is_rejected(): void
    {
        $user = $this->assignment();
        DB::table('Trips')->insert(['trip_id' => 2, 'driver_id' => 1, 'vehicle_id' => 1, 'route_id' => 1, 'start_time' => '2026-10-25 15:00:00', 'status' => 'In Progress']);
        $this->start($user)->assertConflict();
        $this->assertDatabaseCount('Trip_GPS_Points', 0);
        DB::table('Trips')->where('trip_id', 2)->update(['driver_id' => 2]);
        $this->start($user)->assertConflict();
        $this->assertDatabaseCount('Trip_GPS_Points', 0);
    }

    public function test_passengers_and_other_drivers_cannot_submit_gps_or_start_the_trip(): void
    {
        $owner = $this->assignment();
        $passenger = $this->user('Passenger');
        $this->actingAs($passenger)->postJson('/api/transport/trips/1/start', ['vehicle_id' => 1, 'route_id' => 1, 'point' => $this->point()])->assertForbidden();
        $this->actingAs($passenger)->postJson('/api/transport/trips/1/gps', $this->point())->assertForbidden();
        $id = DB::table('Users')->insertGetId(['fullname' => 'Other driver', 'email' => 'other@example.test', 'password' => 'unused', 'role' => 'Driver']);
        DB::table('Drivers')->insert(['driver_id' => 2, 'user_id' => $id, 'license_number' => 'OTHER-LICENSE']);
        $this->actingAs(User::find($id))->postJson('/api/transport/trips/1/start', ['vehicle_id' => 1, 'route_id' => 1, 'point' => $this->point()])->assertNotFound();
        $this->assertDatabaseCount('Trip_GPS_Points', 0);
    }

    public function test_passengers_receive_the_latest_trip_location_and_stale_status(): void
    {
        $driver = $this->assignment();
        $this->start($driver)->assertOk();
        CarbonImmutable::setTestNow('2026-10-25T10:00:10Z');
        $new = $this->point(['latitude' => 18.065, 'captured_at' => '2026-10-25T10:00:08Z']);
        $this->actingAs($driver)->postJson('/api/transport/trips/1/gps', $new)->assertOk();
        $this->actingAs($driver)->postJson('/api/transport/trips/1/gps', $this->point(['latitude' => 18.06, 'captured_at' => '2026-10-25T10:00:03Z']))->assertOk();
        $this->actingAs($driver)->postJson('/api/transport/trips/1/gps', $new)->assertOk();
        $this->assertDatabaseCount('Trip_GPS_Points', 3);
        $passenger = $this->user('Passenger');
        $this->actingAs($passenger)->getJson('/api/transport/snapshot')->assertOk()->assertJsonPath('locations.0.latitude', 18.065)->assertJsonPath('locations.0.trip_id', 1)->assertJsonPath('locations.0.is_stale', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        CarbonImmutable::setTestNow('2026-10-25T10:00:45Z');
        $this->actingAs($passenger)->getJson('/api/transport/snapshot')->assertJsonPath('locations.0.is_stale', true);
    }

    public function test_end_stops_public_tracking_and_rejects_further_gps(): void
    {
        $driver = $this->assignment();
        $this->start($driver)->assertOk();
        $this->actingAs($driver)->postJson('/api/transport/trips/1/end', ['destination' => 'Wrong stop'])->assertUnprocessable();
        CarbonImmutable::setTestNow('2026-10-25T10:01:00Z');
        $this->actingAs($driver)->postJson('/api/transport/trips/1/end', ['destination' => 'Laoag Public Market', 'completion_notes' => 'Reached the terminal.'])->assertOk()->assertJsonPath('trip.status', 'Completed');
        $this->actingAs($driver)->postJson('/api/transport/trips/1/gps', $this->point())->assertConflict();
        $this->assertDatabaseCount('Trip_GPS_Points', 1);
        $this->assertDatabaseHas('Trips', ['completion_notes' => 'Reached the terminal.', 'actual_arrival_at' => '2026-10-25 10:01:00.000']);
        $this->actingAs($this->user('Passenger'))->getJson('/api/transport/snapshot')->assertJsonCount(0, 'activeTrips')->assertJsonCount(0, 'locations');
    }

    public function test_reassigned_vehicles_do_not_reuse_the_previous_vehicles_gps(): void
    {
        $driver = $this->assignment();
        $this->start($driver)->assertOk();
        DB::table('Vehicles')->insert(['vehicle_id' => 2, 'plate_number' => 'REASSIGNED-002', 'type' => 'Jeepney', 'capacity' => 26]);
        DB::table('Trips')->where('trip_id', 1)->update(['vehicle_id' => 2]);
        $this->actingAs($this->user('Passenger'))->getJson('/api/transport/snapshot')->assertOk()->assertJsonCount(1, 'activeTrips')->assertJsonCount(0, 'locations');
    }

    public function test_gps_upload_rate_limit_does_not_block_ending_a_trip(): void
    {
        $driver = $this->assignment();
        $this->start($driver)->assertOk();
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($driver)->postJson('/api/transport/trips/1/gps', $this->point())->assertOk();
        }
        $this->actingAs($driver)->postJson('/api/transport/trips/1/end', ['destination' => 'Laoag Public Market'])->assertOk();
    }
}

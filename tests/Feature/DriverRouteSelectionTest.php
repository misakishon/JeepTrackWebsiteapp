<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DriverRouteSelectionTest extends TransportDatabaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_25_000100_add_trip_gps_tracking.php'))->up();
    }

    private function driver(): User
    {
        $user = $this->user('Driver');
        DB::table('Drivers')->insert(['driver_id' => 1, 'user_id' => $user->getKey(), 'license_number' => 'ROUTE-TEST']);
        DB::table('Vehicles')->insert(['vehicle_id' => 1, 'plate_number' => 'ROUTE-001', 'type' => 'Jeepney', 'capacity' => 26]);
        DB::table('Routes')->insert([
            ['route_id' => 1, 'route_name' => 'Batac-Laoag', 'origin' => 'Batac Terminal', 'destination' => 'Laoag Terminal'],
            ['route_id' => 2, 'route_name' => 'Batac-Paoay', 'origin' => 'Batac Terminal', 'destination' => 'Paoay Terminal'],
        ]);
        return $user;
    }

    private function point(): array
    {
        return ['client_point_id' => (string) Str::uuid(), 'latitude' => 18.0553, 'longitude' => 120.5636, 'accuracy_m' => 5, 'captured_at' => now()->toIso8601String()];
    }

    public function test_driver_without_a_trip_can_choose_a_route_and_save_a_scheduled_trip(): void
    {
        $driver = $this->driver();
        $this->actingAs($driver)->getJson('/api/transport/snapshot')->assertJsonCount(2, 'routes')->assertJsonCount(1, 'available_vehicles');
        $response = $this->postJson('/api/transport/trips', ['route_id' => 1, 'vehicle_id' => 1])->assertOk()->assertJsonPath('trip.status', 'Scheduled')->assertJsonPath('trip.route.route_name', 'Batac-Laoag');
        $id = $response->json('trip.trip_id');
        $this->postJson('/api/transport/trips', ['route_id' => 1, 'vehicle_id' => 1])->assertOk()->assertJsonPath('trip.trip_id', $id);
        $this->assertDatabaseCount('Trips', 1);
        $this->assertDatabaseCount('Trip_GPS_Points', 0);
        $this->actingAs($this->user('Passenger'))->getJson('/api/transport/snapshot')->assertJsonCount(0, 'activeTrips')->assertJsonCount(0, 'locations');
        $this->actingAs($driver)->postJson('/api/transport/trips/'.$id.'/start', ['route_id' => 1, 'vehicle_id' => 1, 'point' => $this->point()])->assertOk();
        $this->actingAs(User::where('role', 'Passenger')->first())->getJson('/api/transport/snapshot')->assertJsonPath('activeTrips.0.route.route_name', 'Batac-Laoag')->assertJsonPath('locations.0.trip_id', $id);
    }

    public function test_driver_can_switch_a_scheduled_paoay_trip_to_laoag_but_not_after_start(): void
    {
        $driver = $this->driver();
        DB::table('Trips')->insert(['trip_id' => 1, 'driver_id' => 1, 'vehicle_id' => 1, 'route_id' => 2, 'status' => 'Scheduled', 'start_time' => now()]);
        $this->actingAs($driver)->patchJson('/api/transport/trips/1/route', ['route_id' => 1])->assertOk()->assertJsonPath('trip.route_id', 1);
        $this->postJson('/api/transport/trips/1/start', ['route_id' => 2, 'vehicle_id' => 1, 'point' => $this->point()])->assertUnprocessable();
        $this->postJson('/api/transport/trips/1/start', ['route_id' => 1, 'vehicle_id' => 1, 'point' => $this->point()])->assertOk();
        $this->patchJson('/api/transport/trips/1/route', ['route_id' => 2])->assertConflict();
        $this->postJson('/api/transport/trips', ['route_id' => 2, 'vehicle_id' => 1])->assertConflict();
        $this->assertDatabaseHas('Trips', ['trip_id' => 1, 'route_id' => 1, 'status' => 'In Progress']);
        $this->postJson('/api/transport/trips/1/end', ['destination' => 'Laoag Terminal'])->assertOk();
        $this->postJson('/api/transport/trips', ['route_id' => 2, 'vehicle_id' => 1])->assertOk()->assertJsonPath('trip.route_id', 2)->assertJsonPath('trip.status', 'Scheduled');
        $this->assertDatabaseCount('Trips', 2);
    }

    public function test_driver_cannot_take_an_active_or_other_drivers_reserved_vehicle(): void
    {
        $driver = $this->driver();
        $id = DB::table('Users')->insertGetId(['fullname' => 'Other driver', 'email' => 'other-route@example.test', 'password' => 'unused', 'role' => 'Driver']);
        DB::table('Drivers')->insert(['driver_id' => 2, 'user_id' => $id, 'license_number' => 'OTHER-ROUTE']);
        DB::table('Trips')->insert(['trip_id' => 1, 'driver_id' => 2, 'vehicle_id' => 1, 'route_id' => 2, 'status' => 'Scheduled', 'start_time' => now()]);
        $this->actingAs($driver)->getJson('/api/transport/snapshot')->assertJsonCount(0, 'available_vehicles');
        $this->postJson('/api/transport/trips', ['route_id' => 1, 'vehicle_id' => 1])->assertConflict();
        $this->patchJson('/api/transport/trips/1/route', ['route_id' => 1])->assertNotFound();
        DB::table('Trips')->where('trip_id', 1)->update(['status' => 'In Progress']);
        $this->postJson('/api/transport/trips', ['route_id' => 1, 'vehicle_id' => 1])->assertConflict();
        $this->assertDatabaseCount('Trips', 1);
    }

    public function test_route_planning_requires_a_driver_and_valid_registered_ids(): void
    {
        $driver = $this->driver();
        $this->postJson('/api/transport/trips', ['route_id' => 1, 'vehicle_id' => 1])->assertUnauthorized();
        foreach (['Passenger', 'Admin'] as $role) {
            $this->actingAs($this->user($role))->postJson('/api/transport/trips', ['route_id' => 1, 'vehicle_id' => 1])->assertForbidden();
        }
        $this->actingAs($driver)->postJson('/api/transport/trips', ['route_id' => 999, 'vehicle_id' => 1])->assertUnprocessable();
        $this->postJson('/api/transport/trips', ['route_id' => 1, 'vehicle_id' => 999])->assertUnprocessable();
        $this->postJson('/api/transport/trips', ['route_id' => 1, 'vehicle_id' => 1, 'driver_id' => 2, 'status' => 'In Progress'])->assertUnprocessable();
        $this->assertDatabaseCount('Trips', 0);
    }

    public function test_route_change_clears_old_arrival_and_direction_without_changing_vehicle_or_departure(): void
    {
        (require database_path('migrations/2026_10_25_000200_add_analytics_records.php'))->up();
        $driver = $this->driver();
        DB::table('Trips')->insert(['trip_id' => 1, 'driver_id' => 1, 'vehicle_id' => 1, 'route_id' => 2, 'status' => 'Scheduled', 'start_time' => '2026-10-28 09:00:00', 'scheduled_arrival_at' => '2026-10-28 02:00:00', 'travel_direction' => 'Outbound']);
        $this->actingAs($driver)->patchJson('/api/transport/trips/1/route', ['route_id' => 1])->assertOk();
        $this->assertDatabaseHas('Trips', ['trip_id' => 1, 'vehicle_id' => 1, 'driver_id' => 1, 'route_id' => 1, 'start_time' => '2026-10-28 09:00:00', 'scheduled_arrival_at' => null, 'travel_direction' => null]);
        $this->assertDatabaseCount('Trip_GPS_Points', 0);
    }
}

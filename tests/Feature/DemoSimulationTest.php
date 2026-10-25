<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DemoSimulationService;
use App\Services\TransportDatabase;
use Illuminate\Support\Facades\DB;

class DemoSimulationTest extends TransportDatabaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require database_path('migrations/2026_10_25_000100_add_trip_gps_tracking.php');
        $migration->up();
    }

    public function test_demo_simulation_service_seeds_five_drivers_jeepneys_and_active_trips(): void
    {
        $service = app(DemoSimulationService::class);
        $result = $service->seedDemoData();

        $this->assertCount(5, $result['drivers']);
        $this->assertCount(5, $result['vehicles']);
        $this->assertCount(5, $result['trips']);

        // Verify 5 demo driver accounts exist
        $demoEmails = [
            'demodriver1@puj.com',
            'demodriver2@puj.com',
            'demodriver3@puj.com',
            'demodriver4@puj.com',
            'demodriver5@puj.com',
        ];

        foreach ($demoEmails as $email) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "Demo driver with email {$email} should exist.");
            $this->assertSame('Driver', $user->role);
            $this->assertNotNull(Driver::where('user_id', $user->user_id)->first());
        }

        // Verify 5 jeepneys
        $plates = ['JEP-101', 'JEP-102', 'JEP-103', 'JEP-104', 'JEP-105'];
        foreach ($plates as $plate) {
            $vehicle = Vehicle::where('plate_number', $plate)->first();
            $this->assertNotNull($vehicle, "Jeepney with plate {$plate} should exist.");
            $this->assertSame('Jeepney', $vehicle->type);
        }

        // Verify 5 active trips on routes 1 through 5
        for ($routeId = 1; $routeId <= 5; $routeId++) {
            $trip = Trip::where('route_id', $routeId)->where('status', 'In Progress')->first();
            $this->assertNotNull($trip, "Active trip on route {$routeId} should exist.");
            $this->assertSame('In Progress', $trip->status);
        }
    }

    public function test_simulated_positions_are_generated_and_clearly_labeled(): void
    {
        $service = app(DemoSimulationService::class);
        $service->seedDemoData();

        $positions = $service->getSimulatedPositions();

        $this->assertCount(5, $positions);

        foreach ($positions as $pos) {
            $this->assertTrue($pos['is_simulation']);
            $this->assertSame('Demo simulation — not real GPS', $pos['simulation_label']);
            $this->assertNotEmpty($pos['plate_number']);
            $this->assertSame('Jeepney', $pos['vehicle_type']);
            $this->assertNotEmpty($pos['route_name']);
            $this->assertNotEmpty($pos['driver_name']);

            // Verify coordinates are within Ilocos Norte bounding box
            $this->assertGreaterThanOrEqual(18.0, $pos['latitude']);
            $this->assertLessThanOrEqual(18.3, $pos['latitude']);
            $this->assertGreaterThanOrEqual(120.4, $pos['longitude']);
            $this->assertLessThanOrEqual(120.7, $pos['longitude']);

            $this->assertGreaterThan(0, $pos['speed']);
            $this->assertNotEmpty($pos['waypoints']);
        }
    }

    public function test_simulated_locations_are_kept_separate_from_real_tracking(): void
    {
        $service = app(DemoSimulationService::class);
        $service->seedDemoData();

        $initialRealPointCount = DB::table('Trip_GPS_Points')->count();
        $initialGpsLocationCount = DB::table('GPS_Locations')->count();

        // Generate simulated positions multiple times
        $service->getSimulatedPositions();
        $service->getSimulatedPositions();
        $service->getSimulatedPositions();

        // Real tracking tables must remain completely untouched
        $this->assertSame($initialRealPointCount, DB::table('Trip_GPS_Points')->count());
        $this->assertSame($initialGpsLocationCount, DB::table('GPS_Locations')->count());
    }

    public function test_simulation_endpoint_returns_all_five_moving_jeepneys(): void
    {
        $service = app(DemoSimulationService::class);
        $service->seedDemoData();

        $passenger = $this->user('Passenger');
        $response = $this->actingAs($passenger)->getJson('/api/transport/simulation');

        $response->assertOk();
        $response->assertJsonStructure([
            'simulatedVehicles' => [
                '*' => [
                    'is_simulation',
                    'simulation_label',
                    'vehicle_id',
                    'plate_number',
                    'route_id',
                    'route_name',
                    'driver_name',
                    'latitude',
                    'longitude',
                    'speed',
                    'heading',
                ]
            ]
        ]);

        $data = $response->json('simulatedVehicles');
        $this->assertCount(5, $data);
        foreach ($data as $vehicle) {
            $this->assertSame('Demo simulation — not real GPS', $vehicle['simulation_label']);
        }
    }

    public function test_passenger_snapshot_includes_simulated_vehicles(): void
    {
        $service = app(DemoSimulationService::class);
        $service->seedDemoData();

        $passenger = $this->user('Passenger');
        $response = $this->actingAs($passenger)->getJson('/api/transport/snapshot');

        $response->assertOk();
        $data = $response->json();

        $this->assertArrayHasKey('simulatedVehicles', $data);
        $this->assertCount(5, $data['simulatedVehicles']);
        $this->assertSame('Demo simulation — not real GPS', $data['simulatedVehicles'][0]['simulation_label']);
    }

    public function test_admin_dataset_includes_simulated_vehicles(): void
    {
        $service = app(DemoSimulationService::class);
        $service->seedDemoData();

        $database = app(TransportDatabase::class);
        $dataset = $database->adminDataset();

        $this->assertArrayHasKey('simulatedVehicles', $dataset);
        $this->assertCount(5, $dataset['simulatedVehicles']);
        $this->assertSame('Demo simulation — not real GPS', $dataset['simulatedVehicles'][0]['simulation_label']);
    }

    public function test_simulated_positions_move_over_time(): void
    {
        $service = app(DemoSimulationService::class);
        $waypoints = DemoSimulationService::ROUTE_WAYPOINTS[1];

        $pos0 = $service->interpolatePosition($waypoints, 0.0, false, 35.0);
        $pos50 = $service->interpolatePosition($waypoints, 0.5, false, 35.0);
        $pos100 = $service->interpolatePosition($waypoints, 1.0, false, 35.0);

        // Coordinates change as progress advances
        $this->assertNotEquals($pos0['latitude'], $pos50['latitude']);
        $this->assertNotEquals($pos0['longitude'], $pos50['longitude']);
        $this->assertNotEquals($pos50['latitude'], $pos100['latitude']);

        // Start point matches first waypoint
        $this->assertEqualsWithDelta($waypoints[0][1], $pos0['latitude'], 0.0001);
        $this->assertEqualsWithDelta($waypoints[0][0], $pos0['longitude'], 0.0001);

        // End point matches last waypoint
        $last = end($waypoints);
        $this->assertEqualsWithDelta($last[1], $pos100['latitude'], 0.0001);
        $this->assertEqualsWithDelta($last[0], $pos100['longitude'], 0.0001);
    }
}

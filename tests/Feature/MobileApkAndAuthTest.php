<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MobileApkAndAuthTest extends TransportDatabaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require database_path('migrations/2026_10_25_000100_add_trip_gps_tracking.php');
        $migration->up();
    }

    public function test_apk_download_endpoint_serves_the_compiled_android_apk(): void
    {
        $this->assertFileExists(public_path('downloads/JeepTrack.apk'));

        $response = $this->get('/download/apk');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        $this->assertStringContainsString('JeepTrack.apk', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_mobile_ping_endpoint_returns_shared_database_status_and_cors_headers(): void
    {
        $response = $this->withHeaders(['X-JeepTrack-Mobile' => 'android'])
            ->getJson('/api/mobile/ping');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('database_connected', true)
            ->assertJsonPath('allowed_roles', ['Passenger', 'Driver']);

        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_mobile_login_allows_passenger_and_driver_but_blocks_admin(): void
    {
        $admin = $this->user('Admin');
        $passenger = $this->user('Passenger');
        $driver = $this->user('Driver');
        DB::table('Drivers')->insert([
            'driver_id' => 1,
            'user_id' => $driver->user_id,
            'license_number' => 'D01-24-000111',
        ]);

        // Admin must be blocked on mobile APK login
        $this->withHeaders(['X-JeepTrack-Mobile' => 'android'])
            ->postJson('/login', [
                'email' => $admin->email,
                'password' => 'test-password',
                'mobile' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        // Passenger login returns Bearer token and shared database transportData
        $passengerLogin = $this->withHeaders(['X-JeepTrack-Mobile' => 'android'])
            ->postJson('/login', [
                'email' => $passenger->email,
                'password' => 'test-password',
                'mobile' => true,
            ])
            ->assertOk()
            ->assertJsonPath('redirect', '/passenger/home')
            ->assertJsonPath('user.role', 'Passenger')
            ->assertJsonPath('transportData.profile.email', $passenger->email);

        $passengerToken = $passengerLogin->json('token');
        $this->assertNotEmpty($passengerToken);

        // Driver login returns Bearer token and shared database transportData
        $driverLogin = $this->withHeaders(['X-JeepTrack-Mobile' => 'android'])
            ->postJson('/login', [
                'email' => $driver->email,
                'password' => 'test-password',
                'mobile' => true,
            ])
            ->assertOk()
            ->assertJsonPath('redirect', '/driver/home')
            ->assertJsonPath('user.role', 'Driver')
            ->assertJsonPath('transportData.profile.email', $driver->email);

        $driverToken = $driverLogin->json('token');
        $this->assertNotEmpty($driverToken);

        // Verify stateless Bearer token authentication works when session is cleared
        Auth::logout();
        $this->withHeaders([
            'X-JeepTrack-Mobile' => 'android',
            'Authorization' => 'Bearer '.$driverToken,
        ])->getJson('/api/transport/snapshot')
            ->assertOk()
            ->assertJsonPath('profile.role', 'Driver')
            ->assertJsonPath('profile.email', $driver->email);

        Auth::logout();
        $this->withHeaders([
            'X-JeepTrack-Mobile' => 'android',
            'Authorization' => 'Bearer '.$passengerToken,
        ])->getJson('/api/transport/snapshot')
            ->assertOk()
            ->assertJsonPath('profile.role', 'Passenger')
            ->assertJsonPath('profile.email', $passenger->email);
    }

    public function test_mobile_apk_and_website_share_the_same_database_for_trips_and_gps(): void
    {
        $driver = $this->user('Driver');
        $passenger = $this->user('Passenger');

        DB::table('Drivers')->insert([
            'driver_id' => 10,
            'user_id' => $driver->user_id,
            'license_number' => 'D01-26-888999',
        ]);
        DB::table('Passengers')->insert([
            'passenger_id' => 20,
            'user_id' => $passenger->user_id,
        ]);
        DB::table('Vehicles')->insert([
            'vehicle_id' => 5,
            'plate_number' => 'PUJ-2026',
            'type' => 'Modern Jeepney',
            'capacity' => 24,
        ]);
        DB::table('Routes')->insert([
            'route_id' => 7,
            'route_name' => 'Batac–Laoag',
            'origin' => 'Batac Terminal',
            'destination' => 'Laoag Public Market',
        ]);
        DB::table('Stops')->insert([
            ['stop_id' => 1, 'route_id' => 7, 'stop_name' => 'Batac Terminal', 'latitude' => 18.056, 'longitude' => 120.564],
            ['stop_id' => 2, 'route_id' => 7, 'stop_name' => 'Laoag Public Market', 'latitude' => 18.198, 'longitude' => 120.593],
        ]);
        DB::table('Trips')->insert([
            'trip_id' => 50,
            'vehicle_id' => 5,
            'driver_id' => 10,
            'route_id' => 7,
            'start_time' => now()->format('Y-m-d H:i:s'),
            'status' => 'Scheduled',
        ]);

        // Driver logs in via mobile APK
        $driverLogin = $this->withHeaders(['X-JeepTrack-Mobile' => 'android'])
            ->postJson('/login', ['email' => $driver->email, 'password' => 'test-password', 'mobile' => true])
            ->assertOk();
        $driverToken = $driverLogin->json('token');

        // Driver starts trip #50 from the mobile APK using Bearer token
        Auth::logout();
        $this->withHeaders([
            'X-JeepTrack-Mobile' => 'android',
            'Authorization' => 'Bearer '.$driverToken,
        ])->postJson('/api/transport/trips/50/start', [
            'vehicle_id' => 5,
            'route_id' => 7,
            'point' => [
                'client_point_id' => (string) Str::uuid(),
                'latitude' => 18.0612,
                'longitude' => 120.5681,
                'speed_kmh' => 28.5,
                'accuracy_m' => 6.0,
                'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ],
        ])->assertOk()->assertJsonPath('trip.status', 'In Progress');

        // Website Passenger (authenticated via standard web session) immediately sees the active trip & GPS point from the shared database
        $this->actingAs($passenger)
            ->getJson('/api/transport/snapshot')
            ->assertOk()
            ->assertJsonPath('activeTrips.0.trip_id', 50)
            ->assertJsonPath('activeTrips.0.status', 'In Progress')
            ->assertJsonPath('locations.0.vehicle_id', 5);
    }
}

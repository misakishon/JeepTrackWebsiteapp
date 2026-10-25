<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TransportDatabase;
use App\Services\TransportSql;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransportDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['transport.database_enabled' => true, 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
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
    }

    protected function user(string $role = 'Passenger', string $password = 'test-password'): User
    {
        $id = DB::table('Users')->insertGetId(['fullname' => 'Database Test', 'email' => strtolower($role).'@example.test', 'password' => $password, 'role' => $role]);

        return User::find($id);
    }

    public function test_legacy_credentials_upgrade_to_hash_and_follow_assigned_role(): void
    {
        $user = $this->user('Admin');
        $this->postJson('/login', ['email' => $user->email, 'password' => 'test-password'])->assertOk()->assertJsonPath('redirect', '/admin/analytics');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('test-password', $user->fresh()->getRawOriginal('password')));
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    public function test_registration_cannot_grant_admin_or_driver_roles(): void
    {
        $input = ['name' => 'New passenger', 'email' => 'new@example.test', 'password' => 'long-password', 'password_confirmation' => 'long-password', 'terms' => true];
        $this->postJson('/register', $input + ['role' => 'Admin'])->assertUnprocessable();
        $this->postJson('/register', $input)->assertCreated()->assertJsonPath('user.role', 'Passenger');
        $this->assertDatabaseCount('Users', 1);
        $this->assertDatabaseCount('Passengers', 1);
    }

    public function test_driver_cannot_access_another_drivers_trip_or_admin_pages(): void
    {
        $driver = $this->user('Driver');
        DB::table('Drivers')->insert(['driver_id' => 1, 'user_id' => $driver->user_id, 'license_number' => 'TEST-LICENSE']);
        $this->actingAs($driver)->getJson('/api/transport/trips/999')->assertNotFound();
        $this->actingAs($driver)->get('/admin/analytics')->assertForbidden();
        $this->actingAs($driver)->getJson('/api/transport/snapshot')->assertOk()->assertJsonPath('profile.role', 'Driver')->assertJsonCount(0, 'trips');
    }

    public function test_anonymous_requests_cannot_read_database_records(): void
    {
        $this->getJson('/api/transport/snapshot')->assertUnauthorized();
        $this->get('/admin/analytics')->assertRedirect('/login');
    }

    public function test_driver_map_locations_are_limited_to_assigned_vehicles(): void
    {
        $user = $this->user('Driver');
        DB::table('Drivers')->insert(['driver_id' => 1, 'user_id' => $user->getKey(), 'license_number' => 'OWN-LICENSE']);
        $other = DB::table('Users')->insertGetId(['fullname' => 'Other driver', 'email' => 'other-scope@example.test', 'password' => 'unused', 'role' => 'Driver']);
        DB::table('Drivers')->insert(['driver_id' => 2, 'user_id' => $other, 'license_number' => 'OTHER-SCOPE-LICENSE']);
        DB::table('Routes')->insert(['route_id' => 1, 'route_name' => 'Assigned route', 'origin' => 'A', 'destination' => 'B']);
        foreach ([1, 2] as $id) {
            DB::table('Vehicles')->insert(['vehicle_id' => $id, 'plate_number' => 'TEST-'.$id, 'type' => 'Jeepney', 'capacity' => 20]);
            DB::table('Trips')->insert(['trip_id' => $id, 'vehicle_id' => $id, 'driver_id' => $id, 'route_id' => 1, 'start_time' => '2026-09-20 08:00:00', 'status' => 'In Progress']);
            DB::table('GPS_Locations')->insert(['gps_id' => $id, 'vehicle_id' => $id, 'latitude' => 18.05, 'longitude' => 120.56, 'timestamp' => '2026-09-20 08:10:00']);
            if (Schema::hasTable('Trip_GPS_Points')) {
                DB::table('Trip_GPS_Points')->insert(['trip_id' => $id, 'vehicle_id' => $id, 'driver_id' => $id, 'client_point_id' => Str::uuid(), 'latitude' => 18.05, 'longitude' => 120.56, 'accuracy_m' => 10, 'captured_at' => '2026-09-20 00:10:00', 'received_at' => '2026-09-20 00:10:00']);
            }
        }
        $this->actingAs($user)->getJson('/api/transport/snapshot')->assertOk()->assertJsonCount(1, 'trips')->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.vehicle_id', 1)->assertJsonPath('locations.0.timestamp', '2026-09-20T00:10:00+00:00');
        $this->actingAs($user)->getJson('/api/transport/trips/2')->assertNotFound();
    }

    public function test_import_never_contains_drop_statements_or_plaintext_seed_passwords(): void
    {
        $sql = app(TransportSql::class)->prepare(file_get_contents(database_path('transport_system.sql')));
        $this->assertStringNotContainsString('DROP TABLE', $sql);
        $this->assertStringNotContainsString('CREATE DATABASE', $sql);
        $this->assertStringNotContainsString("'admin123'", $sql);
        $this->assertStringNotContainsString("'pass123'", $sql);
        $this->assertCount(17, app(TransportSql::class)->statements($sql));
    }

    public function test_missing_schema_metrics_remain_unavailable_and_times_are_utc(): void
    {
        DB::table('Routes')->insert(['route_id' => 1, 'route_name' => 'Existing route', 'origin' => 'A', 'destination' => 'B']);
        DB::table('Trips')->insert(['trip_id' => 1, 'vehicle_id' => 1, 'driver_id' => 1, 'route_id' => 1, 'start_time' => '2026-09-20 08:00:00', 'end_time' => '2026-09-20 09:00:00', 'status' => 'Completed']);
        $dataset = app(TransportDatabase::class)->adminDataset();
        $this->assertSame(60.0, $dataset['trips'][0]['duration']);
        $this->assertSame('2026-09-20T00:00:00+00:00', $dataset['trips'][0]['departure']);
        $this->assertNull($dataset['trips'][0]['arrivalLate']);
        $this->assertSame(Schema::hasTable('Passenger_Journeys'), $dataset['meta']['journeysAvailable']);
        $this->assertSame([], $dataset['journeys']);
    }

    public function test_local_sample_routes_keep_trip_links_and_use_local_terminal_names(): void
    {
        config(['transport.sample_data' => true]);
        foreach (config('sample_routes.routes') as $id => $sample) {
            DB::table('Routes')->insert(['route_id' => $id, 'route_name' => $sample['legacy'], 'origin' => 'Old origin', 'destination' => 'Old destination']);
        }
        foreach ([1 => [1, 'Laoag Terminal', 18.1975, 120.5931], 2 => [1, 'San Nicolas Plaza Stop', 18.1725, 120.596], 4 => [1, 'Batac Terminal', 18.0553, 120.5636]] as $id => $stop) {
            DB::table('Stops')->insert(['stop_id' => $id, 'route_id' => $stop[0], 'stop_name' => $stop[1], 'latitude' => $stop[2], 'longitude' => $stop[3]]);
        }
        foreach (config('sample_routes.stops') as $id => $sample) {
            DB::table('Stops')->insert(['stop_id' => $id, 'route_id' => $sample['route_id'], 'stop_name' => $sample['legacy'], 'latitude' => 0, 'longitude' => 0]);
        }
        DB::table('Trips')->insert(['trip_id' => 77, 'vehicle_id' => 1, 'driver_id' => 1, 'route_id' => 4, 'start_time' => '2026-10-25 08:00:00', 'status' => 'Scheduled']);
        $this->artisan('transport:localize-samples')->assertSuccessful();
        $this->assertDatabaseHas('Trips', ['trip_id' => 77, 'route_id' => 4, 'status' => 'Scheduled']);
        $this->assertDatabaseHas('Routes', ['route_id' => 1, 'route_name' => 'Batac–Laoag', 'origin' => 'Batac Terminal', 'destination' => 'Laoag Terminal']);
        $this->assertDatabaseHas('Stops', ['stop_id' => 13, 'stop_name' => 'San Nicolas Terminal', 'latitude' => 18.1725]);
        $this->assertDatabaseCount('Routes', 5);
        $this->assertDatabaseCount('Stops', 7);
        $this->artisan('transport:localize-samples')->assertSuccessful();
        $this->assertDatabaseCount('Stops', 7);
    }

    public function test_localizer_refuses_non_sample_or_custom_route_records(): void
    {
        config(['transport.sample_data' => false]);
        $this->artisan('transport:localize-samples')->assertFailed();
        config(['transport.sample_data' => true]);
        DB::table('Routes')->insert(['route_id' => 1, 'route_name' => 'Custom operator route', 'origin' => 'Existing origin', 'destination' => 'Existing destination']);
        $this->artisan('transport:localize-samples')->assertFailed();
        $this->assertDatabaseHas('Routes', ['route_id' => 1, 'route_name' => 'Custom operator route', 'origin' => 'Existing origin']);
    }
}

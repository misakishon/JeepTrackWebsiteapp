<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TransportDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MySQLSimulationVerificationTest extends TestCase
{
    public function test_mysql_puj_database_has_five_demo_drivers_and_simulations(): void
    {
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'PUJ']);
        DB::purge('mysql');
        $conn = DB::connection('mysql');

        // 1. Verify five demo driver users exist
        $demoEmails = [
            'demodriver1@puj.com',
            'demodriver2@puj.com',
            'demodriver3@puj.com',
            'demodriver4@puj.com',
            'demodriver5@puj.com',
        ];

        $users = $conn->table('Users')->whereIn('email', $demoEmails)->get();
        $this->assertCount(5, $users);

        foreach ($users as $u) {
            $this->assertSame('Driver', $u->role);
            $driver = $conn->table('Drivers')->where('user_id', $u->user_id)->first();
            $this->assertNotNull($driver);
        }

        // 2. Verify five jeepneys exist
        $plates = ['JEP-101', 'JEP-102', 'JEP-103', 'JEP-104', 'JEP-105'];
        $vehicles = $conn->table('Vehicles')->whereIn('plate_number', $plates)->get();
        $this->assertCount(5, $vehicles);
        foreach ($vehicles as $v) {
            $this->assertSame('Jeepney', $v->type);
        }

        // 3. Verify five active trips on local routes around Batac, Laoag, San Nicolas, and Paoay
        $trips = $conn->table('Trips')->whereIn('route_id', [1, 2, 3, 4, 5])->where('status', 'In Progress')->get();
        $this->assertGreaterThanOrEqual(5, $trips->count());

        // 4. Verify simulation service returns 5 moving jeepneys labeled "Demo simulation — not real GPS"
        $simService = app(\App\Services\DemoSimulationService::class);
        $positions = $simService->getSimulatedPositions();
        $this->assertCount(5, $positions);

        foreach ($positions as $sim) {
            $this->assertTrue($sim['is_simulation']);
            $this->assertSame('Demo simulation — not real GPS', $sim['simulation_label']);
            $this->assertSame('Jeepney', $sim['vehicle_type']);
            $this->assertNotEmpty($sim['route_name']);
            $this->assertNotEmpty($sim['driver_name']);
        }

        // 5. Verify real GPS tracking points remain separate and unaffected (no fake points in real tracking tables)
        $this->assertSame(0, $conn->table('Trip_GPS_Points')->whereIn('trip_id', [11, 12, 13, 14, 15])->count());
        $this->assertSame(0, $conn->table('GPS_Locations')->whereIn('vehicle_id', [11, 12, 13, 14, 15])->count());
    }
}

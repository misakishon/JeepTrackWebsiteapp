<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DemoSimulationService
{
    /**
     * Standard waypoint coordinates for the five Ilocos Norte routes.
     * Each waypoint is [longitude, latitude].
     */
    public const ROUTE_WAYPOINTS = [
        1 => [ // Batac–Laoag via MacArthur Hwy & San Nicolas
            [120.5636, 18.0553], // Batac Terminal
            [120.5641, 18.0567], // City Hall
            [120.5680, 18.0850], // San Mateo
            [120.5938, 18.1729], // San Nicolas Plaza
            [120.5926, 18.1788], // Robinsons Ilocos
            [120.5908, 18.1913], // Gilbert Bridge
            [120.5931, 18.1878], // Laoag Public Market
            [120.5931, 18.1975], // Laoag Terminal
        ],
        2 => [ // Batac–Paoay via Paoay-Batac Rd
            [120.5636, 18.0553], // Batac Terminal
            [120.5641, 18.0567], // Batac City Hall
            [120.5494, 18.0568], // Quiling
            [120.5215, 18.0615], // Paoay Church / Town Plaza
        ],
        3 => [ // Paoay–Laoag via Suba & Suba-Nangalisan Rd
            [120.5215, 18.0615], // Paoay Church / Town Plaza
            [120.5394, 18.1200], // Paoay Lake / Suba
            [120.5480, 18.1400], // Nangalisan
            [120.5908, 18.1913], // Gilbert Bridge
            [120.5931, 18.1878], // Laoag Public Market
            [120.5931, 18.1975], // Laoag Terminal
        ],
        4 => [ // Laoag–San Nicolas (across Laoag River via Gilbert Bridge)
            [120.5931, 18.1975], // Laoag Terminal
            [120.5908, 18.1913], // Gilbert Bridge
            [120.5926, 18.1788], // Robinsons Ilocos
            [120.5938, 18.1729], // San Nicolas Plaza
        ],
        5 => [ // San Nicolas–Batac (Southbound on MacArthur Hwy)
            [120.5938, 18.1729], // San Nicolas Plaza
            [120.5680, 18.0850], // San Mateo
            [120.5636, 18.0553], // Batac Terminal
        ],
    ];

    /**
     * Demo driver configuration specifications.
     */
    public const DEMO_DRIVERS = [
        [
            'email' => 'demodriver1@puj.com',
            'fullname' => 'Ramon Bautista (Demo Driver)',
            'license_number' => 'DL-DEMO-001',
            'plate_number' => 'JEP-101',
            'type' => 'Jeepney',
            'capacity' => 22,
            'route_id' => 1,
            'route_name' => 'Batac–Laoag',
            'base_speed' => 20.0,
            'cycle_seconds' => 90,
            'offset_seconds' => 0,
        ],
        [
            'email' => 'demodriver2@puj.com',
            'fullname' => 'Eduardo Santos (Demo Driver)',
            'license_number' => 'DL-DEMO-002',
            'plate_number' => 'JEP-102',
            'type' => 'Jeepney',
            'capacity' => 20,
            'route_id' => 2,
            'route_name' => 'Batac–Paoay',
            'base_speed' => 19.5,
            'cycle_seconds' => 75,
            'offset_seconds' => 15,
        ],
        [
            'email' => 'demodriver3@puj.com',
            'fullname' => 'Rodrigo Cruz (Demo Driver)',
            'license_number' => 'DL-DEMO-003',
            'plate_number' => 'JEP-103',
            'type' => 'Jeepney',
            'capacity' => 20,
            'route_id' => 3,
            'route_name' => 'Paoay–Laoag',
            'base_speed' => 21.0,
            'cycle_seconds' => 100,
            'offset_seconds' => 30,
        ],
        [
            'email' => 'demodriver4@puj.com',
            'fullname' => 'Danilo Ramos (Demo Driver)',
            'license_number' => 'DL-DEMO-004',
            'plate_number' => 'JEP-104',
            'type' => 'Jeepney',
            'capacity' => 22,
            'route_id' => 4,
            'route_name' => 'Laoag–San Nicolas',
            'base_speed' => 19.0,
            'cycle_seconds' => 60,
            'offset_seconds' => 10,
        ],
        [
            'email' => 'demodriver5@puj.com',
            'fullname' => 'Arnel Garcia (Demo Driver)',
            'license_number' => 'DL-DEMO-005',
            'plate_number' => 'JEP-105',
            'type' => 'Jeepney',
            'capacity' => 20,
            'route_id' => 5,
            'route_name' => 'San Nicolas–Batac',
            'base_speed' => 20.5,
            'cycle_seconds' => 85,
            'offset_seconds' => 45,
        ],
    ];

    /**
     * Ensure the 5 demo driver accounts, vehicles, and active trips exist in the MySQL database.
     * Preserves all existing records.
     */
    public function seedDemoData(): array
    {
        $created = [
            'drivers' => [],
            'vehicles' => [],
            'trips' => [],
        ];

        DB::transaction(function () use (&$created) {
            $now = CarbonImmutable::now('UTC');
            $legacyTime = $now->setTimezone(config('transport.legacy_timezone', 'Asia/Manila'))->format('Y-m-d H:i:s');
            $utcFormatted = $now->format('Y-m-d H:i:s.v');

            foreach (self::DEMO_DRIVERS as $index => $cfg) {
                // 1. User
                $user = User::where('email', $cfg['email'])->first();
                if (! $user) {
                    $user = new User();
                    $user->email = $cfg['email'];
                    $user->fullname = $cfg['fullname'];
                    $user->role = 'Driver';
                    $user->password = Hash::make('password123');
                    $user->save();
                } else {
                    $user->role = 'Driver';
                    $user->fullname = $cfg['fullname'];
                    $user->save();
                }

                // 2. Driver
                $driver = Driver::where('user_id', $user->user_id)->first();
                if (! $driver) {
                    $driver = new Driver();
                    $driver->user_id = $user->user_id;
                    $driver->license_number = $cfg['license_number'];
                    $driver->save();
                }

                // 3. Vehicle (Jeepney)
                $vehicle = Vehicle::where('plate_number', $cfg['plate_number'])->first();
                if (! $vehicle) {
                    $vehicle = new Vehicle();
                    $vehicle->plate_number = $cfg['plate_number'];
                    $vehicle->type = $cfg['type'];
                    $vehicle->capacity = $cfg['capacity'];
                    $vehicle->save();
                } else {
                    $vehicle->type = $cfg['type'];
                    $vehicle->capacity = $cfg['capacity'];
                    $vehicle->save();
                }

                // 4. Active Trip
                $trip = Trip::where('driver_id', $driver->driver_id)
                    ->where('vehicle_id', $vehicle->vehicle_id)
                    ->where('route_id', $cfg['route_id'])
                    ->where('status', 'In Progress')
                    ->first();

                if (! $trip) {
                    $trip = new Trip();
                    $trip->driver_id = $driver->driver_id;
                    $trip->vehicle_id = $vehicle->vehicle_id;
                    $trip->route_id = $cfg['route_id'];
                    $trip->status = 'In Progress';
                    $trip->start_time = $legacyTime;
                    $trip->end_time = null;
                    if (Schema::hasColumn('Trips', 'actual_departure_at')) {
                        $trip->actual_departure_at = $utcFormatted;
                    }
                    if (Schema::hasColumn('Trips', 'scheduled_departure_at')) {
                        $trip->scheduled_departure_at = $now->subMinutes(10)->format('Y-m-d H:i:s.v');
                    }
                    if (Schema::hasColumn('Trips', 'travel_direction')) {
                        $trip->travel_direction = 'Outbound';
                    }
                    $trip->save();
                }

                $created['drivers'][] = [
                    'user_id' => $user->user_id,
                    'driver_id' => $driver->driver_id,
                    'email' => $user->email,
                    'fullname' => $user->fullname,
                ];
                $created['vehicles'][] = [
                    'vehicle_id' => $vehicle->vehicle_id,
                    'plate_number' => $vehicle->plate_number,
                ];
                $created['trips'][] = [
                    'trip_id' => $trip->trip_id,
                    'route_id' => $cfg['route_id'],
                    'route_name' => $cfg['route_name'],
                ];
            }
        });

        return $created;
    }

    public function getRouteWaypoints(int $routeId): array
    {
        $routes = config('road_routes');
        if (! empty($routes[$routeId])) {
            return $routes[$routeId];
        }

        return self::ROUTE_WAYPOINTS[$routeId] ?? [];
    }

    /**
     * Calculate current moving positions for all active demo simulated jeepneys.
     * Keep simulated locations separate from real tracking.
     */
    public function getSimulatedPositions(?int $forVehicleId = null): array
    {
        if (! config('transport.sample_data')) {
            return [];
        }
        $now = CarbonImmutable::now('UTC');
        $currentTimeSeconds = microtime(true);
        $results = [];

        // Check if DB is available and query demo trips
        $demoEmails = array_column(self::DEMO_DRIVERS, 'email');
        $users = User::whereIn('email', $demoEmails)->get()->keyBy('email');

        foreach (self::DEMO_DRIVERS as $index => $cfg) {
            $user = $users->get($cfg['email']);
            if (! $user) {
                continue;
            }

            $driver = Driver::where('user_id', $user->user_id)->first();
            $vehicle = Vehicle::where('plate_number', $cfg['plate_number'])->first();
            if (! $driver || ! $vehicle) {
                continue;
            }

            if ($forVehicleId !== null && (int) $vehicle->vehicle_id !== $forVehicleId) {
                continue;
            }

            $trip = Trip::where('driver_id', $driver->driver_id)
                ->where('vehicle_id', $vehicle->vehicle_id)
                ->where('status', 'In Progress')
                ->first();

            // Real trip fixes always take priority, including a stale last fix.
            // A completed or cancelled demo assignment must stop moving too.
            if (! $trip || (Schema::hasTable('Trip_GPS_Points') && DB::table('Trip_GPS_Points')->where('trip_id', $trip->trip_id)->exists())) {
                continue;
            }

            $waypoints = $this->getRouteWaypoints($cfg['route_id']);
            if (empty($waypoints)) {
                continue;
            }

            // Calculate progress along route (ping-pong movement)
            $cycle = $cfg['cycle_seconds'] ?: 90;
            $offset = $cfg['offset_seconds'] ?: 0;
            $elapsed = fmod(($currentTimeSeconds + $offset), ($cycle * 2));
            $isReverse = $elapsed > $cycle;
            $progress = $isReverse ? (1 - ($elapsed - $cycle) / $cycle) : ($elapsed / $cycle);

            // Interpolate position along waypoints
            $pos = $this->interpolatePosition($waypoints, $progress, $isReverse, $cfg['base_speed']);

            $results[] = [
                'is_simulation' => true,
                'simulation_label' => 'Demo simulation — not real GPS',
                'trip_id' => $trip ? (int) $trip->trip_id : null,
                'vehicle_id' => (int) $vehicle->vehicle_id,
                'driver_id' => (int) $driver->driver_id,
                'driver_name' => $user->fullname,
                'plate_number' => $vehicle->plate_number,
                'vehicle_type' => $vehicle->type ?: 'Jeepney',
                'capacity' => (int) $vehicle->capacity,
                'route_id' => (int) $cfg['route_id'],
                'route_name' => $cfg['route_name'],
                'latitude' => $pos['latitude'],
                'longitude' => $pos['longitude'],
                'speed' => $pos['speed'],
                'heading' => $pos['heading'],
                'direction' => $isReverse ? 'Inbound' : 'Outbound',
                'timestamp' => $now->toIso8601String(),
                'is_stale' => false,
                'waypoints' => $waypoints,
                'cycle_seconds' => $cycle,
                'offset_seconds' => $offset,
            ];
        }

        return $results;
    }

    /**
     * Interpolates latitude, longitude, and bearing along a series of waypoints.
     */
    public function interpolatePosition(array $waypoints, float $progress, bool $isReverse, float $baseSpeed): array
    {
        $count = count($waypoints);
        if ($count === 0) {
            return ['latitude' => 18.0553, 'longitude' => 120.5636, 'heading' => 0, 'speed' => $baseSpeed];
        }
        if ($count === 1) {
            return ['latitude' => $waypoints[0][1], 'longitude' => $waypoints[0][0], 'heading' => 0, 'speed' => $baseSpeed];
        }

        // Clamp progress
        $progress = max(0.0, min(1.0, $progress));

        // Compute segment lengths
        $segmentDistances = [];
        $totalDistance = 0.0;
        for ($i = 0; $i < $count - 1; $i++) {
            $dx = $waypoints[$i + 1][0] - $waypoints[$i][0];
            $dy = $waypoints[$i + 1][1] - $waypoints[$i][1];
            $dist = sqrt($dx * $dx + $dy * $dy);
            $segmentDistances[] = $dist;
            $totalDistance += $dist;
        }

        if ($totalDistance <= 0.000001) {
            return ['latitude' => $waypoints[0][1], 'longitude' => $waypoints[0][0], 'heading' => 0, 'speed' => $baseSpeed];
        }

        $targetDistance = $progress * $totalDistance;
        $accumulated = 0.0;
        $lat = $waypoints[0][1];
        $lng = $waypoints[0][0];
        $heading = 0.0;

        for ($i = 0; $i < $count - 1; $i++) {
            $segDist = $segmentDistances[$i];
            if ($accumulated + $segDist >= $targetDistance || $i === $count - 2) {
                $segProgress = $segDist > 0 ? max(0.0, min(1.0, ($targetDistance - $accumulated) / $segDist)) : 0;
                $lng = $waypoints[$i][0] + ($waypoints[$i + 1][0] - $waypoints[$i][0]) * $segProgress;
                $lat = $waypoints[$i][1] + ($waypoints[$i + 1][1] - $waypoints[$i][1]) * $segProgress;

                // Bearing
                $dx = $waypoints[$i + 1][0] - $waypoints[$i][0];
                $dy = $waypoints[$i + 1][1] - $waypoints[$i][1];
                if ($isReverse) {
                    $dx = -$dx;
                    $dy = -$dy;
                }
                $heading = (rad2deg(atan2($dx, $dy)) + 360) % 360;
                break;
            }
            $accumulated += $segDist;
        }

        // Add slight realistic speed variation +/- 3 km/h
        $speed = round($baseSpeed + sin($progress * M_PI * 4) * 3, 1);

        return [
            'latitude' => round($lat, 7),
            'longitude' => round($lng, 7),
            'heading' => round($heading, 1),
            'speed' => $speed,
        ];
    }
}

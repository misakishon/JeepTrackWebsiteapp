<?php

namespace App\Services;

use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransportDatabase
{
    public function adminDataset(): array
    {
        $routes = DB::table('Routes')->get();
        $stops = DB::table('Stops')->get();
        $drivers = DB::table('Drivers')->join('Users', 'Users.user_id', '=', 'Drivers.user_id')->select('Drivers.driver_id', 'Users.fullname')->get();
        $vehicles = DB::table('Vehicles')->get();
        $trips = DB::table('Trips')->get();
        $feedback = DB::table('Feedback')->get(['feedback_id', 'rating', 'comments']);
        $routeId = fn ($id) => 'route-'.$id;
        $driverId = fn ($id) => 'driver-'.$id;
        $vehicleId = fn ($id) => 'vehicle-'.$id;
        $parse = fn ($time) => $time ? CarbonImmutable::parse($time, config('transport.legacy_timezone')) : null;
        $dates = $trips->pluck('start_time')->filter()->sort()->values();

        $dataset = [
            'routes' => $routes->map(fn ($r) => ['id' => $routeId($r->route_id), 'name' => $r->route_name, 'origin' => $r->origin, 'destination' => $r->destination, 'stops' => $stops->where('route_id', $r->route_id)->pluck('stop_name')->values()->all(), 'stopCoordinates' => $stops->where('route_id', $r->route_id)->map(fn ($s) => ['stop_id' => $s->stop_id, 'label' => $s->stop_name, 'latitude' => (float) $s->latitude, 'longitude' => (float) $s->longitude])->values()->all(), 'scheduled' => null, 'km' => null, 'mapAvailable' => true])->all(),
            'drivers' => $drivers->map(fn ($d) => ['id' => $driverId($d->driver_id), 'name' => $d->fullname, 'vehicle' => null, 'status' => 'Unavailable'])->all(),
            'vehicles' => $vehicles->map(fn ($v) => ['id' => $vehicleId($v->vehicle_id), 'plate' => $v->plate_number, 'capacity' => (int) $v->capacity, 'type' => $v->type, 'status' => 'Unavailable', 'denominator' => null])->all(),
            'trips' => $trips->map(function ($t) use ($routeId, $driverId, $vehicleId, $parse) {
                $start = ! empty($t->actual_departure_at) ? CarbonImmutable::parse($t->actual_departure_at, 'UTC') : $parse($t->start_time);
                $end = ! empty($t->actual_arrival_at) ? CarbonImmutable::parse($t->actual_arrival_at, 'UTC') : $parse($t->end_time);
                $scheduled = ! empty($t->scheduled_departure_at) ? CarbonImmutable::parse($t->scheduled_departure_at, 'UTC') : ($t->status === 'Scheduled' ? $parse($t->start_time) : null);
                $actual = in_array($t->status, ['Completed', 'In Progress'], true);

                return ['id' => 'trip-'.$t->trip_id, 'date' => $start?->setTimezone(config('transport.legacy_timezone'))->format('Y-m-d'), 'route' => $routeId($t->route_id), 'driver' => $driverId($t->driver_id), 'vehicle' => $vehicleId($t->vehicle_id), 'status' => $t->status, 'direction' => null, 'scheduledDeparture' => $scheduled?->utc()->toIso8601String(), 'scheduledArrival' => null, 'departure' => $actual ? $start?->utc()->toIso8601String() : null, 'arrival' => $actual ? $end?->utc()->toIso8601String() : null, 'duration' => $actual && $start && $end && $end->greaterThanOrEqualTo($start) ? $start->diffInMinutes($end) : null, 'departureLate' => $actual && $scheduled && $start ? $scheduled->diffInMinutes($start, false) : null, 'arrivalLate' => null, 'distance' => null, 'gps' => null, 'delayReason' => null];
            })->all(),
            'journeys' => [],
            'locations' => $this->latestLocations($vehicles->pluck('vehicle_id')->all(), $trips->where('status', 'In Progress')->pluck('trip_id')->all()),
            // This schema has no feedback timestamp, route, trip, category, or resolution fields.
            'feedback' => $feedback->map(fn ($f) => ['id' => 'feedback-'.$f->feedback_id, 'date' => null, 'trip' => null, 'journey' => null, 'route' => null, 'driver' => null, 'vehicle' => null, 'category' => null, 'type' => null, 'rating' => $f->rating === null ? null : (int) $f->rating, 'status' => 'Unavailable', 'message' => $f->comments])->all(),
            'meta' => ['source' => 'MySQL · '.config('database.connections.mysql.database'), 'sample' => config('transport.sample_data'), 'journeysAvailable' => false, 'scheduleAvailable' => false, 'trackingAvailable' => DB::table('GPS_Locations')->exists(), 'feedbackDated' => false, 'undatedFeedback' => $feedback->count(), 'registeredPassengers' => DB::table('Passengers')->count(), 'from' => $dates->isNotEmpty() ? $parse($dates->first())->format('Y-m-d') : null, 'to' => $dates->isNotEmpty() ? $parse($dates->last())->format('Y-m-d') : null, 'coverage' => 'Connected to MySQL. GPS-enabled trips preserve scheduled departure and actual UTC timestamps. Passenger journeys, scheduled arrivals, available vehicle hours, and dated feedback remain unavailable. Stops have no configured order. Legacy DATETIME values are interpreted in Asia/Manila.'],
            'simulatedVehicles' => app(DemoSimulationService::class)->getSimulatedPositions(),
        ];

        return Schema::hasTable('Analytics_Settings') ? app(AnalyticsRecords::class)->enrich($dataset) : $dataset;
    }

    public function forUser(User $user): array
    {
        $profile = $user->only('user_id', 'fullname', 'email', 'role');
        if ($user->role === 'Driver') {
            $driver = $user->driver;
            $trips = $driver ? $driver->trips()->with(['vehicle', 'route.stops'])->get() : collect();
            $activeVehicle = $trips->where('status', 'In Progress')->first()?->vehicle_id;

            return ['profile' => $profile, 'driver' => $driver?->only('driver_id', 'license_number'), 'trips' => $trips->map(fn ($trip) => $this->serializeTrip($trip))->all(), 'locations' => $this->latestLocations($trips->pluck('vehicle_id')->unique()->all(), $trips->where('status', 'In Progress')->pluck('trip_id')->all()), 'simulatedVehicles' => $activeVehicle ? app(DemoSimulationService::class)->getSimulatedPositions((int) $activeVehicle) : [], 'tracking' => ['available' => Schema::hasTable('Trip_GPS_Points'), 'upload_interval_ms' => 5000, 'poll_interval_ms' => 3000, 'stale_seconds' => 30], 'sample_data' => config('transport.sample_data'), 'coverage' => 'GPS is shared during your active trip while this browser is open. Delay reports are saved against your active trip. Manual passenger counts are not collected.'];
        }
        if ($user->role === 'Passenger') {
            $vehicles = DB::table('Vehicles')->get(['vehicle_id', 'plate_number', 'type', 'capacity']);
            $active = Trip::select('trip_id', 'vehicle_id', 'route_id', 'start_time', 'end_time', 'status')->where('status', 'In Progress')->with(['vehicle', 'route.stops'])->get();

            return ['profile' => $profile, 'routes' => TransportRoute::with('stops')->get(), 'vehicles' => $vehicles, 'activeTrips' => $active->map(fn ($trip) => $this->serializeTrip($trip))->all(), 'locations' => $this->latestLocations($vehicles->pluck('vehicle_id')->all(), $active->pluck('trip_id')->all()), 'simulatedVehicles' => app(DemoSimulationService::class)->getSimulatedPositions(), 'tracking' => ['available' => Schema::hasTable('Trip_GPS_Points'), 'poll_interval_ms' => 3000, 'stale_seconds' => 30], 'feedback' => $this->passengerFeedback($user), 'journeys' => $this->passengerJourneys($user), 'sample_data' => config('transport.sample_data'), 'coverage' => 'Passenger maps refresh active vehicle locations every few seconds. Stale positions are labeled. Boarding, arrival, correction, and feedback records are saved to your account. ETA remains unavailable without a prediction service.'];
        }

        return ['profile' => $profile, 'dataset' => $this->adminDataset()];
    }

    private function passengerFeedback(User $user): array
    {
        if (! $user->passenger) return [];
        $columns = ['feedback_id', 'passenger_id', 'rating', 'comments'];
        if (Schema::hasTable('Analytics_Settings')) $columns = array_merge($columns, ['journey_id', 'trip_id', 'submitted_at', 'category', 'feedback_type', 'resolution_status', 'admin_response', 'resolved_at']);
        return $user->passenger->feedback()->get($columns)->toArray();
    }

    private function passengerJourneys(User $user): ?array
    {
        if (! Schema::hasTable('Passenger_Journeys')) return null;
        if (! $user->passenger) return [];
        return DB::table('Passenger_Journeys')->where('passenger_id', $user->passenger->passenger_id)->orderByDesc('boarded_at')->get()->map(function ($j) {
            $record = (array) $j;
            foreach (['boarded_at', 'alighted_at', 'corrected_at'] as $field) $record[$field] = $j->$field ? CarbonImmutable::parse($j->$field, 'UTC')->toIso8601String() : null;
            $trip = Trip::with(['route.stops', 'vehicle'])->find($j->trip_id);
            $record['trip'] = $trip ? $this->serializeTrip($trip) : null;
            return $record;
        })->all();
    }

    public function serializeTrip(Trip $trip): array
    {
        $record = $trip->toArray();
        foreach (['start_time', 'end_time'] as $field) {
            $value = $trip->getRawOriginal($field);
            $record[$field] = $value ? CarbonImmutable::parse($value, config('transport.legacy_timezone'))->utc()->toIso8601String() : null;
        }
        foreach (['scheduled_departure_at', 'actual_departure_at', 'actual_arrival_at'] as $field) {
            $value = $trip->getRawOriginal($field);
            $record[$field] = $value ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
        }
        if ($record['actual_departure_at']) {
            $record['start_time'] = $record['actual_departure_at'];
        }
        if ($record['actual_arrival_at']) {
            $record['end_time'] = $record['actual_arrival_at'];
        }

        return $record;
    }

    private function latestLocations(array $vehicleIds, ?array $tripIds = null): array
    {
        if (! $vehicleIds) {
            return [];
        }
        if (Schema::hasTable('Trip_GPS_Points')) {
            if (! $tripIds) {
                return [];
            }
            $latest = DB::table('Trip_GPS_Points')->select('trip_id')->selectRaw('MAX(captured_at) AS latest_time')->whereIn('trip_id', $tripIds)->whereIn('vehicle_id', $vehicleIds)->groupBy('trip_id');

            return DB::table('Trip_GPS_Points as gps')->joinSub($latest, 'latest', function ($join) {
                $join->on('gps.trip_id', '=', 'latest.trip_id')->on('gps.captured_at', '=', 'latest.latest_time');
            })->join('Trips as active_trip', 'active_trip.trip_id', '=', 'gps.trip_id')->where('active_trip.status', 'In Progress')->whereColumn('gps.vehicle_id', 'active_trip.vehicle_id')->whereColumn('gps.driver_id', 'active_trip.driver_id')->select('gps.*')->whereIn('gps.vehicle_id', $vehicleIds)->orderByDesc('gps.point_id')->get()->unique('trip_id')->map(fn ($p) => TripTracking::location($p))->values()->all();
        }
        if ($tripIds !== null) {
            $vehicleIds = DB::table('Trips')->whereIn('trip_id', $tripIds)->where('status', 'In Progress')->pluck('vehicle_id')->intersect($vehicleIds)->all();
            if (! $vehicleIds) {
                return [];
            }
        }
        $latest = DB::table('GPS_Locations')->select('vehicle_id')->selectRaw('MAX(timestamp) AS latest_time')->whereIn('vehicle_id', $vehicleIds)->groupBy('vehicle_id');

        return DB::table('GPS_Locations as gps')->joinSub($latest, 'latest', function ($join) {
            $join->on('gps.vehicle_id', '=', 'latest.vehicle_id')->on('gps.timestamp', '=', 'latest.latest_time');
        })->select('gps.*')->orderByDesc('gps.gps_id')->get()->unique('vehicle_id')->map(fn ($p) => ['vehicle_id' => (int) $p->vehicle_id, 'latitude' => (float) $p->latitude, 'longitude' => (float) $p->longitude, 'speed' => $p->speed === null ? null : (float) $p->speed, 'timestamp' => CarbonImmutable::parse($p->timestamp, config('transport.legacy_timezone'))->utc()->toIso8601String()])->values()->all();
    }
}

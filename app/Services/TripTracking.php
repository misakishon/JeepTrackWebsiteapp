<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TripTracking
{
    public function assertReady(): void
    {
        abort_unless(Schema::hasTable('Trip_GPS_Points') && Schema::hasColumn('Trips', 'actual_departure_at'), 503, 'Trip tracking setup has not been applied. Contact your administrator.');
    }

    public function validatePoint(array $point): array
    {
        $point = validator($point, [
            'client_point_id' => 'required|uuid',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy_m' => 'required|numeric|between:0,1000',
            'speed_kmh' => 'nullable|numeric|between:0,300',
            'captured_at' => 'required|date',
        ])->validate();
        $captured = CarbonImmutable::parse($point['captured_at'])->utc();
        if ($captured->lessThan(CarbonImmutable::now('UTC')->subSeconds(120)) || $captured->greaterThan(CarbonImmutable::now('UTC')->addSeconds(15))) {
            throw ValidationException::withMessages(['captured_at' => 'The GPS fix is too old or the device clock is incorrect. Get a fresh location and retry.']);
        }
        $point['captured_at'] = $captured->format('Y-m-d H:i:s.v');

        return $point;
    }

    private function lockDriver(User $user): object
    {
        abort_unless($user->role === 'Driver', 403, 'Only the assigned driver can operate this trip.');
        $driver = DB::table('Drivers')->where('user_id', $user->getKey())->lockForUpdate()->first();
        abort_unless($driver, 403, 'No driver record is assigned to this account.');

        return $driver;
    }

    public function start(User $user, int $id, array $confirmation, array $point): Trip
    {
        $this->assertReady();
        $point = $this->validatePoint($point);

        return DB::transaction(function () use ($user, $id, $confirmation, $point) {
            $driver = $this->lockDriver($user);
            $trip = Trip::where('driver_id', $driver->driver_id)->lockForUpdate()->findOrFail($id);
            if ((int) $confirmation['vehicle_id'] !== (int) $trip->vehicle_id || (int) $confirmation['route_id'] !== (int) $trip->route_id) {
                throw ValidationException::withMessages(['trip' => 'The vehicle or route does not match this assignment. Refresh the trip and confirm again.']);
            }
            DB::table('Vehicles')->where('vehicle_id', $trip->vehicle_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($trip->status, ['Scheduled', 'In Progress'], true), 409, 'Only a scheduled trip can be started.');
            abort_if(Trip::where('status', 'In Progress')->where('trip_id', '!=', $id)->where(function ($q) use ($trip) {
                $q->where('driver_id', $trip->driver_id)->orWhere('vehicle_id', $trip->vehicle_id);
            })->exists(), 409, 'The driver or assigned vehicle already has an active trip. End that trip first.');
            if ($trip->status === 'Scheduled') {
                $now = CarbonImmutable::now('UTC');
                $trip->scheduled_departure_at = $trip->getRawOriginal('start_time') ? CarbonImmutable::parse($trip->getRawOriginal('start_time'), config('transport.legacy_timezone'))->utc()->format('Y-m-d H:i:s.v') : null;
                $trip->actual_departure_at = $now->format('Y-m-d H:i:s.v');
                $trip->start_time = $now->setTimezone(config('transport.legacy_timezone'))->format('Y-m-d H:i:s');
                $trip->end_time = null;
                $trip->actual_arrival_at = null;
                $trip->status = 'In Progress';
                $trip->save();
            }
            $this->storePoint($trip, $driver->driver_id, $point);

            return $trip->fresh(['vehicle', 'route.stops']);
        }, 3);
    }

    public function setRoute(User $user, int $routeId, ?int $vehicleId = null, ?int $tripId = null): Trip
    {
        $this->assertReady();

        return DB::transaction(function () use ($user, $routeId, $vehicleId, $tripId) {
            $driver = $this->lockDriver($user);
            abort_if(Trip::where('driver_id', $driver->driver_id)->where('status', 'In Progress')->exists(), 409, 'End your active trip before setting another route.');
            $trip = $tripId ? Trip::where('driver_id', $driver->driver_id)->lockForUpdate()->findOrFail($tripId) : null;
            if ($trip) {
                abort_unless($trip->status === 'Scheduled', 409, 'Only a scheduled trip can change route.');
                $vehicleId = (int) $trip->vehicle_id;
            }
            DB::table('Routes')->where('route_id', $routeId)->firstOrFail();
            DB::table('Vehicles')->where('vehicle_id', $vehicleId)->lockForUpdate()->firstOrFail();
            abort_if(Trip::where('vehicle_id', $vehicleId)->where('status', 'In Progress')->exists(), 409, 'This jeepney is already on an active trip. Choose another vehicle.');
            abort_if(Trip::where('vehicle_id', $vehicleId)->where('driver_id', '!=', $driver->driver_id)->where('status', 'Scheduled')->exists(), 409, 'This jeepney has a scheduled assignment for another driver. Choose another vehicle.');
            if (! $trip) {
                // Repeated saves reuse the driver's pending trip for this vehicle.
                $trip = Trip::where('driver_id', $driver->driver_id)->where('vehicle_id', $vehicleId)->where('status', 'Scheduled')->orderByDesc('trip_id')->lockForUpdate()->first();
            }
            if (! $trip) {
                $trip = new Trip();
                $trip->driver_id = $driver->driver_id;
                $trip->vehicle_id = $vehicleId;
                $trip->status = 'Scheduled';
                $trip->start_time = CarbonImmutable::now(config('transport.legacy_timezone'))->format('Y-m-d H:i:s');
            }
            if ((int) $trip->route_id !== $routeId) {
                // An arrival estimate or direction for the old route cannot carry over.
                foreach (['scheduled_arrival_at', 'travel_direction'] as $field) {
                    if (Schema::hasColumn('Trips', $field)) $trip->$field = null;
                }
            }
            $trip->route_id = $routeId;
            $trip->save();

            return $trip->fresh(['vehicle', 'route.stops']);
        }, 3);
    }

    public function record(User $user, int $id, array $point): array
    {
        $this->assertReady();
        $point = $this->validatePoint($point);

        return DB::transaction(function () use ($user, $id, $point) {
            $driver = $this->lockDriver($user);
            $trip = Trip::where('driver_id', $driver->driver_id)->lockForUpdate()->findOrFail($id);
            abort_unless($trip->status === 'In Progress', 409, 'GPS sharing is allowed only while this trip is active.');

            return $this->storePoint($trip, $driver->driver_id, $point);
        }, 3);
    }

    private function storePoint(Trip $trip, int $driverId, array $point): array
    {
        $existing = DB::table('Trip_GPS_Points')->where('client_point_id', $point['client_point_id'])->first();
        if ($existing) {
            abort_unless((int) $existing->trip_id === (int) $trip->trip_id && (int) $existing->driver_id === $driverId, 409, 'That GPS update belongs to another trip.');

            return self::location($existing);
        }
        $id = DB::table('Trip_GPS_Points')->insertGetId([
            'trip_id' => $trip->trip_id, 'vehicle_id' => $trip->vehicle_id, 'driver_id' => $driverId,
            'client_point_id' => $point['client_point_id'], 'latitude' => $point['latitude'], 'longitude' => $point['longitude'],
            'accuracy_m' => $point['accuracy_m'], 'speed_kmh' => $point['speed_kmh'] ?? null,
            'captured_at' => $point['captured_at'], 'received_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'),
        ], 'point_id');
        // Keep the supplied vehicle GPS table usable by existing tools. The canonical
        // trip association and UTC timestamps live in Trip_GPS_Points.
        DB::table('GPS_Locations')->insert(['vehicle_id' => $trip->vehicle_id, 'latitude' => $point['latitude'], 'longitude' => $point['longitude'], 'speed' => $point['speed_kmh'] ?? null, 'timestamp' => CarbonImmutable::parse($point['captured_at'], 'UTC')->setTimezone(config('transport.legacy_timezone'))->format('Y-m-d H:i:s')]);

        return self::location(DB::table('Trip_GPS_Points')->where('point_id', $id)->first());
    }

    public function end(User $user, int $id, string $destination, ?string $notes): Trip
    {
        $this->assertReady();

        return DB::transaction(function () use ($user, $id, $destination, $notes) {
            $driver = $this->lockDriver($user);
            $trip = Trip::where('driver_id', $driver->driver_id)->with('route')->lockForUpdate()->findOrFail($id);
            if (trim($destination) !== trim($trip->route->destination)) {
                throw ValidationException::withMessages(['destination' => 'Confirm the assigned destination before ending this trip.']);
            }
            if ($trip->status === 'Completed') {
                return $trip->load('vehicle', 'route.stops');
            }
            abort_unless($trip->status === 'In Progress', 409, 'Only an active trip can be ended.');
            $now = CarbonImmutable::now('UTC');
            $trip->status = 'Completed';
            $trip->actual_arrival_at = $now->format('Y-m-d H:i:s.v');
            $trip->end_time = $now->setTimezone(config('transport.legacy_timezone'))->format('Y-m-d H:i:s');
            $trip->completion_notes = $notes;
            $trip->save();

            return $trip->fresh(['vehicle', 'route.stops']);
        }, 3);
    }

    public static function location(object $point): array
    {
        $captured = CarbonImmutable::parse($point->captured_at, 'UTC');
        $age = max(0, (int) $captured->diffInSeconds(CarbonImmutable::now('UTC'), false));

        return ['point_id' => (int) $point->point_id, 'trip_id' => (int) $point->trip_id, 'vehicle_id' => (int) $point->vehicle_id, 'latitude' => (float) $point->latitude, 'longitude' => (float) $point->longitude, 'accuracy_m' => (float) $point->accuracy_m, 'speed' => $point->speed_kmh === null ? null : (float) $point->speed_kmh, 'timestamp' => $captured->toIso8601String(), 'received_at' => CarbonImmutable::parse($point->received_at,'UTC')->toIso8601String(), 'age_seconds' => $age, 'is_stale' => $age > 30];
    }
}

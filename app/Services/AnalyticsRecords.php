<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsRecords
{
    public function enrich(array $data): array
    {
        $trips = DB::table('Trips')->get()->keyBy('trip_id');
        $stops = DB::table('Stops')->get()->keyBy('stop_id');
        $delays = DB::table('Trip_Delay_Reports')->orderBy('reported_at')->get()->groupBy('trip_id');
        $points = Schema::hasTable('Trip_GPS_Points') ? DB::table('Trip_GPS_Points')->orderBy('captured_at')->get()->groupBy('trip_id') : collect();
        foreach ($data['trips'] as &$trip) {
            $id = (int) substr($trip['id'], 5);
            $stored = $trips[$id];
            $trip['direction'] = $stored->travel_direction;
            $trip['scheduledArrival'] = $this->iso($stored->scheduled_arrival_at);
            $trip['arrivalLate'] = $trip['arrival'] && $trip['scheduledArrival'] ? CarbonImmutable::parse($trip['scheduledArrival'])->diffInMinutes(CarbonImmutable::parse($trip['arrival']), false) : null;
            $trip['delayReason'] = $delays->get($id)?->last()?->reason;
            $gps = $this->distance($points->get($id, collect()), $trip['duration']);
            $trip['distance'] = $gps['distance'];
            $trip['gps'] = $gps['coverage'];
        }
        unset($trip);
        $mapped = collect($data['trips'])->keyBy('id');
        $data['journeys'] = DB::table('Passenger_Journeys')->whereIn('status', ['Active', 'Completed'])->get()->map(function ($j) use ($trips, $stops) {
            $t = $trips->get($j->trip_id);
            if (! $t) return null;
            $at = CarbonImmutable::parse($j->boarded_at, 'UTC')->setTimezone('Asia/Manila');
            return ['id' => 'journey-'.$j->journey_id, 'trip' => 'trip-'.$t->trip_id, 'date' => $at->format('Y-m-d'), 'route' => 'route-'.$t->route_id, 'driver' => 'driver-'.$t->driver_id, 'vehicle' => 'vehicle-'.$t->vehicle_id, 'direction' => $t->travel_direction, 'passenger' => $j->passenger_id ? 'passenger-'.$j->passenger_id : null, 'source' => $j->source, 'reconciliationKey' => $j->request_id, 'boarding' => $stops->get($j->boarding_stop_id)?->stop_name, 'alighting' => $j->alighting_stop_id ? $stops->get($j->alighting_stop_id)?->stop_name : null, 'hour' => $at->hour, 'dayOfWeek' => $at->dayOfWeek];
        })->filter()->values()->all();
        $data['feedback'] = DB::table('Feedback')->get()->map(function ($f) use ($mapped) {
            $t = $mapped->get('trip-'.$f->trip_id);
            return ['id' => 'feedback-'.$f->feedback_id, 'date' => $f->submitted_at ? CarbonImmutable::parse($f->submitted_at, 'UTC')->setTimezone('Asia/Manila')->format('Y-m-d') : null, 'trip' => $t['id'] ?? null, 'journey' => $f->journey_id ? 'journey-'.$f->journey_id : null, 'route' => $t['route'] ?? null, 'driver' => $t['driver'] ?? null, 'vehicle' => $t['vehicle'] ?? null, 'category' => $f->category, 'type' => $f->feedback_type, 'rating' => $f->rating === null ? null : (int) $f->rating, 'status' => $f->resolution_status ?? 'Unavailable', 'message' => $f->comments, 'response' => $f->admin_response, 'internalNote' => $f->internal_notes, 'resolvedAt' => $this->iso($f->resolved_at)];
        })->all();
        $availability = DB::table('Vehicle_Availability')->get()->groupBy('vehicle_id');
        foreach ($data['vehicles'] as &$vehicle) {
            $id = (int) substr($vehicle['id'], 8);
            $vehicle['availability'] = $availability->get($id, collect())->mapWithKeys(fn ($a) => [$a->date => (float) $a->available_hours])->all();
            $active = $mapped->first(fn ($t) => $t['vehicle'] === $vehicle['id'] && $t['status'] === 'In Progress');
            $vehicle['status'] = $active ? 'Active trip' : 'No active trip';
        }
        unset($vehicle);
        foreach ($data['drivers'] as &$driver) {
            $assigned = $mapped->filter(fn ($t) => $t['driver'] === $driver['id'])->sortByDesc('date')->first();
            $driver['vehicle'] = $assigned['vehicle'] ?? null;
            $driver['status'] = $mapped->contains(fn ($t) => $t['driver'] === $driver['id'] && $t['status'] === 'In Progress') ? 'Active trip' : 'No active trip';
        }
        unset($driver);
        foreach ($data['routes'] as &$route) {
            $scheduled = $mapped->filter(fn ($t) => $t['route'] === $route['id'] && $t['scheduledDeparture'] && $t['scheduledArrival'])->map(fn ($t) => CarbonImmutable::parse($t['scheduledDeparture'])->diffInMinutes(CarbonImmutable::parse($t['scheduledArrival']), false))->filter(fn ($n) => $n >= 0);
            $route['scheduled'] = $scheduled->isNotEmpty() ? $scheduled->avg() : null;
        }
        $allDates = collect($data['trips'])->pluck('date')->merge(collect($data['journeys'])->pluck('date'))->merge(collect($data['feedback'])->pluck('date'))->filter()->sort()->values();
        $data['meta'] = array_merge($data['meta'], ['journeysAvailable' => true, 'scheduleAvailable' => true, 'feedbackDated' => true, 'analyticsReady' => true, 'threshold' => (int) (DB::table('Analytics_Settings')->where('key', 'delay_threshold')->value('value') ?? 5), 'refreshedAt' => CarbonImmutable::now('UTC')->toIso8601String(), 'from' => $allDates->first(), 'to' => $allDates->last(), 'undatedFeedback' => collect($data['feedback'])->whereNull('date')->count(), 'coverage' => 'MySQL records; reporting dates use Asia/Manila. Journey totals use recorded app journeys, with request IDs preventing duplicates. Anonymous manual counts are not yet collected and are excluded. Legacy trips may lack passenger, schedule, or GPS observations. Undated feedback is available in Feedback Management but excluded from date-filtered reports. GPS distance uses valid consecutive points no more than 120 seconds apart; coverage is observed segment time / actual trip duration. Vehicle availability must be configured for every selected day.']);
        return $data;
    }

    private function iso(?string $value): ?string
    {
        return $value ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
    }

    public function distance($points, ?float $duration): array
    {
        $distance = 0; $seconds = 0; $validSegments = 0; $previous = null;
        foreach ($points as $point) {
            if ($previous) {
                $gap = CarbonImmutable::parse($previous->captured_at)->diffInSeconds(CarbonImmutable::parse($point->captured_at), false);
                $lat = deg2rad($point->latitude - $previous->latitude);
                $lng = deg2rad($point->longitude - $previous->longitude);
                $a = sin($lat / 2) ** 2 + cos(deg2rad($previous->latitude)) * cos(deg2rad($point->latitude)) * sin($lng / 2) ** 2;
                $km = 6371 * 2 * asin(sqrt(min(1, max(0, $a))));
                if ($gap > 0 && $gap <= 120 && $km / $gap * 3600 <= 150 && $point->accuracy_m <= 100 && $previous->accuracy_m <= 100) {
                    $distance += $km; $seconds += $gap; $validSegments++;
                }
            }
            $previous = $point;
        }
        return ['distance' => $validSegments ? round($distance, 3) : null, 'coverage' => $validSegments && $duration > 0 ? round(min(100, $seconds / ($duration * 60) * 100), 1) : null];
    }
}

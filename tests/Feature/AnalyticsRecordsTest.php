<?php

namespace Tests\Feature;

use App\Services\AnalyticsRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsRecordsTest extends TransportDatabaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_25_000100_add_trip_gps_tracking.php'))->up();
        (require database_path('migrations/2026_10_25_000200_add_analytics_records.php'))->up();
    }

    private function records(): void
    {
        DB::table('Vehicles')->insert(['vehicle_id' => 1, 'plate_number' => 'LOCAL-001', 'type' => 'Jeepney', 'capacity' => 20]);
        DB::table('Routes')->insert(['route_id' => 1, 'route_name' => 'Batac–Laoag', 'origin' => 'Batac Terminal', 'destination' => 'Laoag Terminal']);
        DB::table('Stops')->insert([['stop_id' => 1, 'route_id' => 1, 'stop_name' => 'Batac Terminal', 'latitude' => 18.05, 'longitude' => 120.56], ['stop_id' => 2, 'route_id' => 1, 'stop_name' => 'Laoag Terminal', 'latitude' => 18.19, 'longitude' => 120.59]]);
        DB::table('Trips')->insert(['trip_id' => 1, 'vehicle_id' => 1, 'driver_id' => 1, 'route_id' => 1, 'start_time' => '2026-10-25 17:00:00', 'end_time' => null, 'status' => 'In Progress']);
    }

    public function test_only_admin_can_read_aggregate_data_or_change_configuration(): void
    {
        $this->actingAs($this->user('Passenger'))->getJson('/api/admin/analytics')->assertForbidden();
        $this->putJson('/api/admin/analytics/settings', ['threshold' => 10])->assertForbidden();
        $this->actingAs($this->user('Admin'))->putJson('/api/admin/analytics/settings', ['threshold' => 8])->assertOk();
        $this->getJson('/api/admin/analytics')->assertOk()->assertJsonPath('meta.threshold', 8)->assertJsonPath('meta.analyticsReady', true);
    }

    public function test_journeys_are_scoped_deduplicated_and_feed_feedback_without_private_notes(): void
    {
        $this->records();
        $passenger = $this->user();
        DB::table('Passengers')->insert(['passenger_id' => 1, 'user_id' => $passenger->getKey()]);
        $input = ['request_id' => (string) Str::uuid(), 'trip_id' => 1, 'boarding_stop_id' => 1, 'destination_stop_id' => 2];
        $this->actingAs($passenger)->postJson('/api/transport/journeys', $input)->assertOk();
        $this->postJson('/api/transport/journeys', $input)->assertOk();
        $this->postJson('/api/transport/journeys', [...$input, 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('Passenger_Journeys', 1);
        $this->postJson('/api/transport/journeys/1/arrive', ['alighting_stop_id' => 2])->assertOk();
        $this->postJson('/api/transport/journeys/1/feedback', ['request_id' => (string) Str::uuid(), 'rating' => 4, 'type' => 'Rating', 'category' => 'Service quality'])->assertOk();
        $admin = $this->user('Admin');
        $this->actingAs($admin)->patchJson('/api/admin/analytics/feedback/1', ['status' => 'Resolved', 'response' => 'Thank you.', 'internalNote' => 'PRIVATE AUDIT NOTE'])->assertOk();
        $this->getJson('/api/admin/analytics')->assertOk()->assertJsonCount(1, 'journeys')->assertJsonPath('feedback.0.status', 'Resolved')->assertJsonPath('feedback.0.route', 'route-1');
        $this->actingAs($passenger)->getJson('/api/transport/snapshot')->assertOk()->assertJsonPath('feedback.0.admin_response', 'Thank you.')->assertDontSee('PRIVATE AUDIT NOTE');
        $this->actingAs($admin)->postJson('/api/transport/journeys/1/arrive', ['alighting_stop_id' => 2])->assertForbidden();
    }

    public function test_availability_is_date_specific_and_historical_schedules_cannot_be_fabricated(): void
    {
        $this->records();
        $this->actingAs($this->user('Admin'))->putJson('/api/admin/analytics/availability', ['vehicle_id' => 1, 'from' => '2026-10-24', 'to' => '2026-10-25', 'hours' => 8])->assertOk();
        $this->assertDatabaseCount('Vehicle_Availability', 2);
        $this->getJson('/api/admin/analytics')->assertOk()->assertJsonPath('vehicles.0.availability.2026-10-25', 8);
        $this->putJson('/api/admin/analytics/trips/1/schedule', ['scheduled_departure' => '2026-10-25T17:00:00+08:00', 'scheduled_arrival' => '2026-10-25T18:00:00+08:00', 'direction' => 'Outbound'])->assertConflict();
        DB::table('Trips')->where('trip_id', 1)->update(['status' => 'Scheduled']);
        $this->putJson('/api/admin/analytics/trips/1/schedule', ['scheduled_departure' => '2026-10-25T17:00:00+08:00', 'scheduled_arrival' => '2026-10-25T18:00:00+08:00', 'direction' => 'Outbound'])->assertOk();
        $this->assertDatabaseHas('Trips', ['scheduled_arrival_at' => '2026-10-25 10:00:00', 'travel_direction' => 'Outbound']);
    }

    public function test_gps_distance_rejects_long_gaps_jumps_and_missing_observations(): void
    {
        $service = new AnalyticsRecords;
        $point = fn ($time, $lat) => (object) ['captured_at' => $time, 'latitude' => $lat, 'longitude' => 120.56, 'accuracy_m' => 8];
        $this->assertNull($service->distance(collect(), 30)['distance']);
        $good = $service->distance(collect([$point('2026-10-25 09:00:00', 18.05), $point('2026-10-25 09:01:00', 18.051)]), 2);
        $this->assertGreaterThan(0, $good['distance']);
        $this->assertEquals(50, $good['coverage']);
        $this->assertNull($service->distance(collect([$point('2026-10-25 09:00:00', 18.05), $point('2026-10-25 10:00:00', 18.051)]), 60)['distance']);
    }

    public function test_schedule_delay_and_actual_duration_are_independent_metrics(): void
    {
        $this->records();
        DB::table('Trips')->where('trip_id', 1)->update(['status' => 'Completed', 'scheduled_departure_at' => '2026-10-25 09:00:00', 'actual_departure_at' => '2026-10-25 09:03:00', 'scheduled_arrival_at' => '2026-10-25 09:30:00', 'actual_arrival_at' => '2026-10-25 09:42:00', 'travel_direction' => 'Outbound']);
        $r = $this->actingAs($this->user('Admin'))->getJson('/api/admin/analytics')->assertOk();
        $r->assertJsonPath('trips.0.duration', 39)->assertJsonPath('trips.0.departureLate', 3)->assertJsonPath('trips.0.arrivalLate', 12)->assertJsonPath('trips.0.direction', 'Outbound')->assertJsonPath('routes.0.scheduled', 30);
    }

    public function test_delay_submission_is_deduplicated_and_keeps_trip_active(): void
    {
        $this->records();
        $driver = $this->user('Driver');
        DB::table('Drivers')->insert(['driver_id' => 1, 'user_id' => $driver->getKey(), 'license_number' => 'LOCAL-DRIVER']);
        $input = ['request_id' => (string) Str::uuid(), 'reason' => 'Traffic', 'estimated_minutes' => 10];
        $this->actingAs($driver)->postJson('/api/transport/trips/1/delay', $input)->assertOk();
        $this->postJson('/api/transport/trips/1/delay', $input)->assertOk();
        $this->assertDatabaseCount('Trip_Delay_Reports', 1);
        $this->assertDatabaseHas('Trips', ['trip_id' => 1, 'status' => 'In Progress']);
        $this->actingAs($this->user('Admin'))->getJson('/api/admin/analytics')->assertJsonPath('trips.0.delayReason', 'Traffic');
    }
}

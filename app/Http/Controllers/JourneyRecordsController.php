<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JourneyRecordsController extends Controller
{
    public function board(Request $request)
    {
        abort_unless($request->user()->role === 'Passenger', 403);
        $input = $request->validate(['request_id' => 'required|uuid', 'trip_id' => 'required|integer', 'boarding_stop_id' => 'required|integer', 'destination_stop_id' => 'required|integer|different:boarding_stop_id']);
        $id = DB::transaction(function () use ($request, $input) {
            $passenger = DB::table('Passengers')->where('user_id', $request->user()->user_id)->lockForUpdate()->first();
            abort_unless($passenger, 403);
            $existing = DB::table('Passenger_Journeys')->where('request_id', $input['request_id'])->first();
            if ($existing) { abort_unless($existing->passenger_id === $passenger->passenger_id, 409); return $existing->journey_id; }
            abort_if(DB::table('Passenger_Journeys')->where('passenger_id', $passenger->passenger_id)->where('status', 'Active')->exists(), 409, 'You already have an active journey.');
            $trip = DB::table('Trips')->where('trip_id', $input['trip_id'])->lockForUpdate()->first();
            abort_unless($trip && $trip->status === 'In Progress', 409, 'The selected trip is no longer active.');
            foreach (['boarding_stop_id', 'destination_stop_id'] as $field) {
                abort_unless(DB::table('Stops')->where('stop_id', $input[$field])->where('route_id', $trip->route_id)->exists(), 422, 'Select stops on the trip route.');
            }
            return DB::table('Passenger_Journeys')->insertGetId([...$input, 'passenger_id' => $passenger->passenger_id, 'boarded_at' => CarbonImmutable::now('UTC'), 'status' => 'Active', 'source' => 'App journey']);
        });
        return response()->json(['journey_id' => $id]);
    }

    public function arrive(Request $request, int $id)
    {
        $input = $request->validate(['alighting_stop_id' => 'required|integer']);
        DB::transaction(function () use ($request, $id, $input) {
            $journey = $this->own($request, $id);
            if ($journey->status === 'Completed') return;
            abort_unless($journey->status === 'Active', 409, 'This journey is no longer active.');
            $route = DB::table('Trips')->where('trip_id', $journey->trip_id)->value('route_id');
            abort_unless(DB::table('Stops')->where('stop_id', $input['alighting_stop_id'])->where('route_id', $route)->exists(), 422, 'Choose an alighting stop on your route.');
            DB::table('Passenger_Journeys')->where('journey_id', $id)->update([...$input, 'alighted_at' => CarbonImmutable::now('UTC'), 'status' => 'Completed']);
        });
        return response()->json(['message' => 'Arrival recorded.']);
    }

    public function correct(Request $request, int $id)
    {
        $input = $request->validate(['reason' => 'required|string|max:1000']);
        DB::transaction(function () use ($request, $id, $input) {
            $j = $this->own($request, $id);
            abort_unless(in_array($j->status, ['Active', 'Corrected']), 409);
            DB::table('Passenger_Journeys')->where('journey_id', $id)->update(['status' => 'Corrected', 'correction_reason' => $input['reason'], 'corrected_at' => CarbonImmutable::now('UTC')]);
        });
        return response()->json(['message' => 'Journey corrected. Original boarding retained for audit; excluded from journey totals.']);
    }

    public function feedback(Request $request, int $id)
    {
        $input = $request->validate(['request_id' => 'required|uuid', 'rating' => 'nullable|integer|min:1|max:5', 'type' => ['required', Rule::in(['Rating', 'Complaint', 'Suggestion'])], 'category' => ['required', Rule::in(['Service quality', 'Driver behavior', 'Waiting time', 'Vehicle condition', 'Other'])], 'message' => 'nullable|string|max:5000']);
        abort_if($input['type'] === 'Rating' && empty($input['rating']), 422, 'Choose a rating.');
        abort_if($input['type'] !== 'Rating' && empty(trim($input['message'] ?? '')), 422, 'A message is required for complaints and suggestions.');
        DB::transaction(function () use ($request, $id, $input) {
            $j = $this->own($request, $id);
            abort_unless($j->status === 'Completed', 409, 'Feedback requires a completed journey.');
            $existing = DB::table('Feedback')->where('journey_id', $id)->first();
            if ($existing) { abort_unless($existing->request_id === $input['request_id'], 409, 'Feedback already submitted for this journey.'); return; }
            DB::table('Feedback')->insert(['passenger_id' => $j->passenger_id, 'journey_id' => $id, 'trip_id' => $j->trip_id, 'request_id' => $input['request_id'], 'rating' => $input['rating'] ?? null, 'comments' => $input['message'] ?? null, 'feedback_type' => $input['type'], 'category' => $input['category'], 'resolution_status' => 'New', 'submitted_at' => CarbonImmutable::now('UTC')]);
        });
        return response()->json(['message' => 'Feedback submitted.']);
    }

    public function delay(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'Driver', 403);
        $input = $request->validate(['request_id' => 'required|uuid', 'reason' => ['required', Rule::in(['Traffic', 'Road closure', 'Vehicle issue', 'Weather', 'Other'])], 'estimated_minutes' => 'nullable|integer|min:1|max:1440', 'notes' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($request, $id, $input) {
            $driver = DB::table('Drivers')->where('user_id', $request->user()->user_id)->first();
            $trip = DB::table('Trips')->where('trip_id', $id)->lockForUpdate()->first();
            abort_unless($driver && $trip && $trip->driver_id === $driver->driver_id, 403);
            abort_unless($trip->status === 'In Progress', 409, 'Delay reports require an active trip.');
            $exists = DB::table('Trip_Delay_Reports')->where('request_id', $input['request_id'])->first();
            if ($exists) { abort_unless($exists->trip_id === $id, 409); return; }
            DB::table('Trip_Delay_Reports')->insert([...$input, 'trip_id' => $id, 'reported_at' => CarbonImmutable::now('UTC')]);
        });
        return response()->json(['message' => 'Delay report saved. Your trip remains active.']);
    }

    private function own(Request $request, int $id): object
    {
        abort_unless($request->user()->role === 'Passenger', 403);
        $passenger = DB::table('Passengers')->where('user_id', $request->user()->user_id)->value('passenger_id');
        $j = DB::table('Passenger_Journeys')->where('journey_id', $id)->where('passenger_id', $passenger)->lockForUpdate()->first();
        abort_unless($j, 404);
        return $j;
    }
}

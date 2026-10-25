<?php

namespace App\Http\Controllers;

use App\Services\TransportDatabase;
use App\Services\TripTracking;
use Illuminate\Http\Request;

class TripTrackingController extends Controller
{
    public function start(Request $request, string $id, TripTracking $tracking, TransportDatabase $database)
    {
        abort_unless(ctype_digit($id), 404);
        $input = $request->validate(['vehicle_id' => 'required|integer|min:1', 'route_id' => 'required|integer|min:1', 'point' => 'required|array']);
        $trip = $tracking->start($request->user(), (int) $id, $input, $input['point']);

        return response()->json(['trip' => $database->serializeTrip($trip), 'snapshot' => $database->forUser($request->user()), 'message' => 'Trip started. GPS sharing is active while this browser remains open.'])->header('Cache-Control', 'private, no-store');
    }

    public function point(Request $request, string $id, TripTracking $tracking)
    {
        abort_unless(ctype_digit($id), 404);
        $location = $tracking->record($request->user(), (int) $id, $request->all());

        return response()->json(['location' => $location, 'message' => 'GPS update saved.'])->header('Cache-Control', 'private, no-store');
    }

    public function end(Request $request, string $id, TripTracking $tracking, TransportDatabase $database)
    {
        abort_unless(ctype_digit($id), 404);
        $input = $request->validate(['destination' => 'required|string|max:100', 'completion_notes' => 'nullable|string|max:2000']);
        $trip = $tracking->end($request->user(), (int) $id, $input['destination'], $input['completion_notes'] ?? null);

        return response()->json(['trip' => $database->serializeTrip($trip), 'snapshot' => $database->forUser($request->user()), 'message' => 'Trip completed. GPS sharing has stopped.'])->header('Cache-Control','private, no-store');
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\TransportDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function dataset(Request $request, TransportDatabase $database)
    {
        $this->admin($request);
        return response()->json($database->adminDataset())->header('Cache-Control', 'private, no-store');
    }

    public function settings(Request $request)
    {
        $this->admin($request);
        $data = $request->validate(['threshold' => 'required|integer|min:0|max:60']);
        DB::table('Analytics_Settings')->updateOrInsert(['key' => 'delay_threshold'], ['value' => $data['threshold']]);
        return response()->json(['message' => 'Analytics configuration saved.', 'threshold' => $data['threshold']]);
    }

    public function schedule(Request $request, int $id)
    {
        $this->admin($request);
        $data = $request->validate(['scheduled_departure' => 'required|date', 'scheduled_arrival' => 'required|date|after:scheduled_departure', 'direction' => ['required', Rule::in(['Outbound', 'Inbound'])]]);
        $trip = DB::table('Trips')->where('trip_id', $id)->first();
        abort_unless($trip, 404);
        abort_unless($trip->status === 'Scheduled', 409, 'Historical schedules cannot be invented or changed after departure.');
        DB::table('Trips')->where('trip_id', $id)->update(['scheduled_departure_at' => CarbonImmutable::parse($data['scheduled_departure'], 'Asia/Manila')->utc(), 'scheduled_arrival_at' => CarbonImmutable::parse($data['scheduled_arrival'], 'Asia/Manila')->utc(), 'travel_direction' => $data['direction'], 'start_time' => CarbonImmutable::parse($data['scheduled_departure'], 'Asia/Manila')->setTimezone('Asia/Manila')->format('Y-m-d H:i:s')]);
        return response()->json(['message' => 'Scheduled trip updated.']);
    }

    public function availability(Request $request)
    {
        $this->admin($request);
        $data = $request->validate(['vehicle_id' => 'required|integer|exists:Vehicles,vehicle_id', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'hours' => 'required|numeric|min:0|max:24']);
        $from = CarbonImmutable::parse($data['from']); $to = CarbonImmutable::parse($data['to']);
        abort_if($from->diffInDays($to) > 366, 422, 'Configure no more than 367 days at once.');
        DB::transaction(function () use ($data, $from, $to) {
            for ($date = $from; $date <= $to; $date = $date->addDay()) {
                DB::table('Vehicle_Availability')->updateOrInsert(['vehicle_id' => $data['vehicle_id'], 'date' => $date->format('Y-m-d')], ['available_hours' => $data['hours']]);
            }
        });
        return response()->json(['message' => 'Available operating hours saved for the selected vehicle and dates.']);
    }

    public function feedback(Request $request, int $id)
    {
        $this->admin($request);
        $data = $request->validate(['status' => ['required', Rule::in(['New', 'Reviewing', 'Resolved'])], 'response' => 'nullable|string|max:5000', 'internalNote' => 'nullable|string|max:5000']);
        DB::transaction(function () use ($id, $data) {
            $feedback = DB::table('Feedback')->where('feedback_id', $id)->lockForUpdate()->first();
            abort_unless($feedback, 404);
            DB::table('Feedback')->where('feedback_id', $id)->update(['resolution_status' => $data['status'], 'admin_response' => $data['response'] ?? null, 'internal_notes' => $data['internalNote'] ?? null, 'resolved_at' => $data['status'] === 'Resolved' ? ($feedback->resolved_at ?? CarbonImmutable::now('UTC')) : null]);
        });
        return response()->json(['message' => 'Feedback review saved.']);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->role === 'Admin', 403, 'Administrator access required.');
    }
}

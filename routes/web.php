<?php

use App\Http\Controllers\TransportAuthController;
use App\Http\Controllers\TransportDatabaseController;
use App\Http\Controllers\TripTrackingController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\JourneyRecordsController;
use App\Services\TransportDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/login', fn () => Inertia::render('Transport', ['databaseMode' => config('transport.database_enabled')]))->name('login');
Route::post('/login', [TransportAuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/register', [TransportAuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/logout', [TransportAuthController::class, 'logout'])->middleware('auth');
Route::middleware('auth')->group(function () {
    Route::post('/api/transport/journeys', [JourneyRecordsController::class, 'board']);
    Route::post('/api/transport/journeys/{id}/arrive', [JourneyRecordsController::class, 'arrive']);
    Route::post('/api/transport/journeys/{id}/correct', [JourneyRecordsController::class, 'correct']);
    Route::post('/api/transport/journeys/{id}/feedback', [JourneyRecordsController::class, 'feedback']);
    Route::post('/api/transport/trips/{id}/delay', [JourneyRecordsController::class, 'delay']);
    Route::get('/api/admin/analytics', [AnalyticsController::class, 'dataset']);
    Route::put('/api/admin/analytics/settings', [AnalyticsController::class, 'settings']);
    Route::put('/api/admin/analytics/availability', [AnalyticsController::class, 'availability']);
    Route::put('/api/admin/analytics/trips/{id}/schedule', [AnalyticsController::class, 'schedule']);
    Route::patch('/api/admin/analytics/feedback/{id}', [AnalyticsController::class, 'feedback']);
    Route::get('/api/transport/snapshot', [TransportDatabaseController::class, 'snapshot']);
    Route::get('/api/transport/simulation', fn (\App\Services\DemoSimulationService $service) => response()->json(['simulatedVehicles' => $service->getSimulatedPositions()]));
    Route::get('/api/transport/trips/{id}', [TransportDatabaseController::class, 'trip']);
    Route::post('/api/transport/trips/{id}/start', [TripTrackingController::class, 'start'])->middleware('throttle:5,1,trip-start');
    Route::post('/api/transport/trips/{id}/gps', [TripTrackingController::class, 'point'])->middleware('throttle:30,1,trip-gps');
    Route::post('/api/transport/trips/{id}/end', [TripTrackingController::class, 'end'])->middleware('throttle:5,1,trip-end');
    Route::patch('/api/transport/profile', [TransportDatabaseController::class, 'profile']);
    Route::put('/api/transport/password', [TransportDatabaseController::class, 'password'])->middleware('throttle:5,1');
});
Route::get('/{path?}', function (Request $request, $path = '') {
    $enabled = config('transport.database_enabled');
    if ($enabled && $path === '') {
        return redirect($request->user() ? app(TransportAuthController::class)->home($request->user()) : '/login');
    }
    $role = str_starts_with($path, 'admin') ? 'Admin' : (str_starts_with($path, 'driver') ? 'Driver' : (str_starts_with($path, 'passenger') ? 'Passenger' : null));
    if ($enabled && $role) {
        if (! $request->user()) {
            return redirect()->route('login');
        }
        abort_unless($request->user()->role === $role, 403, 'This page belongs to a different account role.');
    }
    $props = ['databaseMode' => $enabled];
    if ($enabled && $role === 'Admin') {
        $props['dataset'] = app(TransportDatabase::class)->adminDataset();
    }
    if ($enabled && in_array($role, ['Driver', 'Passenger'])) {
        $props['transportData'] = app(TransportDatabase::class)->forUser($request->user());
    }

    return Inertia::render($role === 'Admin' ? 'AdminAnalytics' : 'Transport', $props);
})->where('path', '.*');

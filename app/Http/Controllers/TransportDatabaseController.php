<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Services\TransportDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class TransportDatabaseController extends Controller
{
    public function profile(Request $request)
    {
        $user = $request->user();
        $input = $request->validate(['fullname' => 'required|string|max:100', 'email' => ['required', 'email', 'max:100', Rule::unique('Users', 'email')->ignore($user->getKey(), 'user_id')]]);
        $user->fill($input)->save();

        return response()->json(['user' => $user->only('user_id', 'fullname', 'email', 'role'), 'message' => 'Profile saved to MySQL. Email verification is not configured in the supplied schema.']);
    }

    public function password(Request $request)
    {
        $input = $request->validate(['current_password' => 'required|string', 'password' => ['required', 'confirmed', Password::min(8)]]);
        $stored = $request->user()->getRawOriginal('password');
        $valid = password_get_info($stored)['algo'] ? Hash::check($input['current_password'], $stored) : hash_equals($stored, $input['current_password']);
        if (! $valid) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
        $request->user()->password = Hash::make($input['password']);
        $request->user()->save();

        return response()->json(['message' => 'Password changed in MySQL.']);
    }

    public function snapshot(Request $request, TransportDatabase $database)
    {
        return response()->json($database->forUser($request->user()))->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function trip(Request $request, string $id, TransportDatabase $database)
    {
        abort_unless(ctype_digit($id), 404);
        $query = Trip::with(['vehicle', 'route.stops']);
        if ($request->user()->role === 'Driver') {
            $query->where('driver_id', $request->user()->driver?->driver_id ?? 0);
        } else {
            abort_unless($request->user()->role === 'Admin', 403);
        }

        return response()->json($database->serializeTrip($query->findOrFail($id)));
    }
}

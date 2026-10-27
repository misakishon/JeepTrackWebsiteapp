<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class DriverAccountsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'Admin', 403, 'Only administrators can manage driver accounts.');
        $drivers = DB::table('Drivers')->join('Users', 'Users.user_id', '=', 'Drivers.user_id')
            ->where('Users.role', 'Driver')
            ->orderBy('Users.fullname')
            ->get(['Drivers.driver_id', 'Users.user_id', 'Users.fullname', 'Users.email', 'Drivers.license_number']);

        return response()->json(['drivers' => $drivers])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'Admin', 403, 'Only administrators can create driver accounts.');
        $normalized = [];
        foreach (['fullname', 'email', 'license_number'] as $field) {
            if (is_string($request->input($field))) {
                $normalized[$field] = trim($request->input($field));
            }
        }
        if (isset($normalized['email'])) $normalized['email'] = strtolower($normalized['email']);
        $request->merge($normalized);
        $input = $request->validate([
            'fullname' => 'required|string|max:100',
            'email' => 'required|email|max:100|unique:Users,email',
            'license_number' => 'required|string|max:50|unique:Drivers,license_number',
            'password' => ['required', 'confirmed', Password::min(8)],
            'role' => 'prohibited',
        ]);

        $driver = DB::transaction(function () use ($input) {
            $user = new User(['fullname' => $input['fullname'], 'email' => $input['email'], 'password' => $input['password']]);
            // User's hashed cast stores the password; the client cannot choose a role.
            $user->forceFill(['role' => 'Driver'])->save();
            $id = DB::table('Drivers')->insertGetId(['user_id' => $user->getKey(), 'license_number' => $input['license_number']], 'driver_id');

            return ['driver_id' => $id, 'user_id' => $user->getKey(), 'fullname' => $user->fullname, 'email' => $user->email, 'license_number' => $input['license_number']];
        });

        return response()->json(['driver' => $driver, 'message' => 'Driver account created. The driver can sign in with the email and password you supplied.'], 201)
            ->header('Cache-Control', 'private, no-store');
    }
}

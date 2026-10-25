<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class TransportAuthController extends Controller
{
    public function login(Request $request)
    {
        $input = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', $input['email'])->first();
        $valid = false;
        if ($user) {
            $stored = $user->getRawOriginal('password');
            $valid = password_get_info($stored)['algo'] ? Hash::check($input['password'], $stored) : hash_equals($stored, $input['password']);
        }
        if (! $valid || ! in_array($user->role, ['Admin', 'Driver', 'Passenger'], true)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        // Upgrade a verified legacy plaintext password in place without changing it.
        if (! password_get_info($stored)['algo'] || Hash::needsRehash($stored)) {
            $user->password = Hash::make($input['password']);
            $user->save();
        }
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['redirect' => $this->home($user), 'user' => $user->only('user_id', 'fullname', 'email', 'role')]);
    }

    public function register(Request $request)
    {
        $input = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:100|unique:Users,email', 'password' => ['required', 'confirmed', Password::min(8)], 'role' => 'prohibited', 'terms' => 'accepted']);
        $user = DB::transaction(function () use ($input) {
            $user = new User(['fullname' => $input['name'], 'email' => $input['email'], 'password' => Hash::make($input['password'])]);
            $user->forceFill(['role' => 'Passenger'])->save();
            DB::table('Passengers')->insert(['user_id' => $user->getKey()]);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['redirect' => '/passenger/home', 'user' => $user->only('user_id', 'fullname', 'email', 'role')], 201);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['redirect' => '/login']);
    }

    public function home(User $user): string
    {
        return match ($user->role) {
            'Admin' => '/admin/analytics','Driver' => '/driver/home',default => '/passenger/home'
        };
    }
}

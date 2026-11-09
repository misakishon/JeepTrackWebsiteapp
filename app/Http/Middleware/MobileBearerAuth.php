<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

class MobileBearerAuth
{
    public static function issueToken(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $user->getKey(),
            'issued_at' => time(),
            'expires_at' => time() + (30 * 86400), // 30 days
        ]));
    }

    public static function resolveUser(?string $bearerToken): ?User
    {
        if (! $bearerToken) {
            return null;
        }
        try {
            $payload = json_decode(Crypt::decryptString($bearerToken), true);
            if (! is_array($payload) || empty($payload['user_id'])) {
                return null;
            }
            if (! empty($payload['expires_at']) && time() > (int) $payload['expires_at']) {
                return null;
            }
            $user = User::find($payload['user_id']);
            if ($user && in_array($user->role, ['Driver', 'Passenger'], true)) {
                return $user;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Handle CORS preflight and headers for native mobile WebView / Capacitor / file:// origins
        $origin = $request->headers->get('Origin');
        $isMobileClient = $request->headers->has('X-JeepTrack-Mobile')
            || $request->bearerToken()
            || ($origin && (str_starts_with($origin, 'http://localhost') || str_starts_with($origin, 'https://localhost') || $origin === 'null' || str_starts_with($origin, 'capacitor://')));

        if ($request->isMethod('OPTIONS') && $isMobileClient) {
            return response('', 204, $this->corsHeaders($origin));
        }

        if (! Auth::check() && $request->bearerToken()) {
            $user = self::resolveUser($request->bearerToken());
            if ($user) {
                Auth::setUser($user);
                $request->setUserResolver(fn () => $user);
            }
        }

        $response = $next($request);

        if ($isMobileClient) {
            foreach ($this->corsHeaders($origin) as $key => $value) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }

    private function corsHeaders(?string $origin): array
    {
        return [
            'Access-Control-Allow-Origin' => $origin ?: '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-JeepTrack-Mobile, Accept',
            'Access-Control-Allow-Credentials' => 'true',
        ];
    }
}

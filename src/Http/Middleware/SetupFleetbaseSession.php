<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class SetupFleetbaseSession
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function handle($request, \Closure $next)
    {
        // Log::info('SetupFleetbaseSession', [
        //     'request' => $request->all(),
        //     'headers' => $request->headers->all(),
        // ]);

        $user = $request->user();
        Auth::setSession($user);
        Auth::setSandboxSession($request);

        if (method_exists($user, 'currentAccessToken')) {
            $personalAccessToken = $user->currentAccessToken();
            if ($personalAccessToken && $personalAccessToken instanceof PersonalAccessToken) {
                Auth::setApiKey($personalAccessToken);
            }
        }

        return $next($request);
    }
}

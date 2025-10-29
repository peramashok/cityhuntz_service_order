<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Support\Facades\Validator;
use App\CentralLogics\Helpers;
use App\Models\User;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        
       $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401)
                             ->header('Content-Type', 'application/json');
        }

        // strip newline, carriage return, tabs, and extra spaces
        $token = preg_replace('/[\r\n\t]+/', '', trim($token));

        try {
            $tokenModel = PersonalAccessToken::findToken($token);

            if (!$tokenModel || $tokenModel->revoked) {
                return response()->json(['message' => 'Invalid or revoked token'], 401)
                                 ->header('Content-Type', 'application/json');
            }

            $user = $tokenModel->user;
            if (!$user) {
                return response()->json(['message' => 'User no longer exists'], 401)
                                 ->header('Content-Type', 'application/json');
            }

            $request->setUserResolver(fn() => $user);

        } catch (\Throwable $e) {
            // Clean message to avoid newline in exception message
            $msg = str_replace(["\r", "\n"], ' ', $e->getMessage());
            Log::error('Auth middleware exception: '.$msg);
            return response()->json(['message' => 'Invalid token'], 401)
                             ->header('Content-Type', 'application/json');
        }

        return $next($request);
    }
}

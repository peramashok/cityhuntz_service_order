<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\CentralLogics\Helpers;

use App\Models\User;
class MemberTokenIsValid
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Helpers::check_subscription_validity();

        // $token=$request->bearerToken();
        // if(strlen($token)<1)
        // {
        //     return response()->json([
        //         'errors' => [
        //             ['code' => 'auth-001', 'message' => 'Unauthorized.']
        //         ]
        //     ], 401);
        // }
        // $vendor = User::where('auth_token', $token)->first();
        // if($vendor)
        // {
        //     $request['vendor']=$vendor;
        //     return $next($request);
        // }
        // return response()->json([
        //     'errors' => [
        //         ['code' => 'auth-001', 'message' => 'Unauthorized.']
        //     ]
        // ], 401);

            if (!$request->expectsJson()) {
                return response()->json([
                    'message' => 'Not Authorised'
                ], 401);
            }
    
    }
}

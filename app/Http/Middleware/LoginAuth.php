<?php

namespace App\Http\Middleware;

use App\Users;
use Closure;

class LoginAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!$request->json()->has('token')) {
            return response()->json([
                'status' => false,
                'code' => 40003,
                'message' => config('errorcode.code')[40003],
                'data' => [],
            ], 200, [], 320);
        }

        try {
            $user_id = decrypt($request->json()->get('token'));
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'code' => 40002,
                'message' => config('errorcode.code')[40002],
                'data' => [],
            ], 200, [], 320);
        }

        $user = new Users();
        $user_id = $user->select('id')->where('id', $user_id)->first();

        if (!$user_id) {
            return response()->json([
                'status' => false,
                'code' => 40004,
                'message' => config('errorcode.code')[40004],
                'data' => [],
            ], 200, [], 320);
        }

        return $next($request);
    }
}

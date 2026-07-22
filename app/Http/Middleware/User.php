<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AuthService;
use Auth;
use Closure;
use Illuminate\Support\Facades\Cache;

class User
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!Auth::guard('sanctum')->check()) {
            throw new ApiException('未登录或登陆已过期', 403);
        }

        // 封禁后 removeAllSessions 可能未覆盖全部路径；已签发 token 仍须拒绝
        $user = Auth::guard('sanctum')->user();
        if ($user && (int) $user->banned === 1) {
            throw new ApiException(__('Your account has been suspended'), 403);
        }

        return $next($request);
    }
}

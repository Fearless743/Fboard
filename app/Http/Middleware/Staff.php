<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AuthService;
use Closure;

class Staff
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
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        // auth_data 仅接受字符串；数组输入（如 ?auth_data[is_staff]=1）直接拒绝
        if (!$authorization || !is_string($authorization)) throw new ApiException('未登录或登陆已过期', 403);

        $user = AuthService::decryptAuthData($authorization);
        if (!$user || !$user['is_staff']) throw new ApiException('未登录或登陆已过期', 403);
        $request->merge([
            'user' => $user
        ]);
        return $next($request);
    }
}

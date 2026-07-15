<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Plugin\HookManager;
use Closure;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class Client
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
        // 订阅鉴权前钩子：可用于 IP/UA/限流等预检拦截
        HookManager::call('client.auth.before', $request);

        $token = $request->input('token', $request->route('token'));
        if (empty($token)) {
            throw new ApiException('token is null',403);
        }

        // Token 黑名单等拦截（在查库前执行，减少无效查询）
        HookManager::call('client.auth.token', [$request, $token]);

        $user = User::where('token', $token)->first();
        if (!$user) {
            throw new ApiException('token is error',403);
        }

        Auth::setUser($user);

        // 鉴权成功后钩子
        HookManager::call('client.auth.after', [$request, $user]);

        return $next($request);
    }
}

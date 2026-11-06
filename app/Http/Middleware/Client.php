<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
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

        // 订阅 token 只取 route / query 中的字符串，忽略 JSON body：
        // input() 会把 JSON 数字 token 解析为 int，在 MySQL 中与 CHAR(32)
        // 比较时触发隐式类型转换（数字前缀匹配），导致认证绕过。
        $rawToken = $request->route('token') ?? $request->query('token');
        if (empty($rawToken)) {
            throw new ApiException('token is null',403);
        }

        $token = Helper::validateSubscriptionToken($rawToken);
        if ($token === null) {
            throw new ApiException('token is error',403);
        }

        // Token 黑名单等拦截（在查库前执行，减少无效查询）
        HookManager::call('client.auth.token', [$request, $token]);

        $user = User::where('token', $token)->first();
        if (!$user) {
            throw new ApiException('token is error',403);
        }
        if ((int) $user->banned === 1) {
            throw new ApiException(__('Your account has been suspended'), 403);
        }

        Auth::setUser($user);

        // 鉴权成功后钩子
        HookManager::call('client.auth.after', [$request, $user]);

        return $next($request);
    }
}

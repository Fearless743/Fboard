<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Auth;
use Closure;
use App\Models\User;

class Admin
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
        /** @var User|null $user */
        $user = Auth::guard('sanctum')->user();

        if (!$user || !$user->is_admin || (int) $user->banned === 1) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // 强制 Passkey：无凭证的管理员只能访问 passkey 自助接口，其余一律拒绝
        if (str_contains($request->path(), 'passkey')) {
            return $next($request);
        }
        if (!\App\Models\UserPasskey::where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Passkey required'], 403);
        }

        return $next($request);
    }
}

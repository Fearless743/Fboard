<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;

class MaintenanceMode
{
    /**
     * 阻止维护期间的用户状态变更，同时保留只读接口。
     */
    public function handle(Request $request, Closure $next)
    {
        if (!(bool) admin_setting('maintenance_mode', false)) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        $safeMethod = in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
        $mutatingGet = in_array($path, [
            'api/v1/user/resetSecurity',
            'api/v1/user/invite/save',
            'api/v2/user/resetSecurity',
        ], true);

        if ($safeMethod && !$mutatingGet) {
            return $next($request);
        }

        throw new ApiException('系统维护中，暂不允许用户操作', 503);
    }
}

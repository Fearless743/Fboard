<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 统一下发安全响应头（含 CSP），作为应用层消毒之外的浏览器层纵深防御。
 *
 * 注意：admin 是 SPA，且插件可下发第三方脚本 / iframe，因此 CSP 的
 * script-src / frame-src 放行了 http(s)，但 object-src / base-uri /
 * form-action / frame-ancestors 保持收紧，且可在 config/security.php 调整。
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('security.headers.enabled', true)) {
            foreach ((array) config('security.headers.values', []) as $name => $value) {
                $response->headers->set($name, (string) $value);
            }
        }

        if (config('security.csp.enabled', true)) {
            $policy = config('security.csp.policy');
            if (is_string($policy) && $policy !== '') {
                $response->headers->set('Content-Security-Policy', $policy);
            }
        }

        return $response;
    }
}

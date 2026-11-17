<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 安全响应头
    |--------------------------------------------------------------------------
    |
    | 对所有响应下发的基础安全头。X-XSS-Protection 设为 0 是 OWASP 的现行
    | 建议：旧版浏览器自带的 XSS Auditor 可被绕过引入新漏洞，XSS 防御应以
    | 应用层消毒 + 下方 CSP 为准。
    |
    */
    'headers' => [
        'enabled' => env('FBOARD_SECURITY_HEADERS', true),
        'values' => [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-XSS-Protection' => '0',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | admin 前端存在内联脚本（window.settings）以及插件动态注入的第三方
    | 脚本 / iframe，因此 script-src 保留了 'unsafe-inline' 与 http(s)；
    | 其余指令按最小权限收紧。若部署环境不需要第三方插件，可用
    | FBOARD_CSP_POLICY 环境变量覆盖为更严格的策略（移除 http: https:）。
    |
    */
    'csp' => [
        'enabled' => env('FBOARD_CSP_ENABLED', true),
        'policy' => env(
            'FBOARD_CSP_POLICY',
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline' http: https:; " .
            "style-src 'self' 'unsafe-inline' http: https:; " .
            "img-src 'self' data: blob: http: https:; " .
            "font-src 'self' data: https:; " .
            "connect-src 'self' http: https:; " .
            "frame-src 'self' http: https:; " .
            "object-src 'none'; " .
            "base-uri 'self'; " .
            "form-action 'self'; " .
            "frame-ancestors 'self'; " .
            "worker-src 'self' blob:"
        ),
    ],
];

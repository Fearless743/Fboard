<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\Admin;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * 封禁后的管理员不得继续访问管理端 API（即使 Sanctum 会话仍在）。
 */
class BannedAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_middleware_rejects_banned_admin(): void
    {
        $admin = new User();
        $admin->forceFill([
            'email' => 'admin-ban-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => 1,
            'banned' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $admin->save();

        Auth::guard('sanctum')->setUser($admin);

        $mw = new Admin();
        $request = Request::create('/api/v2/secure/user/fetch', 'GET');

        $response = $mw->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(403, $response->getStatusCode(), '封禁管理员不得通过 Admin 中间件');
    }

    public function test_admin_middleware_allows_active_admin(): void
    {
        $admin = new User();
        $admin->forceFill([
            'email' => 'admin-ok-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => 1,
            'banned' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $admin->save();

        Auth::guard('sanctum')->setUser($admin);

        $mw = new Admin();
        $request = Request::create('/api/v2/secure/user/fetch', 'GET');

        $response = $mw->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }
}

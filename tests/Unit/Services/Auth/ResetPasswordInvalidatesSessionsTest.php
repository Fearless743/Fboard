<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 重置密码后必须作废已签发会话，否则旧 token 仍可访问用户 API。
 */
class ResetPasswordInvalidatesSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_removes_existing_sessions(): void
    {
        $user = new User();
        $user->forceFill([
            'email' => 'reset-' . Helper::guid() . '@example.com',
            'password' => password_hash('old-password-here', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $auth = new AuthService($user);
        $before = $auth->generateAuthData();
        $this->assertNotEmpty($before['auth_data'] ?? null);

        // 签发后库内应有 token
        $foundBefore = AuthService::findUserByBearerToken($before['auth_data']);
        $this->assertNotNull($foundBefore);

        $emailCode = '123456';
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $user->email), $emailCode, 300);

        $svc = app(LoginService::class);
        [$ok, $result] = $svc->resetPassword($user->email, $emailCode, 'new-password-here');
        $this->assertTrue($ok, is_array($result) ? json_encode($result) : (string) $result);

        // 旧会话必须失效
        $foundAfter = AuthService::findUserByBearerToken($before['auth_data']);
        $this->assertNull(
            $foundAfter,
            '重置密码后旧 Sanctum token 仍可解析到用户'
        );
    }
}

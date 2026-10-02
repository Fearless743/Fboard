<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\LoginService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 登录失败限流必须同时按 IP 维度生效，防止对大量邮箱做密码喷洒。
 */
class LoginServiceIpLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        admin_setting([
            'password_limit_enable' => 1,
            'password_limit_count' => 2,
            'password_limit_expire' => 60,
        ]);
    }

    public function test_ip_is_locked_out_across_different_emails(): void
    {
        $service = app(LoginService::class);
        $ip = '198.51.100.7';

        [$s1] = $service->login('a@example.com', 'bad', $ip, 'UA');
        [$s2] = $service->login('b@example.com', 'bad', $ip, 'UA');
        $this->assertFalse($s1);
        $this->assertFalse($s2);

        // 同一 IP 第三次即使凭据正确也必须被限流
        $this->createUser('victim@example.com', 'good-pass');
        [$ok, $result] = $service->login('victim@example.com', 'good-pass', $ip, 'UA');
        $this->assertFalse($ok);
        $this->assertSame(429, $result[0]);

        // 换一个 IP 仍可正常登录
        [$ok2] = $service->login('victim@example.com', 'good-pass', '203.0.113.99', 'UA');
        $this->assertTrue($ok2);
    }

    public function test_success_clears_ip_counter(): void
    {
        $service = app(LoginService::class);
        $ip = '198.51.100.8';
        $this->createUser('ok@example.com', 'good-pass');

        $service->login('ok@example.com', 'bad', $ip, 'UA'); // 1 fail
        [$ok] = $service->login('ok@example.com', 'good-pass', $ip, 'UA'); // success 清除 IP 计数
        $this->assertTrue($ok);
        $this->assertNull(Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT_IP', $ip)));

        // 再失败一次不应达到阈值
        [$s] = $service->login('ok@example.com', 'bad', $ip, 'UA');
        $this->assertFalse($s);
        $ipErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT_IP', $ip), 0);
        $this->assertLessThan(2, $ipErrorCount);
    }

    private function createUser(string $email, string $password): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}

<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Models\UserLoginLog;
use App\Services\Auth\LoginLogService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoginLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LoginLogService::class);
    }

    public function test_record_login_updates_user_and_creates_log(): void
    {
        $user = $this->createUser();
        $before = time();

        $this->service->recordLogin(
            $user,
            '203.0.113.10',
            'PHPUnit/1.0',
            UserLoginLog::METHOD_PASSWORD
        );

        $user->refresh();
        $this->assertSame('203.0.113.10', $user->last_login_ip);
        $this->assertNotNull($user->last_login_at);
        $this->assertGreaterThanOrEqual($before, (int) $user->last_login_at);

        $this->assertDatabaseCount('v2_user_login_log', 1);
        $this->assertDatabaseHas('v2_user_login_log', [
            'user_id' => $user->id,
            'ip' => '203.0.113.10',
            'user_agent' => 'PHPUnit/1.0',
            'method' => UserLoginLog::METHOD_PASSWORD,
        ]);
    }

    public function test_record_login_accepts_ipv6(): void
    {
        $user = $this->createUser();
        $ipv6 = '2001:db8::1';

        $this->service->recordLogin($user, $ipv6, null, UserLoginLog::METHOD_PASSWORD);

        $user->refresh();
        $this->assertSame($ipv6, $user->last_login_ip);
        $this->assertDatabaseHas('v2_user_login_log', [
            'user_id' => $user->id,
            'ip' => $ipv6,
            'method' => UserLoginLog::METHOD_PASSWORD,
        ]);
    }

    public function test_record_register_sets_register_ip_once_and_logs(): void
    {
        $user = $this->createUser();

        $this->service->recordRegister($user, '198.51.100.7', 'RegisterAgent/1.0');
        $user->refresh();

        $this->assertSame('198.51.100.7', $user->register_ip);
        $this->assertSame('198.51.100.7', $user->last_login_ip);
        $this->assertDatabaseHas('v2_user_login_log', [
            'user_id' => $user->id,
            'method' => UserLoginLog::METHOD_REGISTER,
            'ip' => '198.51.100.7',
        ]);

        // 再次注册记录不应覆盖 register_ip
        $this->service->recordRegister($user, '203.0.113.99', 'RegisterAgent/2.0');
        $user->refresh();
        $this->assertSame('198.51.100.7', $user->register_ip);
        $this->assertSame('203.0.113.99', $user->last_login_ip);
        $this->assertSame(2, UserLoginLog::where('user_id', $user->id)->count());
    }

    public function test_history_is_trimmed_to_max_per_user(): void
    {
        $user = $this->createUser();
        $other = $this->createUser('other@example.com');

        for ($i = 1; $i <= UserLoginLog::MAX_PER_USER + 5; $i++) {
            $this->service->recordLogin(
                $user,
                '203.0.113.' . $i,
                'Agent',
                UserLoginLog::METHOD_PASSWORD
            );
        }

        // 另一用户的日志不受裁剪影响
        $this->service->recordLogin(
            $other,
            '198.51.100.1',
            'Other',
            UserLoginLog::METHOD_PASSWORD
        );

        $this->assertSame(
            UserLoginLog::MAX_PER_USER,
            UserLoginLog::where('user_id', $user->id)->count()
        );
        $this->assertSame(1, UserLoginLog::where('user_id', $other->id)->count());

        $oldestKeptIp = UserLoginLog::where('user_id', $user->id)
            ->orderBy('id')
            ->value('ip');
        // 前 5 条应被删：保留 6..25 → 最旧为 203.0.113.6
        $this->assertSame('203.0.113.6', $oldestKeptIp);

        $latestIp = UserLoginLog::where('user_id', $user->id)
            ->orderByDesc('id')
            ->value('ip');
        $this->assertSame('203.0.113.25', $latestIp);
    }

    public function test_empty_ip_falls_back_for_log_and_nulls_user_field(): void
    {
        $user = $this->createUser();
        $user->last_login_ip = '1.2.3.4';
        $user->save();

        $this->service->recordLogin($user, '   ', 'Agent', UserLoginLog::METHOD_MAIL_LINK);

        $user->refresh();
        $this->assertNull($user->last_login_ip);
        $this->assertDatabaseHas('v2_user_login_log', [
            'user_id' => $user->id,
            'ip' => '0.0.0.0',
            'method' => UserLoginLog::METHOD_MAIL_LINK,
        ]);
    }

    public function test_user_agent_is_truncated_to_512(): void
    {
        $user = $this->createUser();
        $longUa = str_repeat('A', 600);

        $this->service->recordLogin($user, '203.0.113.1', $longUa, UserLoginLog::METHOD_PASSWORD);

        $log = UserLoginLog::where('user_id', $user->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(512, mb_strlen((string) $log->user_agent));
    }

    private function createUser(string $email = 'user@example.com'): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}

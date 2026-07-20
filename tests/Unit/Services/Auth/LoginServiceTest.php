<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Models\UserLoginLog;
use App\Services\Auth\LoginService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class LoginServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoginService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        admin_setting([
            'password_limit_enable' => 0,
        ]);
        $this->service = app(LoginService::class);
    }

    public function test_login_records_ip_and_history(): void
    {
        $user = $this->createUser('login@example.com', 'secret-pass');

        [$success, $result] = $this->service->login(
            'login@example.com',
            'secret-pass',
            '203.0.113.50',
            'LoginTestAgent/1.0'
        );

        $this->assertTrue($success);
        $this->assertInstanceOf(User::class, $result);

        $user->refresh();
        $this->assertSame('203.0.113.50', $user->last_login_ip);
        $this->assertNotNull($user->last_login_at);

        $this->assertDatabaseHas('v2_user_login_log', [
            'user_id' => $user->id,
            'ip' => '203.0.113.50',
            'user_agent' => 'LoginTestAgent/1.0',
            'method' => UserLoginLog::METHOD_PASSWORD,
        ]);
    }

    public function test_login_failure_does_not_write_history(): void
    {
        $user = $this->createUser('fail@example.com', 'correct-pass');

        [$success] = $this->service->login(
            'fail@example.com',
            'wrong-pass',
            '203.0.113.9',
            'Agent'
        );

        $this->assertFalse($success);
        $this->assertDatabaseCount('v2_user_login_log', 0);

        $user->refresh();
        $this->assertNull($user->last_login_ip);
    }

    public function test_reset_password_rejects_missing_cached_email_code(): void
    {
        $user = $this->createUser('victim@example.com', 'old-password');

        [$success, $result] = $this->service->resetPassword($user->email, '', 'new-password');

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);

        $user->refresh();
        $this->assertTrue(password_verify('old-password', $user->password));
    }

    public function test_reset_password_accepts_matching_cached_email_code(): void
    {
        $user = $this->createUser('user@example.com', 'old-password');
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $user->email), 123456, 300);

        [$success, $result] = $this->service->resetPassword($user->email, '123456', 'new-password');

        $this->assertTrue($success);
        $this->assertTrue($result);

        $user->refresh();
        $this->assertTrue(password_verify('new-password', $user->password));
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $user->email)));
    }

    public function test_forget_password_validation_rejects_boolean_email_code(): void
    {
        $validator = Validator::make([
            'email' => 'victim@example.com',
            'password' => 'new-password',
            'email_code' => false,
        ], [
            'email' => 'required|email:strict',
            'password' => 'required|min:8',
            'email_code' => 'required|digits:6',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email_code', $validator->errors()->toArray());
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
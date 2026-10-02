<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 认证接口必须按 IP 限流，避免撞库/邮件轰炸。
 */
class PassportRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_endpoint_is_rate_limited(): void
    {
        $status = 0;

        for ($i = 0; $i < 31; $i++) {
            $response = $this->postJson('/api/v1/passport/auth/login', [
                'email' => 'rate-limit@example.com',
                'password' => 'whatever',
            ]);
            $status = $response->getStatusCode();
        }

        $this->assertSame(429, $status);
    }

    public function test_first_login_attempt_is_not_throttled(): void
    {
        $response = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'first@example.com',
            'password' => 'whatever',
        ]);

        $this->assertNotSame(429, $response->getStatusCode());
    }
}

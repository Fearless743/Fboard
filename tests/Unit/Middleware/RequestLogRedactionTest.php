<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\RequestLog;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RequestLogRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_nested_and_compound_secrets_are_redacted(): void
    {
        $admin = User::query()->create([
            'email' => 'admin-' . Helper::guid() . '@example.com',
            'password' => password_hash('secret', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'is_admin' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $request = Request::create('/api/v2/deadbeef/config/save', 'POST', [
            'app_name' => 'Fboard',
            'telegram_bot_token' => 'bot-secret',
            'email_password' => 'smtp-pass',
            'password' => 'plain',
            'config' => [
                'api_key' => 'k',
                'webhook_key' => 'wk',
                'public_name' => 'visible',
            ],
        ]);
        $request->setUserResolver(fn () => $admin);

        (new RequestLog())->handle($request, fn () => response('ok'));

        $log = AdminAuditLog::query()->latest('id')->first();
        $this->assertNotNull($log);

        $data = json_decode($log->request_data, true);
        $this->assertIsArray($data);

        $this->assertSame('Fboard', $data['app_name']);
        $this->assertSame('[REDACTED]', $data['telegram_bot_token']);
        $this->assertSame('[REDACTED]', $data['email_password']);
        $this->assertSame('[REDACTED]', $data['password']);
        $this->assertSame('[REDACTED]', $data['config']['api_key']);
        $this->assertSame('[REDACTED]', $data['config']['webhook_key']);
        $this->assertSame('visible', $data['config']['public_name']);
    }

    public function test_non_post_requests_are_not_logged(): void
    {
        $admin = User::query()->create([
            'email' => 'admin-' . Helper::guid() . '@example.com',
            'password' => password_hash('secret', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'is_admin' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $request = Request::create('/api/v2/deadbeef/user/fetch', 'GET');
        $request->setUserResolver(fn () => $admin);

        (new RequestLog())->handle($request, fn () => response('ok'));

        $this->assertDatabaseCount('v2_admin_audit_log', 0);
    }
}

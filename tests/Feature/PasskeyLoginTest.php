<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPasskey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PasskeyLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        admin_setting(['password_limit_enable' => 0]);
    }

    public function test_admin_password_login_returns_ticket_without_token(): void
    {
        $admin = $this->createAdmin('admin@example.com', 'secret-pass');

        $res = $this->postJson('/api/v2/passport/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-pass',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.passkey_required', true);
        $res->assertJsonPath('data.passkey_setup_required', true);
        $this->assertArrayNotHasKey('auth_data', $res->json('data'));
        $this->assertNotEmpty($res->json('data.ticket'));
    }

    public function test_admin_with_passkey_gets_challenge_but_no_token(): void
    {
        $admin = $this->createAdmin('admin2@example.com', 'secret-pass');
        UserPasskey::create([
            'user_id' => $admin->id,
            'name' => 'test',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFs',
            'type' => 'public-key',
            'transports' => ['hybrid'],
            'attestation_type' => 'none',
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'credential_public_key' => base64_encode('fake'),
            'counter' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $res = $this->postJson('/api/v2/passport/auth/login', [
            'email' => 'admin2@example.com',
            'password' => 'secret-pass',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.passkey_required', true);
        $this->assertArrayNotHasKey('auth_data', $res->json('data'));
        $this->assertArrayNotHasKey('passkey_setup_required', $res->json('data'));

        // 用票据换 options
        $ticket = $res->json('data.ticket');
        $opt = $this->postJson('/api/v2/passport/auth/passkey/auth-options', ['ticket' => $ticket]);
        $opt->assertOk();
        $this->assertNotEmpty($opt->json('data.options.challenge'));
    }

    public function test_non_admin_password_login_unaffected(): void
    {
        $this->createAdmin('user@example.com', 'secret-pass', false);

        $res = $this->postJson('/api/v2/passport/auth/login', [
            'email' => 'user@example.com',
            'password' => 'secret-pass',
        ]);

        $res->assertOk();
        $this->assertNotEmpty($res->json('data.auth_data'));
    }

    public function test_admin_middleware_blocks_passkeyless_admin(): void
    {
        $admin = $this->createAdmin('blocked@example.com', 'secret-pass');
        $middleware = app(\App\Http\Middleware\Admin::class);
        $request = \Illuminate\Http\Request::create('/api/v2/whatever/config/fetch', 'GET');
        $request->setUserResolver(fn () => $admin);
        \Illuminate\Support\Facades\Auth::shouldUse('sanctum');
        $this->actingAs($admin, 'sanctum');

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Passkey', (string) $response->getContent());

        // 绑定后放行
        UserPasskey::create([
            'user_id' => $admin->id,
            'name' => 'test',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFsLTI',
            'type' => 'public-key',
            'attestation_type' => 'none',
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'credential_public_key' => base64_encode('fake'),
            'counter' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $response2 = $middleware->handle($request, fn () => response()->json(['ok' => true]));
        $this->assertSame(200, $response2->getStatusCode());
    }

    private function createAdmin(string $email, string $password, bool $isAdmin = true): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'is_admin' => $isAdmin,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}

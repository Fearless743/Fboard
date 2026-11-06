<?php

namespace Tests\Unit\Middleware;

use App\Exceptions\ApiException;
use App\Http\Middleware\Client;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * 订阅 token 认证绕过回归：JSON 数字 token 必须被拒绝，
 * 只有 route / query 中的 32 位 hex 字符串可以鉴权。
 */
class ClientSubscribeTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_subscription_token_accepts_32_hex_string(): void
    {
        $this->assertSame('44155bc2000000000000000000000000', Helper::validateSubscriptionToken('44155bc2000000000000000000000000'));
        $this->assertSame('44155bc2000000000000000000000000', Helper::validateSubscriptionToken('44155BC2000000000000000000000000'));
    }

    public function test_validate_subscription_token_rejects_non_string_and_malformed_values(): void
    {
        $this->assertNull(Helper::validateSubscriptionToken(2));
        $this->assertNull(Helper::validateSubscriptionToken(0));
        $this->assertNull(Helper::validateSubscriptionToken('2'));
        $this->assertNull(Helper::validateSubscriptionToken('44155bc2'));
        $this->assertNull(Helper::validateSubscriptionToken('zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'));
        $this->assertNull(Helper::validateSubscriptionToken(['44155bc2000000000000000000000000']));
        $this->assertNull(Helper::validateSubscriptionToken(null));
        $this->assertNull(Helper::validateSubscriptionToken(''));
    }

    public function test_client_middleware_rejects_json_int_token(): void
    {
        $this->makeUser('2' . str_repeat('a', 31));

        $request = Request::create('/api/v1/client/subscribe', 'GET', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['token' => 2, 'flag' => 'clashmeta']));

        $this->assertClientRejects($request);
    }

    public function test_client_middleware_rejects_json_string_body_token(): void
    {
        $token = '2' . str_repeat('a', 31);
        $this->makeUser($token);

        $request = Request::create('/api/v1/client/subscribe', 'GET', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['token' => $token, 'flag' => 'clashmeta']));

        // body 中的 token（含完整字符串）也不得作为鉴权来源
        $this->assertClientRejects($request);
    }

    public function test_client_middleware_accepts_query_string_token(): void
    {
        $token = '2' . str_repeat('a', 31);
        $user = $this->makeUser($token);

        $request = Request::create('/api/v1/client/subscribe', 'GET', ['token' => $token]);

        $response = (new Client())->handle($request, fn () => response('ok'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame($user->id, Auth::user()?->id);
    }

    public function test_client_middleware_rejects_numeric_string_query_token(): void
    {
        $this->makeUser('2' . str_repeat('a', 31));

        $request = Request::create('/api/v1/client/subscribe', 'GET', ['token' => '2']);

        $this->assertClientRejects($request);
    }

    private function assertClientRejects(Request $request): void
    {
        try {
            (new Client())->handle($request, fn () => response('ok'));
            $this->fail('数字/非法订阅 token 不得通过 Client 中间件');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getCode() ?: 403);
            $this->assertNull(Auth::user());
        }
    }

    private function makeUser(string $token): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'sub-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => $token,
            'banned' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();
        return $user;
    }
}

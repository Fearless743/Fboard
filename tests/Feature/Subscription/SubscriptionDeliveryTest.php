<?php

namespace Tests\Feature\Subscription;

use App\Models\Plugin;
use App\Models\Server;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugin\CoreProtocols\ProtocolTypes;
use Tests\TestCase;

/**
 * 订阅下发端到端回归：缓存批量化/父节点预加载优化后，响应体与响应头保持一致。
 */
class SubscriptionDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 启用内置协议定义，protocol_settings 才会按 schema casting
        Plugin::query()->create([
            'name' => 'Core Protocols',
            'code' => 'core_protocols',
            'version' => '1.0.0',
            'type' => Plugin::TYPE_PROTOCOL,
            'is_enabled' => true,
            'installed_at' => now(),
        ]);
        $this->app->forgetScopedInstances();
    }

    public function test_general_subscription_keeps_output_and_headers(): void
    {
        $user = $this->makeUser();
        $this->makeShadowsocksNode();

        $response = $this->get(route('client.subscribe', ['token' => $user->token]) . '?flag=general');

        $response->assertOk();
        $this->assertStringContainsString(
            'text/plain',
            (string) $response->headers->get('content-type'),
        );
        $this->assertSame(
            'upload=0; download=0; total=1073741824; expire=',
            $response->headers->get('subscription-userinfo'),
        );

        $body = (string) base64_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('ss://', $body);
        $this->assertStringContainsString('@example.com:8388', $body);
        $this->assertStringContainsString('#subscription-node', $body);
    }

    private function makeShadowsocksNode(): Server
    {
        return Server::create([
            'name' => 'subscription-node',
            'type' => ProtocolTypes::SHADOWSOCKS,
            'host' => 'example.com',
            'port' => 8388,
            'server_port' => 8388,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => [
                'cipher' => 'aes-256-gcm',
            ],
        ]);
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'subscriber@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => 'subscriber-uuid',
            'token' => Helper::guid(),
            'group_id' => 1,
            'transfer_enable' => 1024 * 1024 * 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        return $user;
    }
}

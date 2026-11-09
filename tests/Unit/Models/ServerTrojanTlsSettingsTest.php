<?php

namespace Tests\Unit\Models;

use App\Models\Server;
use App\Services\ProtocolDefinitionRegistry;
use Plugin\CoreProtocols\Plugin;
use Tests\TestCase;

/**
 * Trojan 的 SNI/allow_insecure 历史上曾放在 protocol_settings 根级，
 * 新版本统一到 tls_settings.*。读取旧数据时必须回填，否则订阅会丢 SNI。
 */
class ServerTrojanTlsSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 单元测试不会加载 v2_plugins，手动注册核心协议定义供 protocol_settings 强转使用
        app(ProtocolDefinitionRegistry::class)->reset();
        (new Plugin('CoreProtocols'))->boot();
    }

    private function rawTrojanServer(array $settings): Server
    {
        $server = new Server();
        $server->setRawAttributes([
            'type' => 'trojan',
            'protocol_settings' => json_encode($settings),
        ], true);

        return $server;
    }

    public function test_legacy_root_fields_are_backfilled_into_tls_settings(): void
    {
        $server = $this->rawTrojanServer([
            'tls' => 1,
            'network' => 'tcp',
            'server_name' => 'legacy.example.com',
            'allow_insecure' => true,
        ]);

        $settings = $server->protocol_settings;

        $this->assertSame('legacy.example.com', $settings['tls_settings']['server_name'] ?? null);
        $this->assertTrue($settings['tls_settings']['allow_insecure'] ?? null);
    }

    public function test_legacy_root_fields_are_migrated_on_save(): void
    {
        $server = new Server();
        $server->type = 'trojan';
        $server->protocol_settings = [
            'tls' => 1,
            'network' => 'tcp',
            'server_name' => 'legacy.example.com',
            'allow_insecure' => true,
        ];

        $stored = json_decode($server->getAttributes()['protocol_settings'], true);

        $this->assertSame('legacy.example.com', $stored['tls_settings']['server_name'] ?? null);
        $this->assertTrue($stored['tls_settings']['allow_insecure'] ?? null);
        $this->assertArrayNotHasKey('server_name', $stored);
        $this->assertArrayNotHasKey('allow_insecure', $stored);
    }

    public function test_tls_settings_take_precedence_over_legacy_root_fields(): void
    {
        $server = $this->rawTrojanServer([
            'tls' => 1,
            'network' => 'tcp',
            'server_name' => 'legacy.example.com',
            'allow_insecure' => true,
            'tls_settings' => [
                'server_name' => 'preferred.example.com',
                'allow_insecure' => false,
            ],
        ]);

        $settings = $server->protocol_settings;

        $this->assertSame('preferred.example.com', $settings['tls_settings']['server_name'] ?? null);
        $this->assertFalse($settings['tls_settings']['allow_insecure'] ?? null);
    }

    public function test_reality_keeps_using_reality_settings(): void
    {
        $server = $this->rawTrojanServer([
            'tls' => 2,
            'network' => 'tcp',
            'reality_settings' => [
                'server_name' => 'reality.example.com',
                'public_key' => 'pk',
                'private_key' => 'sk',
                'short_id' => 'abcd',
            ],
        ]);

        $settings = $server->protocol_settings;

        $this->assertSame('reality.example.com', $settings['reality_settings']['server_name'] ?? null);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Utils\Helper;
use Plugin\CoreProtocols\NodeConfigBuilders;
use PHPUnit\Framework\TestCase;

/**
 * 纯单元测试：不启动 Laravel 容器。
 * StubServer 覆盖 protocol_settings accessor，避免触发 ProtocolDefinitionRegistry。
 */
class NodeConfigBuildersTest extends TestCase
{
    private function baseConfig(string $protocol = 'vmess'): array
    {
        return [
            'protocol' => $protocol,
            'listen_ip' => '0.0.0.0',
            'server_port' => 443,
            'network' => null,
            'networkSettings' => null,
            'maintenance_mode' => false,
        ];
    }

    private function server(string $type, array $protocolSettings, array $attrs = []): StubServerForNodeConfig
    {
        $server = new StubServerForNodeConfig();
        $server->forcedSettings = $protocolSettings;
        // setRawAttributes 写内部 attributes，绕过 mutator / 协议注册表
        $server->setRawAttributes([
            'type' => $type,
            'host' => $attrs['host'] ?? 'example.com',
            'port' => $attrs['port'] ?? 443,
            'server_port' => $attrs['server_port'] ?? 443,
            // 避免 timestamp cast 把 int 变成 Carbon；builder 侧会 md5($node->created_at)
            'created_at' => (string) ($attrs['created_at'] ?? 1700000000),
        ], true);

        return $server;
    }

    public function test_vmess_builder(): void
    {
        $node = $this->server('vmess', [
            'tls' => 1,
            'tls_settings' => ['server_name' => 'sni.example.com'],
            'multiplex' => ['enabled' => true],
            'network' => 'ws',
        ]);

        $config = NodeConfigBuilders::vmess($node, $this->baseConfig('vmess'));

        $this->assertSame('vmess', $config['protocol']);
        $this->assertSame(1, $config['tls']);
        $this->assertSame(['server_name' => 'sni.example.com'], $config['tls_settings']);
        $this->assertSame(['enabled' => true], $config['multiplex']);
    }

    public function test_shadowsocks_2022_server_key(): void
    {
        $createdAt = 1700000000;
        $node = $this->server('shadowsocks', [
            'cipher' => '2022-blake3-aes-128-gcm',
            'plugin' => null,
            'plugin_opts' => null,
        ], ['created_at' => $createdAt]);

        $config = NodeConfigBuilders::shadowsocks($node, $this->baseConfig('shadowsocks'));

        $this->assertSame('2022-blake3-aes-128-gcm', $config['cipher']);
        $this->assertSame(Helper::getServerKey($createdAt, 16), $config['server_key']);
    }

    public function test_vless_reality_uses_normalized_settings(): void
    {
        $node = $this->server('vless', [
            'tls' => 2,
            'flow' => 'xtls-rprx-vision',
            'encryption' => ['enabled' => false],
            'tls_settings' => ['server_name' => 'ignored'],
            'reality_settings' => [
                'server_name' => 'reality.example.com',
                'public_key' => 'pk',
                'private_key' => 'sk',
                'short_id' => 'abcd',
            ],
            'multiplex' => null,
        ]);

        $config = NodeConfigBuilders::vless($node, $this->baseConfig('vless'));

        $this->assertSame(2, $config['tls']);
        $this->assertSame('xtls-rprx-vision', $config['flow']);
        $this->assertNull($config['decryption']);
        $this->assertIsArray($config['tls_settings']);
        $this->assertSame('reality.example.com', $config['tls_settings']['server_name'] ?? null);
    }

    public function test_hysteria_listen_ports_for_range(): void
    {
        $node = $this->server('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'hy.example.com', 'allow_insecure' => false],
            'bandwidth' => ['up' => 100, 'down' => 100],
            'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'obfs-pass'],
            'realm' => null,
        ], ['port' => '10000-10100', 'server_port' => 10000]);

        $config = NodeConfigBuilders::hysteria($node, $this->baseConfig('hysteria'));

        $this->assertSame('10000-10100', $config['listen_ports']);
        $this->assertSame(2, $config['version']);
        $this->assertSame('salamander', $config['obfs']);
        $this->assertSame('obfs-pass', $config['obfs-password']);
        $this->assertNull($config['realm']);
    }

    public function test_anytls_always_exposes_tls_one(): void
    {
        $node = $this->server('anytls', [
            'tls' => ['server_name' => 'any.example.com'],
            'padding_scheme' => "stop=8\n0=30-30",
        ]);

        $config = NodeConfigBuilders::anytls($node, $this->baseConfig('anytls'));

        $this->assertSame(1, $config['tls']);
        $this->assertSame('any.example.com', $config['server_name']);
        $this->assertSame("stop=8\n0=30-30", $config['padding_scheme']);
    }

    public function test_sudoku_maps_httpmask_and_master_key(): void
    {
        $node = $this->server('sudoku', [
            'master_public_key' => 'pub-key',
            'aead_method' => 'aes-128-gcm',
            'padding_min' => 3,
            'padding_max' => 12,
            'httpmask' => [
                'disable' => false,
                'mode' => 'stream',
                'path_root' => '/aabbcc/',
            ],
            'multiplex' => 'auto',
        ]);

        $config = NodeConfigBuilders::sudoku($node, $this->baseConfig('sudoku'));

        $this->assertSame('pub-key', $config['server_key']);
        $this->assertSame('aes-128-gcm', $config['sudoku_config']['aead_method']);
        $this->assertSame('stream', $config['sudoku_config']['http_mask_mode']);
        $this->assertSame('aabbcc', $config['sudoku_config']['path_root']);
        $this->assertSame('auto', $config['sudoku_config']['multiplex']);
    }

    public function test_shadowquic_defaults(): void
    {
        $node = $this->server('shadowquic', [
            'jls_upstream' => 'www.cloudflare.com:443',
        ]);

        $config = NodeConfigBuilders::shadowquic($node, $this->baseConfig('shadowquic'));

        $this->assertSame('www.cloudflare.com:443', $config['jls_upstream']);
        $this->assertSame('bbr', $config['congestion_control']);
        $this->assertTrue($config['zero_rtt']);
        $this->assertSame(['h3'], $config['alpn']);
    }
}

/**
 * 轻量 Stub：无参构造，覆盖 protocol_settings 读取。
 */
class StubServerForNodeConfig extends Server
{
    public array $forcedSettings = [];

    public function getProtocolSettingsAttribute($value = null)
    {
        return $this->forcedSettings;
    }
}

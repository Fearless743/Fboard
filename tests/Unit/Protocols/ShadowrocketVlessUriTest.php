<?php

namespace Tests\Unit\Protocols;

use Plugin\Shadowrocket\Shadowrocket;
use Tests\TestCase;

class ShadowrocketVlessUriTest extends TestCase
{
    private const UUID = '00000000-0000-0000-0000-000000000001';

    public function test_vless_encryption_tls_xhttp_uses_standard_uri(): void
    {
        $uri = Shadowrocket::buildVless(self::UUID, $this->makeServer([
            'name' => 'xhttp node',
            'protocol_settings' => [
                'tls' => 1,
                'tls_settings' => [
                    'server_name' => 'example.com',
                    'allow_insecure' => false,
                ],
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                'flow' => 'xtls-rprx-vision',
                'encryption' => [
                    'enabled' => true,
                    'encryption' => 'mlkem768x25519plus.native.0rtt.CLIENTKEY',
                    'decryption' => 'SERVER-DECRYPTION-MUST-NOT-LEAK',
                ],
                'network' => 'xhttp',
                'network_settings' => [
                    'path' => '/test-path',
                    'host' => 'cdn.example.com',
                    'mode' => 'auto',
                ],
            ],
        ]));

        $this->assertStringStartsWith('vless://' . self::UUID . '@example.com:443?', $uri);
        $this->assertStringContainsString('tfo=1', $uri);
        $this->assertStringContainsString('encryption=mlkem768x25519plus.native.0rtt.CLIENTKEY', $uri);
        $this->assertStringContainsString('security=tls', $uri);
        $this->assertStringContainsString('sni=example.com', $uri);
        $this->assertStringContainsString('fp=chrome', $uri);
        $this->assertStringContainsString('flow=xtls-rprx-vision', $uri);
        $this->assertStringContainsString('type=xhttp', $uri);
        $this->assertStringContainsString('path=%2Ftest-path', $uri);
        $this->assertStringContainsString('host=cdn.example.com', $uri);
        $this->assertStringContainsString('mode=auto', $uri);
        $this->assertStringEndsWith('#' . rawurlencode('xhttp node') . "\r\n", $uri);

        $this->assertStringNotContainsString('base64', $uri);
        $this->assertStringNotContainsString('SERVER-DECRYPTION-MUST-NOT-LEAK', $uri);
        $this->assertStringNotContainsString('remark=', $uri);
        $this->assertStringNotContainsString('obfs=', $uri);
        $this->assertStringNotContainsString('xtls=', $uri);
        $this->assertStringNotContainsString('peer=', $uri);
    }

    public function test_vless_encryption_disabled_or_empty_outputs_none(): void
    {
        $disabled = Shadowrocket::buildVless(self::UUID, $this->makeServer([
            'protocol_settings' => [
                'tls' => 1,
                'tls_settings' => ['server_name' => 'example.com'],
                'encryption' => [
                    'enabled' => false,
                    'encryption' => 'CLIENTKEY',
                    'decryption' => 'SERVER-DECRYPTION',
                ],
                'network' => 'tcp',
            ],
        ]));
        $this->assertStringContainsString('encryption=none', $disabled);
        $this->assertStringNotContainsString('CLIENTKEY', $disabled);
        $this->assertStringNotContainsString('SERVER-DECRYPTION', $disabled);

        $empty = Shadowrocket::buildVless(self::UUID, $this->makeServer([
            'protocol_settings' => [
                'tls' => 1,
                'tls_settings' => ['server_name' => 'example.com'],
                'encryption' => [
                    'enabled' => true,
                    'encryption' => null,
                    'decryption' => 'SERVER-DECRYPTION',
                ],
                'network' => 'tcp',
            ],
        ]));
        $this->assertStringContainsString('encryption=none', $empty);
        $this->assertStringNotContainsString('SERVER-DECRYPTION', $empty);
    }

    public function test_vless_reality_vision_uses_standard_fields(): void
    {
        $uri = Shadowrocket::buildVless(self::UUID, $this->makeServer([
            'protocol_settings' => [
                'tls' => 2,
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                'reality_settings' => [
                    'server_name' => 'real.example.com',
                    'public_key' => 'PUBLIC-KEY',
                    'short_id' => 'SHORT-ID',
                ],
                'flow' => 'xtls-rprx-vision',
                'network' => 'xhttp',
                'network_settings' => [
                    'path' => '/api/v1/chat',
                    'host' => 'cdn.example.com',
                    'mode' => 'auto',
                    'extra' => ['xPaddingBytes' => '100-1000'],
                ],
            ],
        ]));

        $query = $this->parseQuery($uri);
        $this->assertSame('reality', $query['security']);
        $this->assertSame('real.example.com', $query['sni']);
        $this->assertSame('PUBLIC-KEY', $query['pbk']);
        $this->assertSame('SHORT-ID', $query['sid']);
        $this->assertSame('chrome', $query['fp']);
        $this->assertSame('xtls-rprx-vision', $query['flow']);
        $this->assertSame('xhttp', $query['type']);
        $this->assertSame('/api/v1/chat', $query['path']);
        $this->assertSame('cdn.example.com', $query['host']);
        $this->assertSame('{"xPaddingBytes":"100-1000"}', $query['extra']);
        $this->assertSame('none', $query['encryption']);
        $this->assertArrayNotHasKey('xtls', $query);
    }

    public function test_vless_ws_grpc_tcp_kcp_transports(): void
    {
        $ws = $this->parseQuery(Shadowrocket::buildVless(self::UUID, $this->makeServer([
            'protocol_settings' => [
                'tls' => 1,
                'tls_settings' => ['server_name' => 'example.com'],
                'network' => 'ws',
                'network_settings' => [
                    'path' => '/ws-path',
                    'headers' => ['Host' => 'example.com'],
                ],
            ],
        ])));
        $this->assertSame('ws', $ws['type']);
        $this->assertSame('/ws-path', $ws['path']);
        $this->assertSame('example.com', $ws['host']);

        $grpc = $this->parseQuery(Shadowrocket::buildVless(self::UUID, $this->makeServer([
            'protocol_settings' => [
                'tls' => 1,
                'tls_settings' => ['server_name' => 'example.com'],
                'network' => 'grpc',
                'network_settings' => ['serviceName' => 'test-service'],
            ],
        ])));
        $this->assertSame('grpc', $grpc['type']);
        $this->assertSame('test-service', $grpc['serviceName']);

        $tcp = $this->parseQuery(Shadowrocket::buildVless(self::UUID, $this->makeServer([
            'protocol_settings' => [
                'tls' => 1,
                'tls_settings' => ['server_name' => 'example.com'],
                'network' => 'tcp',
                'network_settings' => [
                    'header' => [
                        'type' => 'http',
                        'request' => [
                            'path' => ['/header-path'],
                            'headers' => ['Host' => ['example.com']],
                        ],
                    ],
                ],
            ],
        ])));
        $this->assertSame('tcp', $tcp['type']);
        $this->assertSame('http', $tcp['headerType']);
        $this->assertSame('/header-path', $tcp['path']);
        $this->assertSame('example.com', $tcp['host']);

        $kcp = $this->parseQuery(Shadowrocket::buildVless(self::UUID, $this->makeServer([
            'protocol_settings' => [
                'network' => 'kcp',
                'network_settings' => [
                    'seed' => 'test-seed',
                    'header' => ['type' => 'srtp'],
                ],
            ],
        ])));
        $this->assertSame('kcp', $kcp['type']);
        $this->assertSame('srtp', $kcp['headerType']);
        $this->assertSame('test-seed', $kcp['seed']);
    }

    public function test_vless_ipv6_host_and_fragment_encoding(): void
    {
        $uri = Shadowrocket::buildVless(self::UUID, $this->makeServer([
            'name' => '中文 节点',
            'host' => '2001:db8::1',
            'protocol_settings' => [
                'tls' => 1,
                'tls_settings' => ['server_name' => 'example.com'],
                'network' => 'tcp',
            ],
        ]));

        $this->assertStringStartsWith('vless://' . self::UUID . '@[2001:db8::1]:443?', $uri);
        $this->assertStringEndsWith('#' . rawurlencode('中文 节点') . "\r\n", $uri);
    }

    private function makeServer(array $overrides = []): array
    {
        return array_merge([
            'name' => 'test-node',
            'host' => 'example.com',
            'port' => 443,
            'protocol_settings' => [],
        ], $overrides);
    }

    private function parseQuery(string $uri): array
    {
        $query = parse_url(trim($uri), PHP_URL_QUERY);
        $result = [];
        foreach (explode('&', (string) $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $result[$key] = rawurldecode($value);
        }
        return $result;
    }
}

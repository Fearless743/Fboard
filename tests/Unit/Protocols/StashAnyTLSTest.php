<?php

namespace Tests\Unit\Protocols;

use Plugin\Stash\Stash;
use Tests\TestCase;

class StashAnyTLSTest extends TestCase
{
    public function test_build_anytls_with_sni_and_insecure(): void
    {
        $proxy = Stash::buildAnyTLS('letmein', [
            'name' => 'demo node',
            'host' => 'example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => 'real.example.com',
                    'allow_insecure' => true,
                ],
            ],
        ]);

        $this->assertSame([
            'name' => 'demo node',
            'type' => 'anytls',
            'server' => 'example.com',
            'port' => 443,
            'password' => 'letmein',
            'udp' => true,
            'sni' => 'real.example.com',
            'skip-cert-verify' => true,
        ], $proxy);
    }

    public function test_build_anytls_omits_empty_sni_and_false_insecure(): void
    {
        $proxy = Stash::buildAnyTLS('secret', [
            'name' => 'node',
            'host' => '1.2.3.4',
            'port' => 8443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => null,
                    'allow_insecure' => false,
                ],
            ],
        ]);

        $this->assertSame([
            'name' => 'node',
            'type' => 'anytls',
            'server' => '1.2.3.4',
            'port' => 8443,
            'password' => 'secret',
            'udp' => true,
        ], $proxy);
        $this->assertArrayNotHasKey('sni', $proxy);
        $this->assertArrayNotHasKey('skip-cert-verify', $proxy);
    }

    public function test_build_anytls_handles_null_protocol_settings(): void
    {
        $proxy = Stash::buildAnyTLS('p@ss', [
            'name' => '节点',
            'host' => 'host.example',
            'port' => 443,
            'protocol_settings' => null,
        ]);

        $this->assertSame([
            'name' => '节点',
            'type' => 'anytls',
            'server' => 'host.example',
            'port' => 443,
            'password' => 'p@ss',
            'udp' => true,
        ], $proxy);
        $this->assertArrayNotHasKey('sni', $proxy);
        $this->assertArrayNotHasKey('skip-cert-verify', $proxy);
    }

    public function test_build_anytls_treats_integer_one_as_insecure(): void
    {
        $proxy = Stash::buildAnyTLS('uuid-here', [
            'name' => 'legacy',
            'host' => 'proxy.example',
            'port' => 443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => '',
                    'allow_insecure' => 1,
                ],
            ],
        ]);

        $this->assertSame('anytls', $proxy['type']);
        $this->assertTrue($proxy['udp']);
        $this->assertTrue($proxy['skip-cert-verify']);
        // 空字符串 SNI 不应写出
        $this->assertArrayNotHasKey('sni', $proxy);
    }
}

<?php

namespace Tests\Unit\Protocols;

use Plugin\General\General;
use Tests\TestCase;

class GeneralAnyTLSUriTest extends TestCase
{
    public function test_build_anytls_matches_official_uri_scheme(): void
    {
        $uri = General::buildAnyTLS('letmein', [
            'name' => 'demo node',
            'host' => 'example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => 'real.example.com',
                    'allow_insecure' => false,
                ],
            ],
        ]);

        $this->assertSame(
            "anytls://letmein@example.com:443/?sni=real.example.com&insecure=0#demo%20node\r\n",
            $uri
        );
    }

    public function test_build_anytls_omits_empty_sni_and_forces_insecure_digits(): void
    {
        $uri = General::buildAnyTLS('secret', [
            'name' => 'node',
            'host' => '1.2.3.4',
            'port' => 8443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => null,
                    'allow_insecure' => true,
                ],
            ],
        ]);

        $this->assertSame(
            "anytls://secret@1.2.3.4:8443/?insecure=1#node\r\n",
            $uri
        );
    }

    public function test_build_anytls_handles_null_protocol_settings_and_encodes_password(): void
    {
        $uri = General::buildAnyTLS('p@ss:w/d?', [
            'name' => '节点',
            'host' => 'host.example',
            'port' => 443,
            'protocol_settings' => null,
        ]);

        $this->assertSame(
            'anytls://p%40ss%3Aw%2Fd%3F@host.example:443/?insecure=0#' . rawurlencode('节点') . "\r\n",
            $uri
        );
    }

    public function test_build_anytls_wraps_ipv6_host(): void
    {
        $uri = General::buildAnyTLS('0fdf77d7-d4ba-455e-9ed9-a98dd6d5489a', [
            'name' => 'v6',
            'host' => '2409:8a71:6a00:1953::615',
            'port' => 8964,
            'protocol_settings' => [
                'tls' => [
                    'allow_insecure' => 1,
                ],
            ],
        ]);

        $this->assertStringStartsWith(
            'anytls://0fdf77d7-d4ba-455e-9ed9-a98dd6d5489a@[2409:8a71:6a00:1953::615]:8964/?insecure=1#',
            $uri
        );
    }
}

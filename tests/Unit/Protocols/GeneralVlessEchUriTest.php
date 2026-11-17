<?php

namespace Tests\Unit\Protocols;

use Plugin\General\General;
use Tests\TestCase;

class GeneralVlessEchUriTest extends TestCase
{
    public function test_build_vless_adds_ech_params_from_pem_config(): void
    {
        $pem = "-----BEGIN ECH CONFIGS-----\nAAj+DQAEAAAAAA==\n-----END ECH CONFIGS-----";

        $uri = General::buildVless('letmein', [
            'name' => 'ech node',
            'host' => 'example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'tcp',
                'tls_settings' => [
                    'server_name' => 'real.example.com',
                    'ech' => [
                        'enabled' => true,
                        'config' => $pem,
                        'query_server_name' => 'cloudflare-ech.com',
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('security=tls', $uri);
        $this->assertStringContainsString('sni=real.example.com', $uri);
        $this->assertStringContainsString('ech=AAj%2BDQAEAAAAAA%3D%3D', $uri);
        $this->assertStringContainsString('echSni=cloudflare-ech.com', $uri);
        $this->assertStringEndsWith("#ech%20node\r\n", $uri);
    }

    public function test_build_vless_omits_ech_params_when_ech_disabled(): void
    {
        $uri = General::buildVless('letmein', [
            'name' => 'node',
            'host' => 'example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'tcp',
                'tls_settings' => [
                    'ech' => [
                        'enabled' => false,
                        'config' => "-----BEGIN ECH CONFIGS-----\nAAj+DQAEAAAAAA==\n-----END ECH CONFIGS-----",
                    ],
                ],
            ],
        ]);

        $this->assertStringNotContainsString('ech=', $uri);
        $this->assertStringNotContainsString('echSni=', $uri);
    }
}

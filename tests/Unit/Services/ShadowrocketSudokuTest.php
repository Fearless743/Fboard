<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Plugin\Shadowrocket\Shadowrocket;

/**
 * Shadowrocket Sudoku 订阅下发格式：
 * sudoku://<base64("<aead>:<key>@<host>:<port>")>?obfsParam=<json>&obfs=httpmask&udp=1#<name>
 * 对齐 Shadowrocket 2.2.92 实际导出的 sudoku 链接方言，而非官方短链 sudoku://base64url(json)。
 */
class ShadowrocketSudokuTest extends TestCase
{
    private function parse(string $uri): array
    {
        $uri = trim($uri);
        $this->assertStringStartsWith('sudoku://', $uri);
        $parts = parse_url($uri);
        $this->assertNotFalse($parts);
        $query = [];
        parse_str($parts['query'] ?? '', $query);

        return [
            'userinfo' => base64_decode($parts['host'] ?? ''),
            'query' => $query,
            'obfsParam' => json_decode($query['obfsParam'] ?? '', true),
            'fragment' => isset($parts['fragment']) ? urldecode($parts['fragment']) : null,
        ];
    }

    public function test_matches_shadowrocket_export_format(): void
    {
        $password = '3b850eac77e67e5a5b73e815ed8595b07e3c1a83a865d416eafbd20cb62c9702142c3212a13fd51dfe4d97859c6392057e92f46d3aea72de3fe7d2e48095620c';
        $server = [
            'name' => 'node-1',
            'host' => 'cf.999311.xyz',
            'port' => 443,
            'protocol_settings' => [
                'advanced' => true,
                'aead_method' => 'chacha20-poly1305',
                'padding_min' => 5,
                'padding_max' => 15,
                'table_type' => 'prefer_entropy',
                'enable_pure_downlink' => true,
                'httpmask' => [
                    'disable' => false,
                    'mode' => 'ws',
                    'tls' => true,
                    'host' => 'o79qq66t.421895.xyz',
                    'path_root' => 'QL0EUOjp',
                ],
            ],
        ];

        $parsed = $this->parse(Shadowrocket::buildSudoku($password, $server));

        $this->assertSame("chacha20-poly1305:{$password}@cf.999311.xyz:443", $parsed['userinfo']);
        $this->assertSame('httpmask', $parsed['query']['obfs'] ?? null);
        $this->assertSame('1', (string) ($parsed['query']['udp'] ?? null));
        $this->assertSame('node-1', $parsed['fragment']);

        $obfs = $parsed['obfsParam'];
        $this->assertSame(5, $obfs['padding-min']);
        $this->assertSame(15, $obfs['padding-max']);
        $this->assertSame('prefer_entropy', $obfs['table-type']);
        $this->assertTrue($obfs['enable-pure-downlink']);
        $this->assertSame(true, $obfs['httpmask']['tls']);
        $this->assertSame('o79qq66t.421895.xyz', $obfs['httpmask']['host']);
        $this->assertSame(0, $obfs['httpmask']['disable']);
        $this->assertSame('ws', $obfs['httpmask']['mode']);
        $this->assertSame('/QL0EUOjp', $obfs['httpmask']['path-root']);
    }

    public function test_defaults_and_normalization(): void
    {
        $server = [
            'name' => 'n',
            'host' => 'example.com',
            'port' => 8443,
            'protocol_settings' => [
                'advanced' => true,
                'httpmask' => [
                    'disable' => true,
                    'path_root' => '/aabbcc/',
                ],
                'custom_table' => 'xpxvvpvv',
                'custom_tables' => ['xpxvvpvv', 'vxpvxvvp'],
                'multiplex' => 'auto',
            ],
        ];

        $parsed = $this->parse(Shadowrocket::buildSudoku('key-hex', $server));

        $this->assertSame('chacha20-poly1305:key-hex@example.com:8443', $parsed['userinfo']);
        $obfs = $parsed['obfsParam'];
        $this->assertSame(5, $obfs['padding-min']);
        $this->assertSame(15, $obfs['padding-max']);
        $this->assertSame('prefer_entropy', $obfs['table-type']);
        $this->assertTrue($obfs['enable-pure-downlink']);
        $this->assertSame('xpxvvpvv', $obfs['custom-table']);
        $this->assertSame(['xpxvvpvv', 'vxpvxvvp'], $obfs['custom-tables']);
        $this->assertSame('auto', $obfs['multiplex']);
        $this->assertSame(1, $obfs['httpmask']['disable']);
        $this->assertSame('example.com', $obfs['httpmask']['host']);
        $this->assertSame('/aabbcc', $obfs['httpmask']['path-root']);
        $this->assertTrue($obfs['httpmask']['tls']);
    }

    public function test_invalid_path_root_is_omitted(): void
    {
        $server = [
            'name' => 'n',
            'host' => 'example.com',
            'port' => 443,
            'protocol_settings' => [
                'httpmask' => ['path_root' => 'api.v1'],
            ],
        ];

        $parsed = $this->parse(Shadowrocket::buildSudoku('key-hex', $server));
        $this->assertArrayNotHasKey('path-root', $parsed['obfsParam']['httpmask']);
    }

    public function test_advanced_disabled_omits_advanced_fields(): void
    {
        $server = [
            'name' => 'n',
            'host' => 'example.com',
            'port' => 443,
            'protocol_settings' => [
                // 即便残留模板默认值，未开高级模式也不下发
                'padding_min' => 5,
                'padding_max' => 15,
                'table_type' => 'prefer_entropy',
                'enable_pure_downlink' => true,
                'custom_table' => 'xpxvvpvv',
                'custom_tables' => ['xpxvvpvv'],
                'handshake_timeout' => 5,
                'httpmask' => ['mode' => 'ws'],
                'multiplex' => 'auto',
            ],
        ];

        $parsed = $this->parse(Shadowrocket::buildSudoku('key-hex', $server));
        $obfs = $parsed['obfsParam'];
        $this->assertArrayNotHasKey('padding-min', $obfs);
        $this->assertArrayNotHasKey('padding-max', $obfs);
        $this->assertArrayNotHasKey('table-type', $obfs);
        $this->assertArrayNotHasKey('enable-pure-downlink', $obfs);
        $this->assertArrayNotHasKey('custom-table', $obfs);
        $this->assertArrayNotHasKey('custom-tables', $obfs);
        // 非高级字段仍下发
        $this->assertSame('auto', $obfs['multiplex']);
        $this->assertSame('ws', $obfs['httpmask']['mode']);
    }
}

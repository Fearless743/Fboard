<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\User;
use App\Utils\Helper;
use Plugin\CoreProtocols\PasswordGenerators;
use PHPUnit\Framework\TestCase;

class PasswordGeneratorsTest extends TestCase
{
    private function server(string $type, array $protocolSettings, array $attrs = []): StubServerForPassword
    {
        $server = new StubServerForPassword();
        $server->forcedSettings = $protocolSettings;
        $server->setRawAttributes([
            'type' => $type,
            'parent_id' => $attrs['parent_id'] ?? null,
            'created_at' => (string) ($attrs['created_at'] ?? 1700000000),
        ], true);

        return $server;
    }

    private function user(string $uuid): User
    {
        $user = new User();
        $user->forceFill(['uuid' => $uuid]);

        return $user;
    }

    public function test_shadowsocks_non_2022_returns_uuid(): void
    {
        $node = $this->server('shadowsocks', ['cipher' => 'aes-256-gcm']);
        $user = $this->user('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        $this->assertSame($user->uuid, PasswordGenerators::shadowsocks($node, $user));
    }

    public function test_shadowsocks_2022_returns_server_and_user_key(): void
    {
        $createdAt = 1700000000;
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $node = $this->server('shadowsocks', [
            'cipher' => '2022-blake3-aes-128-gcm',
        ], ['created_at' => $createdAt]);
        $user = $this->user($uuid);

        $expected = Helper::getServerKey($createdAt, 16) . ':' . Helper::uuidToBase64($uuid, 16);
        $this->assertSame($expected, PasswordGenerators::shadowsocks($node, $user));
    }

    public function test_shadowsocks_server_key_resolver(): void
    {
        $createdAt = 1700000000;
        $node = $this->server('shadowsocks', [], ['created_at' => $createdAt]);

        $this->assertSame(
            Helper::getServerKey($createdAt, 16),
            PasswordGenerators::shadowsocksServerKey($node)
        );
    }

    public function test_sudoku_without_master_falls_back_to_uuid(): void
    {
        $node = $this->server('sudoku', []);
        $user = $this->user('user-uuid-1');

        $this->assertSame('user-uuid-1', PasswordGenerators::sudoku($node, $user));
    }
}

class StubServerForPassword extends Server
{
    public array $forcedSettings = [];

    public function getProtocolSettingsAttribute($value = null)
    {
        return $this->forcedSettings;
    }
}

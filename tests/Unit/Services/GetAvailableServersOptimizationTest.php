<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\ProtocolDefinitionRegistry;
use App\Services\ServerService;
use App\Support\ProtocolDefinition;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Plugin\CoreProtocols\ProtocolTypes;
use Tests\TestCase;

/**
 * FEARLESS-18 回归测试：ServerService::getAvailableServers 优化前后行为必须一致。
 *
 * 覆盖：权限组过滤、流量上限过滤、show 过滤、动态端口随机化、虚拟节点合并、
 * 缓存访问器取值、REALITY 归一化。
 */
class GetAvailableServersOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_visible_servers_for_group(): void
    {
        $group = 1;
        $user = $this->makeUser($group);
        $node = $this->makeServer(groupIds: [$group], show: true, type: ProtocolTypes::VMESS);

        $servers = ServerService::getAvailableServers($user);
        $ids = collect($servers)->pluck('id')->all();

        $this->assertSame([$node->id], $ids);
    }

    public function test_filters_out_hidden_servers(): void
    {
        $user = $this->makeUser(1);
        $this->makeServer(groupIds: [1], show: true, type: ProtocolTypes::VMESS);
        $hidden = $this->makeServer(groupIds: [1], show: false, type: ProtocolTypes::VMESS);

        $servers = ServerService::getAvailableServers($user);
        $ids = collect($servers)->pluck('id')->all();

        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_filters_out_servers_not_in_user_group(): void
    {
        $user = $this->makeUser(1);
        $node = $this->makeServer(groupIds: [2], show: true, type: ProtocolTypes::VMESS);

        $servers = ServerService::getAvailableServers($user);
        $this->assertSame([], $servers);
        $this->assertNotContains($node->id, collect($servers)->pluck('id')->all());
    }

    public function test_filters_out_exhausted_transfer_servers(): void
    {
        $user = $this->makeUser(1);
        // 流量用尽：u + d >= transfer_enable
        $this->makeServer(groupIds: [1], show: true, type: ProtocolTypes::VMESS, u: 700, d: 400, transferEnable: 1000);
        $ok = $this->makeServer(groupIds: [1], show: true, type: ProtocolTypes::VMESS, u: 10, d: 10, transferEnable: 1000);

        $servers = ServerService::getAvailableServers($user);
        $ids = collect($servers)->pluck('id')->all();

        $this->assertSame([$ok->id], $ids);
    }

    public function test_returns_all_servers_when_transfer_enable_null(): void
    {
        $user = $this->makeUser(1);
        $node = $this->makeServer(groupIds: [1], show: true, type: ProtocolTypes::VMESS, transferEnable: null, u: 999, d: 999);

        $servers = ServerService::getAvailableServers($user);
        $ids = collect($servers)->pluck('id')->all();

        $this->assertSame([$node->id], $ids);
    }

    public function test_dynamic_port_range_randomized(): void
    {
        $user = $this->makeUser(1);
        $node = $this->makeServer(groupIds: [1], show: true, type: ProtocolTypes::VMESS, port: '10000-20000');

        $servers = ServerService::getAvailableServers($user);

        $this->assertCount(1, $servers);
        $server = $servers[0];
        $this->assertIsInt($server['port']);
        $this->assertGreaterThanOrEqual(10000, $server['port']);
        $this->assertLessThanOrEqual(20000, $server['port']);
        // 原始端口范围应保留在 ports 字段
        $this->assertSame('10000-20000', $server['ports']);
    }

    public function test_virtual_child_merges_parent_config(): void
    {
        $user = $this->makeUser(1);
        $parent = $this->makeServer(groupIds: [1], show: true, type: ProtocolTypes::VMESS, host: 'parent.example.com', port: 443);
        $child = Server::createVirtual([
            'parent_id' => $parent->id,
            'name' => 'child-node',
            'host' => 'child.example.com',
            'port' => 8443,
            'group_ids' => [1],
            'show' => true,
        ]);

        $servers = ServerService::getAvailableServers($user);
        $byId = collect($servers)->keyBy('id');

        // 子节点展示字段覆盖为子节点自身的值
        $this->assertArrayHasKey($child->id, $byId);
        $this->assertSame('child.example.com', $byId[$child->id]['host']);
        $this->assertSame(8443, $byId[$child->id]['port']);
        // 协议类型继承自父节点
        $this->assertSame($parent->type, $byId[$child->id]['type']);
    }

    public function test_cache_accessors_hydrated_from_cache(): void
    {
        $user = $this->makeUser(1);
        $node = $this->makeServer(groupIds: [1], show: true, type: ProtocolTypes::VMESS);

        $now = time();
        $type = strtoupper($node->type);
        Cache::put("SERVER_{$type}_LAST_CHECK_AT_{$node->id}", $now, 3600);
        Cache::put("SERVER_{$type}_ONLINE_USER_{$node->id}", 42, 3600);

        $servers = ServerService::getAvailableServers($user);
        $server = collect($servers)->firstWhere('id', $node->id);

        $this->assertSame($now, $server['last_check_at']);
        $this->assertSame(42, $server['online']);
        $this->assertSame(1, $server['is_online']);
        // available_status：last_check_at 在窗口内、last_push_at 缺失 → ONLINE_NO_PUSH
        $this->assertSame(Server::STATUS_ONLINE_NO_PUSH, $server['available_status']);
    }

    public function test_empty_cache_misses_default(): void
    {
        $user = $this->makeUser(1);
        $node = $this->makeServer(groupIds: [1], show: true, type: ProtocolTypes::VMESS);

        $servers = ServerService::getAvailableServers($user);
        $server = collect($servers)->firstWhere('id', $node->id);

        $this->assertNull($server['last_check_at']);
        $this->assertNull($server['last_push_at']);
        $this->assertSame(0, $server['online']);
        $this->assertSame(0, $server['is_online']);
        $this->assertSame(0, $server['available_status']);
    }

    public function test_rate_time_enabled_returns_current_rate(): void
    {
        $user = $this->makeUser(1);
        $now = now()->format('H:i');
        $ranges = [
            ['start' => '00:00', 'end' => '23:59', 'rate' => '2.5'],
        ];
        $node = $this->makeServer(
            groupIds: [1],
            show: true,
            type: ProtocolTypes::VMESS,
            rate: 1.0,
            rateTimeEnable: true,
            rateTimeRanges: $ranges,
        );

        $servers = ServerService::getAvailableServers($user);
        $server = collect($servers)->firstWhere('id', $node->id);

        $this->assertSame(2.5, $server['rate']);
    }

    public function test_reality_settings_normalized(): void
    {
        $user = $this->makeUser(1);
        // 32 字节 X25519 公钥，含标准 Base64 填充与 +/=，用于验证 RawURL 归一化
        $publicKey = '6e0bH9H1a2K5L7mN8pQ0rS2tU4vW6xY8zAbC123+def=';
        $settings = [
            'tls' => 2,
            'reality_settings' => [
                'public_key' => $publicKey,
            ],
        ];
        $node = $this->makeServer(groupIds: [1], show: true, type: ProtocolTypes::VLESS, protocolSettings: $settings);

        $servers = ServerService::getAvailableServers($user);
        $server = collect($servers)->firstWhere('id', $node->id);

        // 规范化后 public_key 应为 RawURL Base64（'+' 变 '-'，'=' 去掉）
        $this->assertStringNotContainsString('+', $server['protocol_settings']['reality_settings']['public_key'], json_encode($server));
        $this->assertStringNotContainsString('=', $server['protocol_settings']['reality_settings']['public_key']);
    }

    public function test_password_and_rate_present(): void
    {
        $user = $this->makeUser(1);
        $node = $this->makeServer(groupIds: [1], show: true, type: ProtocolTypes::VMESS);

        $servers = ServerService::getAvailableServers($user);
        $server = collect($servers)->firstWhere('id', $node->id);

        $this->assertArrayHasKey('password', $server);
        $this->assertSame(1.0, $server['rate']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 测试环境未启用插件时协议注册表为空；为 reality_settings 归一化测试
        // 补充一个带 reality 配置的协议定义。
        $registry = app(ProtocolDefinitionRegistry::class);
        if ($registry->get(ProtocolTypes::VLESS) === null) {
            $registry->register(new ProtocolDefinition(
                type: ProtocolTypes::VLESS,
                name: 'VLESS',
                configFields: [
                    'tls' => ['type' => 'integer', 'default' => 0],
                    'reality_settings' => ['type' => 'object', 'fields' => [
                        'public_key' => ['type' => 'string', 'default' => null],
                        'private_key' => ['type' => 'string', 'default' => null],
                    ]],
                ],
                validationRules: [],
            ));
        }
    }

    private function makeUser(int $groupId): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'gas-' . Helper::guid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'group_id' => $groupId,
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

    private function makeServer(
        array $groupIds,
        bool $show,
        string $type,
        string|int $port = 443,
        string $host = '127.0.0.1',
        int|float $rate = 1.0,
        ?int $u = 0,
        ?int $d = 0,
        ?int $transferEnable = null,
        ?array $protocolSettings = null,
        ?bool $rateTimeEnable = null,
        ?array $rateTimeRanges = null,
    ): Server {
        return Server::create(array_filter([
            'name' => 'node-' . Helper::guid(),
            'type' => $type,
            'host' => $host,
            'port' => $port,
            'server_port' => 443,
            'rate' => (string) $rate,
            'group_ids' => $groupIds,
            'show' => $show,
            'enabled' => true,
            'u' => $u,
            'd' => $d,
            'transfer_enable' => $transferEnable,
            'protocol_settings' => $protocolSettings,
            'rate_time_enable' => $rateTimeEnable,
            'rate_time_ranges' => $rateTimeRanges,
        ], fn ($v) => $v !== null));
    }
}

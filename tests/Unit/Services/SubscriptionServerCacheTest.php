<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Support\ServerCacheBag;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Plugin\CoreProtocols\ProtocolTypes;
use Tests\TestCase;

/**
 * 订阅下发/节点列表的运行状态字段必须与缓存保持一致，
 * 同时批量入口只对缓存做一次预读，不改变任何输出字段。
 */
class SubscriptionServerCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_servers_carry_node_runtime_status(): void
    {
        $user = $this->makeUser(groupId: 1, uuid: 'cache-user');
        $node = $this->makeNode(groupIds: [1]);

        $now = time();
        Cache::put(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $node->id), $now, 600);
        Cache::put(CacheKey::get('SERVER_VMESS_LAST_PUSH_AT', $node->id), $now, 600);
        Cache::put(CacheKey::get('SERVER_VMESS_ONLINE_USER', $node->id), 7, 600);

        $servers = ServerService::getAvailableServers($user);
        $row = collect($servers)->firstWhere('id', $node->id);

        $this->assertNotNull($row);
        $this->assertSame($now, $row['last_check_at']);
        $this->assertSame($now, $row['last_push_at']);
        $this->assertSame(7, $row['online']);
        $this->assertSame(1, $row['is_online']);
        $this->assertSame(Server::STATUS_ONLINE, $row['available_status']);
        $this->assertSame("vmess-{$node->id}-{$node->updated_at}-1", $row['cache_key']);
    }

    public function test_virtual_node_runtime_status_reads_parent_cache_keys(): void
    {
        $user = $this->makeUser(groupId: 1, uuid: 'virtual-user');
        $parent = $this->makeNode(groupIds: [1]);
        $child = Server::createVirtual([
            'parent_id' => $parent->id,
            'name' => 'virtual-child',
            'host' => 'child.example.com',
            'port' => 8443,
            'group_ids' => [1],
            'show' => true,
        ]);

        $now = time();
        Cache::put(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $parent->id), $now, 600);
        Cache::put(CacheKey::get('SERVER_VMESS_LAST_PUSH_AT', $parent->id), $now, 600);
        Cache::put(CacheKey::get('SERVER_VMESS_ONLINE_USER', $parent->id), 5, 600);

        $servers = ServerService::getAvailableServers($user);
        $row = collect($servers)->firstWhere('id', $child->id);

        $this->assertNotNull($row);
        $this->assertSame('virtual-child', $row['name']);
        $this->assertSame($now, $row['last_check_at']);
        $this->assertSame(5, $row['online']);
        $this->assertSame(1, $row['is_online']);
        $this->assertSame(Server::STATUS_ONLINE, $row['available_status']);
    }

    public function test_virtual_node_parents_are_eager_loaded(): void
    {
        $user = $this->makeUser(groupId: 1, uuid: 'eager-user');
        $parent = $this->makeNode(groupIds: [1]);
        foreach (['child-a', 'child-b', 'child-c'] as $name) {
            Server::createVirtual([
                'parent_id' => $parent->id,
                'name' => $name,
                'host' => "{$name}.example.com",
                'port' => 8443,
                'group_ids' => [1],
                'show' => true,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        ServerService::getAvailableServers($user);
        $serverQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn ($sql) => str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'v2_server'))
            ->values();

        // 主查询 + 一次 parent 预加载；懒加载会产生 1 + N 条。
        $this->assertCount(2, $serverQueries, $serverQueries->implode("\n"));
    }

    public function test_available_servers_batches_runtime_cache_reads(): void
    {
        $user = $this->makeUser(groupId: 1, uuid: 'batch-user');
        foreach (['batch-a', 'batch-b', 'batch-c'] as $name) {
            Server::create([
                'name' => $name,
                'type' => ProtocolTypes::VMESS,
                'host' => "{$name}.example.com",
                'port' => 443,
                'server_port' => 443,
                'rate' => '1',
                'group_ids' => [1],
                'show' => true,
                'enabled' => true,
            ]);
        }

        $repository = $this->countingRepository();
        Cache::swap($repository);

        ServerService::getAvailableServers($user);

        // 3 个节点 × 5 个访问器缓存键一次预读完成，访问器不再逐个回源。
        $this->assertSame(1, $repository->manyCalls);
        $this->assertSame(0, $repository->getCalls);
    }

    public function test_no_virtual_nodes_skips_extra_parent_query(): void
    {
        $user = $this->makeUser(groupId: 1, uuid: 'flat-user');
        $this->makeNode(groupIds: [1]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        ServerService::getAvailableServers($user);
        $serverQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn ($sql) => str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'v2_server'))
            ->values();

        $this->assertCount(1, $serverQueries, $serverQueries->implode("\n"));
    }

    public function test_server_cache_bag_batches_and_memoizes_reads(): void
    {
        $repository = $this->countingRepository();
        Cache::swap($repository);

        Cache::put('bag:one', 'value-1', 600);
        Cache::put('bag:missing', null, 600);

        $bag = new ServerCacheBag();
        $bag->preload(['bag:one', 'bag:missing', 'bag:absent']);

        $this->assertSame(1, $repository->manyCalls);
        $this->assertSame(0, $repository->getCalls);

        $this->assertSame('value-1', $bag->get('bag:one'));
        $this->assertNull($bag->get('bag:missing'));
        $this->assertNull($bag->get('bag:absent'));
        $this->assertSame(0, $repository->getCalls, '预读过的键不应再回源');

        $bag->get('bag:other');
        $this->assertSame(1, $repository->getCalls, '未预读的键才按需回源');

        $bag->flush();
        $this->assertSame('value-1', $bag->get('bag:one'));
        $this->assertSame(2, $repository->getCalls, 'flush 后应重新回源');
    }

    private function countingRepository(): Repository
    {
        return new class(new ArrayStore()) extends Repository {
            public int $manyCalls = 0;
            public int $getCalls = 0;

            public function many(array $keys): array
            {
                $this->manyCalls++;

                return parent::many($keys);
            }

            public function get($key, $default = null): mixed
            {
                $this->getCalls++;

                return parent::get($key, $default);
            }
        };
    }

    private function makeNode(array $groupIds): Server
    {
        return Server::create([
            'name' => 'parent-node',
            'type' => ProtocolTypes::VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => $groupIds,
            'show' => true,
            'enabled' => true,
        ]);
    }

    private function makeUser(int $groupId, string $uuid): User
    {
        $user = new User();
        $user->forceFill([
            'email' => $uuid . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => $uuid,
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
}

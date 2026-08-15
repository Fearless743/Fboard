<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Plugin\CoreProtocols\ProtocolTypes;
use Tests\TestCase;

/**
 * FEARLESS-18 回归测试：ServerService::getAllServers 优化前后行为一致。
 */
class GetAllServersOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_all_servers_sorted_by_sort(): void
    {
        $a = $this->makeServer(sort: 2, name: 'a');
        $b = $this->makeServer(sort: 1, name: 'b');

        $servers = ServerService::getAllServers();

        $this->assertCount(2, $servers);
        $this->assertSame([$b->id, $a->id], $servers->pluck('id')->all());
    }

    public function test_appends_cache_accessors(): void
    {
        $node = $this->makeServer(sort: 1, name: 'n');
        $type = strtoupper($node->type);
        $now = time();
        Cache::put("SERVER_{$type}_LAST_CHECK_AT_{$node->id}", $now, 3600);
        Cache::put("SERVER_{$type}_LAST_PUSH_AT_{$node->id}", $now, 3600);
        Cache::put("SERVER_{$type}_ONLINE_USER_{$node->id}", 7, 3600);

        $server = ServerService::getAllServers()->first();

        $this->assertSame($now, $server['last_check_at']);
        $this->assertSame($now, $server['last_push_at']);
        $this->assertSame(7, $server['online']);
        $this->assertSame(1, $server['is_online']);
        $this->assertSame(Server::STATUS_ONLINE, $server['available_status']);
        $this->assertArrayHasKey('cache_key', $server->toArray());
        $this->assertArrayHasKey('load_status', $server->toArray());
        $this->assertArrayHasKey('metrics', $server->toArray());
        $this->assertArrayHasKey('online_conn', $server->toArray());
    }

    private function makeServer(int $sort, string $name, int|string $port = 443): Server
    {
        return Server::create([
            'name' => $name,
            'type' => ProtocolTypes::VMESS,
            'host' => '127.0.0.1',
            'port' => $port,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'enabled' => true,
            'sort' => $sort,
        ]);
    }
}

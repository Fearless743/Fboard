<?php

namespace Tests\Unit\Services;

use App\Services\DeviceStateService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Guards the device-state index + pipeline rewrite.
 *
 * The old code iterated every user of a node (one HGETALL each) and used
 * Redis::KEYS, which blocked the single-threaded Workerman WS worker long
 * enough to stall handshakes. These tests pin the new O(active users) model.
 */
class DeviceStateServiceTest extends TestCase
{
    private InMemoryRedisConnection $redis;

    private DeviceStateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = new InMemoryRedisConnection();
        $this->app->instance('redis', new InMemoryRedisManager($this->redis));
        Redis::clearResolvedInstances();

        $this->service = new DeviceStateService();
    }

    public function test_set_devices_populates_indexes_and_snapshot(): void
    {
        $this->service->setDevices(1, 10, ['1.2.3.4:443', '1.2.3.5'], false);
        $this->service->setDevices(1, 11, ['1.2.3.6'], false);
        $this->service->setDevices(2, 10, ['9.9.9.9'], false);

        $devices = $this->service->getActiveDevices();
        $this->assertSame([1, 2], array_keys($devices));
        $this->assertSame(['1.2.3.4', '1.2.3.5', '1.2.3.6'], $this->sorted($devices[1]));
        $this->assertSame(['9.9.9.9'], $devices[2]);

        $node10 = $this->service->getNodeDevices(10);
        $this->assertSame([1, 2], array_keys($node10));
        $this->assertSame(['1.2.3.4', '1.2.3.5'], $this->sorted($node10[1]));
        $this->assertSame(['9.9.9.9'], $node10[2]);

        // Both indexes exist.
        $this->assertArrayHasKey('user_devices_index', $this->redis->sets);
        $this->assertArrayHasKey('node_devices:10', $this->redis->sets);
    }

    public function test_remove_node_devices_keeps_other_nodes_and_cleans_up(): void
    {
        $this->service->setDevices(1, 10, ['a'], false);
        $this->service->setDevices(1, 11, ['b'], false);

        $this->service->removeNodeDevices(10, 1);

        $this->assertSame([], $this->service->getNodeDevices(10));
        $this->assertSame(['b'], $this->service->getActiveDevices()[1]);

        $this->service->removeNodeDevices(11, 1);

        $this->assertSame([], $this->service->getActiveDevices());
        $this->assertArrayNotHasKey('user_devices:1', $this->redis->hashes);
        $this->assertArrayNotHasKey('user_devices_index', $this->redis->sets);
    }

    public function test_setting_empty_device_list_clears_user(): void
    {
        $this->service->setDevices(4, 10, ['1.1.1.1'], false);
        $this->service->setDevices(4, 10, [], false);

        $this->assertSame([], $this->service->getActiveDevices());
        $this->assertArrayNotHasKey('user_devices:4', $this->redis->hashes);
        $this->assertArrayNotHasKey('user_devices_index', $this->redis->sets);
    }

    public function test_clear_all_node_devices_returns_affected_and_keeps_peers(): void
    {
        $this->service->setDevices(1, 10, ['a'], false);
        $this->service->setDevices(2, 10, ['b'], false);
        $this->service->setDevices(2, 11, ['c'], false);

        $affected = $this->service->clearAllNodeDevices(10);
        sort($affected);

        $this->assertSame([1, 2], $affected);
        $this->assertSame([], $this->service->getNodeDevices(10));
        $this->assertArrayNotHasKey('node_devices:10', $this->redis->sets);

        $remaining = $this->service->getActiveDevices();
        $this->assertSame([2], array_keys($remaining));
        $this->assertSame(['c'], $remaining[2]);
    }

    public function test_expired_fields_are_ignored_and_index_is_pruned(): void
    {
        $this->redis->hashes['user_devices:5'] = ['10:1.1.1.1' => time() - 400];
        $this->redis->sets['user_devices_index'] = ['5' => true];

        $this->assertSame([], $this->service->getActiveDevices());
        $this->assertArrayNotHasKey('user_devices_index', $this->redis->sets);
    }

    public function test_device_counts_deduplicate_ips_across_nodes(): void
    {
        $this->service->setDevices(3, 10, ['1.1.1.1', '1.1.1.2'], false);
        $this->service->setDevices(3, 11, ['1.1.1.1'], false);

        $this->assertSame([3 => 2], $this->service->getDeviceCounts([3]));
        $this->assertSame(2, $this->service->getDeviceCount(3));
    }

    /**
     * @param  string[]  $values
     * @return string[]
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}

/**
 * Minimal in-memory stand-in for the phpredis connection used by
 * DeviceStateService. Supports the hash/set commands plus pipelines.
 */
final class InMemoryRedisConnection
{
    /** @var array<string, array<string, int|string>> */
    public array $hashes = [];

    /** @var array<string, array<string, true>> */
    public array $sets = [];

    private bool $pipelining = false;

    /** @var array<int, mixed> */
    private array $pipelineResults = [];

    private function reply(mixed $value): mixed
    {
        if ($this->pipelining) {
            $this->pipelineResults[] = $value;

            return $this;
        }

        return $value;
    }

    public function client(): self
    {
        return $this;
    }

    public function pipeline(?callable $callback = null): mixed
    {
        $this->pipelining = true;
        $this->pipelineResults = [];

        if ($callback !== null) {
            $callback($this);

            return $this->exec();
        }

        return $this;
    }

    public function exec(): array
    {
        $this->pipelining = false;
        $results = $this->pipelineResults;
        $this->pipelineResults = [];

        return $results;
    }

    public function hgetall(string $key): mixed
    {
        return $this->reply($this->hashes[$key] ?? []);
    }

    public function hkeys(string $key): mixed
    {
        return $this->reply(array_keys($this->hashes[$key] ?? []));
    }

    public function hmset(string $key, array $fields): mixed
    {
        foreach ($fields as $field => $value) {
            $this->hashes[$key][$field] = $value;
        }

        return $this->reply(true);
    }

    public function hdel(string $key, string ...$fields): mixed
    {
        $deleted = 0;
        foreach ($fields as $field) {
            if (isset($this->hashes[$key][$field])) {
                unset($this->hashes[$key][$field]);
                $deleted++;
            }
        }
        if (isset($this->hashes[$key]) && $this->hashes[$key] === []) {
            unset($this->hashes[$key]);
        }

        return $this->reply($deleted);
    }

    public function expire(string $key, int $ttl): mixed
    {
        return $this->reply(1);
    }

    public function del(string ...$keys): mixed
    {
        $deleted = 0;
        foreach ($keys as $key) {
            if (isset($this->hashes[$key])) {
                unset($this->hashes[$key]);
                $deleted++;
            }
            if (isset($this->sets[$key])) {
                unset($this->sets[$key]);
                $deleted++;
            }
        }

        return $this->reply($deleted);
    }

    public function sadd(string $key, mixed ...$members): mixed
    {
        $added = 0;
        foreach ($members as $member) {
            $member = (string) $member;
            if (! isset($this->sets[$key][$member])) {
                $this->sets[$key][$member] = true;
                $added++;
            }
        }

        return $this->reply($added);
    }

    public function srem(string $key, mixed ...$members): mixed
    {
        $removed = 0;
        foreach ($members as $member) {
            $member = (string) $member;
            if (isset($this->sets[$key][$member])) {
                unset($this->sets[$key][$member]);
                $removed++;
            }
        }
        if (isset($this->sets[$key]) && $this->sets[$key] === []) {
            unset($this->sets[$key]);
        }

        return $this->reply($removed);
    }

    public function smembers(string $key): mixed
    {
        return $this->reply(array_keys($this->sets[$key] ?? []));
    }
}

final class InMemoryRedisManager
{
    public function __construct(private InMemoryRedisConnection $connection)
    {
    }

    public function connection(?string $name = null): InMemoryRedisConnection
    {
        return $this->connection;
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection->{$method}(...$parameters);
    }
}

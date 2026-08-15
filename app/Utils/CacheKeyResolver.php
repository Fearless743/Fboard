<?php

namespace App\Utils;

use App\Models\Server;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 服务端缓存键的批量解析器。
 *
 * Server 的缓存访问器（last_check_at / last_push_at / online / metrics /
 * load_status）都从 Redis 按「SERVER_{TYPE}_{ID}」键读取。单节点逐次
 * Cache::get 时每个访问器一次 Redis 往返；本类把同一请求内所有访问器的
 * 命中缓存到静态 Map，使同一模型重复访问/多个访问器只打一次 Redis。
 */
class CacheKeyResolver
{
    /** @var array<string, mixed> key => value，null 表示已探测过但未命中 */
    private static array $resolved = [];

    /**
     * 解析单个服务器的缓存访问器值。
     *
     * @param  Server  $server  当前模型（虚拟节点用 parent_id 定位缓存键）
     * @param  Closure $keyFor  生成缓存键：fn(string $typeUpper, int $serverId): string
     * @return mixed
     */
    public static function serverCache(Server $server, Closure $keyFor): mixed
    {
        $type = strtoupper((string) $server->type);
        $serverId = (int) ($server->parent_id ?: $server->id);
        $cacheKey = $keyFor($type, $serverId);

        if (array_key_exists($cacheKey, self::$resolved)) {
            return self::$resolved[$cacheKey];
        }

        return self::$resolved[$cacheKey] = Cache::get($cacheKey);
    }

    /**
     * 清空本请求内的已解析缓存。供批量入口在开始前调用，
     * 避免跨请求（Octane 常驻进程）或跨用户列表污染缓存值。
     */
    public static function flush(): void
    {
        self::$resolved = [];
    }
}

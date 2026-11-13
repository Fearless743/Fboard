<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * 请求级节点缓存读取器。
 *
 * Server 的 last_check_at / last_push_at / online / metrics / load_status
 * 访问器都按「SERVER_{TYPE}_{ID}」从缓存读取。一次订阅下发会把每个节点的
 * 这些访问器全部求值，同一节点约产生 5~8 次独立缓存读取；节点较多时会
 * 把订阅耗时堆在缓存往返上。
 *
 * 本类在单次请求内 memoize 已读取的键，并支持通过 Cache::many() 分片
 * 预读：预读后访问器不再逐个回源。绑定为 scoped 实例，Octane 每个请求
 * 结束会丢弃，不会跨请求串数据；批量入口在开始前调用 flush() 以取得
 * 最新快照。
 */
class ServerCacheBag
{
    /** 单次 Cache::many() 的键数量上限，避免 Redis MGET 参数过大。 */
    private const PRELOAD_CHUNK_SIZE = 500;

    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * 读取缓存值；未预读的键在首次访问时回源并记住。
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        return $this->values[$key] = Cache::get($key, $default);
    }

    /**
     * 批量预读缓存键。
     *
     * 未命中的键同样以 null 记录，语义与 Cache::get() 一致，
     * 后续访问器不会对同一缺失键重复回源。
     *
     * @param  iterable<int, string>  $keys
     */
    public function preload(iterable $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if ($key === '' || array_key_exists($key, $this->values)) {
                continue;
            }
            $missing[$key] = true;
        }

        foreach (array_chunk(array_keys($missing), self::PRELOAD_CHUNK_SIZE) as $chunk) {
            $values = Cache::many($chunk);
            foreach ($chunk as $key) {
                $this->values[$key] = $values[$key] ?? null;
            }
        }
    }

    /**
     * 清空本请求已读取的值，使下一次读取回到缓存源。
     */
    public function flush(): void
    {
        $this->values = [];
    }
}

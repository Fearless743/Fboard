<?php

namespace App\Models;

use App\Traits\HasPinyinSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * App\Models\ServerMachine
 *
 * @property int $id
 * @property string $name 机器名称
 * @property string $token 认证 Token
 * @property string|null $notes 备注
 * @property bool $is_active 是否启用
 * @property int|null $last_seen_at 最后心跳时间
 * @property array|null $load_status 负载状态
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Server> $servers 关联的节点
 */
class ServerMachine extends Model
{
    use HasPinyinSearch;

    protected $table = 'v2_server_machine';

    protected $guarded = ['id'];

    /**
     * 需要生成拼音索引的字段
     */
    protected array $pinyinSearchable = ['name', 'notes'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'integer',
        'load_status' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    protected $hidden = ['token'];

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'machine_id');
    }

    public function loadHistory(): HasMany
    {
        return $this->hasMany(ServerMachineLoadHistory::class, 'machine_id');
    }

    /**
     * 生成新的随机 Token
     */
    public static function generateToken(): string
    {
        return Str::random(32);
    }

    /**
     * 更新最后心跳时间
     */
    public function updateHeartbeat(): bool
    {
        return $this->forceFill(['last_seen_at' => now()->timestamp])->save();
    }

    /**
     * 判断机器是否在最近一个检查周期内上报心跳。
     *
     * DB 字段 `last_seen_at` 由心跳异步写回，存在延迟；为避免「节点一直活跃
     * 但面板误判离线导致升级/重启/拉日志被 409002 拒绝」，同时检查 NodeWorker
     * 维护的 Redis 活跃缓存（node_ws_alive:{machine_id}），DB 字段作 fallback。
     */
    public function isOnline(int $timeout = Server::CHECK_INTERVAL): bool
    {
        $now = time();

        if ($this->last_seen_at !== null && ($now - (int) $this->last_seen_at) < $timeout) {
            return true;
        }

        try {
            return (bool) \Illuminate\Support\Facades\Cache::get("node_ws_alive:{$this->id}");
        } catch (\Throwable) {
            return false;
        }
    }
}

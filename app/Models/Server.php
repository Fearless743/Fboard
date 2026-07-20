<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\App;
use App\Utils\CacheKey;
use App\Utils\Helper;
use App\Models\User;
use App\Services\ProtocolDefinitionRegistry;
use App\Support\SudokuKey;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * App\Models\Server
 *
 * @property int $id
 * @property string $name 节点名称
 * @property string $type 服务类型
 * @property string $host 主机地址
 * @property string|int $port 端口
 * @property int|null $server_port 服务器端口
 * @property array|null $group_ids 分组IDs
 * @property array|null $route_ids 路由IDs
 * @property array|null $tags 标签
 * @property boolean $show 是否显示
 * @property string|null $allow_insecure 是否允许不安全
 * @property string|null $network 网络类型
 * @property int|null $parent_id 父节点ID
 * @property float|null $rate 倍率
 * @property boolean $rate_time_enable 是否启用时间范围功能
 * @property array|null $rate_time_ranges 倍率时间范围
 * @property int|null $sort 排序
 * @property array|null $protocol_settings 协议设置
 * @property int $created_at
 * @property int $updated_at
 *
 * @property-read Server|null $parent 父节点
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StatServer> $stats 节点统计
 *
 * @property-read int|null $last_check_at 最后检查时间（Unix时间戳）
 * @property-read int|null $last_push_at 最后推送时间（Unix时间戳）
 * @property-read int $online 在线用户数
 * @property-read int $online_conn 在线连接数
 * @property-read array|null $metrics 节点指标指标
 * @property-read int $is_online 是否在线（1在线 0离线）
 * @property-read string $available_status 可用状态描述
 * @property-read string $cache_key 缓存键
 * @property string|null $ports 端口范围
 * @property string|null $password 密码
 * @property int|null $u 上行流量
 * @property int|null $d 下行流量
 * @property int|null $total 总流量
 * @property-read array|null $load_status 负载状态（包含CPU、内存、交换区、磁盘信息）
 *
 * @property int $transfer_enable 流量上限，0或者null表示不限制
 * @property int $u 当前上传流量
 * @property int $d 当前下载流量
 */
class Server extends Model
{
    public const TYPE_HYSTERIA = "hysteria";
    public const TYPE_VLESS = "vless";
    public const TYPE_TROJAN = "trojan";
    public const TYPE_VMESS = "vmess";
    public const TYPE_TUIC = "tuic";
    public const TYPE_SHADOWSOCKS = "shadowsocks";
    public const TYPE_ANYTLS = "anytls";
    public const TYPE_SOCKS = "socks";
    public const TYPE_NAIVE = "naive";
    public const TYPE_HTTP = "http";
    public const TYPE_MIERU = "mieru";
    public const TYPE_SUDOKU = "sudoku";
    public const STATUS_OFFLINE = 0;
    public const STATUS_ONLINE_NO_PUSH = 1;
    public const STATUS_ONLINE = 2;

    public const CHECK_INTERVAL = 300; // 5 minutes in seconds

    private const CIPHER_CONFIGURATIONS = [
        "2022-blake3-aes-128-gcm" => [
            "serverKeySize" => 16,
            "userKeySize" => 16,
        ],
        "2022-blake3-aes-256-gcm" => [
            "serverKeySize" => 32,
            "userKeySize" => 32,
        ],
        "2022-blake3-chacha20-poly1305" => [
            "serverKeySize" => 32,
            "userKeySize" => 32,
        ],
    ];

    public const TYPE_ALIASES = [
        "v2ray" => self::TYPE_VMESS,
        "hysteria2" => self::TYPE_HYSTERIA,
    ];

    public static function getValidTypes(): array
    {
        return app(ProtocolDefinitionRegistry::class)->getValidTypes();
    }

    protected $table = "v2_server";

    protected $guarded = ["id"];
    protected $casts = [
        "group_ids" => "array",
        "route_ids" => "array",
        "tags" => "array",
        "protocol_settings" => "array",
        "custom_outbounds" => "array",
        "custom_routes" => "array",
        "cert_config" => "array",
        "last_check_at" => "integer",
        "last_push_at" => "integer",
        "show" => "boolean",
        "enabled" => "boolean",
        "created_at" => "timestamp",
        "updated_at" => "timestamp",
        "rate_time_ranges" => "array",
        "rate_time_enable" => "boolean",
        "transfer_enable" => "integer",
        "u" => "integer",
        "d" => "integer",
        "machine_id" => "integer",
    ];

    public static function getProtocolConfigurations(): array
    {
        $registry = app(ProtocolDefinitionRegistry::class);
        $configs = [];

        foreach ($registry->getAll() as $type => $definition) {
            $configs[$type] = self::convertConfigFieldsToCastingFormat(
                $definition->configFields,
            );
        }

        return $configs;
    }

    private static function convertConfigFieldsToCastingFormat(
        array $fields,
    ): array {
        $result = [];
        foreach ($fields as $key => $field) {
            if (isset($field["type"])) {
                $result[$key] = [
                    "type" => $field["type"],
                    "default" => $field["default"] ?? null,
                ];
                if ($field["type"] === "object" && isset($field["fields"])) {
                    $result[$key]["fields"] = $field["fields"];
                }
            }
        }
        return $result;
    }

    private function castValueWithConfig($value, array $config)
    {
        if ($value === null && $config["type"] !== "object") {
            return $config["default"] ?? null;
        }

        return match ($config["type"]) {
            "integer" => (int) $value,
            "boolean" => (bool) $value,
            "string" => (string) $value,
            "array" => (array) $value,
            "object" => is_array($value)
                ? $this->castSettingsWithConfig($value, $config["fields"])
                : $config["default"] ?? null,
            default => $value,
        };
    }

    private function castSettingsWithConfig(
        array $settings,
        array $configs,
    ): array {
        $result = [];
        foreach ($configs as $key => $config) {
            $value = $settings[$key] ?? null;
            $result[$key] = $this->castValueWithConfig($value, $config);
        }
        return $result;
    }

    public function getProtocolSettingsAttribute($value)
    {
        $settings = json_decode($value, true) ?? [];
        $configs = self::getProtocolConfigurations()[$this->type] ?? [];
        return $this->castSettingsWithConfig($settings, $configs);
    }

    public function setProtocolSettingsAttribute($value)
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $configs = self::getProtocolConfigurations()[$this->type] ?? [];
        $castedSettings = $this->castSettingsWithConfig($value ?? [], $configs);

        // 保存时规范化 REALITY 密钥为 RawURL Base64，与 Xray/mihomo 一致
        if (!empty($castedSettings['reality_settings']) && is_array($castedSettings['reality_settings'])) {
            $castedSettings['reality_settings'] = Helper::normalizeRealitySettings($castedSettings['reality_settings']);
        }

        $this->attributes["protocol_settings"] = json_encode($castedSettings);
    }

    /**
     * 统一将 group_ids 序列化为字符串数组，避免 JSON_CONTAINS 因类型不匹配导致查询失败。
     *
     * MySQL JSON_CONTAINS 对类型严格敏感：
     *   - 数据库存为 [16,15,14,12] (整型) 时，传 "(string) 14" 不会命中
     *   - 数据库存为 ["16","15","14","12"] (字符串) 时，传 "(int) 14" 不会命中
     * 通过在 setter 中统一转为字符串，确保后续 whereJsonContains((string) $id) 能稳定命中。
     */
    public function setGroupIdsAttribute($value)
    {
        if (is_null($value)) {
            $this->attributes["group_ids"] = null;
            return;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        $normalized = array_values(
            array_unique(
                array_map(
                    fn($id) => (string) $id,
                    is_array($value) ? $value : [$value],
                ),
            ),
        );
        $this->attributes["group_ids"] = json_encode($normalized);
    }

    public function generateServerPassword(User $user): string
    {
        if ($this->type === self::TYPE_SUDOKU) {
            return $this->generateSudokuAvailableKey($user);
        }
        if ($this->type !== self::TYPE_SHADOWSOCKS) {
            return $user->uuid;
        }

        $cipher = data_get($this, "protocol_settings.cipher");
        if (!$cipher || !isset(self::CIPHER_CONFIGURATIONS[$cipher])) {
            return $user->uuid;
        }

        $config = self::CIPHER_CONFIGURATIONS[$cipher];
        // Use parent's created_at if this is a child node
        $serverCreatedAt = $this->parent_id
            ? $this->parent->created_at
            : $this->created_at;
        $serverKey = Helper::getServerKey(
            $serverCreatedAt,
            $config["serverKeySize"],
        );
        $userKey = Helper::uuidToBase64($user->uuid, $config["userKeySize"]);
        return "{$serverKey}:{$userKey}";
    }


    /**
     * Deterministic Sudoku Available Private Key for a user.
     * Compatible wire format with official split keys: hex(r||k) where master = r+k (mod L).
     * UserHash = hex(sha256(raw_key_bytes)[:8]).
     */
    private function generateSudokuAvailableKey(User $user): string
    {
        $settings = $this->protocol_settings ?? [];
        $masterPrivateHex = data_get($settings, 'master_private_key');
        if (!$masterPrivateHex) {
            // Fallback: cannot derive without master private; return uuid (will fail auth).
            return $user->uuid;
        }
        return SudokuKey::deriveAvailablePrivateKey($masterPrivateHex, $user->uuid);
    }

    public static function normalizeType(?string $type): string|null
    {
        return $type ? strtolower(self::TYPE_ALIASES[$type] ?? $type) : null;
    }

    public static function isValidType(?string $type): bool
    {
        return $type
            ? in_array(self::normalizeType($type), self::getValidTypes(), true)
            : true;
    }

    public function getAvailableStatusAttribute(): int
    {
        $now = time();
        if (
            !$this->last_check_at ||
            $now - self::CHECK_INTERVAL >= $this->last_check_at
        ) {
            return self::STATUS_OFFLINE;
        }
        if (
            !$this->last_push_at ||
            $now - self::CHECK_INTERVAL >= $this->last_push_at
        ) {
            return self::STATUS_ONLINE_NO_PUSH;
        }
        return self::STATUS_ONLINE;
    }

    /**
     * 获取合并后的有效配置（子节点继承父节点配置）
     */
    public function getEffectiveAttribute(): self
    {
        if (!$this->parent_id || !$this->parent) {
            return $this;
        }
        $parent = $this->parent;
        $merged = clone $parent;
        $merged->id = $this->id;
        $merged->name = $this->name;
        $merged->host = $this->host;
        $merged->port = $this->port;
        $merged->server_port = $this->server_port;
        $merged->group_ids = $this->group_ids;
        $merged->tags = $this->tags;
        $merged->show = $this->show;
        $merged->sort = $this->sort;
        return $merged;
    }

    /**
     * 创建虚拟节点（继承父节点配置）
     */
    public static function createVirtual(array $data): self
    {
        $parentId = $data["parent_id"] ?? null;
        if (!$parentId || !($parent = self::find($parentId))) {
            throw new \InvalidArgumentException("父节点不存在");
        }

        $data["type"] = "virtual";
        $data["protocol_settings"] = $parent->protocol_settings;
        $data["rate"] = $parent->rate;
        $data["show"] = $data["show"] ?? $parent->show;
        $data["listen_address"] =
            $data["listen_address"] ?? $parent->listen_address;
        $data["server_port"] = $data["server_port"] ?? $parent->server_port;
        $data["banned"] = $data["banned"] ?? ($parent->banned ?? false);
        $data["traffic_limit"] =
            $data["traffic_limit"] ?? ($parent->traffic_limit ?? 0);
        $data["sort"] = $parent->sort ?? 0;

        return self::create($data);
    }

    /**
     * 获取虚拟节点列表（从 protocol_settings.virtual_nodes JSON 字段）
     */
    public function getVirtualNodes(): array
    {
        $settings = $this->protocol_settings ?? [];
        return $settings["virtual_nodes"] ?? [];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, "parent_id", "id");
    }

    public function stats(): HasMany
    {
        return $this->hasMany(StatServer::class, "server_id", "id");
    }

    public function machine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, "machine_id");
    }

    public function groups()
    {
        return ServerGroup::whereIn("id", $this->group_ids ?? [])->get();
    }

    public function routes()
    {
        return ServerRoute::whereIn("id", $this->route_ids)->get();
    }

    /**
     * 最后检查时间访问器
     */
    protected function lastCheckAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(
                    CacheKey::get("SERVER_{$type}_LAST_CHECK_AT", $serverId),
                );
            },
        );
    }

    /**
     * 最后推送时间访问器
     */
    protected function lastPushAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(
                    CacheKey::get("SERVER_{$type}_LAST_PUSH_AT", $serverId),
                );
            },
        );
    }

    /**
     * 在线用户数访问器
     */
    protected function online(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(
                    CacheKey::get("SERVER_{$type}_ONLINE_USER", $serverId),
                ) ?? 0;
            },
        );
    }

    /**
     * 是否在线访问器
     */
    protected function isOnline(): Attribute
    {
        return Attribute::make(
            get: function () {
                return time() - 300 > $this->last_check_at ? 0 : 1;
            },
        );
    }

    /**
     * 缓存键访问器
     */
    protected function cacheKey(): Attribute
    {
        return Attribute::make(
            get: function () {
                return "{$this->type}-{$this->id}-{$this->updated_at}-{$this->is_online}";
            },
        );
    }

    /**
     * 服务器密钥访问器
     */
    protected function serverKey(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->type === self::TYPE_SHADOWSOCKS) {
                    return Helper::getServerKey($this->created_at, 16);
                }
                return null;
            },
        );
    }

    /**
     * 指标指标访问器
     */
    protected function metrics(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(
                    CacheKey::get("SERVER_{$type}_METRICS", $serverId),
                );
            },
        );
    }

    /**
     * 版本号访问器（从 metrics 缓存中读取）
     */
    protected function version(): Attribute
    {
        return Attribute::make(
            get: function () {
                $metrics = $this->metrics;
                return $metrics["version"] ?? null;
            },
        );
    }

    /**
     * 在线连接数访问器
     */
    protected function onlineConn(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->metrics["active_connections"] ?? 0;
            },
        );
    }

    /**
     * 负载状态访问器
     */
    protected function loadStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->parent_id ?: $this->id;
                return Cache::get(
                    CacheKey::get("SERVER_{$type}_LOAD_STATUS", $serverId),
                );
            },
        );
    }

    public function getCurrentRate(): float
    {
        if (!$this->rate_time_enable) {
            return (float) $this->rate;
        }

        $now = now()->format("H:i");
        $ranges = $this->rate_time_ranges ?? [];
        $matchedRange = collect($ranges)->first(
            fn($range) => $now >= $range["start"] && $now <= $range["end"],
        );

        return $matchedRange
            ? (float) $matchedRange["rate"]
            : (float) $this->rate;
    }
}

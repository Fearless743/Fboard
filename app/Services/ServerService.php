<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerRoute;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class ServerService
{

    /**
     * 获取所有服务器列表
     * @return Collection
     */
    public static function getAllServers(): Collection
    {
        $query = Server::orderBy('sort', 'ASC');

        return $query->get()->append([
            'last_check_at',
            'last_push_at',
            'online',
            'is_online',
            'available_status',
            'cache_key',
            'load_status',
            'metrics',
            'online_conn'
        ]);
    }

    /**
     * 获取机器下所有已启用节点
     */
    public static function getMachineNodes(ServerMachine $machine): Collection
    {
        return Server::where('machine_id', $machine->id)
            ->where('enabled', true)
            ->orderBy('sort', 'ASC')
            ->get();
    }

    /**
     * 获取指定用户可用的服务器列表
     * @param User $user
     * @return array
     */
    public static function getAvailableServers(User $user): array
    {
        $servers = Server::where(function ($query) use ($user) {
                $groupId = (string) $user->group_id;
                // 同时匹配字符串和整型两种存储形式，避免 JSON_CONTAINS 类型不匹配
                $query->whereJsonContains('group_ids', $groupId)
                      ->orWhereJsonContains('group_ids', (int) $groupId);
            })
            ->where('show', true)
            ->where(function ($query) {
                $query->whereNull('transfer_enable')
                    ->orWhere('transfer_enable', 0)
                    ->orWhereRaw('u + d < transfer_enable');
            })
            ->orderBy('sort', 'ASC')
            ->get()
            ->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'cache_key', 'server_key']);

        $servers = collect($servers)->map(function ($server) use ($user) {
            // 虚拟节点继承父节点配置（合并后会同步 appends，避免 is_online 等字段丢失）
            if ($server->type === 'virtual') {
                $server = $server->getEffectiveAttribute();
            }
            // 判断动态端口
            if (str_contains((string) $server->port, '-')) {
                $port = $server->port;
                $server->port = (int) Helper::randomPort($port);
                $server->ports = $port;
            } else {
                $server->port = (int) $server->port;
            }
            $server->password = $server->generateServerPassword($user);
            $server->rate = $server->getCurrentRate();

            // 订阅下发前将 REALITY 密钥规范为 RawURL Base64，避免客户端报 invalid REALITY public key
            $protocolSettings = $server->protocol_settings;
            if (is_array($protocolSettings) && !empty($protocolSettings['reality_settings']) && is_array($protocolSettings['reality_settings'])) {
                $protocolSettings['reality_settings'] = Helper::normalizeRealitySettings($protocolSettings['reality_settings']);
                $server->protocol_settings = $protocolSettings;
            }

            // 确保序列化字段完整（虚拟节点合并 / 部分路径可能未 append）
            $server->append([
                'last_check_at',
                'last_push_at',
                'online',
                'is_online',
                'available_status',
                'cache_key',
                'server_key',
            ]);

            return $server;
        })->toArray();

        return $servers;
    }

    /**
     * 根据权限组获取可用的用户列表。
     *
     * 物理节点只按自身 group_ids 下发；子/虚拟节点权限组必须是父节点子集
     * （见 Server::assertGroupIdsWithinParent），因此不会出现「能订阅不能连」。
     *
     * @return Collection
     */
    public static function getAvailableUsers(Server $node)
    {
        $groupIds = $node->group_ids ?? [];
        if (empty($groupIds)) {
            return collect();
        }
        $users = User::toBase()
            ->whereIn('group_id', $groupIds)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit'
            ])
            ->get();

        // 部分协议（如 Sudoku）要求节点用户列表的 uuid 字段为派生密钥而非原始 uuid
        $definition = app(ProtocolDefinitionRegistry::class)->get((string) ($node->type ?? ''));
        if ($definition?->transformNodeUserUuid) {
            $users = $users->map(function ($user) use ($node) {
                $userModel = new User();
                $userModel->forceFill(['uuid' => $user->uuid]);
                $user->uuid = $node->generateServerPassword($userModel);
                return $user;
            });
        }

        return HookManager::filter('server.users.get', $users, $node);
    }

    // 获取路由规则
    public static function getRoutes(array $routeIds)
    {
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])->whereIn('id', $routeIds)->get();
        return $routes;
    }

    /**
     * 处理节点流量数据汇报
     */
    public static function processTraffic(Server $node, array $traffic): void
    {
        $data = array_filter($traffic, fn($item) =>
            is_array($item) && count($item) === 2
            && is_numeric($item[0]) && is_numeric($item[1])
        );

        if (empty($data)) {
            return;
        }

        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        Cache::put(CacheKey::get("SERVER_{$nodeType}_ONLINE_USER", $nodeId), count($data), 3600);
        Cache::put(CacheKey::get("SERVER_{$nodeType}_LAST_PUSH_AT", $nodeId), time(), 3600);

        (new UserService())->trafficFetch($node, $node->type, $data);
    }

    /**
     * 处理节点在线设备汇报
     */
    public static function processAlive(int $nodeId, array $alive): void
    {
        $service = app(DeviceStateService::class);
        foreach ($alive as $uid => $ips) {
            $service->setDevices((int) $uid, $nodeId, (array) $ips);
        }
    }

    /**
     * 处理节点在线人数 / 连接数汇报
     *
     * $online 为 uid => 设备数（distinct IP）快照；键数量即当前在线用户数。
     * 空数组表示该节点当前无在线用户，需写 0 以清掉陈旧 ONLINE_USER。
     */
    public static function processOnline(Server $node, array $online): void
    {
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        $nodeType = $node->type;
        $nodeId = $node->id;
        $nodeTypeUpper = strtoupper($nodeType);

        // 管理端「在线人数」读 SERVER_*_ONLINE_USER；优先用真实在线快照，而非本周期有流量的用户数
        Cache::put(
            CacheKey::get("SERVER_{$nodeTypeUpper}_ONLINE_USER", $nodeId),
            count($online),
            $cacheTime
        );

        foreach ($online as $uid => $conn) {
            $cacheKey = CacheKey::get("USER_ONLINE_CONN_{$nodeType}_{$nodeId}", $uid);
            Cache::put($cacheKey, (int) $conn, $cacheTime);
        }
    }

    /**
     * 处理节点负载状态汇报
     */
    public static function processStatus(Server $node, array $status): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        $statusData = [
            'cpu' => (float) ($status['cpu'] ?? 0),
            'mem' => [
                'total' => (int) ($status['mem']['total'] ?? 0),
                'used' => (int) ($status['mem']['used'] ?? 0),
            ],
            'swap' => [
                'total' => (int) ($status['swap']['total'] ?? 0),
                'used' => (int) ($status['swap']['used'] ?? 0),
            ],
            'disk' => [
                'total' => (int) ($status['disk']['total'] ?? 0),
                'used' => (int) ($status['disk']['used'] ?? 0),
            ],
            'updated_at' => now()->timestamp,
            'kernel_status' => $status['kernel_status'] ?? null,
        ];

        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        cache([
            CacheKey::get("SERVER_{$nodeType}_LOAD_STATUS", $nodeId) => $statusData,
            CacheKey::get("SERVER_{$nodeType}_LAST_LOAD_AT", $nodeId) => now()->timestamp,
        ], $cacheTime);
    }

    /**
     * 标记节点心跳
     */
    public static function touchNode(Server $node): void
    {
        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id),
            time(),
            3600
        );
    }

    /**
     * Update node metrics and load status
     */
    public static function updateMetrics(Server $node, array $metrics): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);

        $metricsData = [
            'uptime' => (int) ($metrics['uptime'] ?? 0),
            'goroutines' => (int) ($metrics['goroutines'] ?? 0),
            'active_connections' => (int) ($metrics['active_connections'] ?? 0),
            'total_connections' => (int) ($metrics['total_connections'] ?? 0),
            'total_users' => (int) ($metrics['total_users'] ?? 0),
            'active_users' => (int) ($metrics['active_users'] ?? 0),
            'inbound_speed' => (int) ($metrics['inbound_speed'] ?? 0),
            'outbound_speed' => (int) ($metrics['outbound_speed'] ?? 0),
            'cpu_per_core' => $metrics['cpu_per_core'] ?? [],
            'load' => $metrics['load'] ?? [],
            'speed_limiter' => $metrics['speed_limiter'] ?? [],
            'gc' => $metrics['gc'] ?? [],
            'api' => $metrics['api'] ?? [],
            'ws' => $metrics['ws'] ?? [],
            'limits' => $metrics['limits'] ?? [],
            'updated_at' => now()->timestamp,
            'kernel_status' => (bool) ($metrics['kernel_status'] ?? false),
        ];

        Cache::put(
            CacheKey::get('SERVER_' . $nodeType . '_METRICS', $nodeId),
            $metricsData,
            $cacheTime
        );
    }

    /**
     * 构建节点守护进程配置。
     * 协议字段由 ProtocolDefinition.serverConfigBuilder（插件注册）生成；
     * 公共字段（路由/出站/证书）在此追加，最后经 protocols.server_config 钩子扩展。
     */
    public static function buildNodeConfig(Server $node): array
    {
        $nodeType = $node->type;
        $protocolSettings = $node->protocol_settings;

        $baseConfig = [
            'protocol' => $nodeType,
            'listen_ip' => '0.0.0.0',
            'server_port' => (int) $node->server_port,
            'network' => data_get($protocolSettings, 'network'),
            'networkSettings' => data_get($protocolSettings, 'network_settings') ?: null,
            'maintenance_mode' => (bool) admin_setting('maintenance_mode', 0),
        ];

        // 协议专属配置：由 CoreProtocols 等插件在 registerProtocolDefinition 时注册的 builder 生成
        $definition = app(ProtocolDefinitionRegistry::class)->get($nodeType);
        $response = $definition
            ? $definition->buildServerConfig($node, $baseConfig)
            : [];

        if (!empty($node['route_ids'])) {
            $response['routes'] = self::getRoutes($node['route_ids']);
        }

        if (!empty($node['custom_outbounds'])) {
            $response['custom_outbounds'] = $node['custom_outbounds'];
        }

        if (!empty($node['custom_routes'])) {
            $response['custom_routes'] = $node['custom_routes'];
        }

        if (!empty($node['cert_config'])) {
            $certConfig = $node['cert_config'];
            // Normalize: accept both "mode" and "cert_mode" from the database
            if (isset($certConfig['mode']) && !isset($certConfig['cert_mode'])) {
                $certConfig['cert_mode'] = $certConfig['mode'];
                unset($certConfig['mode']);
            }
            if (data_get($certConfig, 'cert_mode') !== 'none') {
                $response['cert_config'] = $certConfig;
            }
        }

        // 插件可在完整配置上追加/覆盖字段（初始值为已构建的配置，与文档一致）
        return HookManager::filter('protocols.server_config', $response, $node);
    }

    /**
     * 根据协议类型和标识获取服务器
     * @param int $serverId
     * @param string $serverType
     * @return Server|null
     */
    public static function getServer($serverId, ?string $serverType = null): Server | null
    {
        return Server::query()
            ->when($serverType, function ($query) use ($serverType) {
                $query->where('type', Server::normalizeType($serverType));
            })
            ->where(function ($query) use ($serverId) {
                $query->where('code', $serverId)
                    ->orWhere('id', $serverId);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$serverId])
            ->first();
    }

    /**
     * Resolve Hysteria2 listen port range for the node daemon.
     *
     * When the client-facing port is a range (e.g. "10000-20000"), the node must
     * listen on every port in that range so client UDP port hopping works.
     * Returns null for a single port (node keeps using server_port only).
     *
     * Caps range span at 1024 ports to avoid exhausting UDP sockets.
     */
    public static function hysteriaListenPorts(Server $node): ?string
    {
        $raw = trim((string) $node->port);
        if ($raw === '' || !str_contains($raw, '-')) {
            return null;
        }

        $parts = preg_split('/\s*-\s*/', $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $start = (int) $parts[0];
        $end = (int) $parts[1];
        if ($start < 1 || $start > 65535 || $end < 1 || $end > 65535 || $end < $start) {
            return null;
        }

        // Large ranges create one UDP socket per port; hard-cap for safety.
        if (($end - $start + 1) > 1024) {
            return null;
        }

        return $start . '-' . $end;
    }
}

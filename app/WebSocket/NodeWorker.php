<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class NodeWorker
{
    private const AUTH_TIMEOUT = 10;
    private const PING_INTERVAL = 55;
    /** Throttle DB write-back of machine heartbeat to avoid write amplification. */
    private const HEARTBEAT_DB_WRITE_MIN_INTERVAL = 60;

    public const HEARTBEAT_CACHE_KEY = 'ws_server:heartbeat';
    private const HEARTBEAT_INTERVAL = 10;
    private const HEARTBEAT_TTL = 30;

    private Worker $worker;

    private array $handlers = [
        'pong' => [NodeEventHandlers::class, 'handlePong'],
        'node.status' => [NodeEventHandlers::class, 'handleNodeStatus'],
        'machine.status' => [NodeEventHandlers::class, 'handleMachineStatus'],
        'report.devices' => [NodeEventHandlers::class, 'handleDeviceReport'],
        'request.devices' => [NodeEventHandlers::class, 'handleDeviceRequest'],
        'request.sync' => [NodeEventHandlers::class, 'handleSyncRequest'],
        'report.logs' => [NodeEventHandlers::class, 'handleReportLogs'],
        'op.ack' => [NodeEventHandlers::class, 'handleOpAck'],
    ];

    public function __construct(string $host, int $port)
    {
        $this->worker = new Worker("websocket://{$host}:{$port}");
        $this->worker->count = 1;
        $this->worker->name = 'fboard-ws-server';
    }

    public function run(): void
    {
        $this->setupLogging();
        $this->setupCallbacks();
        Worker::runAll();
    }

    private function setupLogging(): void
    {
        $logPath = storage_path('logs');
        if (!is_dir($logPath)) {
            mkdir($logPath, 0777, true);
        }
        Worker::$logFile = $logPath . '/fboard-ws-server.log';
        Worker::$pidFile = $logPath . '/fboard-ws-server.pid';
    }

    private function setupCallbacks(): void
    {
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    public function onWorkerStart(Worker $worker): void
    {
        WsLog::info("[WS] Worker started, pid={$worker->id}");
        $this->safely('redis subscribe', fn () => $this->subscribeRedis());
        $this->safely('timer setup', fn () => $this->setupTimers());
    }

    /**
     * Run a Workerman callback body without letting a transient dependency
     * failure (Redis down, DB hiccup, …) escape. Workerman treats an exception
     * thrown from a handshake/timer callback as fatal and calls stopAll(),
     * which took the whole ws-server down during a Redis outage.
     */
    private function safely(string $where, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            WsLog::warning("[WS] {$where} failed: {$e->getMessage()}");
        }
    }

    private function setupTimers(): void
    {
        $this->safely('heartbeat write', fn () => Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL));
        Timer::add(self::HEARTBEAT_INTERVAL, function () {
            $this->safely('heartbeat write', fn () => Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL));
        });

        Timer::add(self::PING_INTERVAL, function () {
            $this->safely('ping broadcast', function () {
                $seen = [];

                foreach (NodeRegistry::getConnectedNodeIds() as $nodeId) {
                    $conn = NodeRegistry::get($nodeId);
                    if ($conn) {
                        $oid = spl_object_id($conn);
                        if (!isset($seen[$oid])) {
                            $seen[$oid] = true;
                            $conn->send(json_encode(['event' => 'ping']));
                        }
                    }
                }

                foreach (NodeRegistry::getConnectedMachineIds() as $machineId) {
                    $conn = NodeRegistry::getMachine($machineId);
                    if ($conn) {
                        $oid = spl_object_id($conn);
                        if (!isset($seen[$oid])) {
                            $seen[$oid] = true;
                            $conn->send(json_encode(['event' => 'ping']));
                        }
                    }
                }
            });
        });

        Timer::add(10, function () {
            $this->safely('device push', fn () => $this->pushPendingDevices());
        });
    }

    /**
     * Fan out sync.devices to every pending node.
     *
     * The fleet snapshot is built once per tick and reused for all nodes:
     * building it per node was O(nodes × users) Redis round-trips and blocked
     * the single-threaded worker long enough to stall WS handshakes.
     */
    private function pushPendingDevices(): void
    {
        // HTTP alive path (Octane) cannot see NodeRegistry; it sets this flag.
        $pushAll = (bool) Redis::get('device:push_all');
        if ($pushAll) {
            Redis::del('device:push_all');
            $pendingNodeIds = NodeRegistry::getConnectedNodeIds();
        } else {
            $pendingNodeIds = Redis::spop('device:push_pending_nodes', 100);
        }
        if (empty($pendingNodeIds)) {
            return;
        }

        // Deduplicate and always fan-out whatever is pending.
        $pendingNodeIds = array_values(array_unique(array_map('intval', (array) $pendingNodeIds)));

        $service = app(DeviceStateService::class);
        $devices = null;
        foreach ($pendingNodeIds as $nodeId) {
            if (NodeRegistry::get($nodeId) === null) {
                continue;
            }
            if ($devices === null) {
                $devices = $service->getActiveDevices();
            }
            NodeEventHandlers::pushDeviceStateToNode($nodeId, $service, $devices);
        }
    }

    public function onConnect(TcpConnection $conn): void
    {
        $conn->authTimer = Timer::add(self::AUTH_TIMEOUT, function () use ($conn) {
            if (empty($conn->nodeId) && empty($conn->machineNodeIds)) {
                $conn->close(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'auth timeout'],
                ]));
            }
        }, [], false);
    }

    public function onWebSocketConnect(TcpConnection $conn, $httpMessage): void
    {
        try {
            $queryString = '';
            if (is_string($httpMessage)) {
                $queryString = parse_url($httpMessage, PHP_URL_QUERY) ?? '';
            } elseif ($httpMessage instanceof \Workerman\Protocols\Http\Request) {
                $queryString = $httpMessage->queryString();
            }

            parse_str($queryString, $params);

            if (isset($conn->authTimer)) {
                Timer::del($conn->authTimer);
            }

            // 判断认证模式
            if (!empty($params['machine_id'])) {
                $this->authenticateMachine($conn, $params);
            } else {
                $this->authenticateNode($conn, $params);
            }
        } catch (\Throwable $e) {
            // Never let an auth-path dependency failure (Redis/DB) bubble into
            // Workerman: it would stopAll() the entire WS server.
            WsLog::warning("[WS] handshake auth failed: {$e->getMessage()}");
            $this->safely('close failed handshake', function () use ($conn) {
                $conn->close(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'internal error'],
                ]));
            });
        }
    }

    /**
     * 旧模式：单节点认证
     */
    private function authenticateNode(TcpConnection $conn, array $params): void
    {
        $token = $params['token'] ?? '';
        $nodeId = (int) ($params['node_id'] ?? 0);

        $serverToken = admin_setting('server_token', '');
        if ($token === '' || $serverToken === '' || !hash_equals($serverToken, $token)) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid token'],
            ]));
            return;
        }

        $node = ServerService::getServer($nodeId, null);
        if (!$node) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'node not found'],
            ]));
            return;
        }

        $conn->nodeId = $nodeId;
        NodeRegistry::add($nodeId, $conn);
        Cache::put("node_ws_alive:{$nodeId}", true, 86400);

        app(DeviceStateService::class)->clearAllNodeDevices($nodeId);

        WsLog::debug("[WS] Node#{$nodeId} connected", [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => ['node_id' => $nodeId],
        ]));

        NodeEventHandlers::pushFullSync($conn, $node);
    }

    /**
     * 新模式：机器认证，自动注册该机器下所有已启用节点
     */
    private function authenticateMachine(TcpConnection $conn, array $params): void
    {
        $machineId = (int) ($params['machine_id'] ?? 0);
        $token = $params['token'] ?? '';

        $machine = ServerMachine::where('id', $machineId)
            ->where('token', $token)
            ->first();

        if (!$machine || !$machine->is_active) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid machine credentials'],
            ]));
            return;
        }

        $nodes = ServerService::getMachineNodes($machine);

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();
        // Keep the WS-alive cache in sync with DB so isOnline() can fall back to it.
        Cache::put("node_ws_alive:{$machineId}", true, 86400);
        NodeRegistry::addMachine($machineId, $conn);

        // 把同一个连接注册到该机器下所有节点
        $nodeIds = [];
        $deviceService = app(DeviceStateService::class);
        foreach ($nodes as $node) {
            NodeRegistry::add($node->id, $conn);
            Cache::put("node_ws_alive:{$node->id}", true, 86400);
            $deviceService->clearAllNodeDevices($node->id);
            $nodeIds[] = $node->id;
        }

        // 连接上记录所属机器和节点列表
        $conn->machineId = $machineId;
        $conn->machineNodeIds = $nodeIds;

        WsLog::debug("[WS] Machine#{$machineId} connected, nodes: " . implode(',', $nodeIds), [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
            'machines' => NodeRegistry::machineCount(),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => [
                'machine_id' => $machineId,
                'node_ids' => $nodeIds,
                'nodes' => $nodes->map(fn($node) => [
                    'id' => (int) $node->id,
                    'type' => (string) $node->type,
                    'name' => (string) $node->name,
                ])->values()->all(),
                'base_config' => [
                    'push_interval' => (int) admin_setting('server_push_interval', 60),
                ],
            ],
        ]));

        // 为每个节点推送完整同步
        foreach ($nodes as $node) {
            NodeEventHandlers::pushFullSync($conn, $node);
        }
    }

    public function onMessage(TcpConnection $conn, $data): void
    {
        try {
            $this->dispatchMessage($conn, $data);
        } catch (\Throwable $e) {
            // A Redis/DB failure while handling one message must not kill the
            // worker (and every panel instance's WS) — log and drop the message.
            WsLog::warning("[WS] message handler failed: {$e->getMessage()}");
        }
    }

    private function dispatchMessage(TcpConnection $conn, $data): void
    {
        $msg = json_decode($data, true);
        if (!is_array($msg)) {
            return;
        }

        $event = $msg['event'] ?? '';

        // 机器连接：从消息中读取 node_id 来分派到具体节点
        if (!empty($conn->machineNodeIds) || !empty($conn->machineId)) {
            if ($event === 'pong') {
                $machineId = (int) ($conn->machineId ?? 0);
                foreach (($conn->machineNodeIds ?? []) as $nid) {
                    Cache::put("node_ws_alive:{$nid}", true, 86400);
                }
                // Reflect liveness back to DB so MachineController::getOperableMachine
                // and ServerMachine::isOnline() see the machine as online without
                // relying on the Redis fallback. Throttle to one write per minute.
                //
                // last_seen_at is unsignedInteger (epoch seconds); updated_at is a
                // datetime — DO NOT write a unix int into updated_at, MySQL will
                // raise SQLSTATE[22007] and roll back the entire UPDATE.
                if ($machineId > 0) {
                    $cacheKey = "machine_heartbeat_db_write:{$machineId}";
                    if (!Cache::has($cacheKey)) {
                        Cache::put($cacheKey, time(), self::HEARTBEAT_DB_WRITE_MIN_INTERVAL);
                        try {
                            DB::table('v2_server_machine')
                                ->where('id', $machineId)
                                ->update(['last_seen_at' => time()]);
                        } catch (\Throwable $e) {
                            WsLog::warning("[WS] heartbeat db write failed: {$e->getMessage()}", [
                                'machine_id' => $machineId,
                            ]);
                        }
                    }
                }
                return;
            }

            // Machine-level events (no node_id), e.g. report.logs
            if ($event === 'report.logs' || $event === 'op.ack' || $event === 'machine.status') {
                $handler = $this->handlers[$event] ?? null;
                if ($handler) {
                    $handler($conn, 0, $msg['data'] ?? []);
                }
                return;
            }

            $nodeId = (int) ($msg['data']['node_id'] ?? 0);
            if ($nodeId <= 0 || !in_array($nodeId, $conn->machineNodeIds ?? [], true)) {
                return;
            }
            if (isset($this->handlers[$event])) {
                $handler = $this->handlers[$event];
                $handler($conn, $nodeId, $msg['data'] ?? []);
            }
            return;
        }

        // 旧模式：单节点
        $nodeId = $conn->nodeId ?? null;
        if (isset($this->handlers[$event]) && $nodeId) {
            $handler = $this->handlers[$event];
            $handler($conn, $nodeId, $msg['data'] ?? []);
        }
    }

    public function onClose(TcpConnection $conn): void
    {
        try {
            $this->cleanupConnection($conn);
        } catch (\Throwable $e) {
            WsLog::warning("[WS] close cleanup failed: {$e->getMessage()}");
        }
    }

    private function cleanupConnection(TcpConnection $conn): void
    {
        $service = app(DeviceStateService::class);

        // 机器模式：清理所有关联节点
        if (!empty($conn->machineNodeIds)) {
            $machineId = $conn->machineId ?? 'unknown';
            foreach ($conn->machineNodeIds as $nodeId) {
                NodeRegistry::remove($nodeId, $conn);
                Cache::forget("node_ws_alive:{$nodeId}");

                $affectedUserIds = $service->clearAllNodeDevices($nodeId);
                $service->notifyUpdates($affectedUserIds);
            }

            if (!empty($conn->machineId)) {
                NodeRegistry::removeMachine((int) $conn->machineId, $conn);
            }

            // Remaining nodes must drop this machine's device occupancy.
            NodeEventHandlers::markAllOnlineNodesForDevicePush();

            WsLog::debug("[WS] Machine#{$machineId} disconnected", [
                'nodes' => $conn->machineNodeIds,
                'total' => NodeRegistry::count(),
                'machines' => NodeRegistry::machineCount(),
            ]);
            return;
        }

        // 旧模式：单节点
        if (!empty($conn->nodeId)) {
            $nodeId = $conn->nodeId;
            NodeRegistry::remove($nodeId, $conn);
            Cache::forget("node_ws_alive:{$nodeId}");

            $affectedUserIds = $service->clearAllNodeDevices($nodeId);
            $service->notifyUpdates($affectedUserIds);

            NodeEventHandlers::markAllOnlineNodesForDevicePush();

            WsLog::debug("[WS] Node#{$nodeId} disconnected", [
                'total' => NodeRegistry::count(),
                'affected_users' => count($affectedUserIds),
            ]);
        }
    }

    private function subscribeRedis(): void
    {
        $host = config('database.redis.default.host', '127.0.0.1');
        $port = config('database.redis.default.port', 6379);

        if (str_starts_with($host, '/')) {
            $redisUri = "unix://{$host}";
        } else {
            $redisUri = "redis://{$host}:{$port}";
        }

        $redis = new \Workerman\Redis\Client($redisUri);

        $password = config('database.redis.default.password');
        if ($password) {
            $redis->auth($password);
        }

        $prefix = config('database.redis.options.prefix', '');
        $channel = $prefix . 'node:push';

        $redis->subscribe([$channel], function ($chan, $message) {
            $payload = json_decode($message, true);
            if (!is_array($payload)) {
                return;
            }

            $event = $payload['event'] ?? '';
            $data = $payload['data'] ?? [];

            // Machine-level events (e.g., sync.nodes)
            $machineId = $payload['machine_id'] ?? null;
            if ($machineId && $event) {
                // Update server-side registry when node membership changes
                if ($event === 'sync.nodes') {
                    $nodeIds = array_map('intval', array_column($data['nodes'] ?? [], 'id'));
                    NodeRegistry::refreshMachineNodes((int) $machineId, $nodeIds);
                }

                $sent = NodeRegistry::sendMachine((int) $machineId, $event, $data);
                if ($sent) {
                    WsLog::debug("[WS] Pushed {$event} to machine#{$machineId}");
                }
                return;
            }

            // Per-node events
            $nodeId = $payload['node_id'] ?? null;
            if (!$nodeId || !$event) {
                return;
            }

            $sent = NodeRegistry::send((int) $nodeId, $event, $data);
            if ($sent) {
                WsLog::debug("[WS] Pushed {$event} to node#{$nodeId}");
            }
        });

        WsLog::info("[WS] Subscribed to Redis channel: {$channel}");
    }
}

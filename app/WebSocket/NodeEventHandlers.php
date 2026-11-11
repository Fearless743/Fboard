<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;

class NodeEventHandlers
{
    /**
     * Handle pong heartbeat
     */
    public static function handlePong(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        Cache::put("node_ws_alive:{$nodeId}", true, 86400);
    }

    /**
     * Handle node status update (WS-only report channel).
     *
     * Payload (sent by fboard-node as `node.status`):
     *   {
     *     node_id: int,
     *     traffic: {uid: [up, down]},
     *     alive:   {uid: [ips]},
     *     online:  {uid: connCount},
     *     status:  {cpu, mem:{total,used}, swap:{...}, disk:{...}},
     *     metrics: {...}
     *   }
     * All keys except node_id are optional; missing sections are skipped.
     * For backward compat, a flat metrics map without the `metrics` wrapper
     * is still accepted as metrics.
     */
    public static function handleNodeStatus(TcpConnection $conn, int $nodeId, array $data): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $nodeType = strtoupper($node->type);
        Cache::put(CacheKey::get('SERVER_' . $nodeType . '_LAST_CHECK_AT', $nodeId), time(), 3600);
        ServerService::touchNode($node);

        $traffic = $data['traffic'] ?? null;
        if (is_array($traffic) && !empty($traffic)) {
            ServerService::processTraffic($node, $traffic);
        }

        $alive = $data['alive'] ?? null;
        if (is_array($alive) && !empty($alive)) {
            ServerService::processAlive($nodeId, $alive);
        }

        $online = $data['online'] ?? null;
        if (is_array($online)) {
            ServerService::processOnline($node, $online);
        }

        $status = $data['status'] ?? null;
        if (is_array($status) && !empty($status)) {
            ServerService::processStatus($node, $status);
        }

        $metrics = $data['metrics'] ?? null;
        if (is_array($metrics) && !empty($metrics)) {
            ServerService::updateMetrics($node, $metrics);
        } elseif (!isset($data['traffic'], $data['alive'], $data['online'], $data['status'], $data['metrics'])) {
            // Legacy flat payload: whole data is the metrics map.
            ServerService::updateMetrics($node, $data);
        }

        WsLog::debug("[WS] Node#{$nodeId} status updated");
    }

    /**
     * Handle machine status update (WS-only, replaces POST /api/v2/server/machine/status).
     *
     * Payload: {cpu, mem:{total,used}, swap, disk, net:{in_speed,out_speed}, version, kernel}
     */
    public static function handleMachineStatus(TcpConnection $conn, int $nodeId, array $data): void
    {
        $machineId = (int) ($conn->machineId ?? 0);
        if ($machineId <= 0) {
            return;
        }

        $machine = ServerMachine::find($machineId);
        if (!$machine || !$machine->is_active) {
            return;
        }

        if (!isset($data['cpu']) || !is_numeric($data['cpu'])) {
            return;
        }
        $mem = $data['mem'] ?? null;
        if (!is_array($mem)) {
            return;
        }

        $recordedAt = time();
        $loadStatus = [
            'cpu' => (float) $data['cpu'],
            'mem' => [
                'total' => (int) ($mem['total'] ?? 0),
                'used' => (int) ($mem['used'] ?? 0),
            ],
            'swap' => [
                'total' => (int) ($data['swap']['total'] ?? 0),
                'used' => (int) ($data['swap']['used'] ?? 0),
            ],
            'disk' => [
                'total' => (int) ($data['disk']['total'] ?? 0),
                'used' => (int) ($data['disk']['used'] ?? 0),
            ],
            'updated_at' => $recordedAt,
        ];

        $net = $data['net'] ?? null;
        if (is_array($net) && isset($net['in_speed'], $net['out_speed'])) {
            $loadStatus['net'] = [
                'in_speed' => (float) $net['in_speed'],
                'out_speed' => (float) $net['out_speed'],
            ];
        }

        $version = trim((string) ($data['version'] ?? ''));
        if ($version !== '') {
            $loadStatus['version'] = substr($version, 0, 64);
        }

        $kernel = $data['kernel'] ?? null;
        if (is_array($kernel)) {
            $status = (string) ($kernel['status'] ?? '');
            if (in_array($status, ['idle', 'running', 'stopped', 'partial'], true)) {
                $loadStatus['kernel'] = [
                    'status' => $status,
                    'nodes_total' => max(0, (int) ($kernel['nodes_total'] ?? 0)),
                    'nodes_running' => max(0, (int) ($kernel['nodes_running'] ?? 0)),
                    'nodes_desired' => max(0, (int) ($kernel['nodes_desired'] ?? 0)),
                ];
            }
        }

        $machine->forceFill([
            'load_status' => $loadStatus,
            'last_seen_at' => $recordedAt,
        ])->save();

        $historyData = [
            'machine_id' => $machine->id,
            'cpu' => (float) $data['cpu'],
            'mem_total' => (int) ($mem['total'] ?? 0),
            'mem_used' => (int) ($mem['used'] ?? 0),
            'disk_total' => (int) ($data['disk']['total'] ?? 0),
            'disk_used' => (int) ($data['disk']['used'] ?? 0),
            'recorded_at' => $recordedAt,
        ];
        if (is_array($net) && isset($net['in_speed'], $net['out_speed'])) {
            $historyData['net_in_speed'] = (float) $net['in_speed'];
            $historyData['net_out_speed'] = (float) $net['out_speed'];
        }

        ServerMachineLoadHistory::create($historyData);

        if (random_int(1, 20) === 1) {
            ServerMachineLoadHistory::query()
                ->where('machine_id', $machine->id)
                ->where('recorded_at', '<', now()->subDay()->timestamp)
                ->delete();
        }

        WsLog::debug("[WS] Machine#{$machineId} status updated");
    }

    /**
     * Handle device report from node
     * 
     * 数据格式: {"event": "report.devices", "data": {userId: [ip1, ip2, ...], ...}}
     */
    public static function handleDeviceReport(TcpConnection $conn, int $nodeId, array $data): void
    {
        $service = app(DeviceStateService::class);

        if (isset($data['devices']) && is_array($data['devices'])) {
            $data = $data['devices'];
        }

        // Get old data
        $oldDevices = $service->getNodeDevices($nodeId);

        // Calculate diff
        $removedUsers = array_diff_key($oldDevices, $data);
        $newDevices = [];

        foreach ($data as $userId => $ips) {
            if (is_numeric($userId) && is_array($ips)) {
                $newDevices[(int) $userId] = $ips;
            }
        }

        // Handle removed users
        foreach ($removedUsers as $userId => $ips) {
            $service->removeNodeDevices($nodeId, $userId);
            $service->notifyUpdate($userId);
        }

        // Handle new/updated users
        foreach ($newDevices as $userId => $ips) {
            $service->setDevices($userId, $nodeId, $ips);
        }

        // Fan-out to every online node: multi-node device limits only work when
        // peers receive the fleet-wide snapshot, not just the reporter.
        self::markAllOnlineNodesForDevicePush();

        WsLog::debug("[WS] Node#{$nodeId} synced " . count($newDevices) . " users, removed " . count($removedUsers));
    }

    /**
     * Queue sync.devices for all currently connected nodes.
     *
     * When called inside the Workerman process, connected node IDs are known
     * via NodeRegistry. When called from Octane (HTTP /alive|/report), the
     * registry is empty — fall back to a Redis flag that the WS timer expands
     * into a full fan-out on the next tick.
     */
    public static function markAllOnlineNodesForDevicePush(): void
    {
        $nodeIds = NodeRegistry::getConnectedNodeIds();
        if (!empty($nodeIds)) {
            Redis::sadd('device:push_pending_nodes', ...$nodeIds);
            return;
        }

        // Cross-process signal for the NodeWorker timer.
        Redis::setex('device:push_all', 60, '1');
    }

    /**
     * Handle device state request from node
     */
    public static function handleDeviceRequest(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();

        $service = app(DeviceStateService::class);
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices,
        ]);

        WsLog::debug("[WS] Node#{$nodeId} requested devices, sent " . count($devices) . " users");
    }

    /**
     * Push device state to node
     */
    public static function pushDeviceStateToNode(int $nodeId, DeviceStateService $service): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices
        ]);

        WsLog::debug("[WS] Pushed device state to node#{$nodeId}: " . count($devices) . " users");
    }

    /**
     * Handle explicit full-sync request from a node (WS-only re-pull).
     * Covers nodes added via sync.nodes (panel only auto-pushes on connect)
     * and races where the connect-time push arrived before the node was ready.
     */
    public static function handleSyncRequest(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $target = NodeRegistry::get($nodeId) ?? $conn;
        self::pushFullSync($target, $node);

        WsLog::debug("[WS] Node#{$nodeId} requested full sync");
    }

    /**
     * Push full config + users to newly connected node
     */
    public static function pushFullSync(TcpConnection $conn, Server $node): void
    {
        $nodeId = (int) $node->id;

        // Push config
        $config = ServerService::buildNodeConfig($node);
        NodeRegistry::send($nodeId, 'sync.config', [
            'config' => $config,
        ]);

        // Push users
        $users = ServerService::getAvailableUsers($node)->toArray();
        NodeRegistry::send($nodeId, 'sync.users', [
            'users' => $users,
        ]);

        WsLog::info("[WS] Full sync pushed to node#{$nodeId}", [
            'users' => count($users),
        ]);
    }

    /**
     * Handle machine log report (reply to sync.logs).
     * Payload: { lines: string[], req_id?: string }
     */
    public static function handleReportLogs(TcpConnection $conn, int $nodeId, array $data): void
    {
        $machineId = (int) ($conn->machineId ?? 0);
        if ($machineId <= 0) {
            WsLog::debug('[WS] report.logs ignored: no machineId on connection');
            return;
        }

        $lines = $data['lines'] ?? [];
        if (!is_array($lines)) {
            $lines = [];
        }
        // Keep only string lines; cap payload size
        $clean = [];
        foreach ($lines as $line) {
            if (is_string($line)) {
                $clean[] = $line;
            }
            if (count($clean) >= 1000) {
                break;
            }
        }

        $payload = [
            'lines' => $clean,
            'req_id' => is_string($data['req_id'] ?? null) ? $data['req_id'] : null,
            'updated_at' => time(),
            'count' => count($clean),
        ];

        Cache::put("machine_logs:{$machineId}", $payload, 120);

        $reqId = $payload['req_id'];
        if (is_string($reqId) && $reqId !== '') {
            Cache::put("machine_logs_req:{$machineId}:{$reqId}", $payload, 60);
        }

        WsLog::debug("[WS] Machine#{$machineId} reported " . count($clean) . " log lines");
    }

    /**
     * Handle remote-ops ack from the node (reply to sync.upgrade / sync.kernel / legacy restart).
     *
     * Payload shape (set by Fboard-Node panel.WSClient.SendOpAck):
     *   {
     *     op:     "upgrade" | "restart" | "kernel.stop|start|reload|restart",
     *     status: "ok" | "failed",
     *     detail: string,   // short reason on failed; empty on ok
     *     ts:     int,
     *   }
     *
     * Logged loudly; the most recent ack per machine/op is cached so the
     * admin UI can fetch it for status.
     */
    public static function handleOpAck(TcpConnection $conn, int $nodeId, array $data): void
    {
        $machineId = (int) ($conn->machineId ?? 0);
        if ($machineId <= 0) {
            WsLog::debug('[WS] op.ack ignored: no machineId on connection');
            return;
        }

        $op = strtolower((string) ($data['op'] ?? ''));
        $status = strtolower((string) ($data['status'] ?? ''));
        $detail = (string) ($data['detail'] ?? '');
        $allowedOps = [
            'upgrade',
            'restart', // legacy process-restart ack
            'kernel.stop',
            'kernel.start',
            'kernel.reload',
            'kernel.restart',
        ];
        if (!in_array($op, $allowedOps, true)) {
            WsLog::debug('[WS] op.ack ignored: unknown op', ['op' => $op]);
            return;
        }
        if (!in_array($status, ['ok', 'failed'], true)) {
            WsLog::debug('[WS] op.ack ignored: unknown status', ['status' => $status]);
            return;
        }

        $payload = [
            'op' => $op,
            'status' => $status,
            'detail' => $detail,
            'ts' => (int) ($data['ts'] ?? time()),
            'node_id' => $nodeId,
        ];
        Cache::put("op_ack:{$machineId}:{$op}", $payload, 86400);

        if ($status === 'ok') {
            WsLog::info("[WS] op.ack ok machine=#{$machineId} op={$op}", $payload);
        } else {
            WsLog::warning("[WS] op.ack failed machine=#{$machineId} op={$op} detail={$detail}", $payload);
        }
    }
}

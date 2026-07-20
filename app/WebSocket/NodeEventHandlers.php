<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
     * Handle node status update
     */
    public static function handleNodeStatus(TcpConnection $conn, int $nodeId, array $data): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $nodeType = strtoupper($node->type);
        Cache::put(\App\Utils\CacheKey::get('SERVER_' . $nodeType . '_LAST_CHECK_AT', $nodeId), time(), 3600);
        ServerService::updateMetrics($node, $data);

        Log::debug("[WS] Node#{$nodeId} status updated");
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

        // Mark for push
        Redis::sadd('device:push_pending_nodes', $nodeId);

        Log::debug("[WS] Node#{$nodeId} synced " . count($newDevices) . " users, removed " . count($removedUsers));
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

        Log::debug("[WS] Node#{$nodeId} requested devices, sent " . count($devices) . " users");
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

        Log::debug("[WS] Pushed device state to node#{$nodeId}: " . count($devices) . " users");
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

        Log::info("[WS] Full sync pushed to node#{$nodeId}", [
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
            Log::debug('[WS] report.logs ignored: no machineId on connection');
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

        Log::debug("[WS] Machine#{$machineId} reported " . count($clean) . " log lines");
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
            Log::debug('[WS] op.ack ignored: no machineId on connection');
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
            Log::debug('[WS] op.ack ignored: unknown op', ['op' => $op]);
            return;
        }
        if (!in_array($status, ['ok', 'failed'], true)) {
            Log::debug('[WS] op.ack ignored: unknown status', ['status' => $status]);
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
            Log::info("[WS] op.ack ok machine=#{$machineId} op={$op}", $payload);
        } else {
            Log::warning("[WS] op.ack failed machine=#{$machineId} op={$op} detail={$detail}", $payload);
        }
    }
}

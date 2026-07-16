<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodeSyncService
{
    /**
     * Check if node has active WS connection
     */
    public static function isNodeOnline(int $nodeId): bool
    {
        return (bool) Cache::get("node_ws_alive:{$nodeId}");
    }

    /**
     * 获取维护期间可安全下发的用户快照。
     */
    private static function getUsersForSync(Server $server): array
    {
        if ((bool) admin_setting('maintenance_mode', 0)) {
            return [];
        }

        return ServerService::getAvailableUsers($server)->toArray();
    }

    /**
     * Push node config update
     */
    public static function notifyConfigUpdated(int $nodeId): void
    {
        if (!self::isNodeOnline($nodeId))
            return;

        $node = Server::find($nodeId);
        if (!$node)
            return;

        self::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);
    }

    /**
     * Push all users to all nodes in the group
     */
    public static function notifyUsersUpdatedByGroup(int $groupId): void
    {
        $servers = Server::whereJsonContains('group_ids', (string) $groupId)
            ->get();

        foreach ($servers as $server) {
            if (!self::isNodeOnline($server->id))
                continue;

            $users = self::getUsersForSync($server);
            self::push($server->id, 'sync.users', ['users' => $users]);
        }
    }

    /**
     * Push user changes (add/remove) to affected nodes
     */
    public static function notifyUserChanged(User $user): void
    {
        if (!$user->group_id)
            return;

        $servers = Server::whereJsonContains('group_ids', (string) $user->group_id)->get();
        foreach ($servers as $server) {
            if (!self::isNodeOnline($server->id))
                continue;

            if ((bool) admin_setting('maintenance_mode', 0)) {
                self::push($server->id, 'sync.users', ['users' => []]);
                continue;
            }

            if ($user->isAvailable()) {
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'add',
                    'users' => [
                        [
                            'id' => $user->id,
                            'uuid' => $user->uuid,
                            'speed_limit' => $user->speed_limit,
                            'device_limit' => $user->device_limit,
                        ]
                    ],
                ]);
            } else {
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'remove',
                    'users' => [['id' => $user->id]],
                ]);
            }
        }
    }

    /**
     * Push user removal from a specific group's nodes
     */
    public static function notifyUserRemovedFromGroup(int $userId, int $groupId): void
    {
        $servers = Server::whereJsonContains('group_ids', (string) $groupId)
            ->get();

        foreach ($servers as $server) {
            if (!self::isNodeOnline($server->id))
                continue;

            self::push($server->id, 'sync.user.delta', [
                'action' => 'remove',
                'users' => [['id' => $userId]],
            ]);
        }
    }

    /**
     * Full sync: push config + users to a node
     */
    public static function notifyFullSync(int $nodeId): void
    {
        if (!self::isNodeOnline($nodeId))
            return;

        $node = Server::find($nodeId);
        if (!$node)
            return;

        self::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);

        $users = self::getUsersForSync($node);
        self::push($nodeId, 'sync.users', ['users' => $users]);
    }

    /**
     * 向所有启用节点下发维护状态和兼容的用户快照。
     */
    public static function notifyMaintenanceModeChanged(): void
    {
        try {
            Server::query()
                ->where('enabled', true)
                ->pluck('id')
                ->each(fn($nodeId) => self::notifyFullSync((int) $nodeId));
        } catch (\Throwable $e) {
            Log::warning('[NodePush] maintenance mode sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Notify machine that its node set has changed.
     * Always publishes via Redis so the WS process can update its in-memory registry.
     */
    public static function notifyMachineNodesChanged(int $machineId): void
    {
        $machine = ServerMachine::find($machineId);

        $nodeList = [];
        if ($machine) {
            $nodes = ServerService::getMachineNodes($machine);
            $nodeList = $nodes->map(fn($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'name' => $n->name,
            ])->values()->toArray();
        }

        // Always publish via Redis so the WS process can update its in-memory registry
        self::pushMachine($machineId, 'sync.nodes', ['nodes' => $nodeList]);
    }

    /**
     * Push upgrade command to a machine.
     */
    public static function notifyMachineUpgrade(int $machineId, string $version = 'latest'): void
    {
        self::pushMachine($machineId, 'sync.upgrade', ['version' => $version]);
    }

    /**
     * Push restart command to a machine.
     */
    public static function notifyMachineRestart(int $machineId): void
    {
        self::pushMachine($machineId, 'sync.restart', []);
    }

    /**
     * Push upgrade command to a node.
     *
     * This is retained for standalone nodes and legacy callers. Machine-bound
     * nodes are routed through the machine-scoped job before reaching here.
     */
    public static function notifyNodeUpgrade(int $nodeId, string $version = 'latest'): void
    {
        self::push($nodeId, 'sync.upgrade', ['version' => $version]);
    }

    /**
     * Push restart command to a node or its machine.
     */
    public static function notifyNodeRestart(Server $server): void
    {
        if ($server->machine_id !== null) {
            self::notifyMachineRestart($server->machine_id);
            return;
        }

        self::push($server->id, 'sync.restart', ['requested_node_id' => $server->id]);
    }


    /**
     * Request recent process logs from a machine.
     */
    public static function notifyMachineLogs(int $machineId, int $limit = 500, ?string $reqId = null): void
    {
        $data = ['limit' => $limit];
        if ($reqId !== null && $reqId !== '') {
            $data['req_id'] = $reqId;
        }
        self::pushMachine($machineId, 'sync.logs', $data);
    }

    /**
     * Publish a push command to Redis — picked up by the Workerman WS server
     */
    public static function push(int $nodeId, string $event, array $data): void
    {
        try {
            Redis::publish('node:push', json_encode([
                'node_id' => $nodeId,
                'event' => $event,
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis publish failed: {$e->getMessage()}", [
                'node_id' => $nodeId,
                'event' => $event,
            ]);
        }
    }

    /**
     * Publish a machine-level push command to Redis — picked up by the Workerman WS server
     */
    public static function pushMachine(int $machineId, string $event, array $data): void
    {
        try {
            Redis::publish('node:push', json_encode([
                'machine_id' => $machineId,
                'event' => $event,
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis machine publish failed: {$e->getMessage()}", [
                'machine_id' => $machineId,
                'event' => $event,
            ]);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded';
    protected $description = '检查流量超标用户并通知节点';

    public function handle()
    {
        $count = Redis::scard('traffic:pending_check');
        if ($count <= 0) {
            return;
        }

        $pendingUserIds = array_map('intval', Redis::spop('traffic:pending_check', $count));

        $exceededUsers = User::toBase()
            ->whereIn('id', $pendingUserIds)
            ->whereRaw('u + d >= transfer_enable')
            ->where('transfer_enable', '>', 0)
            ->where('banned', 0)
            ->select(['id', 'group_id'])
            ->get();

        if ($exceededUsers->isEmpty()) {
            return;
        }

        $notifiedCount = 0;

        if (\App\Services\UserPlanService::multiPlanEnabled()) {
            // 多套餐：用户可能属于多个权限组，需按其所有活跃实例的组集合逐组踢出
            $groupToUsers = [];
            foreach ($exceededUsers as $exceeded) {
                $groupIds = \App\Services\UserPlanService::getActiveGroupIds($exceeded->id);
                if (empty($groupIds) && $exceeded->group_id) {
                    $groupIds = [$exceeded->group_id];
                }
                foreach ($groupIds as $gid) {
                    $groupToUsers[$gid][] = $exceeded->id;
                }
            }

            foreach ($groupToUsers as $groupId => $userIds) {
                $servers = Server::whereJsonContains('group_ids', (string) $groupId)->get();
                foreach ($servers as $server) {
                    if (!NodeSyncService::isNodeOnline($server->id)) {
                        continue;
                    }
                    NodeSyncService::push($server->id, 'sync.user.delta', [
                        'action' => 'remove',
                        'users' => array_map(fn($id) => ['id' => $id], array_unique($userIds)),
                    ]);
                    $notifiedCount++;
                }
            }

            $this->info("Checked " . count($pendingUserIds) . " users, notified {$notifiedCount} nodes for " . $exceededUsers->count() . " exceeded users.");
            return;
        }

        $groupedUsers = $exceededUsers->groupBy('group_id');

        foreach ($groupedUsers as $groupId => $users) {
            if (!$groupId) {
                continue;
            }

            $userIdsInGroup = $users->pluck('id')->toArray();
            $servers = Server::whereJsonContains('group_ids', (string) $groupId)->get();

            foreach ($servers as $server) {
                if (!NodeSyncService::isNodeOnline($server->id)) {
                    continue;
                }

                NodeSyncService::push($server->id, 'sync.user.delta', [
                    'action' => 'remove',
                    'users' => array_map(fn($id) => ['id' => $id], $userIdsInGroup),
                ]);
                $notifiedCount++;
            }
        }

        $this->info("Checked " . count($pendingUserIds) . " users, notified {$notifiedCount} nodes for " . $exceededUsers->count() . " exceeded users.");
    }
}

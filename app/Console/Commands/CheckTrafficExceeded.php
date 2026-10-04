<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\User;
use App\Models\UserPlan;
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

        $pendingUserIds = array_map('intval', $this->pendingMembers());

        if (UserPlan::isEnabled()) {
            $this->handleMulti($pendingUserIds);
            return;
        }

        $exceededUsers = User::toBase()
            ->whereIn('id', $pendingUserIds)
            ->whereRaw('u + d >= transfer_enable')
            ->where('transfer_enable', '>', 0)
            ->where('banned', 0)
            ->select(['id', 'group_id'])
            ->get();

        // 处理成功才移除。原实现用 spop（不可逆）：只要出现「节点当时离线」
        // 或「group_ids 匹配不到」，这些用户就被永久踢出检查队列；他们不再
        // 产生流量后也不会被 TrafficFetchJob 重新入队，超额用户就永远留在节点上。
        // 已确定无事可做的（未超额）先标记完成。
        $done = array_values(array_diff(
            $pendingUserIds,
            $exceededUsers->pluck('id')->map(fn ($id) => (int) $id)->all()
        ));

        if ($exceededUsers->isEmpty()) {
            $this->forgetPending($done);
            return;
        }

        $groupedUsers = $exceededUsers->groupBy('group_id');
        $notifiedCount = 0;

        try {
            foreach ($groupedUsers as $groupId => $users) {
                $userIdsInGroup = $users->pluck('id')->map(fn ($id) => (int) $id)->all();

                if (!$groupId) {
                    $done = array_merge($done, $userIdsInGroup);
                    continue;
                }

                // 与 ServerService::getAllServers / ServerGroup::servers 对齐：
                // group_ids 的 setter 只保证新写入的是字符串数组，历史整型数据
                // 只查字符串形态会一条都匹配不到，导致超额摘除静默失效。
                $servers = Server::where(function ($query) use ($groupId) {
                    $query->whereJsonContains('group_ids', (string) $groupId)
                        ->orWhereJsonContains('group_ids', (int) $groupId);
                })->get();

                $delivered = 0;
                foreach ($servers as $server) {
                    if (!NodeSyncService::isNodeOnline($server->id)) {
                        continue;
                    }

                    NodeSyncService::push($server->id, 'sync.user.delta', [
                        'action' => 'remove',
                        'users' => array_map(fn($id) => ['id' => $id], $userIdsInGroup),
                    ]);
                    $delivered++;
                    $notifiedCount++;
                }

                // 有可用节点但此刻全部离线 → 保留在队列里下轮重试；
                // 没有任何节点则属于配置问题，重试无意义，直接移除避免队列无限增长。
                if ($delivered > 0 || $servers->isEmpty()) {
                    $done = array_merge($done, $userIdsInGroup);
                }
            }
        } finally {
            // 已完成的部分无论如何都要出队，否则异常会拖慢后续所有轮次。
            $this->forgetPending(array_values(array_unique($done)));
        }

        $this->info("Checked " . count($pendingUserIds) . " users, notified {$notifiedCount} nodes for " . $exceededUsers->count() . " exceeded users.");
    }

    /**
     * 多套餐超额检查：判定走实例聚合子查询（SUM(u+d) >= SUM(transfer_enable)），
     * 推送目标为用户有效分组并集覆盖的在线节点，按组批量 push。
     * 可靠性语义与单套餐一致：有节点但全离线则留队列下轮重试。
     */
    private function handleMulti(array $pendingUserIds): void
    {
        $now = time();
        $exhaustedIds = User::query()->whereIn('id', $pendingUserIds)
            ->wherePlanExhausted($now)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // 已确定无事可做的（未超额）先标记完成。
        $done = array_values(array_diff($pendingUserIds, $exhaustedIds));

        if (empty($exhaustedIds)) {
            $this->forgetPending($done);
            return;
        }

        // 批量取超额用户的有效分组成员，一次查完。
        $instanceQuery = UserPlan::query()->whereIn('user_id', $exhaustedIds);
        UserPlan::applyActive($instanceQuery, $now);
        $byUser = $instanceQuery->get(['user_id', 'group_id'])->groupBy('user_id');

        // 用户 → 有效分组并集；分组 → 用户列表（按组批量 push）。
        $userGroups = [];
        $groupUsers = [];
        foreach ($exhaustedIds as $userId) {
            $gids = $byUser->get($userId, collect())
                ->pluck('group_id')->map(fn ($gid) => (int) $gid)
                ->unique()->values()->all();
            $userGroups[$userId] = $gids;
            foreach ($gids as $gid) {
                $groupUsers[$gid][] = $userId;
            }
        }

        $groupServers = [];
        $deliveredServers = [];
        $notifiedCount = 0;

        try {
            foreach ($groupUsers as $groupId => $userIdsInGroup) {
                $userIdsInGroup = array_map('intval', $userIdsInGroup);
                $servers = Server::where(function ($query) use ($groupId) {
                    $query->whereJsonContains('group_ids', (string) $groupId)
                        ->orWhereJsonContains('group_ids', (int) $groupId);
                })->get();
                $groupServers[$groupId] = $servers->pluck('id')->map(fn ($id) => (int) $id)->all();

                foreach ($servers as $server) {
                    if (!NodeSyncService::isNodeOnline($server->id)) {
                        continue;
                    }

                    NodeSyncService::push($server->id, 'sync.user.delta', [
                        'action' => 'remove',
                        'users' => array_map(fn ($id) => ['id' => $id], $userIdsInGroup),
                    ]);
                    $deliveredServers[(int) $server->id] = true;
                    $notifiedCount++;
                }
            }

            foreach ($exhaustedIds as $userId) {
                $covering = [];
                foreach ($userGroups[$userId] as $gid) {
                    $covering = array_merge($covering, $groupServers[$gid] ?? []);
                }
                // 无覆盖节点（配置问题）直接移除；至少一个节点送达即完成；
                // 有节点但全离线则留队列下轮重试。
                if (empty($covering) || !empty(array_intersect(array_keys($deliveredServers), $covering))) {
                    $done[] = $userId;
                }
            }
        } finally {
            // 已完成的部分无论如何都要出队，否则异常会拖慢后续所有轮次。
            $this->forgetPending(array_values(array_unique(array_map('intval', $done))));
        }

        $this->info('Checked ' . count($pendingUserIds) . ' users, notified ' . $notifiedCount . ' nodes for ' . count($exhaustedIds) . ' exceeded users.');
    }

    /**
     * 读取待检查用户快照（不消费）。
     *
     * @return array<int>
     */
    private function pendingMembers(): array
    {
        return array_map('intval', (array) Redis::smembers('traffic:pending_check'));
    }

    /**
     * 把已处理完的用户从待检查集合中移除。
     *
     * @param  array<int>  $userIds
     */
    private function forgetPending(array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }
        foreach (array_chunk($userIds, 500) as $batch) {
            Redis::srem('traffic:pending_check', ...$batch);
        }
    }
}

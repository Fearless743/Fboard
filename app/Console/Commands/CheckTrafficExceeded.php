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

        // 实例表是唯一数据源：无条件走实例聚合判定。
        $this->handleMulti($pendingUserIds);
        return;
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

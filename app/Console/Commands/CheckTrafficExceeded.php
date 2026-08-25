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

        $pendingUserIds = array_map('intval', Redis::spop('traffic:pending_check', $count));

        // 支持多套餐：累计流量超标也视为超限
        $exceededUsers = User::toBase()
            ->whereIn('id', $pendingUserIds)
            ->where(function ($query) {
                $query->whereRaw('u + d >= transfer_enable')
                      ->orWhereRaw('(SELECT COALESCE(SUM(p.transfer_enable * 1073741824), 0)
                                     FROM v2_user_plan up
                                     JOIN v2_plan p ON p.id = up.plan_id
                                     WHERE up.user_id = v2_user.id
                                       AND (up.expired_at IS NULL OR up.expired_at > UNIX_TIMESTAMP())) >= u + d');
            })
            ->where(function ($query) {
                $query->where('transfer_enable', '>', 0)
                      ->orWhereExists(function ($q) {
                          $q->from('v2_user_plan')
                            ->join('v2_plan', 'v2_plan.id', '=', 'v2_user_plan.plan_id')
                            ->whereColumn('v2_user_plan.user_id', 'v2_user.id')
                            ->where(function ($sub) {
                                $sub->whereNull('v2_user_plan.expired_at')
                                    ->orWhere('v2_user_plan.expired_at', '>', time());
                            })
                            ->selectRaw(1)
                            ->limit(1);
                      });
            })
            ->where('banned', 0)
            ->select(['id', 'group_id'])
            ->get();

        if ($exceededUsers->isEmpty()) {
            return;
        }

        $groupedUsers = $exceededUsers->groupBy('group_id');
        $notifiedCount = 0;

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

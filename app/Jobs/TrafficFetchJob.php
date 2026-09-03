<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserPlan;
use App\Services\UserPlanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    public $tries = 1;
    public $timeout = 20;

    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
    }

    public function handle(): void
    {
        $userIds = array_keys($this->data);

        $rate = (float) ($this->server['rate'] ?? 1);
        foreach ($this->data as $uid => $v) {
            // 流量列是整数字节；倍率可能是 1.5 等 float，必须 round 后再写入，
            // 避免 SQLite/MySQL 严格模式下 float 写入 INTEGER 失败或截断不一致。
            $uInc = (int) max(0, (int) round(((float) $v[0]) * $rate));
            $dInc = (int) max(0, (int) round(((float) $v[1]) * $rate));
            if ($uInc === 0 && $dInc === 0) {
                continue;
            }
            User::where('id', $uid)
                ->incrementEach(
                    [
                        'u' => $uInc,
                        'd' => $dInc,
                    ],
                    ['t' => time()]
                );

            // 多套餐模式：把本次增量按"先到期先扣"分摊到各套餐实例
            if (UserPlanService::multiPlanEnabled()) {
                $this->allocateToUserPlans((int) $uid, $uInc, $dInc);
            }
        }

        if (!empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }
    }

    /**
     * 把流量增量按"节点归属优先 + 先到期先扣"分摊到用户的活跃套餐实例。
     *
     * 上报流量来自具体节点，扣减顺序：
     *  1. 仅覆盖该节点权限组的实例（套餐独占节点）——组内按 expired_at 升序（NULL 排最后）、id 升序；
     *  2. 其余实例（与该节点无专属关系）按同样的先到期先扣顺序。
     * 逐实例用条件增量（u+d<transfer_enable）吸收，防并发上报超扣；
     * 若该节点无法归属任何实例，退化为全局先到期先扣（记入排序最末实例，允许超额）。
     */
    private function allocateToUserPlans(int $userId, int $uInc, int $dInc): void
    {
        $instances = UserPlanService::getActiveInstances($userId);
        if ($instances->isEmpty()) {
            return;
        }

        $nodeGroupIds = $this->getNodeGroupIds();
        if ($nodeGroupIds !== null) {
            // 独占实例：其 group_id 覆盖该节点、且其他活跃实例不覆盖该节点
            $groupCoverage = $instances
                ->filter(fn($i) => $i->group_id !== null)
                ->groupBy('group_id')
                ->map->count();
            $exclusive = $instances->filter(
                fn($i) => $i->group_id !== null
                    && in_array((int) $i->group_id, $nodeGroupIds, true)
                    && ($groupCoverage[$i->group_id] ?? 0) === 1
                    && $groupCoverage->keys()->intersect($nodeGroupIds)->count() === 1
            )->values();
            $rest = $instances->reject(fn($i) => $exclusive->contains(fn($e) => $e->id === $i->id))->values();
            if ($exclusive->isNotEmpty()) {
                $instances = $exclusive->merge($rest)->values();
            }
            // 若无独占实例：保持全局先到期先扣
        }

        $remainU = $uInc;
        $remainD = $dInc;
        $lastId = $instances->last()->id;

        foreach ($instances as $inst) {
            if ($remainU === 0 && $remainD === 0) {
                break;
            }
            $capacity = max(0, (int) $inst->transfer_enable - ((int) $inst->u + (int) $inst->d));
            $isLast = $inst->id === $lastId;

            // 最后一个实例吸收全部剩余（允许超额），否则只吸收到容量上限
            if ($isLast) {
                $takeU = $remainU;
                $takeD = $remainD;
            } else {
                // 按 u/d 比例在容量内分配，简化处理：先满足 u，再满足 d
                $takeU = min($remainU, $capacity);
                $takeD = min($remainD, max(0, $capacity - $takeU));
            }

            if ($takeU === 0 && $takeD === 0) {
                continue;
            }

            $query = UserPlan::where('id', $inst->id);
            if (!$isLast) {
                $query->whereRaw('u + d < transfer_enable');
            }
            $query->incrementEach(['u' => $takeU, 'd' => $takeD], ['updated_at' => time()]);

            $remainU -= $takeU;
            $remainD -= $takeD;
        }
    }

    /**
     * 上报流量的节点权限组集合；节点信息缺失时返回 null（退化为全局先到期先扣）。
     *
     * @return array<int>|null
     */
    private function getNodeGroupIds(): ?array
    {
        $groupIds = $this->server['group_ids'] ?? null;
        if (!is_array($groupIds) || $groupIds === []) {
            return null;
        }
        return array_map('intval', $groupIds);
    }
}

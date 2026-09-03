<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlan;
use Illuminate\Support\Facades\DB;

/**
 * 用户套餐实例服务（多套餐支持）
 *
 * v2_user_plan 为套餐数据的唯一权威数据源；v2_user 的套餐字段
 * （plan_id/group_id/transfer_enable/u/d/expired_at/next_reset_at 等）
 * 降级为物化派生缓存，由本类的 syncUserAggregate() 作为唯一写入点维护，
 * 以兼容订阅协议头、用户前台主题、邮件、导出等未改造的读取方。
 */
class UserPlanService
{
    /**
     * 多套餐特性开关（admin config subscribe 段 multi_plan_enable，默认关闭）。
     * 关闭时系统保持单套餐旧行为，所有读取回落 v2_user 旧列。
     */
    public static function multiPlanEnabled(): bool
    {
        return (bool) admin_setting('multi_plan_enable', 0);
    }

    /**
     * 将用户所有活跃套餐实例聚合，刷新 v2_user 缓存列。
     *
     * 聚合规则：
     *  - transfer_enable/u/d = 各活跃实例之和
     *  - expired_at = 最晚到期（任一活跃实例为一次性流量包 NULL 则整体 NULL=长期）
     *  - plan_id/group_id = 主套餐（活跃实例中 expired_at 最晚，并列取 id 较大者）
     *  - next_reset_at = 各活跃实例最早的重置时间
     *
     * 无任何实例时将缓存列清空为"无套餐"状态，保持 ClearUser 等旧逻辑兼容。
     */
    public static function syncUserAggregate(int $userId): void
    {
        $now = time();

        $active = UserPlan::where('user_id', $userId)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($q) use ($now) {
                $q->where('expired_at', '>=', $now)->orWhereNull('expired_at');
            })
            ->get();

        if ($active->isEmpty()) {
            // 无活跃实例：若用户从未有过任何实例，保持现状；否则清空缓存为无套餐状态
            $hasAny = UserPlan::where('user_id', $userId)->exists();
            if ($hasAny) {
                User::where('id', $userId)->update([
                    'plan_id' => null,
                    'group_id' => null,
                    'transfer_enable' => 0,
                    'u' => 0,
                    'd' => 0,
                    'expired_at' => null,
                    'next_reset_at' => null,
                    'updated_at' => $now,
                ]);
            }
            return;
        }

        $transferEnable = 0;
        $u = 0;
        $d = 0;
        $hasOnetime = false;   // 是否有一次性流量包（expired_at 为 NULL）
        $maxExpired = null;    // 最晚到期（不含一次性包）
        $minNextReset = null;
        /** @var UserPlan|null $primary 主套餐 */
        $primary = null;

        foreach ($active as $inst) {
            $transferEnable += $inst->transfer_enable;
            $u += $inst->u;
            $d += $inst->d;

            if ($inst->expired_at === null) {
                $hasOnetime = true;
            } else {
                $maxExpired = $maxExpired === null ? $inst->expired_at : max($maxExpired, $inst->expired_at);
            }

            if ($inst->next_reset_at !== null) {
                $minNextReset = $minNextReset === null ? $inst->next_reset_at : min($minNextReset, $inst->next_reset_at);
            }

            // 主套餐：一次性包（NULL expired）视为最晚，优先作为展示主套餐；
            // 否则取 expired_at 较大者，并列取 id 较大者
            if ($primary === null) {
                $primary = $inst;
            } else {
                $curKey = $inst->expired_at === null ? PHP_INT_MAX : $inst->expired_at;
                $priKey = $primary->expired_at === null ? PHP_INT_MAX : $primary->expired_at;
                if ($curKey > $priKey || ($curKey === $priKey && $inst->id > $primary->id)) {
                    $primary = $inst;
                }
            }
        }

        User::where('id', $userId)->update([
            'plan_id' => $primary?->plan_id,
            'group_id' => $primary?->group_id,
            'transfer_enable' => $transferEnable,
            'u' => $u,
            'd' => $d,
            'expired_at' => $hasOnetime ? null : $maxExpired,
            'next_reset_at' => $minNextReset,
            'updated_at' => $now,
        ]);
    }

    /**
     * 获取用户当前活跃的套餐实例集合（按到期时间升序，一次性包排最后）。
     *
     * @return \Illuminate\Support\Collection<int, UserPlan>
     */
    public static function getActiveInstances(int $userId)
    {
        $now = time();
        return UserPlan::where('user_id', $userId)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($q) use ($now) {
                $q->where('expired_at', '>=', $now)->orWhereNull('expired_at');
            })
            ->orderByRaw('expired_at IS NULL')   // NULL（一次性包）排最后
            ->orderBy('expired_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * 获取用户所有活跃实例的权限组 ID 集合（用于节点可见性并集）。
     *
     * @return array<int>
     */
    public static function getActiveGroupIds(int $userId): array
    {
        return self::getActiveInstances($userId)
            ->pluck('group_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 取或创建用户某套餐的"当前实例"行（同一 user_id+plan_id 只保留一条）。
     */
    public static function findInstance(int $userId, int $planId): ?UserPlan
    {
        return UserPlan::where('user_id', $userId)
            ->where('plan_id', $planId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 管理后台编辑用户套餐：以提交的 plans[] 为目标状态做 diff 同步。
     *
     * $plans 形如 [{plan_id: int, expired_at: int|null}, ...]：
     *  - 已有同 plan 实例 → 更新 expired_at
     *  - 提交中不存在的实例 → 删除（撤销该套餐）
     *  - 新 plan_id → 新建实例（配额取套餐定义，u/d=0，source=admin）
     *
     * @param array<int, array<string, mixed>> $plans
     */
    public static function syncFromAdmin(User $user, array $plans): void
    {
        DB::transaction(function () use ($user, $plans) {
            $targetPlanIds = [];
            foreach ($plans as $item) {
                $planId = (int) ($item['plan_id'] ?? 0);
                if ($planId <= 0) {
                    continue;
                }
                $targetPlanIds[] = $planId;
                $expiredAt = $item['expired_at'] ?? null;
                $expiredAt = $expiredAt ? (int) $expiredAt : null;

                $plan = Plan::find($planId);
                if (!$plan) {
                    continue;
                }

                $instance = self::findInstance($user->id, $planId);
                if ($instance) {
                    $instance->expired_at = $expiredAt;
                    $instance->group_id = $plan->group_id;
                    $instance->source = $instance->source ?: UserPlan::SOURCE_ADMIN;
                    $instance->save();
                } else {
                    UserPlan::create([
                        'user_id' => $user->id,
                        'plan_id' => $planId,
                        'group_id' => $plan->group_id,
                        'transfer_enable' => (int) $plan->transfer_enable * 1073741824,
                        'u' => 0,
                        'd' => 0,
                        'expired_at' => $expiredAt,
                        'source' => UserPlan::SOURCE_ADMIN,
                    ]);
                }
            }

            // 删除未在提交列表中的实例
            UserPlan::where('user_id', $user->id)
                ->when(!empty($targetPlanIds), fn($q) => $q->whereNotIn('plan_id', $targetPlanIds))
                ->delete();

            self::syncUserAggregate($user->id);
        });
    }
}

<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\UserPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 单套餐主表 → v2_user_plan 实例表，一次性迁移。
 * - orderBy(id) 游标 chunk，可重跑可中断；
 * - 单套餐用户建 cycle 行（kind=1，order_ids=[]，9 列值照抄含 u/d 全额与 group 快照）；
 * - 脏数据：plan_id null 跳过；plan 已删建行但限速/设备置空+日志；零配额跳过；
 * - 同 (user, plan) 已有 cycle 行跳过；
 * - 数据库 migration 与测试共用此实现，禁各写各的。
 *
 * 注意：必须用 DB::table('v2_user') 读「原始列」，不能用 Eloquent —— User 模型对
 * 这 9 列定义了 accessor（返回实例聚合），在迁移时刻实例表尚空，会读成 null。
 */
class UserPlanMigrator
{
    /**
     * @return array{scanned:int,created:int,skipped_no_plan:int,skipped_zero_quota:int,skipped_existing:int,missing_plan:int}
     */
    public static function run(?callable $progress = null): array
    {
        $stats = [
            'scanned' => 0,
            'created' => 0,
            'skipped_no_plan' => 0,
            'skipped_zero_quota' => 0,
            'skipped_existing' => 0,
            'missing_plan' => 0,
        ];

        $lastId = 0;
        do {
            $users = DB::table('v2_user')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(500)
                ->get([
                    'id', 'plan_id', 'group_id', 'transfer_enable', 'u', 'd',
                    'expired_at', 'speed_limit', 'device_limit', 'next_reset_at',
                ]);
            if ($users->isEmpty()) {
                break;
            }

            foreach ($users as $user) {
                $lastId = (int) $user->id;
                $stats['scanned']++;
                self::migrateUser($user, $stats);
                if ($progress) {
                    $progress($stats);
                }
            }
        } while (true);

        return $stats;
    }

    /**
     * @param object $user 原始行（DB::table 结果，绕过 accessor）
     * @param array{scanned:int,created:int,skipped_no_plan:int,skipped_zero_quota:int,skipped_existing:int,missing_plan:int} $stats
     */
    public static function migrateUser(object $user, array &$stats): void
    {
        if ($user->plan_id === null) {
            $stats['skipped_no_plan']++;
            return;
        }
        if ((int) $user->transfer_enable <= 0) {
            $stats['skipped_zero_quota']++;
            return;
        }
        $exists = UserPlan::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $user->plan_id)
            ->where('kind', UserPlan::KIND_CYCLE)
            ->exists();
        if ($exists) {
            $stats['skipped_existing']++;
            return;
        }

        $plan = Plan::query()->find($user->plan_id);
        if (!$plan) {
            $stats['missing_plan']++;
            Log::warning('[migrate-user-plans] plan 已删除，仍按主表快照建行', [
                'user_id' => $user->id, 'plan_id' => $user->plan_id,
            ]);
        }

        self::createRow($user);
        $stats['created']++;
    }

    /**
     * 建 cycle 行：9 列值忠实照抄主表快照（group_id/speed_limit/device_limit 是
     * 购买时写入的快照，plan 之后可能改过，故不取 plan 当前值），保证迁移零漂移、对账零差异。
     */
    public static function createRow(object $user): void
    {
        // 主表 expired_at 0/null 归一为永久（与历史迁移口径一致）。
        $expiredAt = !empty($user->expired_at) ? (int) $user->expired_at : null;
        $quota = (int) $user->transfer_enable;
        $used = (int) $user->u + (int) $user->d;

        $row = new UserPlan();
        $row->forceFill([
            'user_id' => $user->id,
            'plan_id' => $user->plan_id,
            'kind' => UserPlan::KIND_CYCLE,
            'group_id' => $user->group_id,
            'order_ids' => [],
            'transfer_enable' => $quota,
            'u' => (int) $user->u,
            'd' => (int) $user->d,
            'expired_at' => $expiredAt,
            // 迁移时已耗尽直接打点，时钟从此刻起算。
            'exhausted_at' => $used >= $quota ? time() : null,
            'next_reset_at' => $user->next_reset_at !== null ? (int) $user->next_reset_at : null,
            'speed_limit' => $user->speed_limit !== null ? (int) $user->speed_limit : null,
            'device_limit' => $user->device_limit !== null ? (int) $user->device_limit : null,
            'sort_order' => 0,
        ]);
        $row->save();
    }
}

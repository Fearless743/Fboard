<?php

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 将存量 v2_user 的单套餐数据迁移到 v2_user_plan 中间表。
 *
 * 幂等可重跑：已存在同 (user_id, plan_id) 实例则跳过。
 * v2_user 旧列全程保留不删，回滚零风险（仅需 drop v2_user_plan）。
 * 本迁移只写新表，是否启用多套餐由 admin_setting('multi_plan_enable') 控制。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_user_plan')) {
            return;
        }

        User::where(function ($q) {
            $q->whereNotNull('plan_id')->orWhere('transfer_enable', '>', 0);
        })
            ->select(['id', 'plan_id', 'group_id', 'transfer_enable', 'u', 'd', 'expired_at', 'next_reset_at', 'last_reset_at', 'reset_count'])
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    if (empty($user->plan_id)) {
                        continue;
                    }

                    // 幂等：已有同 user_id+plan_id 实例则跳过
                    $exists = UserPlan::where('user_id', $user->id)
                        ->where('plan_id', $user->plan_id)
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $plan = Plan::find($user->plan_id);
                    $groupId = $plan?->group_id ?? $user->group_id;

                    // 关联该用户最近一笔已完成且同套餐的订单
                    $orderId = Order::where('user_id', $user->id)
                        ->where('plan_id', $user->plan_id)
                        ->where('status', Order::STATUS_COMPLETED)
                        ->orderByDesc('id')
                        ->value('id');

                    UserPlan::create([
                        'user_id' => $user->id,
                        'plan_id' => $user->plan_id,
                        'order_id' => $orderId,
                        'group_id' => $groupId,
                        'transfer_enable' => (int) ($user->transfer_enable ?? 0),
                        'u' => (int) ($user->u ?? 0),
                        'd' => (int) ($user->d ?? 0),
                        // expired_at=0 的历史脏数据按"长期有效"归一为 NULL
                        'expired_at' => empty($user->expired_at) ? null : (int) $user->expired_at,
                        'next_reset_at' => $user->next_reset_at,
                        'last_reset_at' => $user->last_reset_at,
                        'reset_count' => (int) ($user->reset_count ?? 0),
                        'source' => UserPlan::SOURCE_MIGRATE,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // 数据迁移非破坏性：v2_user 旧列未动，回滚由
        // 2026_09_03_000001_create_v2_user_plan_table 的 down() 负责删表。
    }
};

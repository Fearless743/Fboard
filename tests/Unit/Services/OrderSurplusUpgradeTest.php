<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 换购折抵不得把「全部历史已完成周期订单金额」累加后按剩余比例抵扣，
 * 否则多次续费后升级可 0 元甚至 surplus_credit 到账余额。
 */
class OrderSurplusUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_surplus_not_based_on_sum_of_all_historical_orders(): void
    {
        admin_setting([
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'change_order_event_id' => 0,
        ]);

        $group = new ServerGroup();
        $group->forceFill(['name' => 'g', 'created_at' => time(), 'updated_at' => time()]);
        $group->save();

        // 旧套餐月付 100 元；新套餐月付 100 元（同价升级应近似 0 残值或小额，绝非按历史双份 200 计）
        $oldPlan = $this->makePlan($group->id, 'old', 100);
        $newPlan = $this->makePlan($group->id, 'new', 100);

        $user = new User();
        $user->forceFill([
            'email' => 'up-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'plan_id' => $oldPlan->id,
            'group_id' => $group->id,
            'transfer_enable' => 100 * 1073741824,
            'u' => 0,
            'd' => 0,
            'balance' => 0,
            // 刚买完约还剩 1 个月
            'expired_at' => time() + 30 * 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        // 两次已完成月付（历史 200 元）——错误实现会按 200 * 剩余比例折抵
        $createdAt = time() - 30 * 86400;
        foreach ([1, 2] as $i) {
            $o = new Order();
            $o->forceFill([
                'user_id' => $user->id,
                'plan_id' => $oldPlan->id,
                'period' => Plan::PERIOD_MONTHLY,
                'trade_no' => 'HIST-' . $i . '-' . Helper::guid(),
                'total_amount' => 10000,
                'balance_amount' => 0,
                'surplus_amount' => 0,
                'surplus_credit' => 0,
                'type' => $i === 1 ? Order::TYPE_NEW_PURCHASE : Order::TYPE_RENEWAL,
                'status' => Order::STATUS_COMPLETED,
                'created_at' => $createdAt + ($i - 1) * 86400,
                'updated_at' => $createdAt + ($i - 1) * 86400,
            ]);
            $o->save();
        }

        $upgrade = OrderService::createFromRequest($user, $newPlan, Plan::PERIOD_MONTHLY);

        $this->assertSame(Order::TYPE_UPGRADE, (int) $upgrade->type);
        // 折抵不应超过「当前周期单笔实付」量级（100 元）；按历史 200 全加会明显更大甚至使 total=0 且 surplus_credit>0
        $this->assertLessThanOrEqual(
            10000,
            (int) $upgrade->surplus_amount,
            'surplus_amount 不应超过单期实付规模（禁止按历史订单金额之和折抵）; got=' . $upgrade->surplus_amount
        );
        $this->assertSame(
            0,
            (int) ($upgrade->surplus_credit ?? 0),
            '同价升级不应产生 surplus_credit 余额倒贴; credit=' . ($upgrade->surplus_credit ?? 0)
        );
    }

    private function makePlan(int $groupId, string $name, int $priceYuan): Plan
    {
        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $groupId,
            'transfer_enable' => 100,
            'name' => $name,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => $priceYuan],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();
        return $plan;
    }
}

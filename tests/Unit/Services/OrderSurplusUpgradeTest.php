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
 * 升级折抵已移除：换套餐直接按新购下单（TYPE_NEW_PURCHASE），
 * 不再计算 surplus_amount/surplus_credit。
 */
class OrderSurplusUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_is_treated_as_new_purchase_without_surplus(): void
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

        // 旧套餐月付 100 元；新套餐月付 100 元
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

        $order = OrderService::createFromRequest($user, $newPlan, Plan::PERIOD_MONTHLY);

        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);
        $this->assertSame(0, (int) ($order->surplus_amount ?? 0));
        $this->assertSame(0, (int) ($order->surplus_credit ?? 0));
        // 全价，无折抵
        $this->assertSame(10000, (int) $order->total_amount);
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

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
 * 一次性订阅升级折抵（getSurplusValue 一次性分支）：
 * surplus = 实付 / 套餐流量 × 未用流量，必须截断到实付总额，
 * 否则同价/更贵的升级订单会把 surplus 溢出成 surplus_credit 余额。
 */
class OrderOnetimeSurplusTest extends TestCase
{
    use RefreshDatabase;

    public function test_onetime_upgrade_surplus_capped_at_paid_amount(): void
    {
        admin_setting([
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'change_order_event_id' => 0,
        ]);

        $group = $this->makeGroup('g-onetime');
        // 旧一次性套餐：100 元 / 50GB
        $oneTimePlan = $this->makePlan($group->id, 'one', [Plan::PERIOD_ONETIME => 100], 50);
        // 新月付套餐：150 元 / 100GB（更贵）
        $newPlan = $this->makePlan($group->id, 'new', [Plan::PERIOD_MONTHLY => 150], 100);

        $user = new User();
        $user->forceFill([
            'email' => 'ot-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'plan_id' => $oneTimePlan->id,
            'group_id' => $group->id,
            'transfer_enable' => 50 * 1073741824,
            // 基本没用：u+d = 1GB，未用 49GB
            'u' => 1 * 1073741824,
            'd' => 0,
            'balance' => 0,
            // 一次性订阅：expired_at 为 NULL
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        // 已完成的一次性订单：实付 100 元（10000 分），已折抵后 total 可能小于面值
        $order = new Order();
        $order->forceFill([
            'user_id' => $user->id,
            'plan_id' => $oneTimePlan->id,
            'period' => Plan::PERIOD_ONETIME,
            'trade_no' => 'OT-' . Helper::guid(),
            'total_amount' => 10000,
            'balance_amount' => 0,
            'surplus_amount' => 0,
            'surplus_credit' => 0,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => time() - 86400,
            'updated_at' => time() - 86400,
        ]);
        $order->save();

        $upgrade = OrderService::createFromRequest($user, $newPlan, Plan::PERIOD_MONTHLY);

        $this->assertSame(Order::TYPE_UPGRADE, (int) $upgrade->type);
        // 理论 surplus = 10000 / 50GB × 49GB = 9800 < 实付 10000，不被截断
        $this->assertSame(
            9800,
            (int) $upgrade->surplus_amount,
            'surplus 应为 实付/流量 × 未用流量; got=' . $upgrade->surplus_amount
        );

        // 更贵套餐：折抵后仍须补差价，不允许溢出成余额
        $this->assertLessThan(
            15000,
            (int) $upgrade->surplus_amount,
            '更贵升级不应让 surplus 超过本单原价产生 credit; total=' . $upgrade->total_amount
        );
        $this->assertSame(
            0,
            (int) ($upgrade->surplus_credit ?? 0),
            '同价/更贵升级不应产生 surplus_credit 余额倒贴; credit=' . ($upgrade->surplus_credit ?? 0)
        );
        // total = 15000 - 9800 = 5200
        $this->assertSame(5200, (int) $upgrade->total_amount);
    }

    public function test_onetime_upgrade_surplus_never_exceeds_paid_amount(): void
    {
        admin_setting([
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'change_order_event_id' => 0,
        ]);

        $group = $this->makeGroup('g-onetime2');
        // 旧一次性套餐：100 元 / 5GB（流量单价极高：20 元/GB）
        $oneTimePlan = $this->makePlan($group->id, 'one', [Plan::PERIOD_ONETIME => 100], 5);
        $newPlan = $this->makePlan($group->id, 'new', [Plan::PERIOD_MONTHLY => 100], 100);

        $user = new User();
        $user->forceFill([
            'email' => 'ot2-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'plan_id' => $oneTimePlan->id,
            'group_id' => $group->id,
            // 当前流量池远大于原套餐（升级残留 / 多包叠加），未用 45GB
            'transfer_enable' => 50 * 1073741824,
            'u' => 5 * 1073741824,
            'd' => 0,
            'balance' => 0,
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $order = new Order();
        $order->forceFill([
            'user_id' => $user->id,
            'plan_id' => $oneTimePlan->id,
            'period' => Plan::PERIOD_ONETIME,
            'trade_no' => 'OT-' . Helper::guid(),
            'total_amount' => 10000,
            'balance_amount' => 0,
            'surplus_amount' => 0,
            'surplus_credit' => 0,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => time() - 86400,
            'updated_at' => time() - 86400,
        ]);
        $order->save();

        $upgrade = OrderService::createFromRequest($user, $newPlan, Plan::PERIOD_MONTHLY);

        // 未截断时：20 元/GB × 45GB = 90000 > 实付 10000，会倒贴 80000 到余额
        // 截断后：surplus = 实付 10000 与 90000 中较小者 = 9000（实付/流量×未用流量），total = 10000 - 9000 = 1000
        $this->assertLessThanOrEqual(
            10000,
            (int) $upgrade->surplus_amount,
            'surplus 不得超过实付总额; got=' . $upgrade->surplus_amount
        );
        // total = 原价 10000 - surplus 9000 = 1000
        $this->assertSame(1000, (int) $upgrade->total_amount, 'total=' . $upgrade->total_amount);
        $this->assertSame(
            0,
            (int) ($upgrade->surplus_credit ?? 0),
            '同价升级不得产生 surplus_credit 余额倒贴; credit=' . ($upgrade->surplus_credit ?? 0)
        );
    }

    private function makeGroup(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => $name, 'created_at' => time(), 'updated_at' => time()]);
        $group->save();
        return $group;
    }

    private function makePlan(int $groupId, string $name, array $prices, int $trafficGb): Plan
    {
        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $groupId,
            'transfer_enable' => $trafficGb,
            'name' => $name,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'speed_limit' => 0,
            'device_limit' => 0,
            'prices' => $prices,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();
        return $plan;
    }
}

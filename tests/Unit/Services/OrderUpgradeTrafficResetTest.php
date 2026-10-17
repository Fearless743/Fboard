<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 订单开通后的流量重置行为：
 * - 新购 / 一次性→循环 / 重置流量包：无条件重置（u/d 清零）；
 * - 周期→周期升级（TYPE_UPGRADE）：由「当订阅变更时触发事件」
 *   （change_order_event_id = 1）控制，与折抵是否考虑剩余流量联动；
 * - 折抵升级（surplus 覆盖全部应付）走同一开通链路，同样按上述规则执行。
 */
class OrderUpgradeTrafficResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => $name, 'created_at' => time(), 'updated_at' => time()]);
        $group->save();
        return $group;
    }

    private function makePlan(int $groupId, string $name, int $priceYuan, int $trafficGb, array $attrs = []): Plan
    {
        $plan = new Plan();
        $plan->forceFill(array_merge([
            'group_id' => $groupId,
            'transfer_enable' => $trafficGb,
            'name' => $name,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'speed_limit' => 0,
            'device_limit' => 0,
            'prices' => [Plan::PERIOD_MONTHLY => $priceYuan],
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        $plan->save();
        return $plan;
    }

    private function makeUser(int $planId, int $groupId, array $attrs = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'email' => 'u-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'plan_id' => $planId,
            'group_id' => $groupId,
            'transfer_enable' => 100 * 1073741824,
            'u' => 50 * 1073741824,
            'd' => 20 * 1073741824,
            'balance' => 0,
            'expired_at' => time() + 30 * 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        $user->save();
        return $user;
    }

    private function makeOrder(int $userId, int $planId, string $period, array $attrs = []): Order
    {
        $order = new Order();
        $order->forceFill(array_merge([
            'user_id' => $userId,
            'plan_id' => $planId,
            'period' => $period,
            'trade_no' => 'ORD-' . Helper::guid(),
            'total_amount' => 0,
            'surplus_amount' => 0,
            'surplus_credit' => 0,
            'type' => Order::TYPE_UPGRADE,
            'status' => Order::STATUS_PROCESSING,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        $order->save();
        return $order;
    }

    public function test_upgrade_resets_traffic_when_change_event_enabled(): void
    {
        admin_setting([
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'change_order_event_id' => 1,
        ]);

        $group = $this->makeGroup('g1');
        $oldPlan = $this->makePlan($group->id, 'old', 100, 100);
        $newPlan = $this->makePlan($group->id, 'new', 100, 200);

        $user = $this->makeUser($oldPlan->id, $group->id);
        $order = $this->makeOrder($user->id, $newPlan->id, Plan::PERIOD_MONTHLY);

        (new OrderService($order))->open();
        $user->refresh();

        $this->assertSame(0, (int) $user->u, 'u=' . $user->u);
        $this->assertSame(0, (int) $user->d, 'd=' . $user->d);
        $this->assertSame(200 * 1073741824, (int) $user->transfer_enable);
        $this->assertSame($newPlan->id, (int) $user->plan_id);
        $this->assertNotNull(
            TrafficResetLog::where('user_id', $user->id)->where('trigger_source', TrafficResetLog::SOURCE_ORDER)->first(),
            '升级重置应留下日志'
        );
    }

    public function test_upgrade_keeps_traffic_when_change_event_disabled(): void
    {
        admin_setting([
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'change_order_event_id' => 0,
        ]);

        $group = $this->makeGroup('g2');
        $oldPlan = $this->makePlan($group->id, 'old', 100, 100);
        $newPlan = $this->makePlan($group->id, 'new', 100, 200);

        $user = $this->makeUser($oldPlan->id, $group->id);
        $order = $this->makeOrder($user->id, $newPlan->id, Plan::PERIOD_MONTHLY);

        (new OrderService($order))->open();
        $user->refresh();

        // 设计行为：周期→周期升级默认不重置流量（由 change_order_event_id 开启），
        // 剩余价值由折抵方案补偿，强制重置会让用户损失未用流量。
        $this->assertSame(50 * 1073741824, (int) $user->u, 'u=' . $user->u);
        $this->assertSame(20 * 1073741824, (int) $user->d, 'd=' . $user->d);
    }

    public function test_upgrade_from_onetime_plan_resets_traffic(): void
    {
        admin_setting([
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'change_order_event_id' => 0,
        ]);

        $group = $this->makeGroup('g3');
        $oneTimePlan = $this->makePlan($group->id, 'onetime', 0, 100);
        $newPlan = $this->makePlan($group->id, 'monthly', 100, 200);

        // 一次性套餐用户：expired_at 为 NULL
        $user = $this->makeUser($oneTimePlan->id, $group->id, ['expired_at' => null]);
        $order = $this->makeOrder($user->id, $newPlan->id, Plan::PERIOD_MONTHLY);

        (new OrderService($order))->open();
        $user->refresh();

        $this->assertSame(0, (int) $user->u, 'u=' . $user->u);
        $this->assertSame(0, (int) $user->d, 'd=' . $user->d);
        $this->assertSame($newPlan->id, (int) $user->plan_id);
        $this->assertSame(200 * 1073741824, (int) $user->transfer_enable);
        $this->assertNotNull($user->expired_at, '转换为周期套餐后应设置到期时间');
    }

    public function test_upgrade_with_full_surplus_offset_resets_traffic_when_event_enabled(): void
    {
        admin_setting([
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'change_order_event_id' => 1,
        ]);

        $group = $this->makeGroup('g4');
        $oldPlan = $this->makePlan($group->id, 'old', 100, 100);
        $newPlan = $this->makePlan($group->id, 'new', 100, 200);

        $user = $this->makeUser($oldPlan->id, $group->id);
        // 已折抵：surplus 全额覆盖本单，多余部分转入余额
        $order = $this->makeOrder($user->id, $newPlan->id, Plan::PERIOD_MONTHLY, [
            'surplus_amount' => 12000,
            'surplus_credit' => 2000,
            'total_amount' => 0,
        ]);

        (new OrderService($order))->open();
        $user->refresh();

        $this->assertSame(0, (int) $user->u, 'u=' . $user->u);
        $this->assertSame(0, (int) $user->d, 'd=' . $user->d);
        $this->assertSame(2000, (int) $user->balance, 'balance=' . $user->balance);
    }

    public function test_new_purchase_resets_traffic(): void
    {
        admin_setting([
            'new_order_event_id' => 0,
            'renew_order_event_id' => 0,
            'change_order_event_id' => 0,
        ]);

        $group = $this->makeGroup('g5');
        $plan = $this->makePlan($group->id, 'p', 100, 200);

        // 已有流量使用记录的存量用户新购本套餐
        $user = $this->makeUser(0, $group->id, ['plan_id' => null, 'u' => 30 * 1073741824, 'd' => 10 * 1073741824]);
        $order = $this->makeOrder($user->id, $plan->id, Plan::PERIOD_MONTHLY, ['type' => Order::TYPE_NEW_PURCHASE]);

        (new OrderService($order))->open();
        $user->refresh();

        $this->assertSame(0, (int) $user->u, 'u=' . $user->u);
        $this->assertSame(0, (int) $user->d, 'd=' . $user->d);
    }

    public function test_renewal_resets_traffic_when_renew_event_enabled(): void
    {
        admin_setting([
            'new_order_event_id' => 0,
            'renew_order_event_id' => 1,
            'change_order_event_id' => 0,
        ]);

        $group = $this->makeGroup('g6');
        $plan = $this->makePlan($group->id, 'p', 100, 200);
        $user = $this->makeUser($plan->id, $group->id);
        $order = $this->makeOrder($user->id, $plan->id, Plan::PERIOD_MONTHLY, ['type' => Order::TYPE_RENEWAL]);

        (new OrderService($order))->open();
        $user->refresh();

        $this->assertSame(0, (int) $user->u, 'u=' . $user->u);
        $this->assertSame(0, (int) $user->d, 'd=' . $user->d);
    }

    public function test_reset_traffic_package_zeroes_traffic(): void
    {
        admin_setting(['new_order_event_id' => 0, 'renew_order_event_id' => 0, 'change_order_event_id' => 0]);

        $group = $this->makeGroup('g7');
        $plan = $this->makePlan($group->id, 'p', 100, 200);
        $user = $this->makeUser($plan->id, $group->id);
        $order = $this->makeOrder($user->id, $plan->id, Plan::PERIOD_RESET_TRAFFIC, [
            'type' => Order::TYPE_RESET_TRAFFIC,
        ]);

        (new OrderService($order))->open();
        $user->refresh();

        $this->assertSame(0, (int) $user->u, 'u=' . $user->u);
        $this->assertSame(0, (int) $user->d, 'd=' . $user->d);
        $this->assertSame(1, (int) $user->reset_count, 'reset_count=' . $user->reset_count);
    }
}

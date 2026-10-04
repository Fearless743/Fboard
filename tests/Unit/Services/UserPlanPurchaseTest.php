<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 多套餐 PR2：OrderService 实例写路径。
 * 开关开启时只写实例表，主表快照列一律不动；开关关闭走 legacy（既有测试覆盖）。
 */
class UserPlanPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'multi_plan_enable' => 1,
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'new_order_event_id' => 0,
            'renew_order_event_id' => 0,
            'change_order_event_id' => 0,
        ]);
    }

    public function test_new_purchase_creates_cycle_row_and_leaves_master_untouched(): void
    {
        [$user, $planA] = $this->seedBasics();
        $now = time();

        $order = OrderService::createFromRequest($user, $planA, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);
        $this->assertTrue((new OrderService($order))->paid('cb-1'));
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::find($order->id)->status);

        $rows = UserPlan::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame(UserPlan::KIND_CYCLE, $row->kind->value);
        $this->assertSame($planA->id, (int) $row->plan_id);
        $this->assertSame(10 * 1073741824, (int) $row->transfer_enable);
        $this->assertSame([$order->id], $row->order_ids);
        $this->assertGreaterThan($now + 25 * 86400, (int) $row->expired_at);
        $this->assertNull($row->speed_limit);
        $this->assertNull($row->device_limit);

        // 主表快照列冻结
        $user->refresh();
        $this->assertNull($user->plan_id);
        $this->assertSame(0, (int) $user->transfer_enable);
    }

    public function test_renewal_reuses_row_accumulates_quota_keeps_usage(): void
    {
        [$user, $planA] = $this->seedBasics();

        $o1 = $this->buy($user, $planA, Plan::PERIOD_MONTHLY);
        $row = UserPlan::where('user_id', $user->id)->sole();
        $firstExpired = (int) $row->expired_at;
        $row->forceFill(['u' => 1 * 1073741824, 'd' => 0])->save();

        $o2 = OrderService::createFromRequest($user->refresh(), $planA, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::TYPE_RENEWAL, (int) $o2->type);
        $this->assertTrue((new OrderService($o2))->paid('cb-2'));

        // 永不盲建：仍是同一行
        $this->assertSame(1, UserPlan::where('user_id', $user->id)->count());
        $row->refresh();
        $this->assertSame(20 * 1073741824, (int) $row->transfer_enable);
        $this->assertGreaterThan($firstExpired, (int) $row->expired_at);
        $this->assertSame(1 * 1073741824, (int) $row->u);
        $this->assertEqualsCanonicalizing([$o1->id, $o2->id], $row->order_ids);
    }

    public function test_expired_cycle_rebuy_resets_same_row(): void
    {
        [$user, $planA] = $this->seedBasics();
        $now = time();

        $o1 = $this->buy($user, $planA, Plan::PERIOD_MONTHLY);
        $row = UserPlan::where('user_id', $user->id)->sole();
        $row->forceFill([
            'transfer_enable' => 20 * 1073741824, // 模拟续费后的累积
            'u' => 5 * 1073741824,
            'd' => 5 * 1073741824,
            'expired_at' => $now - 100,
        ])->save();

        // 有 cycle 行（已过期）→ 类型仍是续费，开通时按新周期写同一行
        $o2 = OrderService::createFromRequest($user->refresh(), $planA, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::TYPE_RENEWAL, (int) $o2->type);
        $this->assertTrue((new OrderService($o2))->paid('cb-2'));

        $this->assertSame(1, UserPlan::where('user_id', $user->id)->count());
        $row->refresh();
        // 覆盖而非累加（防旧剩余额度白送），用量清零，到期从 now 起算
        $this->assertSame(10 * 1073741824, (int) $row->transfer_enable);
        $this->assertSame(0, (int) $row->u);
        $this->assertSame(0, (int) $row->d);
        $this->assertGreaterThan($now, (int) $row->expired_at);
        $this->assertEqualsCanonicalizing([$o1->id, $o2->id], $row->order_ids);
    }

    public function test_different_plan_creates_new_cycle_row_not_upgrade(): void
    {
        [$user, $planA, $planB] = $this->seedBasics();
        $this->buy($user, $planA, Plan::PERIOD_MONTHLY);

        // 多套餐下换套餐=新购，升级分支不可达
        $order = OrderService::createFromRequest($user->refresh(), $planB, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);
        $this->assertTrue((new OrderService($order))->paid('cb-b'));

        $rows = UserPlan::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($planA->id, (int) $rows[0]->plan_id);
        $this->assertSame($planB->id, (int) $rows[1]->plan_id);
        $this->assertSame(10 * 1073741824, (int) $rows[0]->transfer_enable);
    }

    public function test_onetime_always_new_pack_rows(): void
    {
        [$user, $planA, , $packPlan] = $this->seedBasics();
        $this->buy($user, $planA, Plan::PERIOD_MONTHLY);

        // 即使已持有 cycle 行，onetime 永远新购且独立成行
        $o1 = OrderService::createFromRequest($user->refresh(), $packPlan, Plan::PERIOD_ONETIME);
        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $o1->type);
        $this->assertTrue((new OrderService($o1))->paid('cb-p1'));
        $o2 = OrderService::createFromRequest($user->refresh(), $packPlan, Plan::PERIOD_ONETIME);
        $this->assertTrue((new OrderService($o2))->paid('cb-p2'));

        $packs = UserPlan::where('user_id', $user->id)->where('kind', UserPlan::KIND_PACK)->orderBy('id')->get();
        $this->assertCount(2, $packs);
        foreach ($packs as $pack) {
            $this->assertSame(50 * 1073741824, (int) $pack->transfer_enable);
            $this->assertNull($pack->expired_at);
        }
        $this->assertSame([$o1->id], $packs[0]->order_ids);
        $this->assertSame([$o2->id], $packs[1]->order_ids);
    }

    public function test_new_pack_retires_exhausted_same_plan_rows_only(): void
    {
        [$user, , , $packPlan] = $this->seedBasics();
        $before = time();

        $this->buy($user, $packPlan, Plan::PERIOD_ONETIME);
        $pack1 = UserPlan::where('user_id', $user->id)->sole();
        $pack1->forceFill(['u' => 25 * 1073741824, 'd' => 25 * 1073741824])->save(); // 耗尽

        $this->buy($user->refresh(), $packPlan, Plan::PERIOD_ONETIME);
        $pack2 = UserPlan::where('user_id', $user->id)->orderBy('id', 'desc')->first();
        $pack2->forceFill(['u' => 10 * 1073741824, 'd' => 0])->save(); // 有剩余额度

        $this->buy($user->refresh(), $packPlan, Plan::PERIOD_ONETIME);

        $rows = UserPlan::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(3, $rows);
        // 耗尽行退役但保留备查；有剩余额度的行不动；新行正常
        $this->assertGreaterThanOrEqual($before, (int) $rows[0]->expired_at);
        $this->assertNull($rows[1]->expired_at);
        $this->assertNull($rows[2]->expired_at);
        // 有剩余额度的包行用量与配额都不动
        $this->assertSame(10 * 1073741824, (int) $rows[1]->u);
        $this->assertSame(50 * 1073741824, (int) $rows[1]->transfer_enable);
    }

    public function test_reset_package_only_resets_matching_cycle_row(): void
    {
        [$user, $planA, , $packPlan] = $this->seedBasics();
        $this->buy($user, $planA, Plan::PERIOD_MONTHLY);
        $this->buy($user->refresh(), $packPlan, Plan::PERIOD_ONETIME);

        $cycle = UserPlan::where('user_id', $user->id)->where('kind', UserPlan::KIND_CYCLE)->sole();
        $pack = UserPlan::where('user_id', $user->id)->where('kind', UserPlan::KIND_PACK)->sole();
        $cycle->forceFill(['u' => 2 * 1073741824, 'd' => 1 * 1073741824])->save();
        $pack->forceFill(['u' => 3 * 1073741824, 'd' => 0])->save();
        $quotaBefore = (int) $cycle->transfer_enable;
        $expiredBefore = (int) $cycle->expired_at;

        $reset = OrderService::createFromRequest($user->refresh(), $planA, Plan::PERIOD_RESET_TRAFFIC);
        $this->assertSame(Order::TYPE_RESET_TRAFFIC, (int) $reset->type);
        $this->assertTrue((new OrderService($reset))->paid('cb-r'));

        $cycle->refresh();
        $pack->refresh();
        // 只清对应 cycle 行的 u/d，配额/到期不动，订单 id 追加；pack 行不参与
        $this->assertSame(0, (int) $cycle->u);
        $this->assertSame(0, (int) $cycle->d);
        $this->assertSame($quotaBefore, (int) $cycle->transfer_enable);
        $this->assertSame($expiredBefore, (int) $cycle->expired_at);
        $this->assertContains($reset->id, $cycle->order_ids);
        $this->assertSame(3 * 1073741824, (int) $pack->u);
    }

    public function test_reset_package_rejected_without_cycle_row(): void
    {
        [$user, $planA] = $this->seedBasics();

        $this->expectException(ApiException::class);
        OrderService::createFromRequest($user, $planA, Plan::PERIOD_RESET_TRAFFIC);
    }

    public function test_upgrade_order_rejected_in_multi_mode(): void
    {
        [$user, $planA, $planB] = $this->seedBasics();
        $this->buy($user, $planA, Plan::PERIOD_MONTHLY);

        // 模拟管理端/历史遗留的升级单：开通时直接失败
        $order = new Order();
        $order->forceFill([
            'user_id' => $user->id,
            'plan_id' => $planB->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'UPG-' . Helper::guid(),
            'total_amount' => 0,
            'type' => Order::TYPE_UPGRADE,
            'status' => Order::STATUS_PROCESSING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        $this->expectExceptionMessage('多套餐模式下不支持升级折抵');
        (new OrderService($order))->open();
    }

    public function test_open_idempotent_appends_order_once(): void
    {
        [$user, $planA] = $this->seedBasics();

        $order = OrderService::createFromRequest($user, $planA, Plan::PERIOD_MONTHLY);
        $this->assertTrue((new OrderService($order))->paid('cb-1'));
        (new OrderService(Order::find($order->id)))->open();

        $row = UserPlan::where('user_id', $user->id)->sole();
        $this->assertSame(10 * 1073741824, (int) $row->transfer_enable);
        $this->assertSame([$order->id], $row->order_ids);
    }

    /**
     * 下单→支付→开通全链路。
     */
    private function buy(User $user, Plan $plan, string $period): Order
    {
        $order = OrderService::createFromRequest($user, $plan, $period);
        $this->assertTrue((new OrderService($order))->paid('cb-' . Helper::guid()));

        return $order;
    }

    /**
     * @return array{User, Plan, Plan, Plan}
     */
    private function seedBasics(): array
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => 'g', 'created_at' => time(), 'updated_at' => time()]);
        $group->save();

        $planA = $this->makePlan($group->id, 'A', [
            Plan::PERIOD_MONTHLY => 1000,
            Plan::PERIOD_RESET_TRAFFIC => 100,
        ]);
        $planB = $this->makePlan($group->id, 'B', [Plan::PERIOD_MONTHLY => 2000]);
        $packPlan = $this->makePlan($group->id, 'P', [
            Plan::PERIOD_ONETIME => 500,
            Plan::PERIOD_RESET_TRAFFIC => 100,
        ], 50);

        $user = new User();
        $user->forceFill([
            'email' => Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        return [$user, $planA, $planB, $packPlan];
    }

    private function makePlan(int $groupId, string $name, array $prices, int $gb = 10): Plan
    {
        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $groupId,
            'transfer_enable' => $gb,
            'name' => $name,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => $prices,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        return $plan;
    }
}

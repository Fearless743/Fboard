<?php

namespace Tests\Unit\Services;

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
 * 多套餐模式（multi_plan_enable=1）下的购买行为：
 *  新购新增实例、同套餐续费合并、不同套餐并存、一次性流量包叠加。
 */
class UserPlanPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => $name, 'created_at' => time(), 'updated_at' => time()]);
        $group->save();
        return $group;
    }

    private function makePlan(ServerGroup $group, array $prices, int $transferGb = 100): Plan
    {
        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => $transferGb,
            'name' => 'plan-' . substr(Helper::guid(), 0, 6),
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

    private function makeUser(): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'mp-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();
        return $user;
    }

    private function enableMultiPlan(): void
    {
        admin_setting([
            'multi_plan_enable' => 1,
            'plan_change_enable' => 1,
            'surplus_enable' => 0,
            'invite_commission' => 0,
            'commission_first_time_enable' => 0,
        ]);
    }

    /** 下单并支付开通，返回订单 */
    private function purchase(User $user, Plan $plan, string $period): Order
    {
        $order = OrderService::createFromRequest($user, $plan, $period);
        (new OrderService($order))->paid($order->trade_no);
        return $order->fresh();
    }

    public function test_first_purchase_creates_instance(): void
    {
        $this->enableMultiPlan();
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group, [Plan::PERIOD_MONTHLY => 1000]);
        $user = $this->makeUser();

        $order = $this->purchase($user, $plan, Plan::PERIOD_MONTHLY);

        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);
        $instances = UserPlan::where('user_id', $user->id)->get();
        $this->assertCount(1, $instances);
        $this->assertSame($plan->id, $instances[0]->plan_id);
        $this->assertSame(100 * 1073741824, $instances[0]->transfer_enable);
        $this->assertNotNull($instances[0]->expired_at);

        $user->refresh();
        $this->assertSame($plan->id, $user->plan_id);
        $this->assertSame($group->id, $user->group_id);
    }

    public function test_multi_instance_user_buying_new_plan_is_new_purchase(): void
    {
        $this->enableMultiPlan();
        $g1 = $this->makeGroup('g1');
        $g2 = $this->makeGroup('g2');
        $g3 = $this->makeGroup('g3');
        $p1 = $this->makePlan($g1, [Plan::PERIOD_MONTHLY => 1000]);
        $p2 = $this->makePlan($g2, [Plan::PERIOD_MONTHLY => 2000]);
        $p3 = $this->makePlan($g3, [Plan::PERIOD_MONTHLY => 3000]);
        $user = $this->makeUser();

        // 先让管理员分配两个套餐，形成多实例
        \App\Services\UserPlanService::syncFromAdmin($user, [
            ['plan_id' => $p1->id, 'expired_at' => time() + 86400],
            ['plan_id' => $p2->id, 'expired_at' => time() + 86400],
        ]);

        // 已有 ≥2 个活跃实例时再购买新套餐 = 新购（新增实例），不触发升级/折抵
        $order = $this->purchase($user->fresh(), $p3, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);

        $planIds = UserPlan::where('user_id', $user->id)->pluck('plan_id')->all();
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id, $p3->id], $planIds);

        // 节点可见性为三组并集
        $groupIds = \App\Services\UserPlanService::getActiveGroupIds($user->id);
        sort($groupIds);
        $this->assertSame([$g1->id, $g2->id, $g3->id], $groupIds);
    }

    public function test_admin_sync_assigns_multiple_plans(): void
    {
        $this->enableMultiPlan();
        $g1 = $this->makeGroup('g1');
        $g2 = $this->makeGroup('g2');
        $p1 = $this->makePlan($g1, [Plan::PERIOD_MONTHLY => 1000]);
        $p2 = $this->makePlan($g2, [Plan::PERIOD_MONTHLY => 2000]);
        $user = $this->makeUser();

        \App\Services\UserPlanService::syncFromAdmin($user, [
            ['plan_id' => $p1->id, 'expired_at' => time() + 86400],
            ['plan_id' => $p2->id, 'expired_at' => time() + 172800],
        ]);

        $planIds = UserPlan::where('user_id', $user->id)->pluck('plan_id')->all();
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id], $planIds);

        $groupIds = \App\Services\UserPlanService::getActiveGroupIds($user->id);
        sort($groupIds);
        $this->assertSame([$g1->id, $g2->id], $groupIds);
    }

    public function test_repurchase_same_plan_merges_as_renewal(): void
    {
        $this->enableMultiPlan();
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group, [Plan::PERIOD_MONTHLY => 1000]);
        $user = $this->makeUser();

        $this->purchase($user, $plan, Plan::PERIOD_MONTHLY);
        $firstExpiry = UserPlan::where('user_id', $user->id)->first()->expired_at;

        // 前进 1 秒再续费
        $order2 = $this->purchase($user, $plan, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order2->type);

        // 同一 (user_id, plan_id) 仍只有一条实例，且到期时间顺延
        $instances = UserPlan::where('user_id', $user->id)->where('plan_id', $plan->id)->get();
        $this->assertCount(1, $instances);
        $this->assertGreaterThanOrEqual($firstExpiry, $instances[0]->expired_at);
    }

    public function test_onetime_plan_stacks_quota(): void
    {
        $this->enableMultiPlan();
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group, [Plan::PERIOD_ONETIME => 500], 50);
        $user = $this->makeUser();

        $this->purchase($user, $plan, Plan::PERIOD_ONETIME);
        $this->purchase($user, $plan, Plan::PERIOD_ONETIME);

        $instances = UserPlan::where('user_id', $user->id)->where('plan_id', $plan->id)->get();
        $this->assertCount(1, $instances);
        // 两次 50GB 叠加 = 100GB
        $this->assertSame(100 * 1073741824, $instances[0]->transfer_enable);
        $this->assertNull($instances[0]->expired_at);

        // 聚合缓存：一次性包使 expired_at 为 NULL（长期）
        $user->refresh();
        $this->assertNull($user->expired_at);
    }

    public function test_single_instance_upgrade_still_works(): void
    {
        admin_setting([
            'multi_plan_enable' => 1,
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'invite_commission' => 0,
            'commission_first_time_enable' => 0,
        ]);
        $g1 = $this->makeGroup('g1');
        $p1 = $this->makePlan($g1, [Plan::PERIOD_MONTHLY => 1000]);
        $p2 = $this->makePlan($g1, [Plan::PERIOD_MONTHLY => 2000]);
        $user = $this->makeUser();

        $this->purchase($user, $p1, Plan::PERIOD_MONTHLY);
        // 仅一个活跃实例时购买不同套餐 → 升级
        $order2 = OrderService::createFromRequest($user->fresh(), $p2, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::TYPE_UPGRADE, (int) $order2->type);
    }
}

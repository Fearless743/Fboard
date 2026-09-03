<?php

namespace Tests\Unit\Services;

use App\Jobs\TrafficFetchJob;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 多套餐模式下节点流量上报按"先到期先扣"分摊到各套餐实例。
 */
class TrafficAllocateTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(string $name): ServerGroup
    {
        $g = new ServerGroup();
        $g->forceFill(['name' => $name, 'created_at' => time(), 'updated_at' => time()]);
        $g->save();
        return $g;
    }

    private function makePlan(ServerGroup $group): Plan
    {
        $p = new Plan();
        $p->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'p-' . substr(Helper::guid(), 0, 6),
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => 1000],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $p->save();
        return $p;
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'ta-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();
        return $user;
    }

    private function makeInstance(User $user, Plan $plan, int $capacity, ?int $expiredAt): UserPlan
    {
        return UserPlan::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => $capacity,
            'u' => 0,
            'd' => 0,
            'expired_at' => $expiredAt,
            'source' => UserPlan::SOURCE_ORDER,
        ]);
    }

    private function runJob(User $user, int $uInc, int $dInc): void
    {
        Redis::shouldReceive('sadd')->andReturn(true);
        $job = new TrafficFetchJob(['rate' => 1], [$user->id => [$uInc, $dInc]], 'shadowsocks', time());
        $job->handle();
    }

    public function test_allocates_to_soonest_expiring_first(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group);
        $user = $this->makeUser();

        $soon = $this->makeInstance($user, $plan, 1000, time() + 86400);      // 先到期
        $later = $this->makeInstance($user, $this->makePlan($group), 1000, time() + 10 * 86400); // 后到期

        $this->runJob($user, 600, 0);

        $soon->refresh();
        $later->refresh();
        // 先到期的实例先吸收 600
        $this->assertSame(600, (int) $soon->u);
        $this->assertSame(0, (int) $later->u);
    }

    public function test_overflow_spills_to_next_instance(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group);
        $user = $this->makeUser();

        $soon = $this->makeInstance($user, $plan, 500, time() + 86400);
        $later = $this->makeInstance($user, $this->makePlan($group), 1000, time() + 10 * 86400);

        // 增量 800：先到期的 500 容量吸收满后，溢出 300 给后到期的
        $this->runJob($user, 800, 0);

        $soon->refresh();
        $later->refresh();
        $this->assertSame(500, (int) $soon->u);
        $this->assertSame(300, (int) $later->u);
    }

    public function test_onetime_instance_absorbs_last(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group);
        $user = $this->makeUser();

        // 一次性流量包（expired_at NULL）应排在周期性套餐之后扣
        $periodic = $this->makeInstance($user, $plan, 1000, time() + 86400);
        $onetime = $this->makeInstance($user, $this->makePlan($group), 1000, null);

        $this->runJob($user, 400, 0);

        $periodic->refresh();
        $onetime->refresh();
        $this->assertSame(400, (int) $periodic->u);
        $this->assertSame(0, (int) $onetime->u);
    }

    public function test_excess_beyond_all_capacity_goes_to_last(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group);
        $user = $this->makeUser();

        $inst = $this->makeInstance($user, $plan, 500, time() + 86400);

        // 单实例容量 500，上报 800 → 超额 300 记入该（最末）实例
        $this->runJob($user, 800, 0);

        $inst->refresh();
        $this->assertSame(800, (int) $inst->u);
    }

    public function test_user_u_d_still_incremented(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group);
        $user = $this->makeUser();
        $this->makeInstance($user, $plan, 10000, time() + 86400);

        $this->runJob($user, 100, 200);

        $user->refresh();
        // user 表 u/d 作为原子计数器照常累加
        $this->assertSame(100, (int) $user->u);
        $this->assertSame(200, (int) $user->d);
    }

    public function test_node_exclusive_instance_is_charged_first(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $g1 = $this->makeGroup('g1'); // 仅套餐 A 覆盖
        $g2 = $this->makeGroup('g2'); // 套餐 A、B 都覆盖
        $planA = $this->makePlan($g2);
        // 让套餐 A 同时属于 g2 和 g1：直接改实例 group_id 模拟多组归属
        $user = $this->makeUser();

        $instA = $this->makeInstance($user, $planA, 10000, time() + 30 * 86400); // 后到期，group g2
        $instB = $this->makeInstance($user, $this->makePlan($g2), 10000, time() + 86400); // 先到期，group g2

        // 模拟：实例 A 独占 g1 节点（A 的 group 改为 g1；g1 只被 A 覆盖）
        $instA->update(['group_id' => $g1->id]);

        // 节点属于 g1：只有实例 A 覆盖 → 流量全部扣 A（尽管 B 更早到期）
        Redis::shouldReceive('sadd')->andReturn(true);
        $job = new TrafficFetchJob(
            ['rate' => 1, 'group_ids' => [(string) $g1->id]],
            [$user->id => [300, 0]],
            'shadowsocks',
            time()
        );
        $job->handle();

        $instA->refresh();
        $instB->refresh();
        $this->assertSame(300, (int) $instA->u, '独占节点的套餐实例应优先被扣');
        $this->assertSame(0, (int) $instB->u);
    }

    public function test_node_without_exclusive_instance_falls_back_to_soonest(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $g1 = $this->makeGroup('g1');
        $g2 = $this->makeGroup('g2');
        $user = $this->makeUser();

        $soon = $this->makeInstance($user, $this->makePlan($g2), 10000, time() + 86400);
        $later = $this->makeInstance($user, $this->makePlan($g2), 10000, time() + 30 * 86400);

        // 节点属于 g1（无任何实例覆盖，也无独占关系）→ 退化为先到期先扣
        Redis::shouldReceive('sadd')->andReturn(true);
        $job = new TrafficFetchJob(
            ['rate' => 1, 'group_ids' => [(string) $g1->id]],
            [$user->id => [300, 0]],
            'shadowsocks',
            time()
        );
        $job->handle();

        $soon->refresh();
        $later->refresh();
        $this->assertSame(300, (int) $soon->u);
        $this->assertSame(0, (int) $later->u);
    }

    public function test_shared_node_group_charges_soonest_first(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $g1 = $this->makeGroup('g1');
        $user = $this->makeUser();

        $soon = $this->makeInstance($user, $this->makePlan($g1), 10000, time() + 86400);
        $later = $this->makeInstance($user, $this->makePlan($g1), 10000, time() + 30 * 86400);

        // 节点属于 g1，两个实例都覆盖 g1（无独占）→ 先到期先扣
        Redis::shouldReceive('sadd')->andReturn(true);
        $job = new TrafficFetchJob(
            ['rate' => 1, 'group_ids' => [(string) $g1->id]],
            [$user->id => [300, 0]],
            'shadowsocks',
            time()
        );
        $job->handle();

        $soon->refresh();
        $later->refresh();
        $this->assertSame(300, (int) $soon->u);
        $this->assertSame(0, (int) $later->u);
    }
}

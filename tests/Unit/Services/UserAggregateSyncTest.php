<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\UserPlanService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UserPlanService::syncUserAggregate 聚合规则：
 *  Σ transfer_enable/u/d、最晚 expired_at（一次性包则 NULL）、主套餐 plan_id/group_id、min next_reset_at。
 */
class UserAggregateSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'agg-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();
        return $user;
    }

    private function makeGroup(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => $name, 'created_at' => time(), 'updated_at' => time()]);
        $group->save();
        return $group;
    }

    private function makePlan(int $groupId): Plan
    {
        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $groupId,
            'transfer_enable' => 100,
            'name' => 'p' . $groupId . '-' . substr(Helper::guid(), 0, 6),
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => 1000],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();
        return $plan;
    }

    private function makeInstance(User $user, Plan $plan, array $overrides = []): UserPlan
    {
        return UserPlan::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => 10 * 1073741824,
            'u' => 0,
            'd' => 0,
            'expired_at' => time() + 86400,
            'source' => UserPlan::SOURCE_ORDER,
        ], $overrides));
    }

    public function test_single_instance_syncs_cache_columns(): void
    {
        $group = $this->makeGroup("g1");
        $user = $this->makeUser();
        $plan = $this->makePlan($group->id);
        $expired = time() + 86400;

        $this->makeInstance($user, $plan, [
            'transfer_enable' => 5 * 1073741824,
            'u' => 100,
            'd' => 200,
            'expired_at' => $expired,
            'next_reset_at' => time() + 3600,
        ]);

        UserPlanService::syncUserAggregate($user->id);
        $user->refresh();

        $this->assertSame($plan->id, $user->plan_id);
        $this->assertSame($group->id, $user->group_id);
        $this->assertSame(5 * 1073741824, $user->transfer_enable);
        $this->assertSame(300, $user->u + $user->d);
        $this->assertSame($expired, $user->expired_at);
    }

    public function test_multi_instance_aggregates_sum_and_latest_expiry(): void
    {
        $g1 = $this->makeGroup("g1");
        $g2 = $this->makeGroup("g2");
        $user = $this->makeUser();
        $p1 = $this->makePlan($g1->id);
        $p2 = $this->makePlan($g2->id);

        $soon = time() + 86400;       // 较早到期
        $later = time() + 10 * 86400; // 最晚到期 → 主套餐

        $this->makeInstance($user, $p1, ['transfer_enable' => 3 * 1073741824, 'u' => 1, 'd' => 1, 'expired_at' => $soon]);
        $this->makeInstance($user, $p2, ['transfer_enable' => 7 * 1073741824, 'u' => 2, 'd' => 2, 'expired_at' => $later]);

        UserPlanService::syncUserAggregate($user->id);
        $user->refresh();

        $this->assertSame(10 * 1073741824, $user->transfer_enable);
        $this->assertSame(6, $user->u + $user->d);
        $this->assertSame($later, $user->expired_at);
        // 主套餐为最晚到期的 p2
        $this->assertSame($p2->id, $user->plan_id);
        $this->assertSame($g2->id, $user->group_id);
    }

    public function test_onetime_instance_makes_expiry_null(): void
    {
        $group = $this->makeGroup("g1");
        $user = $this->makeUser();
        $plan = $this->makePlan($group->id);

        $this->makeInstance($user, $plan, ['expired_at' => null]); // 一次性流量包

        UserPlanService::syncUserAggregate($user->id);
        $user->refresh();

        $this->assertNull($user->expired_at);
    }

    public function test_no_active_instance_clears_cache(): void
    {
        $group = $this->makeGroup("g1");
        $user = $this->makeUser();
        $plan = $this->makePlan($group->id);

        // 已过期实例 → 非活跃
        $this->makeInstance($user, $plan, ['expired_at' => time() - 100]);

        UserPlanService::syncUserAggregate($user->id);
        $user->refresh();

        $this->assertNull($user->plan_id);
        $this->assertSame(0, $user->transfer_enable);
    }

    public function test_exhausted_instance_is_not_active(): void
    {
        $group = $this->makeGroup("g1");
        $user = $this->makeUser();
        $plan = $this->makePlan($group->id);

        // 流量耗尽 → 非活跃
        $this->makeInstance($user, $plan, ['transfer_enable' => 100, 'u' => 60, 'd' => 60]);

        UserPlanService::syncUserAggregate($user->id);
        $user->refresh();

        $this->assertNull($user->plan_id);
        $this->assertSame(0, $user->transfer_enable);
    }

    public function test_active_group_ids_union(): void
    {
        $g1 = $this->makeGroup("g1");
        $g2 = $this->makeGroup("g2");
        $user = $this->makeUser();
        $p1 = $this->makePlan($g1->id);
        $p2 = $this->makePlan($g2->id);

        $this->makeInstance($user, $p1, ['expired_at' => time() + 86400]);
        $this->makeInstance($user, $p2, ['expired_at' => time() + 86400]);

        $groupIds = UserPlanService::getActiveGroupIds($user->id);
        sort($groupIds);
        $this->assertSame([$g1->id, $g2->id], $groupIds);
    }
}

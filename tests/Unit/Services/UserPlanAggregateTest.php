<?php

namespace Tests\Unit\Services;

use App\Enums\UserPlanKind;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 多套餐 PR1：建表 + Model + 聚合入口（零行为变更）。
 * 只覆盖新增结构与新增方法，不碰既有单套餐行为。
 */
class UserPlanAggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('v2_user_plan'));
        foreach ([
            'id', 'user_id', 'plan_id', 'kind', 'group_id', 'order_ids',
            'transfer_enable', 'u', 'd', 'expired_at', 'exhausted_at',
            'next_reset_at', 'speed_limit', 'device_limit', 'sort_order',
            'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('v2_user_plan', $column), "missing column {$column}");
        }
    }

    public function test_model_defaults_and_kind_cast(): void
    {
        [$user, $plan, $group] = $this->seedBasics();

        $row = new UserPlan();
        $row->forceFill([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'kind' => UserPlan::KIND_CYCLE,
            'group_id' => $group->id,
        ]);
        $row->save();
        $row->refresh();

        $this->assertSame(UserPlan::KIND_CYCLE, UserPlanKind::Cycle->value);
        $this->assertSame(UserPlan::KIND_PACK, UserPlanKind::Pack->value);
        $this->assertSame(UserPlanKind::Cycle, $row->kind);
        $this->assertSame([], $row->order_ids);
        $this->assertSame(0, (int) $row->transfer_enable);
        $this->assertSame(0, (int) $row->u);
        $this->assertSame(0, (int) $row->d);
        $this->assertSame(0, (int) $row->sort_order);
        $this->assertNull($row->expired_at);
    }

    public function test_user_plans_returns_has_many_and_active_scope_filters_expired(): void
    {
        [$user, $plan, $group] = $this->seedBasics();
        $now = time();

        $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_CYCLE, ['expired_at' => $now + 3600]);
        $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_PACK, ['expired_at' => $now - 3600]);
        $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_PACK, ['expired_at' => null]);

        $this->assertInstanceOf(HasMany::class, $user->userPlans());
        $this->assertSame(3, $user->userPlans()->count());
        // activeUserPlans 必须保持链式（仍是 HasMany），只返回未到期行
        $this->assertInstanceOf(HasMany::class, $user->activeUserPlans($now));
        $this->assertSame(2, $user->activeUserPlans($now)->count());
        $this->assertTrue($user->hasActiveUserPlan($now));

        $expiredOnly = $this->makeUser();
        $this->makeInstance($expiredOnly->id, $plan->id, $group->id, UserPlan::KIND_CYCLE, ['expired_at' => $now - 1]);
        $this->assertFalse($expiredOnly->hasActiveUserPlan($now));
    }

    public function test_memory_aggregate_semantics(): void
    {
        [$user, $plan, $group] = $this->seedBasics();
        $group2 = $this->makeGroup('g2');
        $now = time();

        // cycle 行：配额 100G，已用 10G，最晚到期，限速 100
        $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_CYCLE, [
            'transfer_enable' => 100, 'u' => 6, 'd' => 4,
            'expired_at' => $now + 30 * 86400, 'speed_limit' => 100, 'device_limit' => 2,
        ]);
        // pack 行：配额 50G，已用完（耗尽但未到期，仍是有效实例，计入聚合）
        $this->makeInstance($user->id, $plan->id, $group2->id, UserPlan::KIND_PACK, [
            'transfer_enable' => 50, 'u' => 30, 'd' => 20,
            'expired_at' => $now + 86400, 'speed_limit' => 200, 'device_limit' => null,
            'exhausted_at' => $now - 100,
        ]);
        // 过期行：不计入
        $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_PACK, [
            'transfer_enable' => 9999, 'u' => 0, 'd' => 0, 'expired_at' => $now - 10,
        ]);

        $user->load('userPlans');
        $agg = $user->getPlanAggregate($now);

        $this->assertTrue($agg['is_active']);
        $this->assertSame(150, $agg['quota']);
        $this->assertSame(60, $agg['used']);
        $this->assertSame(90, $agg['remaining']);
        $this->assertSame($now + 30 * 86400, $agg['expired_at']);
        $this->assertSame(200, $agg['speed_limit']);
        $this->assertSame(2, $agg['device_limit']);
        $this->assertSame([$group->id, $group2->id], $agg['group_ids']);
        $this->assertSame(2, $agg['active_count']);
    }

    public function test_permanent_instance_forces_null_expiry(): void
    {
        [$user, $plan, $group] = $this->seedBasics();
        $now = time();

        $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_CYCLE, ['expired_at' => $now + 86400]);
        $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_PACK, [
            'transfer_enable' => 10, 'expired_at' => null,
        ]);

        $user->load('userPlans');
        $agg = $user->getPlanAggregate($now);

        $this->assertTrue($agg['is_active']);
        $this->assertNull($agg['expired_at']);
        $this->assertSame(2, $agg['active_count']);
    }

    public function test_empty_user_aggregate(): void
    {
        [$user] = $this->seedBasics();
        $agg = $user->getPlanAggregate();

        $this->assertFalse($agg['is_active']);
        $this->assertSame(0, $agg['quota']);
        $this->assertSame(0, $agg['used']);
        $this->assertSame(0, $agg['remaining']);
        $this->assertNull($agg['expired_at']);
        $this->assertNull($agg['speed_limit']);
        $this->assertNull($agg['device_limit']);
        $this->assertSame([], $agg['group_ids']);
        $this->assertSame(0, $agg['active_count']);
    }

    public function test_with_aggregate_matches_memory_path(): void
    {
        [$user, $plan, $group] = $this->seedBasics();
        $group2 = $this->makeGroup('g2');
        $now = time();

        $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_CYCLE, [
            'transfer_enable' => 100, 'u' => 6, 'd' => 4,
            'expired_at' => $now + 30 * 86400, 'speed_limit' => 100, 'device_limit' => 2,
        ]);
        $this->makeInstance($user->id, $plan->id, $group2->id, UserPlan::KIND_PACK, [
            'transfer_enable' => 50, 'u' => 30, 'd' => 20,
            'expired_at' => $now + 86400, 'speed_limit' => 200,
        ]);
        $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_PACK, [
            'transfer_enable' => 9999, 'expired_at' => $now - 10,
        ]);

        $eager = User::withPlanAggregate($now)->find($user->id);
        $agg = $eager->getPlanAggregate($now);

        $this->assertTrue($agg['is_active']);
        $this->assertSame(150, $agg['quota']);
        $this->assertSame(60, $agg['used']);
        $this->assertSame(90, $agg['remaining']);
        $this->assertSame($now + 30 * 86400, $agg['expired_at']);
        $this->assertSame(200, $agg['speed_limit']);
        $this->assertSame(2, $agg['device_limit']);
        $this->assertSame([$group->id, $group2->id], $agg['group_ids']);
        $this->assertSame(2, $agg['active_count']);

        // 列表场景：scope 可与其他条件叠加
        $this->assertSame(1, User::withPlanAggregate($now)->wherePlanActive($now)->count());
    }

    public function test_sql_scopes_active_and_exhausted(): void
    {
        [$user, $plan, $group] = $this->seedBasics();
        $now = time();

        $full = $this->makeUser('full');
        $this->makeInstance($full->id, $plan->id, $group->id, UserPlan::KIND_CYCLE, [
            'transfer_enable' => 100, 'u' => 60, 'd' => 40, 'expired_at' => $now + 86400,
        ]);
        $half = $this->makeUser('half');
        $this->makeInstance($half->id, $plan->id, $group->id, UserPlan::KIND_CYCLE, [
            'transfer_enable' => 100, 'u' => 30, 'd' => 20, 'expired_at' => $now + 86400,
        ]);
        $expiredUser = $this->makeUser('expired');
        $this->makeInstance($expiredUser->id, $plan->id, $group->id, UserPlan::KIND_CYCLE, [
            'transfer_enable' => 100, 'u' => 100, 'd' => 0, 'expired_at' => $now - 10,
        ]);
        $none = $this->makeUser('none');

        $this->assertEqualsCanonicalizing(
            [$full->id, $half->id],
            User::wherePlanActive($now)->pluck('id')->all()
        );

        // 正向判定：用量之和 >= 配额之和；过期行不参与；无实例用户不算耗尽
        $this->assertEqualsCanonicalizing(
            [$full->id],
            User::wherePlanExhausted($now)->pluck('id')->all()
        );
        $this->assertFalse(User::wherePlanExhausted($now)->where('id', $none->id)->exists());
        $this->assertFalse(User::wherePlanExhausted($now)->where('id', $expiredUser->id)->exists());
    }

    public function test_deduction_ordering_and_order_ids_append(): void
    {
        [$user, $plan, $group] = $this->seedBasics();
        $now = time();

        $plain = $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_PACK, [
            'expired_at' => $now + 86400, 'created_at' => $now - 30,
        ]);
        $pinned = $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_PACK, [
            'expired_at' => $now + 30 * 86400, 'sort_order' => 1, 'created_at' => $now - 20,
        ]);
        $permanent = $this->makeInstance($user->id, $plan->id, $group->id, UserPlan::KIND_PACK, [
            'expired_at' => null, 'created_at' => $now - 10,
        ]);

        $ordered = $user->userPlans()->orderedForDeduction()->pluck('id')->all();
        // sort_order=1 优先；默认 0 沉底按到期排序；永久最后
        $this->assertSame([$pinned->id, $plain->id, $permanent->id], $ordered);

        $pinned->appendOrderId(101);
        $pinned->appendOrderId(101);
        $pinned->appendOrderId(102);
        $pinned->save();
        $this->assertSame([101, 102], $pinned->refresh()->order_ids);

        $this->assertTrue($plain->isActive($now));
        // 零配额零用量按 u+d>=quota 字面判定为耗尽（迁移时零配额无订阅不建行，此处仅锁定字面语义）
        $this->assertTrue($plain->isExhausted());
        $this->assertSame(0, $plain->getRemainingTraffic());
    }

    /**
     * @return array{User, Plan, ServerGroup}
     */
    private function seedBasics(): array
    {
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group->id, 'p1');
        $user = $this->makeUser('base');

        return [$user, $plan, $group];
    }

    private function makeGroup(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => $name, 'created_at' => time(), 'updated_at' => time()]);
        $group->save();

        return $group;
    }

    private function makePlan(int $groupId, string $name): Plan
    {
        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $groupId,
            'transfer_enable' => 100,
            'name' => $name,
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

    private function makeUser(string $tag = ''): User
    {
        $user = new User();
        $user->forceFill([
            'email' => ($tag !== '' ? $tag . '-' : '') . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        return $user;
    }

    private function makeInstance(int $userId, int $planId, int $groupId, int $kind, array $overrides = []): UserPlan
    {
        $row = new UserPlan();
        $row->forceFill(array_merge([
            'user_id' => $userId,
            'plan_id' => $planId,
            'kind' => $kind,
            'group_id' => $groupId,
            'order_ids' => [],
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'sort_order' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
        $row->save();

        return $row;
    }
}

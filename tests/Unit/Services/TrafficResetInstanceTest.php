<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\TrafficResetService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 多套餐模式下每个套餐实例独立计量与重置流量。
 */
class TrafficResetInstanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(string $name): ServerGroup
    {
        $g = new ServerGroup();
        $g->forceFill(['name' => $name, 'created_at' => time(), 'updated_at' => time()]);
        $g->save();
        return $g;
    }

    private function makePlan(ServerGroup $group, ?int $resetMethod): Plan
    {
        $p = new Plan();
        $p->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'p-' . substr(Helper::guid(), 0, 6),
            'show' => true,
            'sell' => true,
            'renew' => true,
            'reset_traffic_method' => $resetMethod,
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
            'email' => 'tr-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();
        return $user;
    }

    private function makeInstance(User $user, Plan $plan, array $overrides = []): UserPlan
    {
        return UserPlan::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => 10000,
            'u' => 500,
            'd' => 300,
            'expired_at' => time() + 30 * 86400,
            'source' => UserPlan::SOURCE_ORDER,
        ], $overrides));
    }

    public function test_perform_reset_resets_all_active_instances(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group, Plan::RESET_TRAFFIC_MONTHLY);
        $user = $this->makeUser();

        $i1 = $this->makeInstance($user, $plan, ['u' => 100, 'd' => 100]);
        $i2 = $this->makeInstance($user, $this->makePlan($group, Plan::RESET_TRAFFIC_MONTHLY), ['u' => 200, 'd' => 200]);

        $service = app(TrafficResetService::class);
        $service->performReset($user, \App\Models\TrafficResetLog::SOURCE_MANUAL);

        $i1->refresh();
        $i2->refresh();
        $this->assertSame(0, (int) $i1->u);
        $this->assertSame(0, (int) $i1->d);
        $this->assertSame(0, (int) $i2->u);
        $this->assertSame(0, (int) $i2->d);
        // 每个实例独立计算了下次重置时间
        $this->assertNotNull($i1->next_reset_at);
        $this->assertNotNull($i2->next_reset_at);
    }

    public function test_instance_reset_uses_own_expired_at(): void
    {
        admin_setting(['multi_plan_enable' => 1, 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group, Plan::RESET_TRAFFIC_MONTHLY);
        $user = $this->makeUser();

        $inst = $this->makeInstance($user, $plan, ['expired_at' => time() + 30 * 86400]);

        $service = app(TrafficResetService::class);
        $next = $service->calculateNextResetTimeForInstance($inst);
        $this->assertNotNull($next);
        // 按月重置：到期日的"日"作为每月重置日（同一时区计算，且在未来）
        $expectedDay = \Carbon\Carbon::createFromTimestamp($inst->expired_at, config('app.timezone'))->day;
        $this->assertSame($expectedDay, $next->day);
        $this->assertGreaterThan(time(), $next->timestamp);
    }

    public function test_never_reset_plan_returns_null(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group, Plan::RESET_TRAFFIC_NEVER);
        $user = $this->makeUser();
        $inst = $this->makeInstance($user, $plan);

        $service = app(TrafficResetService::class);
        $this->assertNull($service->calculateNextResetTimeForInstance($inst));
    }

    public function test_batch_reset_scans_due_instances(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group, Plan::RESET_TRAFFIC_MONTHLY);
        $user = $this->makeUser();

        // 到期需重置的实例
        $due = $this->makeInstance($user, $plan, ['next_reset_at' => time() - 100, 'u' => 999]);
        // 未到重置时间的实例
        $notDue = $this->makeInstance($user, $this->makePlan($group, Plan::RESET_TRAFFIC_MONTHLY), ['next_reset_at' => time() + 86400, 'u' => 888]);

        $service = app(TrafficResetService::class);
        $result = $service->batchCheckResetInstances(100);

        $due->refresh();
        $notDue->refresh();
        $this->assertGreaterThanOrEqual(1, $result['total_reset']);
        $this->assertSame(0, (int) $due->u);
        $this->assertSame(888, (int) $notDue->u, '未到重置时间的实例不应被重置');
    }
}

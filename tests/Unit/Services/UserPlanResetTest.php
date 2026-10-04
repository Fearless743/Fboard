<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\OrderService;
use App\Services\TrafficResetService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 多套餐 PR4：统一 resetInstance（cron/手动/订单同一函数）。
 */
class UserPlanResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'multi_plan_enable' => 1,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
        ]);
    }

    public function test_due_cycle_row_resets_and_advances_next(): void
    {
        [$user, $plan] = $this->seedBasics();
        $now = time();
        $row = $this->makeRow($user->id, $plan->id, [
            'kind' => UserPlan::KIND_CYCLE,
            'u' => 5, 'd' => 3,
            'expired_at' => $now + 30 * 86400,
            'next_reset_at' => $now - 10,
        ]);

        $service = app(TrafficResetService::class);
        $this->assertTrue($service->resetInstance($row, TrafficResetLog::SOURCE_CRON, false));

        $row->refresh();
        $this->assertSame(0, (int) $row->u);
        $this->assertSame(0, (int) $row->d);
        $this->assertGreaterThan($now, (int) $row->next_reset_at);
        // quota/expiry 不动
        $this->assertSame(100, (int) $row->transfer_enable);
        $this->assertSame($now + 30 * 86400, (int) $row->expired_at);
        // 同步刷新 user.last_reset_at（reportTs 防回拨依赖）
        $this->assertGreaterThanOrEqual($now, (int) $user->refresh()->last_reset_at);
        // 重置日志带实例元数据
        $log = TrafficResetLog::where('user_id', $user->id)->sole();
        $this->assertSame(8, (int) $log->old_total);
        $this->assertSame($row->id, (int) ($log->metadata['user_plan_id'] ?? 0));
    }

    public function test_not_due_expired_and_pack_rows_untouched(): void
    {
        [$user, $plan] = $this->seedBasics();
        $now = time();
        $notDue = $this->makeRow($user->id, $plan->id, [
            'kind' => UserPlan::KIND_CYCLE, 'u' => 5,
            'expired_at' => $now + 30 * 86400, 'next_reset_at' => $now + 86400,
        ]);
        $expired = $this->makeRow($user->id, $plan->id, [
            'kind' => UserPlan::KIND_CYCLE, 'u' => 5,
            'expired_at' => $now - 10, 'next_reset_at' => $now - 10,
        ]);
        $pack = $this->makeRow($user->id, $plan->id, [
            'kind' => UserPlan::KIND_PACK, 'u' => 5,
            'expired_at' => null, 'next_reset_at' => $now - 10,
        ]);

        $service = app(TrafficResetService::class);
        $this->assertFalse($service->resetInstance($notDue, TrafficResetLog::SOURCE_CRON, false));
        $this->assertFalse($service->resetInstance($expired, TrafficResetLog::SOURCE_CRON, false));
        $this->assertFalse($service->resetInstance($pack, TrafficResetLog::SOURCE_CRON, true));

        $this->assertSame(5, (int) $notDue->refresh()->u);
        $this->assertSame(5, (int) $expired->refresh()->u);
        $this->assertSame(5, (int) $pack->refresh()->u);
        $this->assertSame(0, TrafficResetLog::where('user_id', $user->id)->count());
    }

    public function test_manual_reset_works_on_exhausted_instance(): void
    {
        [$user, $plan] = $this->seedBasics();
        $now = time();
        // 耗尽但未到期：手动重置同样生效
        $row = $this->makeRow($user->id, $plan->id, [
            'kind' => UserPlan::KIND_CYCLE, 'transfer_enable' => 10,
            'u' => 6, 'd' => 4, 'exhausted_at' => $now - 100,
            'expired_at' => $now + 30 * 86400, 'next_reset_at' => $now + 86400,
        ]);

        $service = app(TrafficResetService::class);
        $this->assertTrue($service->canReset($user));
        $this->assertTrue($service->manualReset($user));

        $row->refresh();
        $this->assertSame(0, (int) $row->u + (int) $row->d);
        $this->assertSame(10, (int) $row->transfer_enable);
    }

    public function test_manual_reset_without_rows_returns_false(): void
    {
        [$user] = $this->seedBasics();
        $service = app(TrafficResetService::class);
        $this->assertFalse($service->canReset($user));
        $this->assertFalse($service->manualReset($user));
    }

    public function test_each_row_resets_on_own_schedule(): void
    {
        [$user, $plan] = $this->seedBasics();
        $now = time();
        // 两行到期日不同 → 下次重置锚点不同
        $rowA = $this->makeRow($user->id, $plan->id, [
            'kind' => UserPlan::KIND_CYCLE, 'u' => 5,
            'expired_at' => $now + 10 * 86400, 'next_reset_at' => $now - 10,
        ]);
        $rowB = $this->makeRow($user->id, $plan->id, [
            'kind' => UserPlan::KIND_CYCLE, 'u' => 7,
            'expired_at' => $now + 20 * 86400, 'next_reset_at' => $now - 10,
        ]);

        $service = app(TrafficResetService::class);
        $this->assertTrue($service->checkAndReset($user, TrafficResetLog::SOURCE_CRON));

        $rowA->refresh();
        $rowB->refresh();
        $this->assertSame(0, (int) $rowA->u);
        $this->assertSame(0, (int) $rowB->u);
        $this->assertNotSame((int) $rowA->next_reset_at, (int) $rowB->next_reset_at);
    }

    public function test_reset_command_resets_due_instances(): void
    {
        [$user, $plan] = $this->seedBasics();
        $now = time();
        $due = $this->makeRow($user->id, $plan->id, [
            'kind' => UserPlan::KIND_CYCLE, 'u' => 5,
            'expired_at' => $now + 30 * 86400, 'next_reset_at' => $now - 10,
        ]);
        $idle = $this->makeRow($user->id, $plan->id, [
            'kind' => UserPlan::KIND_CYCLE, 'u' => 6,
            'expired_at' => $now + 30 * 86400, 'next_reset_at' => $now + 86400,
        ]);

        $this->artisan('reset:traffic')->assertSuccessful();

        $this->assertSame(0, (int) $due->refresh()->u);
        $this->assertSame(6, (int) $idle->refresh()->u);
    }

    public function test_order_reset_package_uses_unified_reset_and_logs(): void
    {
        [$user, $plan] = $this->seedBasics();
        $plan->forceFill(['prices' => array_merge($plan->prices ?? [], [
            Plan::PERIOD_MONTHLY => 1000,
            Plan::PERIOD_RESET_TRAFFIC => 100,
        ])])->save();

        $buy = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
        (new OrderService($buy))->paid('cb-buy');

        $row = UserPlan::where('user_id', $user->id)->sole();
        $row->forceFill(['u' => 4, 'd' => 2])->save();

        $reset = OrderService::createFromRequest($user->refresh(), $plan, Plan::PERIOD_RESET_TRAFFIC);
        (new OrderService($reset))->paid('cb-reset');

        $row->refresh();
        $this->assertSame(0, (int) $row->u + (int) $row->d);
        $this->assertContains($reset->id, $row->order_ids);
        $this->assertSame(
            1,
            TrafficResetLog::where('user_id', $user->id)
                ->where('trigger_source', TrafficResetLog::SOURCE_ORDER)
                ->count()
        );
    }

    /**
     * @return array{User, Plan}
     */
    private function seedBasics(): array
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => 'g', 'created_at' => time(), 'updated_at' => time()]);
        $group->save();

        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'p',
            'show' => true,
            'sell' => true,
            'renew' => true,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 1000],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

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

        return [$user, $plan];
    }

    private function makeRow(int $userId, int $planId, array $overrides = []): UserPlan
    {
        $row = new UserPlan();
        $row->forceFill(array_merge([
            'user_id' => $userId,
            'plan_id' => $planId,
            'kind' => UserPlan::KIND_CYCLE,
            'group_id' => 1,
            'order_ids' => [],
            'transfer_enable' => 100,
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

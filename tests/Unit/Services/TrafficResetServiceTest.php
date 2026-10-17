<?php

namespace Tests\Unit\Services;

use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\TrafficResetService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * performReset 必须真正落库清零：内部任何异常都会让外层 DB::transaction
 * 回滚清零动作，调用方只能拿到 false（曾经 reset_count 回写 (int) 强转
 * DB::raw 表达式抛 TypeError，导致所有路径的重置静默失效）。
 */
class TrafficResetServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(array $attrs = []): \App\Models\Plan
    {
        $plan = new \App\Models\Plan();
        $plan->forceFill(array_merge([
            'group_id' => 0,
            'transfer_enable' => 100,
            'name' => 'p',
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [\App\Models\Plan::PERIOD_MONTHLY => 100],
            'reset_traffic_method' => \App\Models\Plan::RESET_TRAFFIC_MONTHLY,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        $plan->save();
        return $plan;
    }

    private function makeUser(int $planId, array $attrs = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'email' => 'r-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'plan_id' => $planId,
            'group_id' => 0,
            'transfer_enable' => 100 * 1073741824,
            'u' => 30 * 1073741824,
            'd' => 20 * 1073741824,
            'reset_count' => 0,
            'balance' => 0,
            'expired_at' => time() + 30 * 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        $user->save();
        return $user;
    }

    public function test_perform_reset_zeros_traffic_and_writes_log(): void
    {
        admin_setting(['reset_traffic_method' => \App\Models\Plan::RESET_TRAFFIC_MONTHLY]);
        $plan = $this->makePlan();
        $user = $this->makeUser($plan->id, ['reset_count' => 2]);

        $result = app(TrafficResetService::class)->performReset($user, TrafficResetLog::SOURCE_ORDER);

        $this->assertTrue($result, 'performReset 不得静默失败');
        $user->refresh();
        $this->assertSame(0, (int) $user->u, 'u=' . $user->u);
        $this->assertSame(0, (int) $user->d, 'd=' . $user->d);
        $this->assertSame(3, (int) $user->reset_count, 'reset_count=' . $user->reset_count);
        $this->assertNotNull($user->last_reset_at);
        $this->assertNotNull($user->next_reset_at);

        $log = TrafficResetLog::where('user_id', $user->id)->first();
        $this->assertNotNull($log, '重置必须记录日志');
        $this->assertSame(TrafficResetLog::SOURCE_ORDER, $log->trigger_source);
        $this->assertSame(50 * 1073741824, (int) $log->old_total);
    }

    public function test_check_and_reset_respects_next_reset_at(): void
    {
        admin_setting(['reset_traffic_method' => \App\Models\Plan::RESET_TRAFFIC_MONTHLY]);
        $plan = $this->makePlan();
        // 未到期：force=false 不得重置
        $user = $this->makeUser($plan->id, ['next_reset_at' => time() + 3600]);

        $this->assertFalse(app(TrafficResetService::class)->checkAndReset($user, TrafficResetLog::SOURCE_CRON));
        $user->refresh();
        $this->assertSame(30 * 1073741824, (int) $user->u);

        // 到期：应重置
        $user->next_reset_at = time() - 10;
        $user->save();
        $this->assertTrue(app(TrafficResetService::class)->checkAndReset($user, TrafficResetLog::SOURCE_CRON));
        $user->refresh();
        $this->assertSame(0, (int) $user->u);
    }

    public function test_perform_reset_sets_next_reset_at_when_plan_never_resets(): void
    {
        admin_setting(['reset_traffic_method' => \App\Models\Plan::RESET_TRAFFIC_MONTHLY]);
        $plan = $this->makePlan(['reset_traffic_method' => \App\Models\Plan::RESET_TRAFFIC_NEVER]);
        $user = $this->makeUser($plan->id);

        $this->assertTrue(app(TrafficResetService::class)->performReset($user, TrafficResetLog::SOURCE_ORDER));
        $user->refresh();
        $this->assertSame(0, (int) $user->u);
        $this->assertNull($user->next_reset_at, '套餐设为不重置时不应排下次重置');
    }

    public function test_perform_reset_syncs_back_to_caller_model_without_other_dirty_fields(): void
    {
        admin_setting(['reset_traffic_method' => \App\Models\Plan::RESET_TRAFFIC_MONTHLY]);
        $plan = $this->makePlan();
        $user = $this->makeUser($plan->id);
        // 模拟订单流程尚未落库的脏字段：performReset 不应把它们写库
        $user->transfer_enable = 200 * 1073741824;
        $user->plan_id = 999;

        $this->assertTrue(app(TrafficResetService::class)->performReset($user, TrafficResetLog::SOURCE_ORDER));
        $this->assertSame(0, (int) $user->u);
        $this->assertSame(999, (int) $user->plan_id, '调用方模型的脏字段应原样保留');
        $this->assertSame(1, (int) $user->reset_count, 'reset_count 应回写调用方模型');

        $fresh = User::find($user->id);
        $this->assertSame($plan->id, (int) $fresh->plan_id, 'performReset 不得写库 transfer_enable/plan_id');
        $this->assertSame(100 * 1073741824, (int) $fresh->transfer_enable);
        $this->assertSame(1, (int) $fresh->reset_count);
    }
}

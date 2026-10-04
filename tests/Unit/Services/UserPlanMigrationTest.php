<?php

namespace Tests\Unit\Services;

use App\Jobs\TrafficFetchJob;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\GiftCardService;
use App\Services\OrderService;
use App\Services\TrafficResetService;
use App\Services\UserPlanMigrator;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 多套餐 PR6：迁移/对账/清理命令 + 旧列零读写 + 礼包/注册实例路径。
 */
class UserPlanMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'multi_plan_enable' => 1,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'new_order_event_id' => 0,
            'renew_order_event_id' => 0,
            'change_order_event_id' => 0,
        ]);
    }

    public function test_migrate_copies_single_plan_users(): void
    {
        [$group, $plan] = $this->seedPlan();
        $now = time();
        $user = $this->makeUser([
            'plan_id' => $plan->id, 'group_id' => $group->id,
            'transfer_enable' => 1000, 'u' => 300, 'd' => 200,
            'expired_at' => $now + 86400, 'speed_limit' => 10, 'device_limit' => 3,
            'next_reset_at' => $now + 1000,
        ]);

        UserPlanMigrator::run();

        $rows = UserPlan::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame(UserPlan::KIND_CYCLE, $row->kind->value);
        $this->assertSame([], $row->order_ids);
        $this->assertSame(1000, (int) $row->transfer_enable);
        $this->assertSame(300, (int) $row->u);
        $this->assertSame(200, (int) $row->d);
        $this->assertSame($now + 86400, (int) $row->expired_at);
        $this->assertSame($group->id, (int) $row->group_id);
        $this->assertNull($row->exhausted_at);
        // next 按主表现值照抄（创建时 observer 会按 plan 重算，迁移只保证与主表一致）
        $masterNext = User::find($user->id)->getRawOriginal('next_reset_at');
        $this->assertSame($masterNext !== null ? (int) $masterNext : null, $row->next_reset_at !== null ? (int) $row->next_reset_at : null);
        // 迁移不碰主表
        $user->refresh();
        $this->assertSame(1000, (int) $user->transfer_enable);
    }

    public function test_migrate_handles_dirty_data_and_is_idempotent(): void
    {
        [$group, $plan] = $this->seedPlan();
        $noPlan = $this->makeUser(['plan_id' => null, 'transfer_enable' => 500]);
        $zeroQuota = $this->makeUser(['plan_id' => $plan->id, 'transfer_enable' => 0]);
        $deletedPlan = $this->makeUser(['plan_id' => 99999, 'transfer_enable' => 500, 'group_id' => $group->id]);
        $ok = $this->makeUser(['plan_id' => $plan->id, 'transfer_enable' => 500]);

        UserPlanMigrator::run();

        $this->assertSame(0, UserPlan::where('user_id', $noPlan->id)->count());
        $this->assertSame(0, UserPlan::where('user_id', $zeroQuota->id)->count());
        $this->assertSame(1, UserPlan::where('user_id', $ok->id)->count());
        // 已删套餐仍建行，限速/设备置空
        $ghost = UserPlan::where('user_id', $deletedPlan->id)->sole();
        $this->assertNull($ghost->speed_limit);
        $this->assertNull($ghost->device_limit);
        $this->assertSame($group->id, (int) $ghost->group_id);

        // 可重跑：已有 cycle 行跳过，不翻倍
        UserPlanMigrator::run();
        $this->assertSame(1, UserPlan::where('user_id', $ok->id)->count());
        $this->assertSame(1, UserPlan::where('user_id', $deletedPlan->id)->count());
    }

    public function test_migrator_is_idempotent_on_rerun(): void
    {
        [$group, $plan] = $this->seedPlan();
        $user = $this->makeUser(['plan_id' => $plan->id, 'transfer_enable' => 500]);

        UserPlanMigrator::run();
        $this->assertSame(1, UserPlan::where('user_id', $user->id)->count());

        // 可重跑：已有 cycle 行跳过，不翻倍
        $stats = UserPlanMigrator::run();
        $this->assertSame(1, UserPlan::where('user_id', $user->id)->count());
        $this->assertSame(1, $stats['skipped_existing']);
        $this->assertSame(0, $stats['created']);
    }

    public function test_check_command_clean_and_drift(): void
    {
        [$group, $plan] = $this->seedPlan();
        $now = time();
        $user = $this->makeUser([
            'plan_id' => $plan->id, 'group_id' => $group->id,
            'transfer_enable' => 1000, 'u' => 100, 'd' => 50,
            'expired_at' => $now + 86400,
        ]);
        UserPlanMigrator::run();
        // 主表限速与实例快照对齐（迁移时 plan 限速为空，主表也为空才零差异）
        $this->artisan('fboard:check-user-plans')->assertSuccessful();

        // 主表被旁路改写 → 对账失败（只报不改）
        User::whereKey($user->id)->update(['transfer_enable' => 2000]);
        $this->artisan('fboard:check-user-plans')->assertFailed();
        $this->assertSame(1, UserPlan::where('user_id', $user->id)->count());
    }

    public function test_check_command_detects_duplicate_cycle_rows(): void
    {
        [$group, $plan] = $this->seedPlan();
        $user = $this->makeUser(['plan_id' => $plan->id, 'transfer_enable' => 500]);
        UserPlanMigrator::run();
        // 模拟流程 bug 造成的重复行
        $dup = UserPlan::where('user_id', $user->id)->first()->replicate();
        $dup->save();

        $this->artisan('fboard:check-user-plans')->assertFailed();
    }

    public function test_prune_only_old_exhausted_packs(): void
    {
        $user = $this->makeUser();
        $now = time();
        $old = $this->makeRow($user->id, ['kind' => UserPlan::KIND_PACK, 'transfer_enable' => 100, 'u' => 100, 'exhausted_at' => $now - 91 * 86400, 'expired_at' => $now - 80 * 86400]);
        $fresh = $this->makeRow($user->id, ['kind' => UserPlan::KIND_PACK, 'transfer_enable' => 100, 'u' => 100, 'exhausted_at' => $now - 89 * 86400]);
        // 退役不重置时钟：expired 刚被置 now，但 exhausted_at 还是 91 天前 → 照删
        $retired = $this->makeRow($user->id, ['kind' => UserPlan::KIND_PACK, 'transfer_enable' => 100, 'u' => 100, 'exhausted_at' => $now - 91 * 86400, 'expired_at' => $now]);
        $cycle = $this->makeRow($user->id, ['kind' => UserPlan::KIND_CYCLE, 'transfer_enable' => 100, 'u' => 100, 'exhausted_at' => $now - 200 * 86400, 'expired_at' => $now + 86400]);
        $alive = $this->makeRow($user->id, ['kind' => UserPlan::KIND_PACK, 'transfer_enable' => 100, 'u' => 10]);

        $this->artisan('fboard:prune-user-plans')->assertSuccessful();

        $this->assertNull(UserPlan::find($old->id));
        $this->assertNull(UserPlan::find($retired->id));
        $this->assertNotNull(UserPlan::find($fresh->id));
        $this->assertNotNull(UserPlan::find($cycle->id), 'cycle 行永不删');
        $this->assertNotNull(UserPlan::find($alive->id));
    }

    public function test_prune_stamps_missing_exhausted_at_first(): void
    {
        $user = $this->makeUser();
        $row = $this->makeRow($user->id, ['kind' => UserPlan::KIND_PACK, 'transfer_enable' => 100, 'u' => 100, 'exhausted_at' => null]);

        $this->artisan('fboard:prune-user-plans')->assertSuccessful();
        // 第一轮只补打时间戳，不删
        $this->assertNotNull(UserPlan::find($row->id));
        $this->assertNotNull(UserPlan::find($row->id)->exhausted_at);
    }

    public function test_master_columns_synced_through_full_flow(): void
    {
        [$group, $plan] = $this->seedPlan();
        $user = $this->makeUser([
            'plan_id' => $plan->id, 'group_id' => $group->id,
            'transfer_enable' => 1000, 'u' => 0, 'd' => 0,
            'expired_at' => time() + 86400,
        ]);
        UserPlanMigrator::run();

        // 购买续费 → 分摊流量 → 重置 → 超额判定，全程主表 9 列 == 实例聚合
        $plan->forceFill(['prices' => [Plan::PERIOD_MONTHLY => 1000]])->save();
        $order = OrderService::createFromRequest($user->refresh(), $plan, Plan::PERIOD_MONTHLY);
        (new OrderService($order))->paid('cb-frozen');

        Redis::shouldReceive('sadd')->once();
        (new TrafficFetchJob(['rate' => 1], [$user->id => [100, 100]], 'vmess', time()))->handle();

        app(TrafficResetService::class)->manualReset($user->refresh());

        Redis::shouldReceive('scard')->andReturn(0);
        $this->artisan('check:traffic-exceeded');

        $this->artisan('fboard:check-user-plans')->assertSuccessful();
    }

    public function test_gift_plan_card_writes_instance_not_master(): void
    {
        [$group, $plan] = $this->seedPlan();
        $user = $this->makeUser();
        $before = (int) $user->transfer_enable;

        $code = $this->seedGiftCode(['plan_id' => $plan->id, 'transfer_enable' => 777, 'plan_validity_days' => 7]);
        (new GiftCardService($code->code))->setUser($user->refresh())->redeem();

        $rows = UserPlan::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame($plan->id, (int) $row->plan_id);
        // plan 快照配额 + 礼包流量一次
        $this->assertSame(100 * 1073741824 + 777, (int) $row->transfer_enable);
        $this->assertGreaterThan(time(), (int) $row->expired_at);
        $user->refresh();
        $this->assertSame($before, (int) $user->transfer_enable);
    }

    public function test_gift_transfer_goes_to_primary_row(): void
    {
        [$group, $plan] = $this->seedPlan();
        $now = time();
        $user = $this->makeUser();
        $row = $this->makeRow($user->id, ['plan_id' => $plan->id, 'transfer_enable' => 1000, 'expired_at' => $now + 86400]);

        $code = $this->seedGiftCode(['transfer_enable' => 500]);
        (new GiftCardService($code->code))->setUser($user->refresh())->redeem();

        $this->assertSame(1500, (int) $row->refresh()->transfer_enable);
        $this->assertSame(0, (int) $user->refresh()->transfer_enable);
    }

    public function test_registration_seeds_first_row(): void
    {
        [$group, $plan] = $this->seedPlan();
        $service = app(UserService::class);
        $user = $service->createUser([
            'email' => Helper::guid() . '@example.com',
            'password' => 'password123',
            'plan_id' => $plan->id,
        ]);
        $user->save();
        $service->seedInitialPlanRow($user);

        $rows = UserPlan::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame([], $rows->first()->order_ids);
        $this->assertSame(100 * 1073741824, (int) $rows->first()->transfer_enable);
        // 重复调用不翻倍
        $service->seedInitialPlanRow($user->refresh());
        $this->assertSame(1, UserPlan::where('user_id', $user->id)->count());
    }

    /**
     * @return array{ServerGroup, Plan}
     */
    private function seedPlan(): array
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

        return [$group, $plan];
    }

    private function makeUser(array $overrides = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'email' => Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
        $user->save();

        return $user;
    }

    private function makeRow(int $userId, array $overrides = []): UserPlan
    {
        $row = new UserPlan();
        $row->forceFill(array_merge([
            'user_id' => $userId,
            'plan_id' => 1,
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

    private function seedGiftCode(array $rewards): GiftCardCode
    {
        $template = new GiftCardTemplate();
        $template->forceFill([
            'name' => 'gc-' . Helper::guid(),
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => true,
            'rewards' => $rewards,
            'conditions' => [],
            'limits' => [],
            'admin_id' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $template->save();

        $code = new GiftCardCode();
        $code->forceFill([
            'template_id' => $template->id,
            'code' => 'GC' . strtoupper(substr(md5(Helper::guid()), 0, 12)),
            'status' => GiftCardCode::STATUS_UNUSED,
            'usage_count' => 0,
            'max_usage' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $code->save();

        return $code;
    }
}

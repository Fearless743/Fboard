<?php

namespace Tests\Unit\Services;

use App\Jobs\TrafficFetchJob;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
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
 * 实例唯一数据源下：一致性检查、清理、礼包/注册实例路径、全流程主表只读聚合。
 */
class UserPlanMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'new_order_event_id' => 0,
            'renew_order_event_id' => 0,
            'change_order_event_id' => 0,
        ]);
    }

    public function test_check_command_passes_on_consistent_data(): void
    {
        [$group, $plan] = $this->seedPlan();
        $user = $this->makeUser();
        $this->makeRow($user->id, ['plan_id' => $plan->id, 'group_id' => $group->id]);

        $this->artisan('fboard:check-user-plans')->assertSuccessful();
    }

    public function test_check_command_detects_duplicate_cycle_rows(): void
    {
        [$group, $plan] = $this->seedPlan();
        $user = $this->makeUser();
        $row = $this->makeRow($user->id, ['plan_id' => $plan->id, 'group_id' => $group->id]);
        // 模拟流程 bug 造成的重复行
        $dup = $row->replicate();
        $dup->save();

        $this->artisan('fboard:check-user-plans')->assertFailed();
    }

    public function test_check_command_detects_pack_with_next_reset(): void
    {
        [$group, $plan] = $this->seedPlan();
        $user = $this->makeUser();
        $this->makeRow($user->id, [
            'plan_id' => $plan->id,
            'group_id' => $group->id,
            'kind' => UserPlan::KIND_PACK,
            'next_reset_at' => time() + 86400,
        ]);

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

    public function test_full_flow_keeps_master_columns_as_aggregate(): void
    {
        [$group, $plan] = $this->seedPlan();
        $user = $this->makeUser();
        $this->makeRow($user->id, [
            'plan_id' => $plan->id, 'group_id' => $group->id,
            'transfer_enable' => 1000, 'expired_at' => time() + 86400,
        ]);

        // 购买续费 → 分摊流量 → 重置，主表读值恒等于实例聚合。
        $plan->forceFill(['prices' => [Plan::PERIOD_MONTHLY => 1000]])->save();
        $order = OrderService::createFromRequest($user->refresh(), $plan, Plan::PERIOD_MONTHLY);
        (new OrderService($order))->paid('cb-flow');

        Redis::shouldReceive('sadd')->once();
        (new TrafficFetchJob(['rate' => 1], [$user->id => [100, 100]], 'vmess', time()))->handle();

        app(TrafficResetService::class)->manualReset($user->refresh());

        $agg = $user->refresh()->getPlanAggregate();
        $this->assertSame($agg['quota'], (int) $user->transfer_enable);
        $this->assertSame($agg['used'], (int) $user->u + (int) $user->d);
        $this->assertSame($agg['expired_at'], $user->expired_at);

        $this->artisan('fboard:check-user-plans')->assertSuccessful();
    }

    public function test_gift_plan_card_writes_instance(): void
    {
        [$group, $plan] = $this->seedPlan();
        $user = $this->makeUser();

        $code = $this->seedGiftCode(['plan_id' => $plan->id, 'transfer_enable' => 777, 'plan_validity_days' => 7]);
        (new GiftCardService($code->code))->setUser($user->refresh())->redeem();

        $rows = UserPlan::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame($plan->id, (int) $row->plan_id);
        // plan 快照配额 + 礼包流量一次
        $this->assertSame(100 * 1073741824 + 777, (int) $row->transfer_enable);
        $this->assertGreaterThan(time(), (int) $row->expired_at);
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
    }

    public function test_migrator_reads_raw_columns_not_accessor(): void
    {
        [$group, $plan] = $this->seedPlan();

        // 模拟迁移时刻：v2_user 仍有 9 列（平时已删除）。
        \Illuminate\Support\Facades\Schema::table('v2_user', function ($table) {
            $table->integer('plan_id')->nullable();
            $table->integer('group_id')->nullable();
            $table->bigInteger('transfer_enable')->default(0);
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->bigInteger('expired_at')->nullable();
            $table->integer('next_reset_at')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
        });
        $user = $this->makeUser();
        $now = time();
        \Illuminate\Support\Facades\DB::table('v2_user')->where('id', $user->id)->update([
            'plan_id' => $plan->id,
            'group_id' => $group->id,
            'transfer_enable' => 12345,
            'u' => 100,
            'd' => 200,
            'expired_at' => $now + 86400,
            'next_reset_at' => $now + 1000,
            'speed_limit' => 77,
            'device_limit' => 3,
        ]);

        $stats = UserPlanMigrator::run();
        $this->assertSame(1, $stats['created']);

        $row = UserPlan::where('user_id', $user->id)->sole();
        $this->assertSame($plan->id, (int) $row->plan_id);
        $this->assertSame($group->id, (int) $row->group_id);
        $this->assertSame(12345, (int) $row->transfer_enable);
        $this->assertSame(100, (int) $row->u);
        $this->assertSame(200, (int) $row->d);
        $this->assertSame($now + 86400, (int) $row->expired_at);
        $this->assertSame($now + 1000, (int) $row->next_reset_at);
        $this->assertSame(77, (int) $row->speed_limit);
        $this->assertSame(3, (int) $row->device_limit);
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

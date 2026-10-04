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
 * 多套餐 PR3：TrafficFetchJob 原子分摊 + exhausted_at 打点。
 */
class UserPlanAllocateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['multi_plan_enable' => 1]);
    }

    public function test_allocate_splits_across_rows_in_deduction_order(): void
    {
        [$user] = $this->seedBasics();
        $now = time();
        // row1 先到期，row2 后到期：默认最早到期优先
        $row1 = $this->makeRow($user->id, 1, 100, ['expired_at' => $now + 86400]);
        $row2 = $this->makeRow($user->id, 1, 100, ['expired_at' => $now + 30 * 86400]);

        Redis::shouldReceive('sadd')->once()->with('traffic:pending_check', $user->id);
        (new TrafficFetchJob(['rate' => 1], [$user->id => [60, 60]], 'vmess', time()))->handle();

        $row1->refresh();
        $row2->refresh();
        // u 优先：row1 吃满 100（u60+d40），row2 吃剩余 20（u0+d20）
        $this->assertSame(60, (int) $row1->u);
        $this->assertSame(40, (int) $row1->d);
        $this->assertSame(0, (int) $row2->u);
        $this->assertSame(20, (int) $row2->d);
        // 主表不动
        $user->refresh();
        $this->assertSame(0, (int) $user->u);
        $this->assertSame(0, (int) $user->d);
    }

    public function test_partial_fill_carry_over_without_overissue(): void
    {
        [$user] = $this->seedBasics();
        $now = time();
        // row1 只剩 10 容量，delta 30：row1 补 10，row2 吃 20，不多扣不少扣
        $row1 = $this->makeRow($user->id, 1, 100, ['u' => 70, 'd' => 20, 'expired_at' => $now + 86400]);
        $row2 = $this->makeRow($user->id, 1, 100, ['expired_at' => $now + 30 * 86400]);

        Redis::shouldReceive('sadd')->once();
        (new TrafficFetchJob(['rate' => 1], [$user->id => [15, 15]], 'vmess', time()))->handle();

        $row1->refresh();
        $row2->refresh();
        $this->assertSame(100, (int) $row1->u + (int) $row1->d);
        $this->assertSame(20, (int) $row2->u + (int) $row2->d);
        $this->assertNotNull($row1->refresh()->exhausted_at);
        $this->assertNull($row2->refresh()->exhausted_at);
    }

    public function test_exhausted_at_stamped_once(): void
    {
        [$user] = $this->seedBasics();
        $now = time();
        $row = $this->makeRow($user->id, 1, 100, ['expired_at' => $now + 86400]);

        Redis::shouldReceive('sadd')->twice();
        (new TrafficFetchJob(['rate' => 1], [$user->id => [60, 40]], 'vmess', time()))->handle();
        $first = (int) $row->refresh()->exhausted_at;
        $this->assertGreaterThan(0, $first);

        //  second delta：行已满，结转无处可去记负债，exhausted_at 不改写
        (new TrafficFetchJob(['rate' => 1], [$user->id => [10, 10]], 'vmess', $now + 5))->handle();
        $this->assertSame($first, (int) $row->refresh()->exhausted_at);
        $this->assertSame(120, (int) $row->refresh()->u + (int) $row->refresh()->d);
    }

    public function test_overflow_recorded_as_debt_on_last_row(): void
    {
        [$user] = $this->seedBasics();
        $now = time();
        $row1 = $this->makeRow($user->id, 1, 100, ['u' => 90, 'd' => 0, 'expired_at' => $now + 86400]);
        $row2 = $this->makeRow($user->id, 1, 100, ['u' => 90, 'd' => 0, 'expired_at' => $now + 30 * 86400]);

        Redis::shouldReceive('sadd')->once();
        // 剩余容量共 20，delta 50：全部行填满后 30 记到最后一行负债
        (new TrafficFetchJob(['rate' => 1], [$user->id => [50, 0]], 'vmess', time()))->handle();

        $this->assertSame(100, (int) $row1->refresh()->u);
        $this->assertSame(130, (int) $row2->refresh()->u);
        $this->assertNotNull($row2->refresh()->exhausted_at);
        // 聚合剩余为 0（负债不转成负数剩余额度）
        $agg = $user->refresh()->load('userPlans')->getPlanAggregate($now);
        $this->assertSame(0, $agg['remaining']);
        $this->assertSame(230, $agg['used']);
    }

    public function test_expired_rows_excluded_from_allocation(): void
    {
        [$user] = $this->seedBasics();
        $now = time();
        $expired = $this->makeRow($user->id, 1, 100, ['expired_at' => $now - 10]);
        $active = $this->makeRow($user->id, 1, 100, ['expired_at' => $now + 86400]);

        Redis::shouldReceive('sadd')->once();
        (new TrafficFetchJob(['rate' => 1], [$user->id => [10, 10]], 'vmess', time()))->handle();

        $this->assertSame(0, (int) $expired->refresh()->u + (int) $expired->refresh()->d);
        $this->assertSame(20, (int) $active->refresh()->u + (int) $active->refresh()->d);
    }

    public function test_report_ts_guard_skips_stale_report(): void
    {
        [$user] = $this->seedBasics();
        $now = time();
        $row = $this->makeRow($user->id, 1, 100, ['expired_at' => $now + 86400]);
        // 实例重置同步刷新了 user.last_reset_at（见 PR4 约定）
        $user->forceFill(['last_reset_at' => $now])->save();

        Redis::shouldReceive('sadd')->never();
        (new TrafficFetchJob(['rate' => 1], [$user->id => [10, 10]], 'vmess', time(), $now - 100))->handle();

        $this->assertSame(0, (int) $row->refresh()->u + (int) $row->refresh()->d);
    }

    public function test_rate_and_negative_normalization_preserved(): void
    {
        [$user] = $this->seedBasics();
        $now = time();
        $row = $this->makeRow($user->id, 1, 100, ['expired_at' => $now + 86400]);

        // rate<=0 归一为 1；负增量钳 0
        Redis::shouldReceive('sadd')->once()->with('traffic:pending_check', $user->id);
        (new TrafficFetchJob(['rate' => 0], [$user->id => [10, -5]], 'vmess', time()))->handle();
        $this->assertSame(10, (int) $row->refresh()->u);
        $this->assertSame(0, (int) $row->refresh()->d);
    }

    public function test_custom_sort_order_takes_priority(): void
    {
        [$user] = $this->seedBasics();
        $now = time();
        // row2 到期更晚但 sort_order=1，应先扣
        $row1 = $this->makeRow($user->id, 1, 100, ['expired_at' => $now + 86400]);
        $row2 = $this->makeRow($user->id, 1, 100, ['expired_at' => $now + 30 * 86400, 'sort_order' => 1]);

        Redis::shouldReceive('sadd')->once();
        (new TrafficFetchJob(['rate' => 1], [$user->id => [50, 0]], 'vmess', time()))->handle();

        $this->assertSame(0, (int) $row1->refresh()->u);
        $this->assertSame(50, (int) $row2->refresh()->u);
    }

    public function test_user_without_rows_gets_nothing_and_not_queued(): void
    {
        [$user] = $this->seedBasics();

        Redis::shouldReceive('sadd')->never();
        (new TrafficFetchJob(['rate' => 1], [$user->id => [10, 10]], 'vmess', time()))->handle();

        $user->refresh();
        $this->assertSame(0, (int) $user->u);
        $this->assertSame(0, (int) $user->d);
    }

    /**
     * @return array{User}
     */
    private function seedBasics(): array
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => 'g', 'created_at' => time(), 'updated_at' => time()]);
        $group->save();

        $user = new User();
        $user->forceFill([
            'email' => Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        return [$user];
    }

    private function makeRow(int $userId, int $planId, int $quota, array $overrides = []): UserPlan
    {
        $row = new UserPlan();
        $row->forceFill(array_merge([
            'user_id' => $userId,
            'plan_id' => $planId,
            'kind' => UserPlan::KIND_CYCLE,
            'group_id' => 1,
            'order_ids' => [],
            'transfer_enable' => $quota,
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

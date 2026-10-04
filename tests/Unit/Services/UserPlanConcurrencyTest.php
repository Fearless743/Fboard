<?php

namespace Tests\Unit\Services;

use App\Jobs\TrafficFetchJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 多套餐并发：cycle 行不断行、pack 分摊不超发不丢。
 * SQLite 串行执行，断言语义正确性（锁语义由代码结构保证：用户行锁+实例行锁）。
 */
class UserPlanConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'multi_plan_enable' => 1,
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'new_order_event_id' => 0,
            'renew_order_event_id' => 0,
            'change_order_event_id' => 0,
        ]);
    }

    public function test_concurrent_cycle_orders_reuse_single_row(): void
    {
        [$user, $plan] = $this->seedBasics();

        // 两笔同 plan 订单先后开通：永不盲建，仍是同一行，配额累加，订单都追加
        // （单用户同一时刻只允许一笔未完成单：第一笔先开通完成再下第二笔）
        $o1 = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
        (new OrderService($o1))->paid('cb-1');
        $o2 = OrderService::createFromRequest($user->refresh(), $plan, Plan::PERIOD_MONTHLY);
        (new OrderService(Order::find($o2->id)))->paid('cb-2');

        $rows = UserPlan::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame(20 * 1073741824, (int) $row->transfer_enable);
        $this->assertEqualsCanonicalizing([$o1->id, $o2->id], $row->order_ids);
    }

    public function test_split_deltas_never_overissue_nor_lose(): void
    {
        [$user] = $this->seedBasics();
        $now = time();
        $row1 = $this->makeRow($user->id, ['transfer_enable' => 100, 'expired_at' => $now + 86400]);
        $row2 = $this->makeRow($user->id, ['transfer_enable' => 100, 'expired_at' => $now + 30 * 86400]);

        Redis::shouldReceive('sadd')->times(5);
        // 5 个 Job 各报 30：总量 150 <= 配额 200，不得超发（总和恒等），不得丢失
        for ($i = 0; $i < 5; $i++) {
            (new TrafficFetchJob(['rate' => 1], [$user->id => [20, 10]], 'vmess', time()))->handle();
        }

        $total = (int) $row1->refresh()->u + (int) $row1->refresh()->d
            + (int) $row2->refresh()->u + (int) $row2->refresh()->d;
        $this->assertSame(150, $total);
        // row1 先满 100，row2 吃 50
        $this->assertSame(100, (int) $row1->refresh()->u + (int) $row1->refresh()->d);
        $this->assertSame(50, (int) $row2->refresh()->u + (int) $row2->refresh()->d);
    }

    public function test_exhausted_at_never_rewritten(): void
    {
        [$user] = $this->seedBasics();
        $now = time();
        $row = $this->makeRow($user->id, ['transfer_enable' => 100, 'expired_at' => $now + 86400]);

        Redis::shouldReceive('sadd')->twice();
        (new TrafficFetchJob(['rate' => 1], [$user->id => [100, 0]], 'vmess', time()))->handle();
        $first = (int) $row->refresh()->exhausted_at;
        $this->assertGreaterThan(0, $first);

        sleep(1);
        (new TrafficFetchJob(['rate' => 1], [$user->id => [50, 0]], 'vmess', time()))->handle();
        $this->assertSame($first, (int) $row->refresh()->exhausted_at);
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
            'transfer_enable' => 10,
            'name' => 'p',
            'show' => true, 'sell' => true, 'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => 1000],
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $plan->save();

        $user = new User();
        $user->forceFill([
            'email' => Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $user->save();

        return [$user, $plan];
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
            'u' => 0, 'd' => 0,
            'sort_order' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ], $overrides));
        $row->save();

        return $row;
    }
}

<?php

namespace Tests\Unit\Jobs;

use App\Jobs\TrafficFetchJob;
use App\Models\User;
use App\Models\UserPlan;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 流量重置边界：重置之前收到的 report，即使在重置之后才被队列消化，
 * 也不能再累加到用户额度上。
 *
 * 背景：traffic_fetch 队列积压时，performReset() 把 u/d 清零后，
 * 之前入队的 Job 才被处理，于是 u/d 瞬间弹回超额——用户表现为
 * 「刚买的套餐几分钟就没了」。
 */
class TrafficFetchJobResetBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attrs = []): array
    {
        $user = new User();
        $user->forceFill(array_merge([
            'email' => 'tfb-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 10_000_000_000,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        $user->save();

        // 实例表是唯一数据源：给用户一行可用实例，断言读实例行。
        $row = new UserPlan();
        $row->forceFill([
            'user_id' => $user->id,
            'plan_id' => 0,
            'kind' => UserPlan::KIND_CYCLE,
            'group_id' => 0,
            'order_ids' => [],
            'transfer_enable' => 10_000_000_000,
            'u' => 0,
            'd' => 0,
            'expired_at' => null,
            'sort_order' => 0,
        ]);
        $row->save();

        return [$user, $row];
    }

    public function test_report_before_reset_is_not_charged_to_new_cycle(): void
    {
        // 该用户从未重置过（last_reset_at = 0）→ 不做边界判断
        [$user, $row] = $this->makeUser();

        Redis::shouldReceive('sadd')->never();

        $resetAt = time();
        $user->forceFill(['last_reset_at' => $resetAt])->save();

        // report 在重置之前 5 分钟收到，但直到现在才被队列消化
        $job = new TrafficFetchJob(
            ['rate' => 1.0, 'id' => 1],
            [(string) $user->id => [1000, 2000]],
            'vmess',
            time(),
            $resetAt - 300
        );
        $job->handle();

        $row->refresh();
        $this->assertSame(0, (int) $row->u);
        $this->assertSame(0, (int) $row->d, '重置前产生的流量不应计入重置后的额度');
    }

    public function test_report_after_reset_is_applied(): void
    {
        [$user, $row] = $this->makeUser();
        $resetAt = time();

        $user->forceFill(['last_reset_at' => $resetAt])->save();

        Redis::shouldReceive('sadd')->once()->andReturn(true);

        $job = new TrafficFetchJob(
            ['rate' => 1.0, 'id' => 1],
            [(string) $user->id => [1000, 2000]],
            'vmess',
            time(),
            $resetAt + 30
        );
        $job->handle();

        $row->refresh();
        $this->assertSame(1000, (int) $row->u);
        $this->assertSame(2000, (int) $row->d);
    }

    public function test_user_without_reset_history_is_never_skipped(): void
    {
        [$user, $row] = $this->makeUser(['last_reset_at' => 0]);

        Redis::shouldReceive('sadd')->once()->andReturn(true);

        $job = new TrafficFetchJob(
            ['rate' => 1.0, 'id' => 1],
            [(string) $user->id => [500, 700]],
            'vmess',
            time(),
            time() - 86_400
        );
        $job->handle();

        $row->refresh();
        $this->assertSame(500, (int) $row->u);
        $this->assertSame(700, (int) $row->d);
    }

    public function test_non_positive_rate_falls_back_to_one(): void
    {
        [$user, $row] = $this->makeUser();

        Redis::shouldReceive('sadd')->once()->andReturn(true);

        $job = new TrafficFetchJob(
            ['rate' => 0, 'id' => 1],
            [(string) $user->id => [1000, 2000]],
            'vmess',
            time()
        );
        $job->handle();

        $row->refresh();
        $this->assertSame(1000, (int) $row->u, 'rate=0 不应把流量记成 0');
        $this->assertSame(2000, (int) $row->d);
    }
}
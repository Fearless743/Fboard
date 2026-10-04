<?php

namespace Tests\Unit\Services;

use App\Jobs\TrafficFetchJob;
use App\Models\User;
use App\Models\UserPlan;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 即便 Tidalab 等旧入口未过滤负增量，TrafficFetchJob 也必须钳为 0，禁止流量回退。
 */
class TrafficFetchJobNegativeTest extends TestCase
{
    use RefreshDatabase;

    public function test_negative_increments_do_not_reduce_user_traffic(): void
    {
        // 负增量钳为 0 后该用户没有任何流量增量，不应再进入超额检查队列。
        Redis::shouldReceive('sadd')->never();
        $user = new User();
        $user->forceFill([
            'email' => 'traf-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        // 实例表是唯一数据源：用量断言读实例行。
        $row = new UserPlan();
        $row->forceFill([
            'user_id' => $user->id,
            'plan_id' => 1,
            'kind' => UserPlan::KIND_CYCLE,
            'group_id' => 1,
            'order_ids' => [],
            'transfer_enable' => 10_000_000_000,
            'u' => 1_000_000,
            'd' => 2_000_000,
            'expired_at' => null,
            'sort_order' => 0,
        ]);
        $row->save();

        $job = new TrafficFetchJob(
            ['rate' => 1],
            [$user->id => [-500000, -800000]],
            'shadowsocks',
            time()
        );
        $job->handle();

        $row->refresh();
        $this->assertSame(1_000_000, (int) $row->u, '负 u 不得减少上行');
        $this->assertSame(2_000_000, (int) $row->d, '负 d 不得减少下行');
    }
}

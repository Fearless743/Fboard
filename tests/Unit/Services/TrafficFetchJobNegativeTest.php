<?php

namespace Tests\Unit\Services;

use App\Jobs\TrafficFetchJob;
use App\Models\User;
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
        Redis::shouldReceive('sadd')->once()->andReturn(true);
        $user = new User();
        $user->forceFill([
            'email' => 'traf-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'u' => 1_000_000,
            'd' => 2_000_000,
            'transfer_enable' => 10_000_000_000,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $job = new TrafficFetchJob(
            ['rate' => 1],
            [$user->id => [-500000, -800000]],
            'shadowsocks',
            time()
        );
        $job->handle();

        $user->refresh();
        $this->assertSame(1_000_000, (int) $user->u, '负 u 不得减少上行');
        $this->assertSame(2_000_000, (int) $user->d, '负 d 不得减少下行');
    }
}

<?php

namespace Tests\Unit\Jobs;

use App\Jobs\TrafficFetchJob;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 节点 rate 为小数时，流量增量必须写成整数字节，不得把 float 直接写入 u/d。
 */
class TrafficFetchJobRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_fractional_rate_writes_integer_bytes(): void
    {
        Redis::shouldReceive('sadd')->once()->andReturn(true);
        $user = new User();
        $user->forceFill([
            'email' => 'tf-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 10_000_000,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $job = new TrafficFetchJob(
            ['rate' => 1.5, 'id' => 1],
            [(string) $user->id => [100, 200]],
            'vmess',
            time()
        );
        $job->handle();

        $user->refresh();
        $this->assertSame(150, (int) $user->u);
        $this->assertSame(300, (int) $user->d);
    }
}

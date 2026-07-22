<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Plugin\CoreProtocols\ProtocolTypes;
use Tests\TestCase;

class ServerTrafficProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_traffic_rejects_negative_increments(): void
    {
        Queue::fake();

        $user = new User();
        $user->forceFill([
            'email' => 't-' . Helper::guid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'u' => 1_000_000,
            'd' => 2_000_000,
            'transfer_enable' => 10_000_000,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $node = Server::create([
            'name' => 'traffic-node',
            'type' => ProtocolTypes::VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'enabled' => true,
        ]);

        $beforeU = (int) $user->u;
        $beforeD = (int) $user->d;

        ServerService::processTraffic($node, [
            (string) $user->id => [-1000, 0],
            (string) ($user->id + 999) => [10, -5],
        ]);

        // 负增量应被过滤，不得写入用户流量 / 不得投递 TrafficFetchJob。
        // Server::create 可能触发无关的 NodeUserSyncJob，故只断言流量相关 job。
        $user->refresh();
        $this->assertSame($beforeU, (int) $user->u, '负上传增量不得扣减用户流量');
        $this->assertSame($beforeD, (int) $user->d, '负下载增量不得扣减用户流量');
        Queue::assertNotPushed(\App\Jobs\TrafficFetchJob::class);
        Queue::assertNotPushed(\App\Jobs\StatUserJob::class);
        Queue::assertNotPushed(\App\Jobs\StatServerJob::class);
    }

    public function test_process_traffic_accepts_non_negative_increments(): void
    {
        Queue::fake();

        $user = new User();
        $user->forceFill([
            'email' => 't2-' . Helper::guid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 10_000_000,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $node = Server::create([
            'name' => 'traffic-node-2',
            'type' => ProtocolTypes::VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'enabled' => true,
        ]);

        ServerService::processTraffic($node, [
            (string) $user->id => [100, 200],
        ]);

        Queue::assertPushed(\App\Jobs\TrafficFetchJob::class);
    }
}

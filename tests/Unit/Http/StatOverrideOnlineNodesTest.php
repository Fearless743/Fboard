<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\StatController;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\StatServer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StatisticalService;
use App\Utils\CacheKeyResolver;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Plugin\CoreProtocols\ProtocolTypes;
use Tests\TestCase;

/**
 * FEARLESS-18 回归测试：StatController::getOverride 优化前后行为一致。
 */
class StatOverrideOnlineNodesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CacheKeyResolver::flush();
        Cache::flush();
    }

    public function test_online_nodes_count_matches_cache_heartbeat(): void
    {
        $online = $this->makeServer(type: ProtocolTypes::VMESS);
        $offline = $this->makeServer(type: ProtocolTypes::TROJAN);

        $type = strtoupper($online->type);
        $now = time();
        // 在线节点：心跳在 CHECK_INTERVAL 窗口内
        Cache::put("SERVER_{$type}_LAST_CHECK_AT_{$online->id}", $now, 3600);
        // 离线节点无心跳缓存

        $controller = new StatController(app(StatisticalService::class));
        $result = $controller->getOverride(new Request());

        $this->assertSame(1, $result['data']['online_nodes']);
    }

    public function test_online_nodes_count_when_all_offline(): void
    {
        $this->makeServer(type: ProtocolTypes::VMESS);

        $controller = new StatController(app(StatisticalService::class));
        $result = $controller->getOverride(new Request());

        $this->assertSame(0, $result['data']['online_nodes']);
    }

    public function test_override_returns_full_structure(): void
    {
        $this->makeServer(type: ProtocolTypes::VMESS);
        $this->seedAuxData();

        $controller = new StatController(app(StatisticalService::class));
        $result = $controller->getOverride(new Request());

        $data = $result['data'];
        $this->assertArrayHasKey('online_nodes', $data);
        $this->assertArrayHasKey('online_devices', $data);
        $this->assertArrayHasKey('online_users', $data);
        $this->assertArrayHasKey('today_traffic', $data);
        $this->assertArrayHasKey('month_traffic', $data);
        $this->assertArrayHasKey('total_traffic', $data);
        $this->assertArrayHasKey('month_income', $data);
        $this->assertArrayHasKey('ticket_pending_total', $data);
        $this->assertArrayHasKey('commission_pending_total', $data);
    }

    private function seedAuxData(): void
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
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        $user = new User();
        $user->forceFill([
            'email' => 'so-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $order = new Order();
        $order->forceFill([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Helper::generateOrderNo(),
            'total_amount' => 1000,
            'status' => Order::STATUS_COMPLETED,
            'commission_status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        $ticket = new Ticket();
        $ticket->forceFill([
            'user_id' => $user->id,
            'subject' => 'pending ticket',
            'level' => 0,
            'status' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ticket->save();

        $log = new CommissionLog();
        $log->forceFill([
            'invite_user_id' => $user->id,
            'user_id' => $user->id,
            'trade_no' => Helper::generateOrderNo(),
            'order_amount' => 1000,
            'get_amount' => 100,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $log->save();

        $stat = new StatServer();
        $stat->forceFill([
            'server_id' => 1,
            'server_type' => 'vless',
            'record_at' => time(),
            'record_type' => 'd',
            'u' => 100,
            'd' => 200,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $stat->save();
    }

    private function makeServer(string $type): Server
    {
        return Server::create([
            'name' => 'stat-node',
            'type' => $type,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'enabled' => true,
        ]);
    }
}

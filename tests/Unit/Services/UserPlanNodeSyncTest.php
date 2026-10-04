<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Plugin\CoreProtocols\ProtocolTypes;
use Tests\TestCase;

/**
 * 多套餐 PR5：超额检查 + 节点拆分下发 + 去重验证。
 */
class UserPlanNodeSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['multi_plan_enable' => 1]);
    }

    public function test_get_available_users_multi_membership_and_availability(): void
    {
        [$g1, $g2, $g3] = $this->seedGroups();
        $node = $this->makeServer([$g1->id]);
        $now = time();

        // g1+g2 双持用户：出现一次
        $multi = $this->makeUser();
        $this->makeRow($multi->id, $g1->id, ['transfer_enable' => 100, 'u' => 10, 'expired_at' => $now + 86400]);
        $this->makeRow($multi->id, $g2->id, ['transfer_enable' => 100, 'u' => 10, 'expired_at' => $now + 86400]);
        // 全局耗尽用户：排除（部分过期/耗尽不影响其余，但全耗尽则不可用）
        $empty = $this->makeUser();
        $this->makeRow($empty->id, $g1->id, ['transfer_enable' => 100, 'u' => 100, 'expired_at' => $now + 86400]);
        // 仅过期行用户：排除
        $expired = $this->makeUser();
        $this->makeRow($expired->id, $g1->id, ['transfer_enable' => 100, 'expired_at' => $now - 10]);
        // 仅他组用户：排除
        $other = $this->makeUser();
        $this->makeRow($other->id, $g3->id, ['transfer_enable' => 100, 'expired_at' => $now + 86400]);
        // 部分过期不影响其余：过期 A + 有效 B，应包含
        $partial = $this->makeUser();
        $this->makeRow($partial->id, $g1->id, ['transfer_enable' => 100, 'u' => 100, 'expired_at' => $now - 10]);
        $this->makeRow($partial->id, $g1->id, ['transfer_enable' => 100, 'u' => 10, 'expired_at' => $now + 86400]);

        $users = ServerService::getAvailableUsers($node->fresh());
        $ids = $users->pluck('id')->all();

        $this->assertContains($multi->id, $ids);
        $this->assertContains($partial->id, $ids);
        $this->assertNotContains($empty->id, $ids);
        $this->assertNotContains($expired->id, $ids);
        $this->assertNotContains($other->id, $ids);
        // 同一用户只出现一次
        $this->assertSame(1, collect($ids)->filter(fn ($id) => $id === $multi->id)->count());
        // 限速/设备取有效值 max
        $row = $users->firstWhere('id', $multi->id);
        $this->assertSame($multi->uuid, $row->uuid);
    }

    public function test_get_available_servers_multi_merges_groups_deduped(): void
    {
        [$g1, $g2] = $this->seedGroups();
        $s1 = $this->makeServer([$g1->id]);
        $s2 = $this->makeServer([$g2->id]);
        $s3 = $this->makeServer([$g1->id, $g2->id]);
        $now = time();

        $user = $this->makeUser();
        $this->makeRow($user->id, $g1->id, ['expired_at' => $now + 86400]);
        $this->makeRow($user->id, $g2->id, ['expired_at' => $now + 86400]);

        $servers = ServerService::getAvailableServers($user);
        $ids = collect($servers)->pluck('id')->all();

        $this->assertContains($s1->id, $ids);
        $this->assertContains($s2->id, $ids);
        $this->assertContains($s3->id, $ids);
        // 同节点多组命中只保留一份
        $this->assertSame(count($ids), count(array_unique($ids)));

        $noPlan = $this->makeUser();
        $this->assertSame([], ServerService::getAvailableServers($noPlan));
    }

    public function test_check_exceeded_multi_pushes_by_group_and_keeps_offline_queued(): void
    {
        [$g1, $g2] = $this->seedGroups();
        $online = $this->makeServer([$g1->id]);
        $offline = $this->makeServer([$g2->id]);
        Cache::put("node_ws_alive:{$online->id}", true);
        $now = time();

        // g1 组耗尽用户（在线节点覆盖）+ g2 组耗尽用户（节点离线）+ 未耗尽用户
        $hit = $this->makeUser();
        $this->makeRow($hit->id, $g1->id, ['transfer_enable' => 100, 'u' => 100, 'expired_at' => $now + 86400]);
        $down = $this->makeUser();
        $this->makeRow($down->id, $g2->id, ['transfer_enable' => 100, 'u' => 100, 'expired_at' => $now + 86400]);
        $ok = $this->makeUser();
        $this->makeRow($ok->id, $g1->id, ['transfer_enable' => 100, 'u' => 10, 'expired_at' => $now + 86400]);

        $pending = [$hit->id, $down->id, $ok->id];
        Redis::shouldReceive('scard')->once()->with('traffic:pending_check')->andReturn(count($pending));
        Redis::shouldReceive('smembers')->once()->with('traffic:pending_check')->andReturn($pending);
        $published = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) use (&$published) {
            $published[] = json_decode($payload, true);
            return 1;
        });
        // 未超额的 ok 出队；送达的 hit 出队；离线的 down 留队列
        Redis::shouldReceive('srem')->once()->with('traffic:pending_check', $ok->id, $hit->id);

        $this->artisan('check:traffic-exceeded')->assertSuccessful();

        $this->assertCount(1, $published);
        $this->assertSame($online->id, $published[0]['node_id']);
        $this->assertSame('sync.user.delta', $published[0]['event']);
        $this->assertSame('remove', $published[0]['data']['action']);
        $this->assertSame([['id' => $hit->id]], $published[0]['data']['users']);
    }

    public function test_notify_user_changed_multi_splits_copies_by_group(): void
    {
        [$g1, $g2, $g3] = $this->seedGroups();
        $both = $this->makeServer([$g1->id, $g2->id]);
        $second = $this->makeServer([$g2->id]);
        $other = $this->makeServer([$g3->id]);
        foreach ([$both, $second, $other] as $s) {
            Cache::put("node_ws_alive:{$s->id}", true);
        }
        $now = time();

        $user = $this->makeUser();
        $this->makeRow($user->id, $g1->id, [
            'transfer_enable' => 100, 'u' => 10,
            'speed_limit' => 50, 'expired_at' => $now + 86400,
        ]);
        $this->makeRow($user->id, $g2->id, [
            'transfer_enable' => 100, 'u' => 10,
            'speed_limit' => 200, 'expired_at' => $now + 86400,
        ]);

        $published = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) use (&$published) {
            $published[] = json_decode($payload, true);
            return 1;
        });

        NodeSyncService::notifyUserChanged($user);

        $byNode = [];
        foreach ($published as $p) {
            $byNode[$p['node_id']][] = $p;
        }

        // 覆盖双组的节点收到两份（仅 group_id 不同）；单组节点收到一份；无关节点收不到
        $this->assertCount(1, $byNode[$both->id]);
        $copies = $byNode[$both->id][0]['data']['users'];
        $this->assertSame('add', $byNode[$both->id][0]['data']['action']);
        $this->assertCount(2, $copies);
        $this->assertEqualsCanonicalizing([$g1->id, $g2->id], array_column($copies, 'group_id'));
        // 限速取有效值 max
        $this->assertSame(200, $copies[0]['speed_limit']);

        $this->assertCount(1, $byNode[$second->id]);
        $this->assertCount(1, $byNode[$second->id][0]['data']['users']);
        $this->assertSame($g2->id, $byNode[$second->id][0]['data']['users'][0]['group_id']);
        $this->assertArrayNotHasKey($other->id, $byNode);
    }

    public function test_notify_user_changed_multi_removes_when_nothing_left(): void
    {
        [$g1] = $this->seedGroups();
        $server = $this->makeServer([$g1->id]);
        Cache::put("node_ws_alive:{$server->id}", true);
        $now = time();

        $user = $this->makeUser();
        $this->makeRow($user->id, $g1->id, ['transfer_enable' => 100, 'u' => 100, 'expired_at' => $now + 86400]);

        $published = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) use (&$published) {
            $published[] = json_decode($payload, true);
            return 1;
        });

        NodeSyncService::notifyUserChanged($user);

        $this->assertCount(1, $published);
        $this->assertSame('remove', $published[0]['data']['action']);
        $this->assertSame([['id' => $user->id]], $published[0]['data']['users']);
    }

    public function test_model_active_and_available_semantics(): void
    {
        $now = time();
        [$g1] = $this->seedGroups();

        $active = $this->makeUser();
        $this->assertFalse($active->isActive());
        $this->assertFalse($active->isAvailable());

        $this->makeRow($active->id, $g1->id, ['transfer_enable' => 100, 'u' => 10, 'expired_at' => $now + 86400]);
        $this->assertTrue($active->refresh()->isActive());
        $this->assertTrue($active->isAvailable());

        // 耗尽：仍活跃但不可用
        UserPlan::where('user_id', $active->id)->update(['u' => 100]);
        $this->assertTrue($active->refresh()->isActive());
        $this->assertFalse($active->isAvailable());

        // 仅过期：不活跃也不可用
        UserPlan::where('user_id', $active->id)->update(['expired_at' => $now - 10]);
        $this->assertFalse($active->refresh()->isActive());
        $this->assertFalse($active->isAvailable());

        // 封禁：全否
        $banned = $this->makeUser(['banned' => 1]);
        $this->makeRow($banned->id, $g1->id, ['transfer_enable' => 100, 'expired_at' => $now + 86400]);
        $this->assertFalse($banned->refresh()->isActive());
    }

    /**
     * @return array{ServerGroup, ServerGroup, ServerGroup}
     */
    private function seedGroups(): array
    {
        $mk = function (string $name): ServerGroup {
            $g = new ServerGroup();
            $g->forceFill(['name' => $name, 'created_at' => time(), 'updated_at' => time()]);
            $g->save();

            return $g;
        };

        return [$mk('g1'), $mk('g2'), $mk('g3')];
    }

    private function makeServer(array $groupIds): Server
    {
        return Server::create([
            'name' => 'n-' . Helper::guid(),
            'type' => ProtocolTypes::VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => $groupIds,
            'show' => true,
            'enabled' => true,
        ]);
    }

    private function makeUser(array $overrides = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'email' => Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'banned' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
        $user->save();

        return $user;
    }

    private function makeRow(int $userId, int $groupId, array $overrides = []): UserPlan
    {
        $row = new UserPlan();
        $row->forceFill(array_merge([
            'user_id' => $userId,
            'plan_id' => 1,
            'kind' => UserPlan::KIND_CYCLE,
            'group_id' => $groupId,
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
}

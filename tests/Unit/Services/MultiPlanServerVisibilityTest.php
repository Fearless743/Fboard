<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugin\CoreProtocols\ProtocolTypes;
use Tests\TestCase;

/**
 * 多套餐模式：节点可见性 = 所有活跃套餐实例权限组并集；
 * 节点下发用户列表按 per-instance 过滤（部分套餐过期/超额仍可见其余）。
 */
class MultiPlanServerVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(string $name): ServerGroup
    {
        $g = new ServerGroup();
        $g->forceFill(['name' => $name, 'created_at' => time(), 'updated_at' => time()]);
        $g->save();
        return $g;
    }

    private function makeServer(array $groupIds): Server
    {
        return Server::create([
            'name' => 'node-' . substr(Helper::guid(), 0, 6),
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

    private function makePlan(ServerGroup $group): Plan
    {
        $p = new Plan();
        $p->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'p-' . substr(Helper::guid(), 0, 6),
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => 1000],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $p->save();
        return $p;
    }

    private function makeUser(string $uuid): User
    {
        $user = new User();
        $user->forceFill([
            'email' => $uuid . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => $uuid,
            'token' => Helper::guid(true),
            'transfer_enable' => 1024 * 1024 * 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'expired_at' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();
        return $user;
    }

    private function makeInstance(User $user, Plan $plan, array $overrides = []): UserPlan
    {
        return UserPlan::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => 1024 * 1024 * 1024,
            'u' => 0,
            'd' => 0,
            'expired_at' => time() + 86400,
            'source' => UserPlan::SOURCE_ORDER,
        ], $overrides));
    }

    public function test_user_sees_union_of_plan_groups(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $g1 = $this->makeGroup('g1');
        $g2 = $this->makeGroup('g2');
        $server1 = $this->makeServer([$g1->id]);
        $server2 = $this->makeServer([$g2->id]);

        $user = $this->makeUser('union-user');
        $this->makeInstance($user, $this->makePlan($g1));
        $this->makeInstance($user, $this->makePlan($g2));

        $servers = ServerService::getAvailableServers($user);
        $ids = collect($servers)->pluck('id')->all();

        $this->assertContains($server1->id, $ids);
        $this->assertContains($server2->id, $ids);
    }

    public function test_node_lists_user_with_active_instance_in_its_group(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $g1 = $this->makeGroup('g1');
        $g2 = $this->makeGroup('g2');
        $server1 = $this->makeServer([$g1->id]);
        $server2 = $this->makeServer([$g2->id]);

        $user = $this->makeUser('multi-group-user');
        $this->makeInstance($user, $this->makePlan($g1));
        $this->makeInstance($user, $this->makePlan($g2));

        $this->assertTrue(
            ServerService::getAvailableUsers($server1->fresh())->contains(fn($u) => $u->uuid === 'multi-group-user')
        );
        $this->assertTrue(
            ServerService::getAvailableUsers($server2->fresh())->contains(fn($u) => $u->uuid === 'multi-group-user')
        );
    }

    public function test_expired_instance_removes_user_from_that_group_only(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $g1 = $this->makeGroup('g1');
        $g2 = $this->makeGroup('g2');
        $server1 = $this->makeServer([$g1->id]);
        $server2 = $this->makeServer([$g2->id]);

        $user = $this->makeUser('partial-expired-user');
        // g1 套餐已过期
        $this->makeInstance($user, $this->makePlan($g1), ['expired_at' => time() - 100]);
        // g2 套餐仍活跃
        $this->makeInstance($user, $this->makePlan($g2), ['expired_at' => time() + 86400]);

        // user 缓存列需先同步（u+d<transfer_enable 兜底条件）
        \App\Services\UserPlanService::syncUserAggregate($user->id);

        $this->assertFalse(
            ServerService::getAvailableUsers($server1->fresh())->contains(fn($u) => $u->uuid === 'partial-expired-user'),
            'g1 套餐已过期，不应出现在 g1 节点'
        );
        $this->assertTrue(
            ServerService::getAvailableUsers($server2->fresh())->contains(fn($u) => $u->uuid === 'partial-expired-user'),
            'g2 套餐活跃，应出现在 g2 节点'
        );
    }

    public function test_exhausted_instance_removes_user_from_that_group(): void
    {
        admin_setting(['multi_plan_enable' => 1]);
        $g1 = $this->makeGroup('g1');
        $g2 = $this->makeGroup('g2');
        $server1 = $this->makeServer([$g1->id]);
        $server2 = $this->makeServer([$g2->id]);

        $user = $this->makeUser('partial-exhausted-user');
        // g1 套餐流量耗尽
        $this->makeInstance($user, $this->makePlan($g1), ['transfer_enable' => 100, 'u' => 60, 'd' => 60]);
        // g2 套餐流量充足
        $this->makeInstance($user, $this->makePlan($g2), ['transfer_enable' => 1000, 'u' => 0, 'd' => 0]);

        \App\Services\UserPlanService::syncUserAggregate($user->id);

        $this->assertFalse(
            ServerService::getAvailableUsers($server1->fresh())->contains(fn($u) => $u->uuid === 'partial-exhausted-user')
        );
        $this->assertTrue(
            ServerService::getAvailableUsers($server2->fresh())->contains(fn($u) => $u->uuid === 'partial-exhausted-user')
        );
    }
}

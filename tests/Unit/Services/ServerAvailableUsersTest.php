<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Plugin\CoreProtocols\ProtocolTypes;
use Tests\TestCase;

/**
 * 子/虚拟节点权限组必须 ⊆ 父节点；连接侧只按父节点 group_ids 下发用户。
 */
class ServerAvailableUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_assert_group_ids_within_parent_accepts_subset(): void
    {
        $normalized = Server::assertGroupIdsWithinParent([2, 1], [1, 2, 3]);
        $this->assertSame(['2', '1'], $normalized);
    }

    public function test_assert_group_ids_within_parent_rejects_extra(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('子节点权限组不能超出父节点');
        Server::assertGroupIdsWithinParent([1, 9], [1, 2]);
    }

    public function test_create_virtual_rejects_groups_outside_parent(): void
    {
        $parent = $this->makeParentServer(groupIds: [1, 2]);

        $this->expectException(InvalidArgumentException::class);
        Server::createVirtual([
            'parent_id' => $parent->id,
            'name' => 'child',
            'host' => 'child.example.com',
            'port' => 8443,
            'group_ids' => [1, 99],
            'show' => true,
        ]);
    }

    public function test_create_virtual_inherits_parent_groups_when_omitted(): void
    {
        $parent = $this->makeParentServer(groupIds: [1, 2]);

        $child = Server::createVirtual([
            'parent_id' => $parent->id,
            'name' => 'child',
            'host' => 'child.example.com',
            'port' => 8443,
            'show' => true,
        ]);

        $this->assertSame(['1', '2'], Server::normalizeGroupIdList($child->group_ids));
    }

    public function test_create_virtual_accepts_subset_groups(): void
    {
        $parent = $this->makeParentServer(groupIds: [1, 2, 3]);

        $child = Server::createVirtual([
            'parent_id' => $parent->id,
            'name' => 'child',
            'host' => 'child.example.com',
            'port' => 8443,
            'group_ids' => [2],
            'show' => true,
        ]);

        $this->assertSame(['2'], Server::normalizeGroupIdList($child->group_ids));
    }

    public function test_parent_group_shrink_clips_virtual_children(): void
    {
        $parent = $this->makeParentServer(groupIds: [1, 2, 3]);
        $child = Server::createVirtual([
            'parent_id' => $parent->id,
            'name' => 'child',
            'host' => 'child.example.com',
            'port' => 8443,
            'group_ids' => [1, 2],
            'show' => true,
        ]);

        $parent->group_ids = [1];
        $parent->save();

        $this->assertSame(
            ['1'],
            Server::normalizeGroupIdList($child->fresh()->group_ids),
            '父节点缩减权限组后，子节点应被裁剪为子集'
        );
    }

    public function test_get_available_users_only_uses_parent_groups(): void
    {
        $parent = $this->makeParentServer(groupIds: [1, 2]);
        Server::createVirtual([
            'parent_id' => $parent->id,
            'name' => 'child',
            'host' => 'child.example.com',
            'port' => 8443,
            'group_ids' => [2],
            'show' => true,
        ]);

        $userOnShared = $this->makeUser(groupId: 1, uuid: 'shared-user');
        $userOnChildSubset = $this->makeUser(groupId: 2, uuid: 'child-subset-user');
        $userOutside = $this->makeUser(groupId: 99, uuid: 'outside-user');

        $users = ServerService::getAvailableUsers($parent->fresh());
        $uuids = $users->pluck('uuid')->sort()->values()->all();

        $this->assertSame(['child-subset-user', 'shared-user'], $uuids);
        $this->assertNotContains($userOutside->uuid, $uuids);
        $this->assertContains($userOnShared->uuid, $uuids);
        $this->assertContains($userOnChildSubset->uuid, $uuids);
    }

    public function test_get_available_servers_child_only_for_subset_group(): void
    {
        $parent = $this->makeParentServer(groupIds: [1, 2], show: true);
        $child = Server::createVirtual([
            'parent_id' => $parent->id,
            'name' => 'child',
            'host' => 'child.example.com',
            'port' => 8443,
            'group_ids' => [2],
            'show' => true,
        ]);

        $user = $this->makeUser(groupId: 2, uuid: 'group-2-user');
        $servers = ServerService::getAvailableServers($user);
        $ids = collect($servers)->pluck('id')->all();

        // 父与子都含组 2 时两者可见；用户列表侧父节点也能连
        $this->assertContains($parent->id, $ids);
        $this->assertContains($child->id, $ids);
        $this->assertTrue(
            ServerService::getAvailableUsers($parent->fresh())
                ->contains(fn ($u) => $u->uuid === 'group-2-user')
        );
    }

    private function makeParentServer(array $groupIds, bool $show = true): Server
    {
        return Server::create([
            'name' => 'parent-node',
            'type' => ProtocolTypes::VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => $groupIds,
            'show' => $show,
            'enabled' => true,
        ]);
    }

    private function makeUser(int $groupId, string $uuid): User
    {
        $user = new User();
        $user->forceFill([
            'email' => $uuid . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => $uuid,
            'token' => Helper::guid(true),
            'group_id' => $groupId,
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
}

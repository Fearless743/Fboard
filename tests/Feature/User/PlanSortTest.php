<?php

namespace Tests\Feature\User;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 多套餐 PR3：POST /user/planSort 消耗顺序接口。
 */
class PlanSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['multi_plan_enable' => 1]);
    }

    public function test_plan_sort_sets_order_and_keeps_unlisted(): void
    {
        [$user, $token] = $this->seedUser();
        $a = $this->makeRow($user->id, ['sort_order' => 5]);
        $b = $this->makeRow($user->id);
        $c = $this->makeRow($user->id, ['sort_order' => 9]);

        // 只排 b、a：b=1、a=2；未列出的 c 保持原值
        $this->postJson('/api/v1/user/planSort', ['ids' => [$b->id, $a->id]], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertOk()->assertJson(['data' => true]);

        $this->assertSame(2, (int) $a->refresh()->sort_order);
        $this->assertSame(1, (int) $b->refresh()->sort_order);
        $this->assertSame(9, (int) $c->refresh()->sort_order);
    }

    public function test_plan_sort_rejects_foreign_ids_with_403(): void
    {
        [$user, $token] = $this->seedUser();
        [$other] = $this->seedUser();
        $mine = $this->makeRow($user->id);
        $foreign = $this->makeRow($other->id);

        $this->postJson('/api/v1/user/planSort', ['ids' => [$mine->id, $foreign->id]], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertForbidden();

        $this->assertSame(0, (int) $mine->refresh()->sort_order);
    }

    public function test_plan_sort_requires_login(): void
    {
        $this->postJson('/api/v1/user/planSort', ['ids' => [1]])->assertStatus(403);
    }

    /**
     * @return array{User, string}
     */
    private function seedUser(): array
    {
        $user = new User();
        $user->forceFill([
            'email' => Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();
        $token = $user->createToken('test')->plainTextToken;

        return [$user, $token];
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

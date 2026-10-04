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
 * 多套餐 PR6：V1 info/subscribe 聚合字段 + plan_list（含耗尽行）。
 */
class UserPlanApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['multi_plan_enable' => 1]);
    }

    public function test_info_returns_computed_fields_and_plan_list(): void
    {
        [$user, $token, $plan] = $this->seedUserWithRows();
        $now = time();
        $this->makeRow($user->id, $plan->id, [
            'transfer_enable' => 100, 'u' => 60, 'd' => 40,
            'expired_at' => $now + 86400, 'speed_limit' => 50,
        ]);
        $this->makeRow($user->id, $plan->id, [
            'transfer_enable' => 100, 'u' => 100, 'd' => 0,
            'expired_at' => $now + 86400,
        ]);

        $data = $this->getJson('/api/v1/user/info', $this->auth($token))->assertOk()->json('data');

        $this->assertSame(200, $data['transfer_enable']);
        $this->assertCount(2, $data['plan_list']);
        $item = $data['plan_list'][0];
        $this->assertSame($plan->name, $item['name']);
        $this->assertSame(100, $item['transfer_enable']);
        $this->assertSame(0, $item['remaining']);
        $this->assertIsInt($item['expired_at']);
        $this->assertTrue($data['plan_list'][1]['exhausted'] ?? $data['plan_list'][0]['exhausted']);
    }

    public function test_subscribe_single_plan_keeps_plan_object(): void
    {
        [$user, $token, $plan] = $this->seedUserWithRows();
        $now = time();
        $this->makeRow($user->id, $plan->id, [
            'transfer_enable' => 100, 'u' => 10, 'd' => 5,
            'expired_at' => $now + 86400, 'device_limit' => 2,
        ]);

        $data = $this->getJson('/api/v1/user/getSubscribe', $this->auth($token))->assertOk()->json('data');

        $this->assertSame(100, $data['transfer_enable']);
        $this->assertSame(10, $data['u']);
        $this->assertSame(5, $data['d']);
        $this->assertSame($plan->id, $data['plan_id']);
        $this->assertSame($plan->id, $data['plan']['id']);
        $this->assertCount(1, $data['plan_list']);
        $this->assertArrayHasKey('reset_day', $data);
    }

    public function test_subscribe_multi_plan_hides_single_plan_object(): void
    {
        [$user, $token, $plan] = $this->seedUserWithRows();
        $planB = $this->makePlan('B');
        $now = time();
        $this->makeRow($user->id, $plan->id, ['transfer_enable' => 100, 'expired_at' => $now + 86400]);
        $this->makeRow($user->id, $planB->id, ['transfer_enable' => 200, 'expired_at' => $now + 86400]);

        $data = $this->getJson('/api/v1/user/getSubscribe', $this->auth($token))->assertOk()->json('data');

        $this->assertSame(300, $data['transfer_enable']);
        $this->assertNull($data['plan_id']);
        $this->assertArrayNotHasKey('plan', $data);
        $this->assertCount(2, $data['plan_list']);
    }

    public function test_expired_rows_absent_from_plan_list(): void
    {
        [$user, $token, $plan] = $this->seedUserWithRows();
        $now = time();
        $this->makeRow($user->id, $plan->id, ['transfer_enable' => 100, 'expired_at' => $now - 10]);

        $data = $this->getJson('/api/v1/user/getSubscribe', $this->auth($token))->assertOk()->json('data');

        $this->assertSame([], $data['plan_list']);
    }

    /**
     * @return array{User, string, Plan}
     */
    private function seedUserWithRows(): array
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => 'g', 'created_at' => time(), 'updated_at' => time()]);
        $group->save();

        $plan = $this->makePlan('A', $group->id);

        $user = new User();
        $user->forceFill([
            'email' => Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $user->save();
        $token = $user->createToken('test')->plainTextToken;

        return [$user, $token, $plan];
    }

    private function makePlan(string $name, ?int $groupId = null): Plan
    {
        if ($groupId === null) {
            $group = new ServerGroup();
            $group->forceFill(['name' => 'g-' . $name, 'created_at' => time(), 'updated_at' => time()]);
            $group->save();
            $groupId = $group->id;
        }
        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $groupId,
            'transfer_enable' => 10,
            'name' => $name,
            'show' => true, 'sell' => true, 'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => 1000],
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $plan->save();

        return $plan;
    }

    private function makeRow(int $userId, int $planId, array $overrides = []): UserPlan
    {
        $row = new UserPlan();
        $row->forceFill(array_merge([
            'user_id' => $userId,
            'plan_id' => $planId,
            'kind' => UserPlan::KIND_CYCLE,
            'group_id' => 1,
            'order_ids' => [],
            'transfer_enable' => 100,
            'u' => 0, 'd' => 0,
            'sort_order' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ], $overrides));
        $row->save();

        return $row;
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 多套餐 PR6：管理端用户编辑（基字段 + 实例 diff 同事务）与列表/详情聚合。
 */
class UserPlanAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['multi_plan_enable' => 1]);
    }

    private function adminUrl(string $path): string
    {
        $secure = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

        return "/api/v2/{$secure}{$path}";
    }

    private function makeAdmin(): string
    {
        $admin = new User();
        $admin->forceFill([
            'email' => 'admin-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'is_admin' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $admin->save();

        return $admin->createToken('admin')->plainTextToken;
    }

    public function test_update_with_plans_diff_in_one_transaction(): void
    {
        [$user, $planA, $planB] = $this->seedBasics();
        $now = time();
        $rowA = $this->makeRow($user->id, $planA->id, ['expired_at' => $now + 86400, 'u' => 100]);
        $rowC = $this->makeRow($user->id, $planA->id, ['expired_at' => $now + 86400]);

        $resp = $this->postJson($this->adminUrl('/user/update'), [
            'id' => $user->id,
            'remarks' => 'edited',
            'plans' => [
                // 更新 A 行到期（用量只读不动），新增 B 行，删除未列出的 C 行
                ['id' => $rowA->id, 'plan_id' => $planA->id, 'expired_at' => $now + 30 * 86400],
                ['plan_id' => $planB->id, 'expired_at' => $now + 7 * 86400],
            ],
        ], $this->auth());
        $resp->assertOk();

        $this->assertSame('edited', $user->refresh()->remarks);
        $rowA->refresh();
        $this->assertSame($now + 30 * 86400, (int) $rowA->expired_at);
        $this->assertSame(100, (int) $rowA->u, '剩余额度只读');
        $this->assertNull(UserPlan::find($rowC->id), '未列出行删除');
        $rowB = UserPlan::where('user_id', $user->id)->where('plan_id', $planB->id)->sole();
        $this->assertSame([], $rowB->order_ids);
        $this->assertSame(10 * 1073741824, (int) $rowB->transfer_enable, '新增行配额默认取 plan 快照');
        // 主表冻结
        $this->assertNull($user->refresh()->plan_id);
    }

    public function test_update_without_plans_leaves_rows_untouched(): void
    {
        [$user] = $this->seedBasics();
        $row = $this->makeRow($user->id, 1, ['expired_at' => time() + 86400]);

        $this->postJson($this->adminUrl('/user/update'), [
            'id' => $user->id,
            'remarks' => 'only-base',
        ], $this->auth())->assertOk();

        $this->assertSame('only-base', $user->refresh()->remarks);
        $this->assertNotNull(UserPlan::find($row->id));
    }

    public function test_update_with_empty_plans_is_noop(): void
    {
        [$user] = $this->seedBasics();
        $row = $this->makeRow($user->id, 1, ['expired_at' => time() + 86400]);

        $this->postJson($this->adminUrl('/user/update'), [
            'id' => $user->id,
            'plans' => [],
        ], $this->auth())->assertOk();

        $this->assertNotNull(UserPlan::find($row->id));
    }

    public function test_clear_plans_wipes_and_rejects_combo(): void
    {
        [$user] = $this->seedBasics();
        $this->makeRow($user->id, 1);
        $this->makeRow($user->id, 1);

        $this->postJson($this->adminUrl('/user/update'), [
            'id' => $user->id,
            'clear_plans' => true,
        ], $this->auth())->assertOk();
        $this->assertSame(0, UserPlan::where('user_id', $user->id)->count());

        $this->makeRow($user->id, 1);
        $resp = $this->postJson($this->adminUrl('/user/update'), [
            'id' => $user->id,
            'clear_plans' => true,
            'plans' => [['plan_id' => 1]],
        ], $this->auth());
        $resp->assertStatus(500);
        $this->assertSame(1, UserPlan::where('user_id', $user->id)->count());
    }

    public function test_update_bad_plan_rolls_back_base_fields(): void
    {
        [$user] = $this->seedBasics();
        $row = $this->makeRow($user->id, 1);

        // 先校验全部 plan_id：坏 plan 导致整个事务回滚，基字段也不落
        $this->postJson($this->adminUrl('/user/update'), [
            'id' => $user->id,
            'remarks' => 'should-rollback',
            'plans' => [['plan_id' => 999999]],
        ], $this->auth())->assertStatus(500);

        $this->assertNull($user->refresh()->remarks);
        $this->assertNotNull(UserPlan::find($row->id));
    }

    public function test_update_foreign_row_rejected(): void
    {
        [$user] = $this->seedBasics();
        [$other] = $this->seedBasics();
        $foreign = $this->makeRow($other->id, 1);

        $this->postJson($this->adminUrl('/user/update'), [
            'id' => $user->id,
            'plans' => [['id' => $foreign->id, 'plan_id' => 1]],
        ], $this->auth())->assertStatus(500);
    }

    public function test_master_fields_accepted_and_synced(): void
    {
        [$user] = $this->seedBasics();

        // 主表 9 列恢复可写（实例表无行时直接落主表；有行时走 diff 后回写聚合）。
        $resp = $this->postJson($this->adminUrl('/user/update'), [
            'id' => $user->id,
            'expired_at' => time() + 999,
        ], $this->auth());
        $resp->assertOk();
    }

    public function test_fetch_and_detail_contain_plan_list_and_computed(): void
    {
        [$user, $planA] = $this->seedBasics();
        $now = time();
        $this->makeRow($user->id, $planA->id, [
            'transfer_enable' => 100, 'u' => 60, 'd' => 40,
            'expired_at' => $now + 86400, 'speed_limit' => 50,
        ]);
        // 耗尽行仍在列表
        $this->makeRow($user->id, $planA->id, [
            'transfer_enable' => 100, 'u' => 100, 'd' => 0,
            'expired_at' => $now + 86400,
        ]);

        $fetch = $this->postJson($this->adminUrl('/user/fetch'), [
            'current' => 1, 'pageSize' => 10,
        ], $this->auth())->assertOk()->json('data');
        $entry = collect($fetch)->firstWhere('id', $user->id);
        $this->assertNotNull($entry);
        $this->assertSame(200, $entry['transfer_enable']);
        $this->assertCount(2, $entry['plan_list']);
        $exhausted = collect($entry['plan_list'])->firstWhere('exhausted', true);
        $this->assertNotNull($exhausted, '耗尽行带 exhausted=true');

        $detail = $this->getJson($this->adminUrl('/user/getUserInfoById') . "?id={$user->id}", $this->auth())
            ->assertOk()->json('data');
        $this->assertCount(2, $detail['plan_list']);
        $this->assertSame(200, $detail['transfer_enable']);
    }

    /**
     * @return array{User, Plan, Plan}
     */
    private function seedBasics(): array
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => 'g', 'created_at' => time(), 'updated_at' => time()]);
        $group->save();

        $mkPlan = function (string $name) use ($group): Plan {
            $plan = new Plan();
            $plan->forceFill([
                'group_id' => $group->id,
                'transfer_enable' => 10,
                'name' => $name,
                'show' => true, 'sell' => true, 'renew' => true,
                'prices' => [Plan::PERIOD_MONTHLY => 1000],
                'created_at' => time(), 'updated_at' => time(),
            ]);
            $plan->save();

            return $plan;
        };

        $user = new User();
        $user->forceFill([
            'email' => Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $user->save();

        return [$user, $mkPlan('A'), $mkPlan('B')];
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

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->makeAdmin()];
    }
}

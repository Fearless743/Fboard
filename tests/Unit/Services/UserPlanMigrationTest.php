<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Models\UserPlan;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 存量单套餐用户数据迁移到 v2_user_plan 的正确性与幂等性。
 */
class UserPlanMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(string $name): ServerGroup
    {
        $g = new ServerGroup();
        $g->forceFill(['name' => $name, 'created_at' => time(), 'updated_at' => time()]);
        $g->save();
        return $g;
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

    public function test_existing_user_plan_data_is_migrated(): void
    {
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group);
        $expired = time() + 30 * 86400;

        // 构造一个"存量"单套餐用户（直接写 user 行，模拟迁移前状态）
        $user = new User();
        $user->forceFill([
            'email' => 'mig-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'plan_id' => $plan->id,
            'group_id' => $group->id,
            'transfer_enable' => 5 * 1073741824,
            'u' => 1000,
            'd' => 2000,
            'expired_at' => $expired,
            'banned' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        // 该用户在迁移前不应有 user_plan 记录
        $this->assertFalse(UserPlan::where('user_id', $user->id)->exists());

        // 重跑数据迁移
        $this->runDataMigration();

        $inst = UserPlan::where('user_id', $user->id)->first();
        $this->assertNotNull($inst, '迁移后应生成 user_plan 实例');
        $this->assertSame($plan->id, $inst->plan_id);
        $this->assertSame($group->id, $inst->group_id);
        $this->assertSame(5 * 1073741824, (int) $inst->transfer_enable);
        $this->assertSame(1000, (int) $inst->u);
        $this->assertSame(2000, (int) $inst->d);
        $this->assertSame($expired, (int) $inst->expired_at);
        $this->assertSame(UserPlan::SOURCE_MIGRATE, $inst->source);
    }

    public function test_migration_is_idempotent(): void
    {
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group);

        $user = new User();
        $user->forceFill([
            'email' => 'mig2-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'plan_id' => $plan->id,
            'group_id' => $group->id,
            'transfer_enable' => 1073741824,
            'u' => 0,
            'd' => 0,
            'expired_at' => time() + 86400,
            'banned' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $this->runDataMigration();
        $this->runDataMigration(); // 再跑一次

        // 幂等：仍只有一条实例
        $this->assertSame(1, UserPlan::where('user_id', $user->id)->count());
    }

    public function test_expired_at_zero_normalized_to_null(): void
    {
        $group = $this->makeGroup('g1');
        $plan = $this->makePlan($group);

        $user = new User();
        $user->forceFill([
            'email' => 'mig3-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'plan_id' => $plan->id,
            'group_id' => $group->id,
            'transfer_enable' => 1073741824,
            'u' => 0,
            'd' => 0,
            'expired_at' => 0, // 历史脏数据
            'banned' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $this->runDataMigration();

        $inst = UserPlan::where('user_id', $user->id)->first();
        $this->assertNull($inst->expired_at, 'expired_at=0 应归一为 NULL（长期）');
    }

    /**
     * 直接执行数据迁移的 up()（RefreshDatabase 已建表，此处只跑数据迁移逻辑）。
     */
    private function runDataMigration(): void
    {
        $migration = include database_path('migrations/2026_09_03_000002_migrate_user_plans_to_pivot.php');
        $migration->up();
    }
}

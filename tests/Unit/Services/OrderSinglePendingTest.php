<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * createFromRequest 事务内须拒绝第二笔 PENDING（不依赖控制器层检查）。
 */
class OrderSinglePendingTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_create_while_pending_is_rejected(): void
    {
        admin_setting([
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 0,
        ]);

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
            'prices' => [Plan::PERIOD_MONTHLY => 100],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        $user = new User();
        $user->forceFill([
            'email' => 'sp-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $first = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::STATUS_PENDING, (int) $first->status);

        $this->expectException(ApiException::class);
        OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
    }
}

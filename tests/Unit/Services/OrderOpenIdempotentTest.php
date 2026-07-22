<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * open() 必须幂等：二次调用不得重复加 surplus_credit / 重复延长订阅。
 */
class OrderOpenIdempotentTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_twice_does_not_double_credit_surplus(): void
    {
        admin_setting([
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'new_order_event_id' => 0,
            'renew_order_event_id' => 0,
            'change_order_event_id' => 0,
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
            'email' => 'open-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'plan_id' => $plan->id,
            'group_id' => $group->id,
            'transfer_enable' => 100 * 1073741824,
            'balance' => 0,
            'expired_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $order = new Order();
        $order->forceFill([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'OPEN-' . Helper::guid(),
            'total_amount' => 0,
            'surplus_amount' => 5000,
            'surplus_credit' => 3000,
            'type' => Order::TYPE_UPGRADE,
            'status' => Order::STATUS_PROCESSING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        (new OrderService($order))->open();
        $user->refresh();
        $balanceOnce = (int) $user->balance;
        $this->assertSame(3000, $balanceOnce);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::find($order->id)->status);

        // Job 重试 / 并发第二路：订单已是 COMPLETED，open 必须幂等退出
        (new OrderService(Order::find($order->id)))->open();
        $user->refresh();

        $this->assertSame(
            $balanceOnce,
            (int) $user->balance,
            'COMPLETED 后再 open 不得重复 surplus_credit; once=' . $balanceOnce . ' twice=' . $user->balance
        );
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::find($order->id)->status);
    }

    public function test_open_rejects_non_processing_pending(): void
    {
        admin_setting([
            'new_order_event_id' => 0,
            'renew_order_event_id' => 0,
            'change_order_event_id' => 0,
        ]);

        $group = new ServerGroup();
        $group->forceFill(['name' => 'g2', 'created_at' => time(), 'updated_at' => time()]);
        $group->save();
        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'p2',
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
            'email' => 'open2-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'plan_id' => $plan->id,
            'group_id' => $group->id,
            'transfer_enable' => 100 * 1073741824,
            'balance' => 0,
            'expired_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $order = new Order();
        $order->forceFill([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'OPEN2-' . Helper::guid(),
            'total_amount' => 10000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        $this->expectException(\RuntimeException::class);
        (new OrderService($order))->open();
    }
}

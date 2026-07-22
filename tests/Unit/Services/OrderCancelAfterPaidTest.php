<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * paid 已入账后，另一份仍持有旧 PENDING 模型的 cancel 不得覆盖状态或退回余额。
 */
class OrderCancelAfterPaidTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_after_paid_must_not_overwrite_or_refund_balance(): void
    {
        Queue::fake();
        admin_setting([
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 0,
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
            'prices' => [Plan::PERIOD_MONTHLY => 50],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        $user = new User();
        $user->forceFill([
            'email' => 'cap-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 10000,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->status);
        $this->assertGreaterThan(0, (int) $order->balance_amount);

        // 两份独立模型实例（模拟并发请求各 load 一次）
        $rowPaid = Order::find($order->id);
        $rowCancel = Order::find($order->id);

        $balanceAfterCreate = (int) User::find($user->id)->balance;

        // paid 会 dispatchSync OrderHandleJob；Queue::fake 时可能不真正 open
        // 手动把 status 标为 PROCESSING 模拟已入账，再调 cancel
        $svcPaid = new OrderService($rowPaid);
        // 直接走 paid 路径
        $paidOk = $svcPaid->paid('cb-1');
        $this->assertTrue($paidOk);

        $afterPaid = Order::find($order->id);
        $this->assertNotSame(
            Order::STATUS_PENDING,
            (int) $afterPaid->status,
            'paid 后不得仍为 PENDING'
        );

        $balanceAfterPaid = (int) User::find($user->id)->balance;

        $cancelResult = (new OrderService($rowCancel))->cancel();
        // cancel 对非 PENDING 应返回 false 或 true-if-already-cancelled，不得改写已支付
        $final = Order::find($order->id);
        $this->assertNotSame(
            Order::STATUS_CANCELLED,
            (int) $final->status,
            '已支付订单不得被 cancel 覆盖为 CANCELLED; status=' . $final->status
        );

        $balanceFinal = (int) User::find($user->id)->balance;
        $this->assertSame(
            $balanceAfterPaid,
            $balanceFinal,
            'cancel 不得在 paid 后退回 balance_amount'
        );
        $this->assertLessThanOrEqual($balanceAfterCreate, $balanceFinal);
        unset($cancelResult);
    }
}

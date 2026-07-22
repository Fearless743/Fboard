<?php

namespace Tests\Unit\Console;

use App\Console\Commands\CheckCommission;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 佣金自动确认：满足条件后 autoCheck 应将 status=3 且 commission_status=0 的订单
 * 推进到 1，随后 autoPayCommission 入账。
 */
class CheckCommissionAutoCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_check_promotes_orders_older_than_three_days_and_pays(): void
    {
        admin_setting([
            'commission_auto_check_enable' => 1,
            'commission_distribution_enable' => 0,
            'withdraw_close_enable' => 0,
        ]);

        $inviter = $this->makeUser('inv@example.com', commissionBalance: 0);
        $buyer = $this->makeUser('buy@example.com', inviteUserId: $inviter->id);

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

        $oldTs = strtotime('-4 day');
        $order = new Order();
        $order->forceFill([
            'user_id' => $buyer->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Helper::guid(),
            'total_amount' => 10000,
            'commission_balance' => 1000,
            'commission_status' => 0,
            'invite_user_id' => $inviter->id,
            'status' => Order::STATUS_COMPLETED,
            'type' => Order::TYPE_NEW_PURCHASE,
            'paid_at' => $oldTs,
            'created_at' => $oldTs,
            'updated_at' => $oldTs,
        ]);
        $order->save();

        // 强制时间锚点（Eloquent 可能改写）
        \Illuminate\Support\Facades\DB::table('v2_order')
            ->where('id', $order->id)
            ->update(['paid_at' => $oldTs, 'updated_at' => $oldTs]);

        $cmd = app(CheckCommission::class);
        $cmd->autoCheck();

        $order->refresh();
        $this->assertSame(
            1,
            (int) $order->commission_status,
            '超过 3 天的已完成订单应被自动确认（commission_status=1）'
        );

        $cmd->autoPayCommission();
        $order->refresh();
        $inviter->refresh();

        $this->assertSame(2, (int) $order->commission_status);
        $this->assertSame(1000, (int) $inviter->commission_balance);
        $this->assertSame(1, CommissionLog::where('trade_no', $order->trade_no)->count());
    }

    public function test_auto_check_skips_orders_newer_than_three_days(): void
    {
        admin_setting(['commission_auto_check_enable' => 1]);

        $inviter = $this->makeUser('inv2@example.com');
        $buyer = $this->makeUser('buy2@example.com', inviteUserId: $inviter->id);

        $order = new Order();
        $order->forceFill([
            'user_id' => $buyer->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Helper::guid(),
            'total_amount' => 10000,
            'commission_balance' => 1000,
            'commission_status' => 0,
            'invite_user_id' => $inviter->id,
            'status' => Order::STATUS_COMPLETED,
            'type' => Order::TYPE_NEW_PURCHASE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        app(CheckCommission::class)->autoCheck();
        $order->refresh();

        $this->assertSame(
            0,
            (int) $order->commission_status,
            '未满 3 天的订单不应被自动确认'
        );
    }

    public function test_auto_check_disabled_does_nothing(): void
    {
        admin_setting(['commission_auto_check_enable' => 0]);

        $inviter = $this->makeUser('inv3@example.com');
        $buyer = $this->makeUser('buy3@example.com', inviteUserId: $inviter->id);

        $oldTs = strtotime('-4 day');
        $order = new Order();
        $order->forceFill([
            'user_id' => $buyer->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Helper::guid(),
            'total_amount' => 10000,
            'commission_balance' => 1000,
            'commission_status' => 0,
            'invite_user_id' => $inviter->id,
            'status' => Order::STATUS_COMPLETED,
            'type' => Order::TYPE_NEW_PURCHASE,
            'paid_at' => $oldTs,
            'created_at' => $oldTs,
            'updated_at' => $oldTs,
        ]);
        $order->save();
        \Illuminate\Support\Facades\DB::table('v2_order')
            ->where('id', $order->id)
            ->update(['paid_at' => $oldTs, 'updated_at' => $oldTs]);

        app(CheckCommission::class)->autoCheck();
        $order->refresh();
        $this->assertSame(0, (int) $order->commission_status);
    }

    private function makeUser(string $email, int $commissionBalance = 0, ?int $inviteUserId = null): User
    {
        $user = new User();
        $user->forceFill([
            'email' => $email,
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'commission_balance' => $commissionBalance,
            'invite_user_id' => $inviteUserId,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();
        return $user;
    }
}

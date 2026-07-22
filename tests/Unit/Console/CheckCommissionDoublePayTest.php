<?php

namespace Tests\Unit\Console;

use App\Console\Commands\CheckCommission;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * commission_status=1 的订单在发放时若无行锁/状态抢占，并发两次 payHandle 会重复入账。
 */
class CheckCommissionDoublePayTest extends TestCase
{
    use RefreshDatabase;

    public function test_pay_handle_twice_without_status_claim_double_credits_inviter(): void
    {
        admin_setting([
            'commission_distribution_enable' => 0,
            'withdraw_close_enable' => 0,
        ]);

        $inviter = $this->makeUser(0);
        $buyer = $this->makeUser(0);
        $buyer->invite_user_id = $inviter->id;
        $buyer->save();

        $order = new Order();
        $order->forceFill([
            'user_id' => $buyer->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'COMM-' . Helper::guid(),
            'total_amount' => 10000,
            'commission_balance' => 1000, // 10 元佣金
            'invite_user_id' => $inviter->id,
            'commission_status' => 1, // 待发放
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        $cmd = new CheckCommission();
        $this->assertTrue($cmd->payHandle($inviter->id, $order));
        // 模拟 autoPayCommission 会把 status 置 2；若调用方漏标，payHandle 也须幂等
        $order->commission_status = 2;
        $order->save();
        $this->assertFalse(
            $cmd->payHandle($inviter->id, $order),
            'commission_status=2 时二次 payHandle 必须拒绝'
        );

        $inviter->refresh();
        $this->assertSame(
            1000,
            (int) $inviter->commission_balance,
            '同一订单不得重复发放佣金; got=' . $inviter->commission_balance
        );
        $this->assertSame(1, CommissionLog::where('trade_no', $order->trade_no)->count());
    }

    public function test_pay_handle_rejects_when_commission_log_already_exists(): void
    {
        admin_setting([
            'commission_distribution_enable' => 0,
            'withdraw_close_enable' => 0,
        ]);

        $inviter = $this->makeUser(0);
        $buyer = $this->makeUser(0);

        $order = new Order();
        $order->forceFill([
            'user_id' => $buyer->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'COMM2-' . Helper::guid(),
            'total_amount' => 10000,
            'commission_balance' => 1000,
            'invite_user_id' => $inviter->id,
            'commission_status' => 1,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        $cmd = new CheckCommission();
        $this->assertTrue($cmd->payHandle($inviter->id, $order));
        // status 仍为 1 但已有 log —— 重入须拒绝
        $order->commission_status = 1;
        $this->assertFalse($cmd->payHandle($inviter->id, $order));

        $inviter->refresh();
        $this->assertSame(1000, (int) $inviter->commission_balance);
        $this->assertSame(1, CommissionLog::where('trade_no', $order->trade_no)->count());
    }

    private function makeUser(int $commissionBalance): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'comm-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 0,
            'commission_balance' => $commissionBalance,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();
        return $user;
    }
}

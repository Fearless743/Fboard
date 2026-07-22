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
 * 余额不足覆盖两笔并发下单时，不得把内存中的 balance 重复抵扣到两笔订单。
 */
class OrderBalanceConcurrentTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_orders_cannot_both_consume_same_balance(): void
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

        // 套餐 80 元；用户余额 100 元 — 只能完整抵一笔
        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'p',
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => 80],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        $user = new User();
        $user->forceFill([
            'email' => 'bal-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 10000,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $first = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
        $user->refresh();

        $this->assertSame(Order::STATUS_PENDING, (int) $first->status);
        $this->assertSame(8000, (int) $first->balance_amount);
        $this->assertSame(2000, (int) $user->balance);
        // 守恒：订单已抵扣余额 + 用户剩余余额 == 初始 10000
        $this->assertSame(10000, (int) $first->balance_amount + (int) $user->balance);

        // 取消第一笔释放余额后再下第二笔（有未完成订单时 create 会被拒）
        $this->assertTrue((new OrderService($first))->cancel());
        $user->refresh();
        $this->assertSame(10000, (int) $user->balance);

        $second = OrderService::createFromRequest($user->fresh(), $plan, Plan::PERIOD_MONTHLY);
        $user->refresh();
        $this->assertSame(8000, (int) $second->balance_amount);
        $this->assertSame(2000, (int) $user->balance);
        $this->assertSame(
            10000,
            (int) $second->balance_amount + (int) $user->balance,
            '余额抵扣后必须守恒'
        );
    }
}

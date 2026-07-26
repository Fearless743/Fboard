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

class OrderDepositTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_deposit_bonus_picks_highest_matching_tier(): void
    {
        admin_setting([
            'deposit_bonus' => ['50:5', '100:15', '200:40'],
        ]);

        $this->assertSame(0, OrderService::calculateDepositBonus(4900));
        $this->assertSame(500, OrderService::calculateDepositBonus(5000));
        $this->assertSame(1500, OrderService::calculateDepositBonus(10000));
        $this->assertSame(4000, OrderService::calculateDepositBonus(25000));
    }

    public function test_calculate_deposit_bonus_ignores_invalid_tiers(): void
    {
        admin_setting([
            'deposit_bonus' => ['bad', '100:abc', '-1:10', '100:-5', '100:10'],
        ]);

        $this->assertSame(1000, OrderService::calculateDepositBonus(10000));
        $this->assertSame(0, OrderService::calculateDepositBonus(0));
    }

    public function test_open_deposit_credits_principal_and_frozen_bonus(): void
    {
        admin_setting([
            'deposit_bonus' => ['100:50'],
            'new_order_event_id' => 0,
            'renew_order_event_id' => 0,
            'change_order_event_id' => 0,
        ]);

        $user = $this->makeUser(balance: 1000);

        $order = new Order();
        $order->forceFill([
            'user_id' => $user->id,
            'plan_id' => 0,
            'period' => Order::PERIOD_DEPOSIT,
            'trade_no' => 'DEP-' . Helper::guid(),
            'total_amount' => 10000,
            'surplus_amount' => 1500, // 创建时冻结；与当前配置 50 元赠送不同，必须用冻结值
            'type' => Order::TYPE_DEPOSIT,
            'status' => Order::STATUS_PROCESSING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        (new OrderService($order))->open();
        $user->refresh();

        $this->assertSame(1000 + 10000 + 1500, (int) $user->balance);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::find($order->id)->status);
    }

    public function test_open_deposit_is_idempotent(): void
    {
        admin_setting([
            'deposit_bonus' => ['100:10'],
            'new_order_event_id' => 0,
            'renew_order_event_id' => 0,
            'change_order_event_id' => 0,
        ]);

        $user = $this->makeUser(balance: 0);

        $order = new Order();
        $order->forceFill([
            'user_id' => $user->id,
            'plan_id' => 0,
            'period' => Order::PERIOD_DEPOSIT,
            'trade_no' => 'DEP2-' . Helper::guid(),
            'total_amount' => 10000,
            'surplus_amount' => 1000,
            'type' => Order::TYPE_DEPOSIT,
            'status' => Order::STATUS_PROCESSING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        (new OrderService($order))->open();
        $user->refresh();
        $once = (int) $user->balance;

        (new OrderService(Order::find($order->id)))->open();
        $user->refresh();

        $this->assertSame(11000, $once);
        $this->assertSame($once, (int) $user->balance);
    }

    public function test_deposit_order_does_not_block_first_time_commission(): void
    {
        admin_setting([
            'invite_commission' => 10,
            'commission_first_time_enable' => 1,
            'plan_change_enable' => 1,
            'surplus_enable' => 0,
        ]);

        $inviter = $this->makeUser(balance: 0, emailPrefix: 'inv');
        $buyer = $this->makeUser(balance: 0, inviteUserId: $inviter->id, emailPrefix: 'buy');

        $deposit = new Order();
        $deposit->forceFill([
            'user_id' => $buyer->id,
            'plan_id' => 0,
            'period' => Order::PERIOD_DEPOSIT,
            'trade_no' => 'DEPC-' . Helper::guid(),
            'total_amount' => 5000,
            'surplus_amount' => 0,
            'type' => Order::TYPE_DEPOSIT,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $deposit->save();

        [$plan] = $this->makePlan();
        $order = OrderService::createFromRequest($buyer, $plan, Plan::PERIOD_MONTHLY);

        $this->assertSame($buyer->invite_user_id, $order->invite_user_id);
        $this->assertSame(
            Helper::percentOfCents((int) $order->total_amount, 10),
            (int) $order->commission_balance
        );
    }

    public function test_surplus_upgrade_excludes_deposit_orders_from_amount_sum(): void
    {
        admin_setting([
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'change_order_event_id' => 0,
        ]);

        [$planA, $group] = $this->makePlan(price: 100);
        $planB = new Plan();
        $planB->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'pb',
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => 200],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $planB->save();

        $user = $this->makeUser(balance: 0);
        $user->plan_id = $planA->id;
        $user->group_id = $group->id;
        $user->transfer_enable = 100 * 1073741824;
        $user->expired_at = time() + 30 * 86400;
        $user->save();

        // 一笔已完成套餐单 + 一笔大额充值单：折抵不得把充值金额算进去
        $planOrder = new Order();
        $planOrder->forceFill([
            'user_id' => $user->id,
            'plan_id' => $planA->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'SURP-' . Helper::guid(),
            'total_amount' => 10000,
            'balance_amount' => 0,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => time() - 86400,
            'updated_at' => time() - 86400,
        ]);
        $planOrder->save();

        $deposit = new Order();
        $deposit->forceFill([
            'user_id' => $user->id,
            'plan_id' => 0,
            'period' => Order::PERIOD_DEPOSIT,
            'trade_no' => 'SURPD-' . Helper::guid(),
            'total_amount' => 99999900,
            'type' => Order::TYPE_DEPOSIT,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => time() - 3600,
            'updated_at' => time() - 3600,
        ]);
        $deposit->save();

        $upgrade = OrderService::createFromRequest($user, $planB, Plan::PERIOD_MONTHLY);

        $this->assertNotContains(
            $deposit->id,
            $upgrade->surplus_order_ids ?? [],
            '充值单不得进入折抵订单列表'
        );
        // 折抵上限来自套餐实付，不应接近 999999
        $this->assertLessThanOrEqual(10000, (int) $upgrade->surplus_amount);
    }

    /**
     * @return array{0: Plan, 1: ServerGroup}
     */
    private function makePlan(int $price = 100): array
    {
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
            'prices' => [Plan::PERIOD_MONTHLY => $price],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        return [$plan, $group];
    }

    private function makeUser(int $balance = 0, ?int $inviteUserId = null, string $emailPrefix = 'u'): User
    {
        $user = new User();
        $user->forceFill([
            'email' => $emailPrefix . '-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => $balance,
            'invite_user_id' => $inviteUserId,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        return $user;
    }
}

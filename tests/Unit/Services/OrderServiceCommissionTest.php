<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderServiceCommissionTest extends TestCase
{
    use RefreshDatabase;

    private const PLAN_PRICE_YUAN = 100;
    private const PLAN_PRICE_CENTS = 10000;
    private const COMMISSION_RATE = 10;

    protected function setUp(): void
    {
        parent::setUp();

        admin_setting([
            'invite_commission' => self::COMMISSION_RATE,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 0,
        ]);
    }

    public function test_full_balance_payment_does_not_create_commission(): void
    {
        [$buyer, $plan] = $this->createBuyerWithInviter(balance: self::PLAN_PRICE_CENTS);

        $order = OrderService::createFromRequest($buyer, $plan, Plan::PERIOD_MONTHLY);

        $this->assertSame(self::PLAN_PRICE_CENTS, $order->balance_amount);
        $this->assertSame(0, $order->total_amount);
        $this->assertSame(0, (int) $order->commission_balance);
        $this->assertNull($order->invite_user_id);
    }

    public function test_partial_balance_payment_commissions_on_remaining_amount(): void
    {
        $balanceUsed = 3000;
        [$buyer, $plan] = $this->createBuyerWithInviter(balance: $balanceUsed);

        $order = OrderService::createFromRequest($buyer, $plan, Plan::PERIOD_MONTHLY);

        $expectedPay = self::PLAN_PRICE_CENTS - $balanceUsed;
        $expectedCommission = Helper::percentOfCents($expectedPay, self::COMMISSION_RATE);

        $this->assertSame($balanceUsed, $order->balance_amount);
        $this->assertSame($expectedPay, $order->total_amount);
        $this->assertSame($expectedCommission, (int) $order->commission_balance);
        $this->assertSame($buyer->invite_user_id, $order->invite_user_id);
    }

    public function test_no_balance_commissions_on_full_total_amount(): void
    {
        [$buyer, $plan] = $this->createBuyerWithInviter(balance: 0);

        $order = OrderService::createFromRequest($buyer, $plan, Plan::PERIOD_MONTHLY);

        $expectedCommission = Helper::percentOfCents(self::PLAN_PRICE_CENTS, self::COMMISSION_RATE);

        $this->assertNull($order->balance_amount);
        $this->assertSame(self::PLAN_PRICE_CENTS, $order->total_amount);
        $this->assertSame($expectedCommission, (int) $order->commission_balance);
        $this->assertSame($buyer->invite_user_id, $order->invite_user_id);
    }

    public function test_inviter_custom_rate_uses_amount_after_balance(): void
    {
        $balanceUsed = 4000;
        $customRate = 20;
        [$buyer, $plan] = $this->createBuyerWithInviter(
            balance: $balanceUsed,
            inviterCommissionRate: $customRate,
        );

        $order = OrderService::createFromRequest($buyer, $plan, Plan::PERIOD_MONTHLY);

        $expectedPay = self::PLAN_PRICE_CENTS - $balanceUsed;
        $expectedCommission = Helper::percentOfCents($expectedPay, $customRate);

        $this->assertSame($expectedPay, $order->total_amount);
        $this->assertSame($expectedCommission, (int) $order->commission_balance);
    }

    /**
     * 金额含 .99 且佣金 20% 时，佣金必须是整数分（不能是 139.8 这类 float）。
     * 否则在 MySQL STRICT 模式下写入 INTEGER 列会失败，前台表现为「出现问题」。
     */
    public function test_point_nine_nine_price_with_twenty_percent_commission_is_integer_cents(): void
    {
        $priceYuan = 6.99;
        $rate = 20;
        [$buyer, $plan] = $this->createBuyerWithInviter(
            balance: 0,
            inviterCommissionRate: $rate,
            planPriceYuan: $priceYuan,
        );

        $order = OrderService::createFromRequest($buyer, $plan, Plan::PERIOD_MONTHLY);

        $expectedTotal = Helper::yuanToCents($priceYuan); // 699
        $expectedCommission = Helper::percentOfCents($expectedTotal, $rate); // 139

        $this->assertSame(699, $expectedTotal);
        $this->assertSame(139, $expectedCommission);
        $this->assertSame($expectedTotal, $order->total_amount);
        $this->assertSame($expectedCommission, (int) $order->commission_balance);
        $this->assertIsInt($order->commission_balance);
        // 关键现 float：699 * 0.2 = 139.8
        $this->assertNotSame(139.8, $order->commission_balance);
    }

    public function test_yuan_to_cents_avoids_float_truncation(): void
    {
        // 19.99 * 100 在 float 下可能变成 1998.999…，(int) 会截成 1998
        $this->assertSame(1999, Helper::yuanToCents(19.99));
        $this->assertSame(699, Helper::yuanToCents(6.99));
        $this->assertSame(699, Helper::yuanToCents('6.99'));
        $this->assertSame(0, Helper::yuanToCents(null));
    }

    /**
     * @return array{0: User, 1: Plan}
     */
    private function createBuyerWithInviter(
        int $balance,
        ?float $inviterCommissionRate = null,
        int|float $planPriceYuan = self::PLAN_PRICE_YUAN,
    ): array {
        $group = new ServerGroup();
        $group->forceFill([
            'name' => 'test-group',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $group->save();

        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'test-plan',
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [
                Plan::PERIOD_MONTHLY => $planPriceYuan,
            ],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        $inviter = new User();
        $inviter->forceFill([
            'email' => 'inviter-' . Helper::guid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'commission_type' => User::COMMISSION_TYPE_PERIOD,
            'commission_rate' => $inviterCommissionRate,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $inviter->save();

        $buyer = new User();
        $buyer->forceFill([
            'email' => 'buyer-' . Helper::guid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'invite_user_id' => $inviter->id,
            'balance' => $balance,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer->save();

        return [$buyer, $plan];
    }
}

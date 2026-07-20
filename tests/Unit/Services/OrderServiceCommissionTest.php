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
        $expectedCommission = (int) ($expectedPay * self::COMMISSION_RATE / 100);

        $this->assertSame($balanceUsed, $order->balance_amount);
        $this->assertSame($expectedPay, $order->total_amount);
        $this->assertSame($expectedCommission, (int) $order->commission_balance);
        $this->assertSame($buyer->invite_user_id, $order->invite_user_id);
    }

    public function test_no_balance_commissions_on_full_total_amount(): void
    {
        [$buyer, $plan] = $this->createBuyerWithInviter(balance: 0);

        $order = OrderService::createFromRequest($buyer, $plan, Plan::PERIOD_MONTHLY);

        $expectedCommission = (int) (self::PLAN_PRICE_CENTS * self::COMMISSION_RATE / 100);

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
        $expectedCommission = (int) ($expectedPay * $customRate / 100);

        $this->assertSame($expectedPay, $order->total_amount);
        $this->assertSame($expectedCommission, (int) $order->commission_balance);
    }

    /**
     * @return array{0: User, 1: Plan}
     */
    private function createBuyerWithInviter(
        int $balance,
        ?float $inviterCommissionRate = null,
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
                Plan::PERIOD_MONTHLY => self::PLAN_PRICE_YUAN,
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

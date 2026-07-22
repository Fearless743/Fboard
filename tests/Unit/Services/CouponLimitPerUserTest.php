<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * limit_use_with_user 必须把 PENDING 订单也算进「已占用」，
 * 否则同一用户可连下多笔待支付订单并各用一次限量券。
 */
class CouponLimitPerUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_pending_order_with_same_coupon_is_rejected(): void
    {
        [$user, $plan] = $this->seedUserAndPlan();
        $this->createCoupon('ONCEUSER', limitUseWithUser: 1, limitUse: 10);

        $first = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY, 'ONCEUSER');
        $this->assertSame(Order::STATUS_PENDING, (int) $first->status);
        $this->assertNotNull($first->coupon_id);

        $this->expectException(ApiException::class);
        OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY, 'ONCEUSER');
    }

    public function test_cancelled_order_frees_per_user_coupon_slot(): void
    {
        [$user, $plan] = $this->seedUserAndPlan();
        $this->createCoupon('ONCEUSER2', limitUseWithUser: 1, limitUse: 10);

        $first = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY, 'ONCEUSER2');
        $this->assertTrue((new OrderService($first))->cancel());

        $second = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY, 'ONCEUSER2');
        $this->assertSame(Order::STATUS_PENDING, (int) $second->status);
        $this->assertNotNull($second->coupon_id);
    }

    /**
     * @return array{0: User, 1: Plan}
     */
    private function seedUserAndPlan(): array
    {
        admin_setting([
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 0,
        ]);

        $group = new ServerGroup();
        $group->forceFill([
            'name' => 'g',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $group->save();

        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'plan',
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
            'email' => 'c-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        return [$user, $plan];
    }

    private function createCoupon(string $code, int $limitUseWithUser, int $limitUse): Coupon
    {
        $coupon = new Coupon();
        $coupon->forceFill([
            'code' => $code,
            'name' => $code,
            'type' => 1,
            'value' => 1000,
            'show' => true,
            'limit_use' => $limitUse,
            'limit_use_with_user' => $limitUseWithUser,
            'started_at' => time() - 60,
            'ended_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $coupon->save();
        return $coupon;
    }
}

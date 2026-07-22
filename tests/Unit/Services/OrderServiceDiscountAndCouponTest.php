<?php

namespace Tests\Unit\Services;

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
 * 复现并锁定：
 * 1) VIP 折扣 + 优惠券叠加后 total_amount 可为负
 * 2) 取消订单后优惠券 limit_use 未回滚
 */
class OrderServiceDiscountAndCouponTest extends TestCase
{
    use RefreshDatabase;

    private const PLAN_PRICE_YUAN = 100; // 10000 分

    protected function setUp(): void
    {
        parent::setUp();

        admin_setting([
            'invite_commission' => 10,
            'commission_first_time_enable' => 0,
            'plan_change_enable' => 1,
            'surplus_enable' => 0,
        ]);
    }

    /**
     * 套餐 100 元 + 固定 60 元券 + VIP 50% 折扣时，
     * 折扣应按 total 封顶，total_amount 不得为负。
     */
    public function test_vip_and_fixed_coupon_cannot_make_total_amount_negative(): void
    {
        [$user, $plan] = $this->createUserAndPlan(vipDiscount: 50);
        $this->createCoupon(
            code: 'VIPFIX60',
            type: 1,
            value: 6000, // 60 元固定券（分）
            limitUse: 5,
        );

        $order = OrderService::createFromRequest(
            $user,
            $plan,
            Plan::PERIOD_MONTHLY,
            'VIPFIX60',
        );

        $this->assertGreaterThanOrEqual(
            0,
            $order->total_amount,
            'total_amount 不应为负数（VIP+优惠券叠加未封顶）'
        );
        $this->assertLessThanOrEqual(
            10000,
            (int) $order->discount_amount,
            '折扣金额不应超过原价'
        );
        $this->assertSame(
            10000 - (int) $order->discount_amount,
            $order->total_amount
        );
    }

    /**
     * 百分比券 + VIP 折扣同样不得把 total_amount 打成负数。
     * 例：100 元 + 60% 券 + 50% VIP。
     */
    public function test_vip_and_percent_coupon_cannot_make_total_amount_negative(): void
    {
        [$user, $plan] = $this->createUserAndPlan(vipDiscount: 50);
        $this->createCoupon(
            code: 'VIPPCT60',
            type: 2,
            value: 60,
            limitUse: 5,
        );

        $order = OrderService::createFromRequest(
            $user,
            $plan,
            Plan::PERIOD_MONTHLY,
            'VIPPCT60',
        );

        $this->assertGreaterThanOrEqual(0, $order->total_amount);
        $this->assertLessThanOrEqual(10000, (int) $order->discount_amount);
    }

    /**
     * createFromRequest 返回的订单实例 status 必须是 PENDING（不能只靠 DB default）。
     */
    public function test_create_from_request_sets_pending_status_on_model(): void
    {
        [$user, $plan] = $this->createUserAndPlan(vipDiscount: 0);

        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);

        $this->assertSame(Order::STATUS_PENDING, (int) $order->status);
        $this->assertArrayHasKey('status', $order->getAttributes());
        $this->assertSame(Order::STATUS_PENDING, (int) $order->getAttributes()['status']);
    }

    /**
     * 创建待支付订单会扣减 limit_use；取消后应恢复，否则优惠券被白白消耗。
     */
    public function test_cancel_order_restores_coupon_limit_use(): void
    {
        [$user, $plan] = $this->createUserAndPlan(vipDiscount: 0);
        $coupon = $this->createCoupon(
            code: 'ONCEONLY',
            type: 1,
            value: 1000,
            limitUse: 1,
        );

        $order = OrderService::createFromRequest(
            $user,
            $plan,
            Plan::PERIOD_MONTHLY,
            'ONCEONLY',
        );

        $couponFromDb = Coupon::query()->find($coupon->id);
        $this->assertNotNull($couponFromDb);
        $this->assertSame(
            0,
            (int) $couponFromDb->getAttributes()['limit_use'],
            '下单后 limit_use 应减 1；attrs=' . json_encode($couponFromDb->getAttributes())
        );
        // 订单创建时必须显式落 STATUS_PENDING，否则依赖 DB 默认值在 sqlite 测试/无 default 环境会是 null
        $this->assertSame(
            Order::STATUS_PENDING,
            (int) $order->status,
            'createFromRequest 后 status 应为待支付；raw=' . var_export($order->getAttributes()['status'] ?? null, true)
        );
        $this->assertSame($coupon->id, $order->coupon_id);

        $ok = (new OrderService($order))->cancel();
        $this->assertTrue($ok);

        $coupon->refresh();
        $this->assertSame(
            1,
            $coupon->limit_use,
            '取消订单后 limit_use 应回滚，否则限量券被空耗'
        );
    }

    /**
     * @return array{0: User, 1: Plan}
     */
    private function createUserAndPlan(int $vipDiscount): array
    {
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

        $user = new User();
        $user->forceFill([
            'email' => 'buyer-' . Helper::guid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 0,
            'discount' => $vipDiscount,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        return [$user, $plan];
    }

    private function createCoupon(string $code, int $type, int $value, int $limitUse): Coupon
    {
        $coupon = new Coupon();
        $coupon->forceFill([
            'code' => $code,
            'name' => 'test-coupon-' . $code,
            'type' => $type,
            'value' => $value,
            'show' => true,
            'limit_use' => $limitUse,
            'started_at' => time() - 3600,
            'ended_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $coupon->save();

        return $coupon;
    }
}

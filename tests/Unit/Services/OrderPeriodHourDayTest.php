<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 套餐时付 / 日付周期：映射、校验与到期时间计算。
 */
class OrderPeriodHourDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_period_mapping_includes_hour_and_day(): void
    {
        $this->assertSame(Plan::PERIOD_HOURLY, PlanService::getPeriodKey('hour_price'));
        $this->assertSame(Plan::PERIOD_DAILY, PlanService::getPeriodKey('day_price'));
        $this->assertSame(Plan::PERIOD_HOURLY, PlanService::getPeriodKey(Plan::PERIOD_HOURLY));
        $this->assertSame(Plan::PERIOD_DAILY, PlanService::getPeriodKey(Plan::PERIOD_DAILY));
        $this->assertSame('hour_price', PlanService::getLegacyPeriod(Plan::PERIOD_HOURLY));
        $this->assertSame('day_price', PlanService::getLegacyPeriod(Plan::PERIOD_DAILY));
        $this->assertTrue(Plan::isValidPeriod(Plan::PERIOD_HOURLY));
        $this->assertTrue(Plan::isValidPeriod(Plan::PERIOD_DAILY));
        $this->assertArrayHasKey(Plan::PERIOD_HOURLY, Plan::getAvailablePeriods());
        $this->assertArrayHasKey(Plan::PERIOD_DAILY, Plan::getAvailablePeriods());
    }

    public function test_get_time_adds_one_hour_and_one_day(): void
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => 'g', 'created_at' => time(), 'updated_at' => time()]);
        $group->save();

        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 10,
            'name' => 'short',
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [
                Plan::PERIOD_HOURLY => 1,
                Plan::PERIOD_DAILY => 5,
            ],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        $user = new User();
        $user->forceFill([
            'email' => 'period-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $order = new Order();
        $order->forceFill([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_HOURLY,
            'trade_no' => 'P-' . Helper::guid(),
            'total_amount' => 100,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        $service = new OrderService($order);
        $method = new ReflectionMethod(OrderService::class, 'getTime');
        $method->setAccessible(true);

        $base = time() + 3600; // 未来时间，避免被 clamp 到 now
        $hourly = $method->invoke($service, Plan::PERIOD_HOURLY, $base);
        $daily = $method->invoke($service, Plan::PERIOD_DAILY, $base);
        $legacyHour = $method->invoke($service, 'hour_price', $base);
        $legacyDay = $method->invoke($service, 'day_price', $base);

        $this->assertSame($base + 3600, $hourly);
        $this->assertSame($base + 86400, $daily);
        $this->assertSame($hourly, $legacyHour);
        $this->assertSame($daily, $legacyDay);
    }

    public function test_purchase_hourly_extends_expired_at_by_one_hour(): void
    {
        admin_setting([
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
            'transfer_enable' => 10,
            'name' => 'hourly-plan',
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [Plan::PERIOD_HOURLY => 1],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        $user = new User();
        $user->forceFill([
            'email' => 'hour-buy-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 10000,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $before = time();
        $order = OrderService::createFromRequest($user, $plan, 'hour_price');
        $this->assertSame(Plan::PERIOD_HOURLY, $order->period);

        $service = new OrderService($order->fresh());
        $this->assertTrue($service->paid('test-callback'));

        $user->refresh();
        $this->assertNotNull($user->expired_at);
        // 开通后约 1 小时（允许数秒测试开销）
        $this->assertGreaterThanOrEqual($before + 3600 - 2, (int) $user->expired_at);
        $this->assertLessThanOrEqual($before + 3600 + 30, (int) $user->expired_at);
    }
}

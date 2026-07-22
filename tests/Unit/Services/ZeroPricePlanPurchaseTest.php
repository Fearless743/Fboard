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
use Tests\TestCase;

/**
 * 标价为 0 的周期不得被 validatePurchase / 下单开通（与 getAvailablePeriods 的 >0 过滤一致）。
 */
class ZeroPricePlanPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_price_period_cannot_be_purchased_or_opened_for_free(): void
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

        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'free-bug',
            'show' => true,
            'sell' => true,
            'renew' => true,
            // 管理端允许 min:0；列表侧 getAvailablePeriods 过滤 >0，但 validatePurchase 只拒 null
            'prices' => [Plan::PERIOD_MONTHLY => 0],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        $user = new User();
        $user->forceFill([
            'email' => 'zero-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $planService = new PlanService($plan);

        try {
            $planService->validatePurchase($user, Plan::PERIOD_MONTHLY);
            // 若未抛错则继续验证下单路径也会免单开通
            $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
            $this->assertSame(0, (int) $order->total_amount, '0 元周期会生成 total_amount=0 订单');

            $paid = (new OrderService($order))->paid($order->trade_no);
            $this->assertTrue($paid);
            $user->refresh();

            // 若开通成功则为资金/权限漏洞
            $this->fail(
                '0 元周期不应通过 validatePurchase；当前可下单并 paid 开通，plan_id='
                . ($user->plan_id ?? 'null')
            );
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertTrue(true, '拒绝 0 元购买: ' . $e->getMessage());
        }
    }
}

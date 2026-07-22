<?php

namespace Tests\Unit\Http;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugin\Epay\Plugin as EpayPlugin;
use Tests\TestCase;

/**
 * 插件在 PaymentService 只传 $params 时仍须返回 paid_amount，
 * 供 PaymentController 与订单应付比对；少付不得被当成成功。
 */
class PaymentNotifyAmountTest extends TestCase
{
    use RefreshDatabase;

    public function test_epay_returns_paid_amount_and_rejects_underpayment_with_expected(): void
    {
        $order = $this->seedPendingOrder(totalCents: 10000);
        $expected = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);

        $plugin = new EpayPlugin('Epay');
        $plugin->setConfig(['key' => 'k', 'url' => 'https://x', 'pid' => '1']);

        $params = [
            'out_trade_no' => $order->trade_no,
            'trade_no' => 'gw-under',
            'money' => '0.01',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        ksort($params);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . 'k');
        $params['sign_type'] = 'MD5';

        // 插件层带 expected：直接拒绝
        $this->assertFalse($plugin->notify($params, $expected));

        // 生产路径 PaymentService 只传 params：仍返回 paid_amount 供控制器拦截
        $result = $plugin->notify($params, null);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('paid_amount', $result);
        $this->assertSame(1, $result['paid_amount']);
        $this->assertLessThan($expected, $result['paid_amount']);

        // 模拟控制器比对：少付必须判失败（订单不得被 paid）
        $this->assertTrue($result['paid_amount'] < $expected);
        $order->refresh();
        $this->assertSame(Order::STATUS_PENDING, (int) $order->status);
    }

    public function test_epay_matching_amount_returns_paid_amount_in_cents(): void
    {
        $plugin = new EpayPlugin('Epay');
        $plugin->setConfig(['key' => 'k', 'url' => 'https://x', 'pid' => '1']);
        $params = [
            'out_trade_no' => 'ORDER-OK',
            'trade_no' => 'gw-ok',
            'money' => '100.00',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        ksort($params);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . 'k');
        $params['sign_type'] = 'MD5';

        $result = $plugin->notify($params);
        $this->assertIsArray($result);
        $this->assertSame(10000, $result['paid_amount']);
        $this->assertSame('ORDER-OK', $result['trade_no']);
    }

    private function seedPendingOrder(int $totalCents): Order
    {
        $user = new User();
        $user->forceFill([
            'email' => 'pay-' . Helper::guid() . '@example.com',
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
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'PAY-' . Helper::guid(),
            'total_amount' => $totalCents,
            'handling_amount' => 0,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();
        return $order;
    }
}
